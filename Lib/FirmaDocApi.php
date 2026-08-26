<?php
/**
 * This file is part of FirmaDoc plugin for FacturaScripts.
 *
 * @author    Francisco José Matías Olivares <fmatias@creativoz.com>
 * @copyright 2025-2026 Francisco José Matías Olivares
 * @license   Acuerdo de Licencia de Usuario Final (EULA) — véase archivo LICENSE
 * @version   1.4
 * @link      https://creativoz.com
 */
namespace FacturaScripts\Plugins\FirmaDoc\Lib;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\AttachedFile;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocAdjunto;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocConfig;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocFirmante;

/**
 * Lo que FirmaDoc ofrece a otros plugins.
 *
 * Un plugin de contratos, de obras o de lo que sea no debería tener que entrar en los
 * controladores ni en las tablas de este: aquí están las tres cosas que hacen falta
 * desde fuera —recuperar el documento firmado, saber cómo va, y montar el enlace—,
 * y son las únicas que se mantienen estables entre versiones.
 *
 * Ejemplo:
 *   $pdf = FirmaDocApi::pdfFirmado($idFirma);
 *   if (null !== $pdf) { file_put_contents($ruta, $pdf); }
 */
class FirmaDocApi
{
    /**
     * El documento firmado, con su certificado detrás, listo para guardar o enviar.
     * Devuelve null si la solicitud no existe, no está firmada o falta el documento.
     */
    public static function pdfFirmado(int $idFirma): ?string
    {
        $firma = new FirmaDoc();
        if (!$firma->loadFromCode($idFirma)) {
            return null;
        }

        return self::pdfDeFirma($firma);
    }

    /**
     * Igual que pdfFirmado(), pero partiendo de una solicitud ya cargada.
     */
    public static function pdfDeFirma(FirmaDoc $firma): ?string
    {
        if (empty($firma->id) || $firma->estado !== FirmaDoc::ESTADO_FIRMADO) {
            return null;
        }

        $documento = FirmaDocDocumento::cargar($firma->tipo_doc, (int) $firma->id_doc);
        if (null === $documento) {
            return null;
        }

        // Documento subido: el original no se toca, se le añade el certificado detrás
        // si el servidor puede unir PDF; si no, se entrega el original tal cual.
        if ($firma->esExterno()) {
            // Salvo que viniera de un Word con {{firma.aqui}}: entonces el documento se
            // repite igual que se envió, pero con la rúbrica dentro de su recuadro.
            $ruta = self::documentoConFirmasDentro($firma);
            if ($ruta === '') {
                $ruta = FirmaDocDocumento::rutaFicheroExterno($firma);
            }
            if ($ruta === '') {
                return null;
            }

            $certificado = FirmaDocPDFExport::certificadoSuelto($firma, $documento);
            $completo = FirmaDocPdfUnir::unir($ruta, $certificado) ?? (string) file_get_contents($ruta);

            if (strpos($ruta, self::carpetaBloques()) === 0) {
                @unlink($ruta);
            }

            return $completo;
        }

        $export = new FirmaDocPDFExport();
        $export->newDoc($documento->codigo ?? '', 0, '');
        $export->addBusinessDocPage($documento);
        $export->addCertificadoFirma($firma, $documento);
        // Después del certificado: así la marca alcanza también a sus páginas
        $export->estamparMarcaLateral($firma, FirmaDocPDFExport::textoMarcaLateral($firma));

        return $export->getDoc();
    }

