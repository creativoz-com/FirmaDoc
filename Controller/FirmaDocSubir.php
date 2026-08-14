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
namespace FacturaScripts\Plugins\FirmaDoc\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Model\AttachedFile;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocMailer;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocUrl;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocConfig;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocFirmante;

/**
 * Envía a firma un PDF que sube el usuario: contratos, anexos, autorizaciones…
 *
 * Es el mismo circuito que el de los documentos de venta —multi-firmante, verificación
 * en dos pasos, sello de tiempo, certificado y portal de verificación—, pero partiendo
 * de un fichero en lugar de una factura.
 */
class FirmaDocSubir extends Controller
{
    /** Tamaño máximo admitido, en bytes */
    const MAX_BYTES = 20 * 1024 * 1024;

    /** @var FirmaDocConfig */
    public $config;

    /** @var string */
    public $mensaje = '';

    /** @var string */
    public $mensajeTipo = '';

    /** @var FirmaDoc|null Solicitud recién creada */
    public $firmaCreada = null;

    /** @var string Enlace de firma de la solicitud recién creada */
    public $linkFirma = '';

    /** @var string[] Direcciones a las que se envió */
    public $enviadoA = [];

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'ventas';
        $data['title'] = Tools::lang()->trans('firmadoc-upload-title');
        $data['icon'] = 'fas fa-file-signature';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->config = FirmaDocConfig::getConfig();

        if ($this->request->request->get('action', '') === 'subir') {
            $this->actionSubir();
        }

        $this->setTemplate('FirmaDocSubir');
    }

    private function actionSubir(): void
    {
        $fichero = $this->request->files->get('documento');
        $error = $this->validarFichero($fichero);
        if ($error !== '') {
            $this->mensaje = Tools::lang()->trans($error);
            $this->mensajeTipo = 'danger';
            return;
        }

        $firmantes = $this->leerFirmantes();
        if (empty($firmantes)) {
            $this->mensaje = Tools::lang()->trans('firmadoc-upload-no-signers');
            $this->mensajeTipo = 'danger';
            return;
        }

        // El PDF se custodia con AttachedFile, que es donde FacturaScripts guarda los
        // ficheros: hereda su gestión de rutas, límites de almacenamiento y tokens de
        // descarga. El núcleo espera que el fichero esté ya en MyFiles/ antes de
        // guardar el registro, así que primero se mueve y después se crea.
        $destino = FS_FOLDER . '/MyFiles/';
        $nombreDestino = $fichero->getClientOriginalName();
        if (file_exists($destino . $nombreDestino)) {
            $nombreDestino = uniqid() . '_' . $nombreDestino;
        }

        if (!$fichero->move($destino, $nombreDestino)) {
            $this->mensaje = Tools::lang()->trans('firmadoc-upload-store-failed');
            $this->mensajeTipo = 'danger';
            return;
        }

        $adjunto = new AttachedFile();
        // Se usa el nombre con el que realmente se guardó, no el original: si hubo
        // colisión son distintos y el registro apuntaría a un fichero que no es.
        $adjunto->path = $nombreDestino;
        if (!$adjunto->save()) {
            @unlink($destino . $nombreDestino);
            $this->mensaje = Tools::lang()->trans('firmadoc-upload-store-failed');
            $this->mensajeTipo = 'danger';
            return;
        }

        $titulo = trim($this->request->request->get('titulo', ''))
            ?: pathinfo($adjunto->filename, PATHINFO_FILENAME);

        $modoMulti = $this->request->request->get('modo_multifirma', FirmaDoc::MODO_UNICO);
        if (count($firmantes) === 1) {
            $modoMulti = FirmaDoc::MODO_UNICO;
        }

        $firma = new FirmaDoc();
        $firma->clear();
        $firma->tipo_doc = FirmaDoc::TIPO_EXTERNO;
        // En externos, id_doc apunta al AttachedFile
        $firma->id_doc = $adjunto->idfile;
        $firma->titulo = mb_substr($titulo, 0, 200);
        $firma->codigo_doc = mb_substr($adjunto->filename, 0, 30);
        $firma->email_cliente = $firmantes[0]['email'];
        $firma->telefono_cliente = $firmantes[0]['telefono'];
        $firma->fecha_envio = date('d-m-Y H:i:s');
        $firma->fecha_expiracion = date('d-m-Y H:i:s', strtotime('+' . (int) $this->config->dias_validez . ' days'));
        $firma->estado = FirmaDoc::ESTADO_PENDIENTE;
        $firma->modo_multifirma = $modoMulti;
        // La huella es la del PDF entero, byte a byte
        $firma->doc_hash = FirmaDoc::calcularHashFichero(FS_FOLDER . '/' . $adjunto->path);
        $firma->generarToken();

        if (!$firma->save()) {
            $this->mensaje = Tools::lang()->trans('firmadoc-link-generate-error');
            $this->mensajeTipo = 'danger';
            return;
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
            $f->estado = ($modoMulti === FirmaDoc::MODO_SECUENCIAL && $idx > 0)
                ? FirmaDocFirmante::ESTADO_ESPERANDO
                : FirmaDocFirmante::ESTADO_PENDIENTE;
            if ($f->save()) {
                $guardados[] = $f;
            }
        }

        $this->enviadoA = FirmaDocMailer::enviarAlGenerar($firma, $adjunto, $guardados, $modoMulti);
        $this->firmaCreada = $firma;
        $this->linkFirma = FirmaDocUrl::firma($firma->token);

        $this->mensaje = empty($this->enviadoA)
            ? Tools::lang()->trans('firmadoc-upload-created-not-sent')
            : Tools::lang()->trans('firmadoc-upload-created', ['%emails%' => implode(', ', $this->enviadoA)]);
        $this->mensajeTipo = empty($this->enviadoA) ? 'warning' : 'success';
    }

    /**
     * @return string Clave de idioma del error, o cadena vacía si es válido
     */
    private function validarFichero($fichero): string
    {
        if (empty($fichero)) {
            return 'firmadoc-upload-no-file';
        }

        if (!$fichero->isValid()) {
            return 'firmadoc-upload-invalid';
        }

        if ($fichero->getSize() > self::MAX_BYTES) {
            return 'firmadoc-upload-too-big';
        }

        // Se comprueba el contenido, no la extensión ni el tipo que declare el navegador:
        // ambos los elige quien sube el fichero.
        $ruta = $fichero->getPathname();
        $cabecera = is_readable($ruta) ? (string) file_get_contents($ruta, false, null, 0, 5) : '';
        if (strpos($cabecera, '%PDF-') !== 0) {
            return 'firmadoc-upload-not-pdf';
        }

        return '';
    }

    /**
     * @return array[] Cada elemento: ['nombre' => string, 'email' => string, 'telefono' => string]
     */
    private function leerFirmantes(): array
    {
        $todos = $this->request->request->all();
        $filas = isset($todos['firmantes']) && is_array($todos['firmantes'])
            ? array_values($todos['firmantes'])
            : [];

        $firmantes = [];
        foreach ($filas as $fila) {
            $email = trim($fila['email'] ?? '');
            if ($email === '' || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $firmantes[] = [
                'nombre' => trim($fila['nombre'] ?? ''),
                'email' => $email,
                'telefono' => trim($fila['telefono'] ?? ''),
            ];
        }

        return $firmantes;
    }
}
