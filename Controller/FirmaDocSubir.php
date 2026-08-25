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
use FacturaScripts\Core\UploadedFile;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocDocumento;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocMailer;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocUrl;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocApi;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocAdjunto;
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

    /** @var array Clientes para el selector */
    public $clientes = [];

    /** @var array Proveedores para el selector */
    public $proveedores = [];

    /** @var string Enlace de WhatsApp con el mensaje ya montado, vacío si no hay teléfono */
    public $linkWhatsApp = '';

    /** @var string Cliente que llega preseleccionado desde su ficha */
    public $codclienteSel = '';

    /** @var string Proveedor que llega preseleccionado desde su ficha */
    public $codproveedorSel = '';

    /** @var string Enlace de vuelta a la ficha de la que se vino */
    public $volverA = '';

    /** @var int Tope real de subida, en MB: el menor entre el del plugin y el del servidor */
    public $maxSubida = 0;


    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'firmadoc';
        $data['title'] = Tools::lang()->trans('firmadoc-upload-title');
        $data['icon'] = 'fas fa-file-signature';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->config = FirmaDocConfig::getConfig();

        $accion = $this->request->request->get('action', '');
        if ($accion === 'subir') {
            $this->actionSubir();
        } elseif ($accion === 'reenviar') {
            $this->actionReenviar();
        }

        // Si se llega desde la ficha de un tercero, viene ya elegido y con vuelta
        $this->codclienteSel = $this->request->get('codcliente', '');
        $this->codproveedorSel = $this->request->get('codproveedor', '');
        if (!empty($this->codclienteSel)) {
            $this->volverA = 'EditCliente?code=' . rawurlencode($this->codclienteSel);
        } elseif (!empty($this->codproveedorSel)) {
            $this->volverA = 'EditProveedor?code=' . rawurlencode($this->codproveedorSel);
        }

        $this->clientes = (new \FacturaScripts\Core\Model\Cliente())
            ->all([], ['nombre' => 'ASC'], 0, 0);
        $this->proveedores = (new \FacturaScripts\Core\Model\Proveedor())
            ->all([], ['nombre' => 'ASC'], 0, 0);

        // De nada sirve anunciar los 32 MB del servidor si el plugin corta en 20:
        // el aviso tiene que decir lo que de verdad se va a admitir.
        $this->maxSubida = (int) floor(min(self::MAX_BYTES, UploadedFile::getMaxFilesize()) / 1024 / 1024);

        $this->setTemplate('FirmaDocSubir');
    }

    private function actionSubir(): void
    {
        // Subir un documento y mandarlo a firmar en nombre de la empresa no puede
        // dispararse desde un formulario ajeno.
        if (false === $this->validateFormToken()) {
            return;
        }

        // Los que se firman y los que solo acompañan llegan en dos campos distintos:
        // la diferencia es jurídica, así que conviene que sea explícita al subirlos.
        $aFirmar = $this->request->files->getArray('documentos');
        if (empty($aFirmar)) {
            $suelto = $this->request->files->get('documento');
            $aFirmar = $suelto ? [$suelto] : [];
        }
        $anexos = $this->request->files->getArray('anexos');

        if (empty($aFirmar)) {
            $this->fallo('firmadoc-upload-no-file');
            return;
        }

        foreach (array_merge($aFirmar, $anexos) as $f) {
            $error = $this->validarFichero($f);
            if ($error !== '') {
                $this->fallo($error);
                return;
            }
        }

        // El envío en sí lo hace FirmaDocApi, que es la misma puerta que usan los
        // demás plugins: así no hay dos formas de crear una solicitud de firma.
        $firma = FirmaDocApi::enviar([
            'ficheros' => $this->llevarATemporal($aFirmar),
            'anexos' => $this->llevarATemporal($anexos),
            'titulo' => $this->request->request->get('titulo', ''),
            'firmantes' => $this->leerFirmantes(),
            'modo' => $this->request->request->get('modo_multifirma', FirmaDoc::MODO_UNICO),
            'codcliente' => $this->request->request->get('codcliente', ''),
            'codproveedor' => $this->request->request->get('codproveedor', ''),
            'nick' => $this->user->nick ?? '',
            'mover' => true,
        ]);

        if (null === $firma) {
            $this->fallo(FirmaDocApi::getError());
            return;
        }

        $this->enviadoA = FirmaDocApi::getEnviadoA();
        $this->firmaCreada = $firma;
        $this->linkFirma = FirmaDocUrl::firma($firma->token);
        $this->linkWhatsApp = $this->construirLinkWhatsApp($firma, [
            'nombre' => $firma->getDestinatario(),
            'telefono' => $firma->telefono_cliente,
        ]);

        $this->mensaje = empty($this->enviadoA)
            ? Tools::lang()->trans('firmadoc-upload-created-not-sent')
            : Tools::lang()->trans('firmadoc-upload-created', ['%emails%' => implode(', ', $this->enviadoA)]);
        $this->mensajeTipo = empty($this->enviadoA) ? 'warning' : 'success';

        // Enviado desde la pestaña de una ficha, el sitio donde continuar es la ficha
        // de la solicitud recién creada: allí están el enlace, el estado y los reenvíos.
        if ($this->request->request->get('volver', '')) {
            Tools::log()->notice($this->mensaje);
            $this->redirect('EditFirmaDoc?code=' . $firma->id);
        }
    }

    private function fallo(string $clave): void
    {
        $this->mensaje = Tools::lang()->trans($clave ?: 'firmadoc-upload-store-failed');
        $this->mensajeTipo = 'danger';
    }

    /**
     * Saca los ficheros subidos del área temporal de PHP y devuelve sus rutas.
     *
     * PHP borra los ficheros subidos al terminar la petición, así que hay que moverlos
     * antes de dárselos a la API, que trabaja con rutas y no con formularios.
     *
     * @return string[]
     */
    private function llevarATemporal(array $ficheros): array
    {
        $rutas = [];
        $carpeta = FS_FOLDER . '/MyFiles/Cache';
        Tools::folderCheckOrCreate($carpeta);

        foreach ($ficheros as $fichero) {
            if (empty($fichero)) {
                continue;
            }

            $nombre = uniqid('firmadoc_') . '_' . $fichero->getClientOriginalName();
            if ($fichero->move($carpeta, $nombre)) {
                $rutas[] = $carpeta . '/' . $nombre;
            }
        }

        return $rutas;
    }

    /**
     * Reenvía el enlace de firma de una solicitud recién creada.
     */
    private function actionReenviar(): void
    {
        if (false === $this->validateFormToken()) {
            return;
        }

        $id = (int) $this->request->request->get('firmadoc_id', 0);
        $firma = new FirmaDoc();
        if (empty($id) || !$firma->loadFromCode($id)) {
            return;
        }

        $documento = FirmaDocDocumento::cargar($firma->tipo_doc, (int) $firma->id_doc);
        if (null === $documento) {
            $this->mensaje = Tools::lang()->trans('firmadoc-document-not-found');
            $this->mensajeTipo = 'danger';
            return;
        }

        if (FirmaDocMailer::reenviarEmail($firma, $documento, null, $this->user->nick ?? null)) {
            $this->mensaje = Tools::lang()->trans('firmadoc-send-email-sent', [
                '%email%' => $firma->email_cliente ?? '',
            ]);
            $this->mensajeTipo = 'success';
        } else {
            $this->mensaje = Tools::lang()->trans('firmadoc-send-email-error');
            $this->mensajeTipo = 'danger';
        }

        $this->firmaCreada = $firma;
        $this->linkFirma = FirmaDocUrl::firma($firma->token);
        $this->linkWhatsApp = $this->construirLinkWhatsApp($firma, [
            'nombre' => $firma->firma_nombre ?? '',
            'telefono' => $firma->telefono_cliente ?? '',
        ]);
    }

    /**
     * Enlace de wa.me con el mensaje de la plantilla ya sustituido.
     * Devuelve cadena vacía si el firmante no dejó teléfono.
     */
    private function construirLinkWhatsApp(FirmaDoc $firma, array $firmante): string
    {
        $telefono = preg_replace('/[^0-9+]/', '', $firmante['telefono'] ?? '');
        if (empty($telefono)) {
            return '';
        }

        $texto = $this->config->reemplazarVariables($this->config->whatsapp_mensaje ?? '', [
            'cliente' => $firmante['nombre'] ?? '',
            'empresa' => FirmaDocMailer::getNombreEmpresaPublic(),
            'tipo_doc' => Tools::lang()->trans('firmadoc-type-external'),
            'codigo_doc' => $firma->getTitulo(),
            'link_firma' => FirmaDocUrl::firma($firma->token),
            'fecha_expiracion' => $firma->fecha_expiracion ?? '',
            'importe' => '',
        ]);

        // Se preservan acentos y emojis: solo se codifica lo que la URL no admite
        $codificado = implode('%0A', array_map(function ($linea) {
            return preg_replace_callback('/[^\p{L}\p{N}\p{P}\p{S}\p{Zs}]/u', function ($m) {
                return rawurlencode($m[0]);
            }, $linea);
        }, preg_split('/\r\n|\r|\n/', $texto)));

        return 'https://wa.me/' . ltrim($telefono, '+') . '?text=' . $codificado;
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
        if (self::tipoDeFichero($fichero->getPathname()) === '') {
            return 'firmadoc-upload-not-pdf';
        }

        return '';
    }

    /**
     * Qué es el fichero de verdad, mirando sus primeros bytes: 'pdf', 'word' o cadena
     * vacía si es otra cosa.
     *
     * Un .docx es un zip, así que además de la firma del zip hay que asomarse dentro:
     * un .xlsx o un .zip cualquiera empiezan exactamente igual.
     */
    private static function tipoDeFichero(string $ruta): string
    {
        if (!is_readable($ruta)) {
            return '';
        }

        $cabecera = (string) file_get_contents($ruta, false, null, 0, 4);
        if (strpos($cabecera, '%PDF') === 0) {
            return 'pdf';
        }

        if (strpos($cabecera, "PK\x03\x04") !== 0) {
            return '';
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($ruta)) {
            return '';
        }

        $esWord = $zip->locateName('word/document.xml') !== false;
        $zip->close();

        return $esWord ? 'word' : '';
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