    /**
     * Cómo va una solicitud, sin tener que conocer las tablas.
     *
     * Devuelve null si no existe. En caso contrario:
     *   estado, firmado (bool), fecha_envio, fecha_firma, fecha_expiracion,
     *   codigo_verificacion, enlace_firma, enlace_verificacion,
     *   firmantes => [['nombre','email','estado','fecha_firma'], ...]
     */
    public static function estado(int $idFirma): ?array
    {
        $firma = new FirmaDoc();
        if (!$firma->loadFromCode($idFirma)) {
            return null;
        }

        $firmantes = [];
        foreach (FirmaDocFirmante::porSolicitud($firma->id) as $f) {
            $firmantes[] = [
                'nombre' => $f->firma_nombre ?: ($f->nombre ?? ''),
                'email' => $f->email ?? '',
                'estado' => $f->estado ?? '',
                'fecha_firma' => $f->fecha_firma ?? '',
            ];
        }

        return [
            'id' => (int) $firma->id,
            'estado' => $firma->estado,
            'firmado' => $firma->estado === FirmaDoc::ESTADO_FIRMADO,
            'titulo' => $firma->getTitulo(),
            'fecha_envio' => $firma->fecha_envio ?? '',
            'fecha_firma' => $firma->fecha_firma ?? '',
            'fecha_expiracion' => $firma->fecha_expiracion ?? '',
            'codigo_verificacion' => $firma->codigo_verificacion ?? '',
            'enlace_firma' => FirmaDocUrl::firma($firma->token),
            'enlace_verificacion' => FirmaDocUrl::verificacion($firma->codigo_verificacion ?? ''),
            'firmantes' => $firmantes,
        ];
    }

    /**
     * Las solicitudes de un cliente o de un proveedor, de la más reciente a la más
     * antigua. Sirve para enseñar en una ficha ajena lo que ya se le mandó a firmar.
     *
     * @return FirmaDoc[]
     */
    public static function porTercero(string $codcliente = '', string $codproveedor = ''): array
    {
        $campo = $codcliente !== '' ? 'codcliente' : 'codproveedor';
        $valor = $codcliente !== '' ? $codcliente : $codproveedor;
        if ($valor === '') {
            return [];
        }

        return (new FirmaDoc())->all(
            [new DataBaseWhere($campo, $valor)],
            ['fecha_envio' => 'DESC'],
            0,
            0
        );
    }

    /**
     * Convierte un .docx en PDF y devuelve el resultado, por si el plugin que llama
     * quiere enseñarlo antes de mandarlo a firmar.
     *
     * Devuelve null si no se ha podido; el motivo, en $error.
     */
    public static function wordAPdf(string $rutaDocx, string &$error = ''): ?string
    {
        $conversor = new FirmaDocWordPdf();
        $pdf = $conversor->convertir($rutaDocx);
        if (null === $pdf) {
            $error = Tools::lang()->trans($conversor->getError() ?: 'firmadoc-word-failed');
            return null;
        }

        $error = '';
        return $pdf;
    }

    /** @var string Motivo del último fallo de enviar(), como clave de traducción */
    private static $error = '';

    /** @var string[] Direcciones a las que se avisó en el último enviar() */
    private static $enviadoA = [];

    /** @var bool Si el correo del último enviar() salió después de contestar */
    private static $diferido = false;

    /**
     * Bloques del Word convertido, por idfile, mientras dura el envío.
     *
     * Solo se llenan cuando el documento reserva sitio para la firma con
     * {{firma.aqui}}: entonces hay que poder repetir el documento tal cual al firmar,
     * con la rúbrica dentro.
     *
     * @var array
     */
    private static $bloquesPorFichero = [];

    public static function getError(): string
    {
        return self::$error;
    }

    /**
     * A quién se avisó por correo en el último enviar(). Vacío si no se envió a nadie,
     * que es lo que hay que mirar para saber si el enlace hay que pasarlo a mano.
     *
     * @return string[]
     */
    public static function getEnviadoA(): array
    {
        return self::$enviadoA;
    }

    /**
     * Si el correo del último enviar() se mandó después de contestar al navegador.
     *
     * Importa para lo que se le dice al usuario: cuando es así, las direcciones que
     * devuelve getEnviadoA() son a las que se va a escribir, no a las que ya se
     * escribió, y el resultado del envío queda en el registro del ERP.
     */
    public static function fueDiferido(): bool
    {
        return self::$diferido;
    }

    /**
     * A quién le toca recibir el enlace: en secuencial solo el primero, en los demás
     * modos todos los que estén pendientes.
     *
     * @param FirmaDocFirmante[] $firmantes
     * @return string[]
     */
    private static function destinatariosPrevistos(array $firmantes): array
    {
        $destinos = [];
        foreach ($firmantes as $f) {
            if ($f->estado === FirmaDocFirmante::ESTADO_PENDIENTE && !empty($f->email)) {
                $destinos[] = $f->email;
            }
        }

        return $destinos;
    }

    /**
     * Manda un documento a firmar. Es la puerta para hacerlo desde otro plugin sin
     * pasar por la pantalla de subida: se le dan ficheros que ya están en disco y
     * quién tiene que firmarlos, y devuelve la solicitud creada.
     *
     * Opciones:
     *   ficheros      string[]  rutas de lo que se firma; PDF o .docx. Obligatorio.
     *   firmantes     array[]   [['nombre'=>, 'email'=>, 'telefono'=>], ...]. Obligatorio.
     *   anexos        string[]  documentos que acompañan pero no se firman
     *   titulo        string    por defecto, el nombre del primer fichero
     *   modo          string    unico | paralelo | secuencial
     *   codcliente    string    a quién se le manda, para que salga en su ficha
     *   codproveedor  string
     *   nick          string    usuario que lo envía
     *   dias_validez  int       por defecto, el de la configuración
     *   enviar_emails bool      por defecto true; false deja solo el enlace
     *   origen_plugin string    quién la crea, para reencontrarla con porOrigen()
     *   origen_modelo string    su modelo, p. ej. 'ContratoObra'
     *   origen_id     string    el identificador de ese registro
     *   mover         bool      por defecto false: los ficheros se copian y el
     *                           original se queda donde estaba
     *
     * Devuelve null si algo falla; el motivo, en getError().
     */
    public static function enviar(array $opciones): ?FirmaDoc
    {
        self::$error = '';

        $rutas = $opciones['ficheros'] ?? [];
        if (empty($rutas)) {
            self::$error = 'firmadoc-upload-no-file';
            return null;
        }

        $firmantes = self::limpiarFirmantes($opciones['firmantes'] ?? []);
        if (empty($firmantes)) {
            self::$error = 'firmadoc-upload-no-signers';
            return null;
        }

        $mover = (bool) ($opciones['mover'] ?? false);

        $ficherosFirmar = [];
        foreach ($rutas as $ruta) {
            $guardado = self::guardarFichero($ruta, $mover);
            if (null === $guardado) {
                return null;
            }
            $ficherosFirmar[] = $guardado;
        }

        $ficherosAnexos = [];
        foreach ($opciones['anexos'] ?? [] as $ruta) {
            $guardado = self::guardarFichero($ruta, $mover);
            if (null !== $guardado) {
                $ficherosAnexos[] = $guardado;
            }
        }

        // El primero da nombre y sirve de referencia para las firmas de un solo fichero
        $adjunto = $ficherosFirmar[0];
        $config = FirmaDocConfig::getConfig();

        $modo = $opciones['modo'] ?? FirmaDoc::MODO_UNICO;
        if (count($firmantes) === 1) {
            $modo = FirmaDoc::MODO_UNICO;
        }

        $dias = (int) ($opciones['dias_validez'] ?? $config->dias_validez);

        $firma = new FirmaDoc();
        $firma->clear();
        $firma->tipo_doc = FirmaDoc::TIPO_EXTERNO;
        // En externos, id_doc apunta al AttachedFile
        $firma->id_doc = $adjunto->idfile;
        $firma->titulo = mb_substr(
            trim((string) ($opciones['titulo'] ?? '')) ?: pathinfo($adjunto->filename, PATHINFO_FILENAME),
            0,
            200
        );
        $firma->codigo_doc = mb_substr((string) $adjunto->filename, 0, 30);
        $firma->email_cliente = $firmantes[0]['email'];
        $firma->telefono_cliente = $firmantes[0]['telefono'];
        $firma->fecha_envio = date('d-m-Y H:i:s');
        $firma->fecha_expiracion = date('d-m-Y H:i:s', strtotime('+' . $dias . ' days'));
        $firma->estado = FirmaDoc::ESTADO_PENDIENTE;
        $firma->modo_multifirma = $modo;
        // Cliente o proveedor, si se han indicado: es lo que permite luego encontrar
        // el documento desde su ficha y filtrar el listado general.
        $firma->codcliente = ($opciones['codcliente'] ?? '') ?: null;
        $firma->codproveedor = ($opciones['codproveedor'] ?? '') ?: null;
        $firma->nick = ($opciones['nick'] ?? '') ?: null;
        // De dónde nace, si la crea otro plugin: así puede reencontrar sus firmas
        $firma->origen_plugin = ($opciones['origen_plugin'] ?? '') ?: null;
        $firma->origen_modelo = ($opciones['origen_modelo'] ?? '') ?: null;
        $firma->origen_id = ($opciones['origen_id'] ?? '') !== '' ? (string) $opciones['origen_id'] : null;
        // Con un solo documento, la huella es la del fichero; con paquete, la del
        // conjunto, encadenando las huellas individuales en orden.
        $hashes = [];
        foreach ($ficherosFirmar as $f) {
            $hashes[] = FirmaDoc::calcularHashFichero(FS_FOLDER . '/' . $f->path);
        }
        $firma->doc_hash = count($hashes) === 1
            ? $hashes[0]
            : FirmaDoc::calcularHashConjunto($hashes);
        $firma->generarToken();

        if (!$firma->save()) {
            self::$error = 'firmadoc-link-generate-error';
            return null;
        }

        self::archivarBloques($firma, $adjunto);

        // Los ficheros se registran siempre, también cuando solo hay uno: así la ficha,
        // el certificado y la descarga tienen una única forma de recorrerlos.
        $orden = 1;
        foreach ($ficherosFirmar as $f) {
            self::guardarAdjunto($firma->id, $f, FirmaDocAdjunto::TIPO_FIRMAR, $orden++);
        }
        $orden = 1;
        foreach ($ficherosAnexos as $f) {
            self::guardarAdjunto($firma->id, $f, FirmaDocAdjunto::TIPO_ANEXO, $orden++);
        }

        $guardados = [];
        foreach ($firmantes as $idx => $datos) {
            $f = new FirmaDocFirmante();
            $f->id_firmadoc = $firma->id;
            $f->orden = $idx + 1;
            $f->nombre = $datos['nombre'];
            $f->email = $datos['email'];
            $f->telefono = $datos['telefono'];
            $f->generarToken();
            $f->estado = ($modo === FirmaDoc::MODO_SECUENCIAL && $idx > 0)
                ? FirmaDocFirmante::ESTADO_ESPERANDO
                : FirmaDocFirmante::ESTADO_PENDIENTE;
            if ($f->save()) {
                $guardados[] = $f;
            }
        }

        self::$enviadoA = [];
        self::$diferido = false;
        if (false !== ($opciones['enviar_emails'] ?? true)) {
            // Abrir la sesión SMTP cuesta más que todo lo demás junto, y se paga por
            // cada destinatario. Se contesta primero y el correo sale detrás.
            if (FirmaDocDiferido::sePuede()) {
                self::$diferido = true;
                self::$enviadoA = self::destinatariosPrevistos($guardados);
                FirmaDocDiferido::tras(function () use ($firma, $adjunto, $guardados, $modo) {
                    FirmaDocMailer::enviarAlGenerar($firma, $adjunto, $guardados, $modo);
                });
            } else {
                self::$enviadoA = FirmaDocMailer::enviarAlGenerar($firma, $adjunto, $guardados, $modo);
            }
        }

        return $firma;
    }

    /**
     * Deja solo los firmantes con dirección de correo, que es lo único sin lo que no
     * se puede firmar, y quita los repetidos: dos enlaces al mismo buzón son un lío.
     */
    private static function limpiarFirmantes(array $firmantes): array
    {
        $limpios = [];
        $vistos = [];
        foreach ($firmantes as $f) {
            $email = trim((string) ($f['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $clave = mb_strtolower($email);
            if (isset($vistos[$clave])) {
                continue;
            }
            $vistos[$clave] = true;

            $limpios[] = [
                'nombre' => trim((string) ($f['nombre'] ?? '')),
                'email' => $email,
                'telefono' => trim((string) ($f['telefono'] ?? '')),
            ];
        }

        return $limpios;
    }

    /**
     * Lleva un fichero a MyFiles, lo convierte si es un Word y crea su AttachedFile.
     */
    private static function guardarFichero(string $origen, bool $mover): ?AttachedFile
    {
        if (!is_file($origen)) {
            self::$error = 'firmadoc-upload-no-file';
            return null;
        }

        $destino = FS_FOLDER . '/MyFiles/';
        $nombre = basename($origen);
        if (file_exists($destino . $nombre)) {
            $nombre = uniqid() . '_' . $nombre;
        }

        $llevado = $mover ? @rename($origen, $destino . $nombre) : @copy($origen, $destino . $nombre);
        if (!$llevado) {
            self::$error = 'firmadoc-upload-store-failed';
            return null;
        }

        // Un Word se convierte y lo que se guarda —y se firma— es el PDF. El firmante
        // tiene que ver exactamente lo mismo que queda sellado, y un .docx se abre
        // distinto en cada ordenador según fuentes, versión y plantilla.
        $bloques = [];
        if (self::esWord($destino . $nombre)) {
            $nombrePdf = self::convertirWord($destino, $nombre, $bloques);
            if (null === $nombrePdf) {
                @unlink($destino . $nombre);
                return null;
            }
            $nombre = $nombrePdf;
        }

        $adjunto = new AttachedFile();
        // El nombre con el que se guardó de verdad, no el original: si hubo colisión
        // son distintos y el registro apuntaría a otro fichero.
        $adjunto->path = $nombre;
        if (!$adjunto->save()) {
            @unlink($destino . $nombre);
            self::$error = 'firmadoc-upload-store-failed';
            return null;
        }

        if (!empty($bloques)) {
            self::$bloquesPorFichero[(int) $adjunto->idfile] = $bloques;
        }

        return $adjunto;
    }

    /**
     * Convierte el Word en PDF y deja solo el PDF.
     *
     * El .docx original no se conserva a propósito: tener los dos invita a discutir
     * cuál es el bueno, y el bueno es siempre el que se firmó.
     */
    private static function convertirWord(string $carpeta, string $nombre, array &$bloques = []): ?string
    {
        $conversor = new FirmaDocWordPdf();
        $pdf = $conversor->convertir($carpeta . $nombre);
        if (null === $pdf) {
            self::$error = $conversor->getError() ?: 'firmadoc-word-failed';
            return null;
        }

        // Solo hace falta guardar el documento desmontado si reserva sitio para la
        // firma; sin ancla, el PDF se entrega tal cual y el certificado va detrás.
        $bloques = $conversor->tieneAnclas() ? $conversor->getBloques() : [];

        $nombrePdf = pathinfo($nombre, PATHINFO_FILENAME) . '.pdf';
        if (file_exists($carpeta . $nombrePdf)) {
            $nombrePdf = uniqid() . '_' . $nombrePdf;
        }

        if (false === file_put_contents($carpeta . $nombrePdf, $pdf)) {
            self::$error = 'firmadoc-upload-store-failed';
            return null;
        }

        @unlink($carpeta . $nombre);

        // Sin LibreOffice la conversión es de cosecha propia y no reproduce la
        // maquetación: hay que revisar el PDF antes de que alguien lo firme.
        if (false === $conversor->conLibreOffice()) {
            Tools::log()->warning(Tools::lang()->trans('firmadoc-word-review'));
        }

        return $nombrePdf;
    }

    public static function esWord(string $ruta): bool
    {
        if (!is_readable($ruta)) {
            return false;
        }

        // Un .docx es un zip, así que además de la firma del zip hay que asomarse
        // dentro: un .xlsx o un .zip cualquiera empiezan exactamente igual.
        if (strpos((string) file_get_contents($ruta, false, null, 0, 4), "PK\x03\x04") !== 0) {
            return false;
        }

        if (false === FirmaDocWordPdf::sePuedeLeerWord()) {
            return false;
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($ruta)) {
            return false;
        }

        $esWord = $zip->locateName('word/document.xml') !== false;
        $zip->close();

        return $esWord;
    }

    private static function guardarAdjunto(int $idFirma, AttachedFile $fichero, string $tipo, int $orden): void
    {
        $adjunto = new FirmaDocAdjunto();
        $adjunto->clear();
        $adjunto->id_firmadoc = $idFirma;
        $adjunto->idfile = $fichero->idfile;
        $adjunto->tipo = $tipo;
        $adjunto->orden = $orden;
        $adjunto->nombre = mb_substr((string) $fichero->filename, 0, 200);
        $adjunto->doc_hash = FirmaDoc::calcularHashFichero(FS_FOLDER . '/' . $fichero->path);
        $adjunto->save();
    }

    /**
     * Las solicitudes que creó otro plugin para uno de sus registros, de la más
     * reciente a la más antigua.
     *
     * Es la forma de que un plugin de contratos sepa qué se ha mandado a firmar de un
     * contrato suyo sin guardarse nada por su cuenta.
     *
     * @return FirmaDoc[]
     */
    public static function porOrigen(string $plugin, string $modelo = '', string $id = ''): array
    {
        $where = [new DataBaseWhere('origen_plugin', $plugin)];
        if ($modelo !== '') {
            $where[] = new DataBaseWhere('origen_modelo', $modelo);
        }
        if ($id !== '') {
            $where[] = new DataBaseWhere('origen_id', $id);
        }

        return (new FirmaDoc())->all($where, ['fecha_envio' => 'DESC'], 0, 0);
    }

    /**
     * La última solicitud de un registro, que suele ser la que interesa: si se mandó
     * dos veces, manda la de después.
     */
    public static function ultimaPorOrigen(string $plugin, string $modelo, string $id): ?FirmaDoc
    {
        $lista = self::porOrigen($plugin, $modelo, $id);
        return empty($lista) ? null : $lista[0];
    }

    /**
     * Anula una solicitud: el enlace deja de servir y quien lo tenga verá que se
     * canceló. No borra nada, que es lo que interesa cuando hay que explicar después
     * qué pasó con aquel envío.
     */
    public static function cancelar(int $idFirma): bool
    {
        $firma = new FirmaDoc();
        if (!$firma->loadFromCode($idFirma)) {
            self::$error = 'firmadoc-document-not-found';
            return false;
        }

        if ($firma->estado === FirmaDoc::ESTADO_FIRMADO) {
            // Cancelar algo ya firmado no tiene sentido: lo firmado, firmado está
            self::$error = 'firmadoc-already-signed';
            return false;
        }

        $firma->estado = FirmaDoc::ESTADO_CANCELADO;
        return $firma->save();
    }

    /**
     * Vuelve a mandar el enlace a quien tenga la firma pendiente.
     * Devuelve las direcciones a las que se envió; vacío si no se pudo.
     *
     * @return string[]
     */
    public static function reenviar(int $idFirma): array
    {
        $firma = new FirmaDoc();
        if (!$firma->loadFromCode($idFirma) || $firma->estado !== FirmaDoc::ESTADO_PENDIENTE) {
            self::$error = 'firmadoc-only-when-pending';
            return [];
        }

        $documento = FirmaDocDocumento::cargar($firma->tipo_doc, (int) $firma->id_doc);
        if (null === $documento) {
            self::$error = 'firmadoc-document-not-found';
            return [];
        }

        $pendientes = [];
        foreach (FirmaDocFirmante::porSolicitud($firma->id) as $f) {
            if ($f->estado === FirmaDocFirmante::ESTADO_PENDIENTE) {
                $pendientes[] = $f;
            }
        }

        return FirmaDocMailer::enviarAlGenerar($firma, $documento, $pendientes, $firma->modo_multifirma);
    }

    /**
     * Si el documento sigue siendo el que se firmó.
     *
     * Devuelve false también cuando no hay nada que comprobar —solicitud inexistente o
     * documento desaparecido—: ante la duda, no se afirma que esté intacto.
     */
    public static function documentoIntacto(int $idFirma): bool
    {
        $firma = new FirmaDoc();
        if (!$firma->loadFromCode($idFirma)) {
            return false;
        }

        $documento = FirmaDocDocumento::cargar($firma->tipo_doc, (int) $firma->id_doc);
        if (null === $documento) {
            return false;
        }

        return $firma->documentoSinModificar($documento) === true;
    }

    /**
     * Si FirmaDoc puede enviar ahora mismo. Sirve para que otro plugin no ofrezca un
     * botón que va a fallar, y para decir por qué no.
     *
     * @return array ['listo' => bool, 'motivos' => string[]]
     */
    public static function disponible(): array
    {
        $motivos = [];

        if (FirmaDocUrl::base() === '') {
            // Sin dirección pública no hay enlace que mandar
            $motivos[] = 'firmadoc-no-site-url';
        }

        $mail = new \FacturaScripts\Core\Lib\Email\NewMail();
        if (false === $mail->canSendMail()) {
            $motivos[] = 'firmadoc-no-email-setup';
        }

        return ['listo' => empty($motivos), 'motivos' => $motivos];
    }

    /**
     * El documento tal como se mandó a firmar, sin certificado. Es lo que hay que
     * enseñar mientras la firma está pendiente.
     */
    public static function pdfOriginal(int $idFirma): ?string
    {
        $firma = new FirmaDoc();
        if (!$firma->loadFromCode($idFirma)) {
            return null;
        }

        if ($firma->esExterno()) {
            $ruta = FirmaDocDocumento::rutaFicheroExterno($firma);
            return $ruta === '' ? null : (string) file_get_contents($ruta);
        }

        $documento = FirmaDocDocumento::cargar($firma->tipo_doc, (int) $firma->id_doc);
        if (null === $documento) {
            return null;
        }

        $export = new FirmaDocPDFExport();
        $export->newDoc($documento->codigo ?? '', 0, '');
        $export->addBusinessDocPage($documento);

        return $export->getDoc();
    }

    /**
     * Borra una solicitud con todo lo suyo: firmantes, adjuntos e historial de envíos.
     *
     * Pensado para cuando desaparece el registro que la originó. Una firma ya
     * completada no se borra: es la prueba de que alguien firmó, y esa prueba no puede
     * evaporarse porque se borre un contrato.
     */
    public static function eliminar(int $idFirma, bool $incluirFirmadas = false): bool
    {
        $firma = new FirmaDoc();
        if (!$firma->loadFromCode($idFirma)) {
            self::$error = 'firmadoc-document-not-found';
            return false;
        }

        if ($firma->estado === FirmaDoc::ESTADO_FIRMADO && false === $incluirFirmadas) {
            self::$error = 'firmadoc-cannot-delete-signed';
            return false;
        }

        @unlink(self::carpetaBloques() . '/bloques-' . $firma->id . '.json');

        return $firma->delete();
    }

    /** @var callable[] Lo que hay que avisar cuando se completa una firma */
    private static $oyentes = [];

    /**
     * Pide que se avise cuando cualquier documento termine de firmarse.
     *
     * Se registra desde el init() del plugin que escucha, porque la firma ocurre en la
     * petición del firmante y allí no hay nada más de ese plugin en marcha:
     *
     *   FirmaDocApi::alFirmar(function (array $firma) {
     *       if ($firma['origen_plugin'] !== 'MiPlugin') { return; }
     *       // ... archivar $firma['id'], marcar el contrato, lo que toque
     *   });
     *
     * Al oyente le llega el mismo array que devuelve estado(), más el origen. Nunca se
     * le pasa el modelo: así lo que se le promete no cambia aunque cambien las tablas.
     */
    public static function alFirmar(callable $oyente): void
    {
        self::$oyentes[] = $oyente;
    }

    /**
     * Avisa a los oyentes. Lo llama FirmaDoc al completarse una firma.
     *
     * Un oyente que reviente no puede tumbar la firma: el documento ya está firmado y
     * el firmante no tiene por qué ver un error de otro plugin.
     */
    public static function avisarFirmado(FirmaDoc $firma): void
    {
        if (empty(self::$oyentes)) {
            return;
        }

        $datos = self::estado((int) $firma->id);
        if (null === $datos) {
            return;
        }

        $datos['origen_plugin'] = $firma->origen_plugin ?? '';
        $datos['origen_modelo'] = $firma->origen_modelo ?? '';
        $datos['origen_id'] = $firma->origen_id ?? '';
        $datos['codcliente'] = $firma->codcliente ?? '';
        $datos['codproveedor'] = $firma->codproveedor ?? '';

        foreach (self::$oyentes as $oyente) {
            try {
                $oyente($datos);
            } catch (\Throwable $e) {
                Tools::log()->error('FirmaDoc: ' . $e->getMessage());
            }
        }
    }

    /**
     * Deja el documento desmontado junto a la solicitud, para poder repetirlo con la
     * firma dentro cuando llegue el momento.
     */
    private static function archivarBloques(FirmaDoc $firma, AttachedFile $adjunto): void
    {
        $bloques = self::$bloquesPorFichero[(int) $adjunto->idfile] ?? [];
        self::$bloquesPorFichero = [];

        if (empty($bloques)) {
            return;
        }

        $carpeta = self::carpetaBloques();
        Tools::folderCheckOrCreate($carpeta);
        @file_put_contents(
            $carpeta . '/bloques-' . $firma->id . '.json',
            json_encode($bloques, JSON_UNESCAPED_UNICODE)
        );
    }

    private static function carpetaBloques(): string
    {
        return FS_FOLDER . '/MyFiles/FirmaDoc';
    }

    /**
     * Los bloques archivados de una solicitud, o array vacío si no los tiene.
     */
    private static function bloquesDe(FirmaDoc $firma): array
    {
        $ruta = self::carpetaBloques() . '/bloques-' . $firma->id . '.json';
        if (!is_file($ruta)) {
            return [];
        }

        $datos = json_decode((string) file_get_contents($ruta), true);

        return is_array($datos) ? $datos : [];
    }

    /**
     * Las firmas ya recogidas, en el formato que espera el pintor: por número de orden
     * del firmante, que es a lo que apunta {{firma.aqui:2}}.
     */
    private static function firmasParaAnclas(FirmaDoc $firma): array
    {
        $firmas = [];
        foreach (FirmaDocFirmante::porSolicitud($firma->id) as $f) {
            if ($f->estado !== FirmaDocFirmante::ESTADO_FIRMADO) {
                continue;
            }
            $firmas[(int) $f->orden] = [
                'imagen' => $f->firma_imagen ?? '',
                'nombre' => $f->firma_nombre ?: ($f->nombre ?? ''),
                'nif' => $f->firma_nif ?? '',
                'fecha' => $f->fecha_firma ?? '',
            ];
        }

        // Firma sin firmantes en tabla: el modo de un solo firmante clásico
        if (empty($firmas) && !empty($firma->firma_nombre)) {
            $firmas[1] = [
                'imagen' => $firma->firma_imagen ?? '',
                'nombre' => $firma->firma_nombre,
                'nif' => $firma->firma_nif ?? '',
                'fecha' => $firma->fecha_firma ?? '',
            ];
        }

        return $firmas;
    }

    /**
     * Repite el documento con las firmas puestas en sus anclas y lo deja en un fichero
     * temporal, cuya ruta devuelve. Cadena vacía si esta solicitud no venía de un Word
     * con {{firma.aqui}}.
     */
    private static function documentoConFirmasDentro(FirmaDoc $firma): string
    {
        $bloques = self::bloquesDe($firma);
        if (empty($bloques)) {
            return '';
        }

        $firmas = self::firmasParaAnclas($firma);
        if (empty($firmas)) {
            return '';
        }

        $conversor = new FirmaDocWordPdf();
        $pdf = $conversor->repintar($bloques, $firmas);
        if (null === $pdf) {
            return '';
        }

        $ruta = self::carpetaBloques() . '/firmado-' . $firma->id . '-' . uniqid() . '.pdf';
        Tools::folderCheckOrCreate(self::carpetaBloques());

        return @file_put_contents($ruta, $pdf) === false ? '' : $ruta;
    }
}
