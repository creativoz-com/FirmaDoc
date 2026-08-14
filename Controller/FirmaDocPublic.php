<?php
/**
 * This file is part of FirmaDoc plugin for FacturaScripts.
 *
 * @author    Francisco José Matías Olivares <fmatias@creativoz.com>
 * @copyright 2025-2026 Francisco José Matías Olivares
 * @license   Acuerdo de Licencia de Usuario Final (EULA) — véase archivo LICENSE
 * @version   1.11
 * @link      https://creativoz.com
 */
namespace FacturaScripts\Plugins\FirmaDoc\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Lib\Email\NewMail;
use FacturaScripts\Core\Lib\Email\TitleBlock;
use FacturaScripts\Core\Lib\Email\TextBlock;
use FacturaScripts\Core\Lib\Email\TableBlock;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocEmail;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocDocumento;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocEmpresa;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocPdfUnir;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocPDFExport;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocUrl;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocConfig;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocFirmante;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocMailer;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocOtp;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocSelloTiempo;

class FirmaDocPublic extends Controller
{
    public $firma;
    public $config;
    public $mensaje = '';
    public $mensajeTipo = '';
    public $tokenValido = false;

    /** @var FirmaDocFirmante|null Firmante individual activo (en modo multi-firmante) */
    private $firmanteActual = null;

    /** @var int Número de firmantes que ya han firmado */
    public $firmantesHanFirmado = 0;

    /** @var int Total de firmantes requeridos */
    public $firmantesTotal = 0;
    public $linkDocumento = '';
    public $urlBase = '';

    /** @var string Nombre de la empresa que envía el documento */
    public $empresaNombre = '';

    /** @var string URL del logo de la empresa, vacía si no tiene */
    public $empresaLogo = '';

    /** @var string Importe del documento ya formateado, vacío si no aplica */
    public $importeDoc = '';

    /** @var bool True cuando hay que pedir el código de un solo uso antes de firmar */
    public $pideOtp = false;

    /** @var string Dirección enmascarada a la que se envió el código */
    public $otpDestino = '';

    /** @var string Mensaje de error del código, ya traducido */
    public $otpError = '';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = '';
        $data['title'] = Tools::lang()->trans('firmadoc-title');
        $data['icon'] = 'fas fa-signature';
        $data['showonmenu'] = false;
        return $data;
    }

    public function publicCore(&$response)
    {
        parent::publicCore($response);
        if ($this->servirDescarga($response)) {
            return;
        }
        $this->procesarFirma();
        $this->setTemplate('FirmaDocPublic');
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        if ($this->servirDescarga($response)) {
            return;
        }
        $this->procesarFirma();
        $this->setTemplate('FirmaDocPublic');
    }

    /**
     * Atiende las descargas del enlace público.
     *
     * @return bool true si la petición era una descarga y ya está resuelta
     */
    private function servirDescarga(&$response): bool
    {
        $accion = $this->request->get('action', '');

        if ($accion === 'ver_pdf') {
            $this->servirPdf($response);
            return true;
        }

        if ($accion === 'ver_certificado') {
            $firma = FirmaDoc::getByToken($this->request->get('token', ''));
            if (null === $firma) {
                $firmante = FirmaDocFirmante::getByToken($this->request->get('token', ''));
                $padre = new FirmaDoc();
                $firma = ($firmante && $padre->loadFromCode($firmante->id_firmadoc)) ? $padre : null;
            }
            if (null === $firma) {
                $this->setTemplate(false);
                $response->setContent('<h1>' . Tools::lang()->trans('firmadoc-invalid-link') . '</h1>');
                return true;
            }
            $this->servirCertificado($response, $firma);
            return true;
        }

        return false;
    }

    private function servirPdf(&$response): void
    {
        // Indicar al Kernel que no renderice ninguna plantilla
        $this->setTemplate(false);

        $token = $this->request->get('token', '');
        if (empty($token)) {
            $response->setContent('<h1>' . Tools::lang()->trans('firmadoc-invalid-token') . '</h1>');
            return;
        }

        $firma = FirmaDoc::getByToken($token);
        if (!$firma) {
            // Puede ser token de firmante individual
            $firmante = FirmaDocFirmante::getByToken($token);
            if ($firmante) {
                $firmaPadre = new FirmaDoc();
                if ($firmaPadre->loadFromCode($firmante->id_firmadoc)) {
                    $firma = $firmaPadre;
                }
            }
        }
        if (!$firma) {
            $response->setContent('<h1>' . Tools::lang()->trans('firmadoc-invalid-link') . '</h1>');
            return;
        }

        $config = FirmaDocConfig::getConfig();

        if ($config->descarga_pdf === FirmaDocConfig::DESCARGA_NO) {
            $response->setContent('<h1>' . Tools::lang()->trans('firmadoc-download-not-allowed') . '</h1>');
            return;
        }
        if ($config->descarga_pdf === FirmaDocConfig::DESCARGA_POSFIRMA
            && $firma->estado !== FirmaDoc::ESTADO_FIRMADO) {
            $response->setContent('<h1>' . Tools::lang()->trans('firmadoc-available-after-signing') . '</h1>');
            return;
        }

        $documento = $this->cargarDocumento($firma->tipo_doc, $firma->id_doc);
        if (!$documento) {
            $response->setContent('<h1>' . Tools::lang()->trans('firmadoc-document-not-found') . '</h1>');
            return;
        }

        $pdfContent = $firma->esExterno()
            ? $this->pdfDocumentoExterno($firma, $documento)
            : $this->pdfDocumentoVenta($firma, $documento);

        if ($pdfContent === '') {
            $response->setContent('<h1>' . Tools::lang()->trans('firmadoc-document-not-found') . '</h1>');
            return;
        }

        $nombre = $firma->esExterno()
            ? ($documento->filename ?? 'documento.pdf')
            : ($documento->codigo ?? 'documento') . '.pdf';

        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'inline; filename="' . $nombre . '"');
        $response->setContent($pdfContent);
    }

    /**
     * PDF de un documento de venta: se compone al vuelo y, si está firmado, se le
     * añade la página de certificado.
     */
    private function pdfDocumentoVenta(FirmaDoc $firma, object $documento): string
    {
        $export = new FirmaDocPDFExport();
        $export->newDoc($documento->codigo ?? '', 0, '');
        $export->addBusinessDocPage($documento);

        if ($firma->estado === FirmaDoc::ESTADO_FIRMADO) {
            $export->addCertificadoFirma($firma, $documento);
        }

        return $export->getDoc();
    }

    /**
     * PDF de un documento subido.
     *
     * El fichero original **no se modifica nunca**: es el documento que el firmante
     * aceptó, y alterarlo invalidaría su propia huella. Si está firmado se le añade el
     * certificado como páginas finales, pero solo cuando el servidor tiene una
     * herramienta para unir PDF; si no la tiene, se entrega el original y el
     * certificado se descarga aparte.
     */
    private function pdfDocumentoExterno(FirmaDoc $firma, object $documento): string
    {
        $ruta = FirmaDocDocumento::rutaFicheroExterno($firma);
        if ($ruta === '') {
            return '';
        }

        $original = (string) file_get_contents($ruta);

        if ($firma->estado !== FirmaDoc::ESTADO_FIRMADO) {
            return $original;
        }

        $certificado = FirmaDocPDFExport::certificadoSuelto($firma, $documento);
        $unido = FirmaDocPdfUnir::unir($ruta, $certificado);

        return $unido ?? $original;
    }

    /**
     * Sirve solo el certificado de firma, para cuando no se puede unir al original.
     */
    private function servirCertificado(&$response, FirmaDoc $firma): void
    {
        $this->setTemplate(false);

        if ($firma->estado !== FirmaDoc::ESTADO_FIRMADO) {
            $response->setContent('<h1>' . Tools::lang()->trans('firmadoc-available-after-signing') . '</h1>');
            return;
        }

        $documento = $this->cargarDocumento($firma->tipo_doc, (int) $firma->id_doc);
        if (!$documento) {
            $response->setContent('<h1>' . Tools::lang()->trans('firmadoc-document-not-found') . '</h1>');
            return;
        }

        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'inline; filename="certificado.pdf"');
        $response->setContent(FirmaDocPDFExport::certificadoSuelto($firma, $documento));
    }

    private function procesarFirma(): void
    {
        $this->config = FirmaDocConfig::getConfig();
        $token = $this->request->get('token', '');

        if (empty($token)) {
            $this->mensaje     = Tools::lang()->trans('firmadoc-link-invalid');
            $this->mensajeTipo = 'danger';
            return;
        }

        // Buscar primero en firmadoc (token maestro)
        $this->firma = FirmaDoc::getByToken($token);

        if (!$this->firma) {
            // Buscar en firmadoc_firmante (token de firmante individual)
            $firmante = FirmaDocFirmante::getByToken($token);
            if (!$firmante) {
                $this->mensaje     = Tools::lang()->trans('firmadoc-link-not-exists');
                $this->mensajeTipo = 'danger';
                return;
            }
            // Cargar la firma padre
            $firmaPadre = new FirmaDoc();
            if (!$firmaPadre->loadFromCode($firmante->id_firmadoc)) {
                $this->mensaje     = Tools::lang()->trans('firmadoc-link-not-exists');
                $this->mensajeTipo = 'danger';
                return;
            }
            $this->firma          = $firmaPadre;
            $this->firmanteActual = $firmante;

            // Si el firmante está en estado "esperando", no le toca aún
            if ($firmante->estado === FirmaDocFirmante::ESTADO_ESPERANDO) {
                $this->mensaje     = Tools::lang()->trans('firmadoc-not-your-turn');
                $this->mensajeTipo = 'info';
                return;
            }
            // Si el firmante ya firmó
            if ($firmante->estado === FirmaDocFirmante::ESTADO_FIRMADO) {
                $this->cargarDatosEmpresa();
                $this->mensaje     = Tools::lang()->trans('firmadoc-already-signed-thanks');
                $this->mensajeTipo = 'success';
                $this->calcularLinkDocumento();
                return;
            }
        }

        // Quién envía el documento y por cuánto. Se calcula antes de mirar el estado
        // porque la cabecera debe identificar a la empresa en todos los casos: también
        // si el enlace está caducado, cancelado o ya firmado. Sin esto el firmante ve
        // una página anónima pidiéndole su nombre y su DNI.
        $this->cargarDatosEmpresa();

        if ($this->firma->estado === FirmaDoc::ESTADO_FIRMADO) {
            $this->mensaje     = Tools::lang()->trans('firmadoc-document-already-signed');
            $this->mensajeTipo = 'success';
            $this->calcularLinkDocumento();
            return;
        }

        if ($this->firma->estado === FirmaDoc::ESTADO_CANCELADO) {
            $motivo = $this->firma->motivo_rechazo ?? '';
            $this->mensaje = empty($motivo)
                ? Tools::lang()->trans('firmadoc-link-cancelled')
                : Tools::lang()->trans('firmadoc-document-rejected-reason') . ' <em>' . htmlspecialchars($motivo) . '</em>';
            $this->mensajeTipo = 'warning';
            return;
        }

        if ($this->firma->estaExpirado()) {
            $this->firma->estado = FirmaDoc::ESTADO_EXPIRADO;
            $this->firma->save();
            $this->mensaje     = Tools::lang()->trans('firmadoc-link-expired');
            $this->mensajeTipo = 'warning';
            return;
        }

        $this->tokenValido = true;
        $this->calcularLinkDocumento();

        // Calcular progreso de firmantes (para mostrar barra siempre)
        $todosFirms = FirmaDocFirmante::porSolicitud($this->firma->id);
        if (!empty($todosFirms)) {
            $this->firmantesTotal      = count($todosFirms);
            $this->firmantesHanFirmado = count(array_filter($todosFirms, fn($f) => $f->estado === FirmaDocFirmante::ESTADO_FIRMADO));
        }

        // Registrar apertura (auditoría)
        $this->registrarApertura();

        $action = $this->request->request->get('action', '');

        // Verificación en dos pasos. Rechazar el documento no la exige: negarse a firmar
        // no compromete a nadie, y obligar a un código para decir «no» solo consigue que
        // el firmante abandone sin contestar.
        if ($action !== 'rechazar' && !$this->resolverOtp($action)) {
            return;
        }

        if ($action === 'firmar') {
            $this->registrarFirma();
        } elseif ($action === 'rechazar') {
            $this->procesarRechazo();
        }
    }

    /**
     * Gestiona la verificación en dos pasos.
     *
     * @return bool true si se puede continuar hacia el formulario de firma
     */
    private function resolverOtp(string $action): bool
    {
        if (empty($this->config->otp_activo)) {
            return true;
        }

        // En multi-firmante el código es de cada firmante, no de la solicitud
        $registro = $this->firmanteActual ?: $this->firma;

        if (FirmaDocOtp::verificado($registro)) {
            return true;
        }

        $destino = $this->firmanteActual
            ? $this->firmanteActual->email
            : $this->firma->email_cliente;

        if (empty($destino)) {
            // Sin dirección no hay segundo factor posible: se avisa y no se deja firmar,
            // porque saltárselo en silencio dejaría la firma sin la garantía prometida.
            $this->tokenValido = false;
            $this->mensaje     = Tools::lang()->trans('firmadoc-otp-no-address');
            $this->mensajeTipo = 'danger';
            return false;
        }

        $this->pideOtp    = true;
        $this->tokenValido = false;
        $this->otpDestino = FirmaDocOtp::ocultar($destino);

        if ($action === 'comprobar_otp') {
            $error = FirmaDocOtp::comprobar($registro, $this->request->request->get('otp_codigo', ''));
            if ($error === '') {
                $this->pideOtp     = false;
                $this->tokenValido = true;
                return true;
            }
            $this->otpError = Tools::lang()->trans($error);
            return false;
        }

        if ($action === 'reenviar_otp' || empty($registro->otp_codigo)) {
            $documento = $this->cargarDocumento($this->firma->tipo_doc, (int) $this->firma->id_doc);
            if (!FirmaDocOtp::enviar($registro, $destino, (int) $this->config->otp_minutos, $documento)) {
                $this->otpError = Tools::lang()->trans('firmadoc-otp-send-failed');
            }
        }

        return false;
    }

    /**
     * Carga el nombre y el logo de la empresa emisora y el importe del documento,
     * que es lo que permite al firmante saber quién le pide la firma y por cuánto.
     */
    private function cargarDatosEmpresa(): void
    {
        if (!$this->firma) {
            return;
        }

        $documento = $this->cargarDocumento($this->firma->tipo_doc, (int) $this->firma->id_doc);

        $this->empresaNombre = FirmaDocEmpresa::nombre($documento);

        if ($this->config->mostrar_logo) {
            $this->empresaLogo = FirmaDocEmpresa::logoUrl($documento);
        }

        if ($documento && isset($documento->total)) {
            $this->importeDoc = Tools::money((float) $documento->total, $documento->coddivisa ?? '');
        }
    }

    private function calcularLinkDocumento(): void
    {
        if (!$this->firma) return;

        $descarga = $this->config->descarga_pdf ?? FirmaDocConfig::DESCARGA_SIEMPRE;
        if ($descarga === FirmaDocConfig::DESCARGA_NO) return;

        // En DESCARGA_POSFIRMA: mostrar si está firmado o si hay firmantes parciales
        if ($descarga === FirmaDocConfig::DESCARGA_POSFIRMA
            && $this->firma->estado !== FirmaDoc::ESTADO_FIRMADO) {
            // Comprobar si hay al menos un firmante que haya firmado ya
            $firmantes = FirmaDocFirmante::porSolicitud($this->firma->id);
            $hayFirmado = false;
            foreach ($firmantes as $f) {
                if ($f->estado === FirmaDocFirmante::ESTADO_FIRMADO) {
                    $hayFirmado = true;
                    break;
                }
            }
            if (!$hayFirmado) return;
        }

        $this->urlBase = FirmaDocUrl::base();

        // El link del PDF usa el token maestro (ver_pdf busca en firmadoc directamente)
        $this->linkDocumento = FirmaDocUrl::firma($this->firma->token) . '&action=ver_pdf';
    }

    private function registrarApertura(): void
    {
        if (!$this->firma) return;

        // Primera apertura y contador global (firma padre)
        if (empty($this->firma->fecha_primera_apertura)) {
            $this->firma->fecha_primera_apertura = date('d-m-Y H:i:s');
        }
        $this->firma->veces_visto = ((int)$this->firma->veces_visto) + 1;
        $this->firma->save();

        // Contador individual del firmante (multi-firmante)
        if ($this->firmanteActual) {
            if (empty($this->firmanteActual->fecha_primera_apertura)) {
                $this->firmanteActual->fecha_primera_apertura = date('d-m-Y H:i:s');
            }
            $this->firmanteActual->veces_visto = ((int)$this->firmanteActual->veces_visto) + 1;
            $this->firmanteActual->save();
        }
    }

    private function procesarRechazo(): void
    {
        $motivo = trim($this->request->request->get('motivo_rechazo', ''));
        $nombre = trim($this->request->request->get('rechazo_nombre', ''));

        if (empty($motivo) || empty($nombre)) {
            $this->mensaje     = Tools::lang()->trans('firmadoc-please-name-and-reason');
            $this->mensajeTipo = 'warning';
            return;
        }

        $this->firma->estado         = FirmaDoc::ESTADO_CANCELADO;
        $this->firma->firma_nombre   = $nombre;
        $this->firma->motivo_rechazo = $motivo;
        $this->firma->ip_cliente     = $_SERVER['REMOTE_ADDR'] ?? '';
        $this->firma->save();

        // Notificar a la empresa si está configurado
        $this->notificarRechazoEmpresa($motivo);

        $this->tokenValido = false;
        $this->mensaje     = Tools::lang()->trans('firmadoc-rejected-notified');
        $this->mensajeTipo = 'warning';
    }

    private function notificarRechazoEmpresa(string $motivo): void
    {
        if (!$this->config->notif_empresa) return;

        $emailDestino = Tools::settings('email', 'email', '');
        if (empty($emailDestino)) return;

        try {
            $mail = new NewMail();
            if (!$mail->canSendMail()) return;

            $mail->addAddress($emailDestino);
            if (!empty($this->config->email_adicional)) {
                $mail->addAddress($this->config->email_adicional);
            }

            $mail->title = Tools::lang()->trans('firmadoc-email-rejected-subject', ['%code%' => $this->firma->codigo_doc]);
            $logo = FirmaDocEmail::logo($this->cargarDocumento($this->firma->tipo_doc, (int) $this->firma->id_doc));
            if ($logo !== null) {
                $mail->addMainBlock($logo);
            }
            $mail->addMainBlock(new TitleBlock(Tools::lang()->trans('firmadoc-email-rejected-title'), 'h2'));
            $mail->addMainBlock(new TextBlock(
                Tools::lang()->trans('firmadoc-email-rejected-body', [
                    '%type%' => ucfirst($this->firma->tipo_doc),
                    '%code%' => $this->firma->codigo_doc
                ])
            ));
            $filas = [
                [Tools::lang()->trans('firmadoc-field-document'), ucfirst($this->firma->tipo_doc) . ' ' . $this->firma->codigo_doc],
                [Tools::lang()->trans('firmadoc-field-reason'),   nl2br(htmlspecialchars($motivo))],
                [Tools::lang()->trans('firmadoc-field-date'),     date('d/m/Y H:i')],
                [Tools::lang()->trans('firmadoc-field-ip'),       $this->firma->ip_cliente ?? '—'],
            ];
            $mail->addMainBlock(new TableBlock([Tools::lang()->trans('firmadoc-field-field'), Tools::lang()->trans('firmadoc-field-detail')], $filas));

            $enlaceDoc = FirmaDocUrl::firma($this->firma->token);
            if (!empty($enlaceDoc)) {
                $mail->addMainBlock(FirmaDocEmail::boton(
                    $enlaceDoc,
                    Tools::lang()->trans('firmadoc-email-view-document')
                ));
            }

            $mail->send();
        } catch (\Exception $e) {
            Tools::log()->error(Tools::lang()->trans('firmadoc-error-rejection-notification', ['%error%' => $e->getMessage()]));
        }
    }

    private function registrarFirma(): void
    {
        $firmaImagen = $this->request->request->get('firma_imagen', '');
        $firmaNombre = $this->request->request->get('firma_nombre', '');
        $firmaNif    = $this->request->request->get('firma_nif', '');
        $firmaCargo  = $this->request->request->get('firma_cargo', '');
        $aceptoLegal = (bool) $this->request->request->get('acepto_legal', false);

        if (empty($firmaImagen) && empty($firmaNombre)) {
            $this->mensaje     = Tools::lang()->trans('firmadoc-please-sign');
            $this->mensajeTipo = 'danger';
            return;
        }

        // El endpoint es público: no vale fiarse de que el navegador haya acotado la
        // imagen. Se comprueba que sea un PNG en base64 y que quepa en la columna,
        // que es de tipo `text` (65.535 bytes en MySQL).
        if (!empty($firmaImagen)) {
            if (strpos($firmaImagen, 'data:image/png;base64,') !== 0) {
                $this->mensaje     = Tools::lang()->trans('firmadoc-signature-invalid-format');
                $this->mensajeTipo = 'danger';
                return;
            }
            if (strlen($firmaImagen) > 65000) {
                $this->mensaje     = Tools::lang()->trans('firmadoc-signature-too-large');
                $this->mensajeTipo = 'danger';
                return;
            }
        }

        $this->firma->firma_imagen       = $firmaImagen;
        $this->firma->firma_nombre       = $firmaNombre;
        $this->firma->firma_nif          = $firmaNif;
        $this->firma->firma_cargo        = $firmaCargo;
        $this->firma->acepto_legal       = $aceptoLegal;
        $this->firma->fecha_acepto_legal = $aceptoLegal ? date('d-m-Y H:i:s') : null;
        $this->firma->ip_cliente         = $_SERVER['REMOTE_ADDR'] ?? '';
        $this->firma->user_agent         = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $this->firma->fecha_firma        = date('d-m-Y H:i:s');
        $this->firma->observaciones_firmante = trim($this->request->request->get('observaciones_firmante', '')) ?: null;
        $certData = trim($this->request->request->get('firma_certificado_data', ''));
        $this->firma->firma_certificado_data = $certData ?: null;

        // El modo se guarda siempre, no solo cuando hay certificado: es un dato del
        // certificado de firma y antes salía como «—» en manuscrita y tipográfica.
        $modoEnviado = $this->request->request->get('modo_firma', '');
        $this->firma->modo_firma = $certData
            ? 'certificado'
            : (in_array($modoEnviado, ['manuscrita', 'tipografica'], true)
                ? $modoEnviado
                : ($firmaImagen ? 'manuscrita' : 'tipografica'));
        $this->firma->estado             = FirmaDoc::ESTADO_FIRMADO;

        // Si hay firmante individual, primero actualizar su registro
        if ($this->firmanteActual) {
            $this->firmanteActual->estado      = FirmaDocFirmante::ESTADO_FIRMADO;
            $this->firmanteActual->fecha_firma  = date('d-m-Y H:i:s');
            $this->firmanteActual->firma_nombre = $firmaNombre;
            $this->firmanteActual->firma_nif    = $firmaNif;
            $this->firmanteActual->firma_cargo  = $firmaCargo;
            $this->firmanteActual->firma_imagen = $firmaImagen;
            $this->firmanteActual->modo_firma   = $certData ? 'certificado' : ($firmaImagen ? 'manuscrita' : 'tipografica');
            $this->firmanteActual->ip_cliente   = $_SERVER['REMOTE_ADDR'] ?? '';
            $this->firmanteActual->save();

            // Calcular progreso
            $todosFirmantes = FirmaDocFirmante::porSolicitud($this->firma->id);
            $this->firmantesTotal      = count($todosFirmantes);
            $this->firmantesHanFirmado = count(array_filter($todosFirmantes, fn($f) => $f->estado === FirmaDocFirmante::ESTADO_FIRMADO));

            // Solo marcar la firma principal como firmada si todos han firmado
            if (!FirmaDocFirmante::todosFirmaron($this->firma->id)) {
                $this->tokenValido = false;
                $this->mensaje     = Tools::lang()->trans('firmadoc-signed-partial', [
                    '%signed%' => $this->firmantesHanFirmado,
                    '%total%' => $this->firmantesTotal
                ]);
                $this->mensajeTipo = 'success';
                $this->calcularLinkDocumento(); // mostrar PDF parcial si está configurado
                $this->notificarEmpresa();
                $this->procesarMultiFirmante();
                return;
            }
        }

        // Sello de tiempo: la fecha de la firma la certifica un tercero, no el reloj
        // del servidor del emisor. Si la TSA falla, se firma igual y se avisa por el
        // log: perder la firma por una caída de un servicio externo sería peor.
        $this->sellarSiProcede();

        if ($this->firma->save()) {
            $this->tokenValido = false;
            $this->firmantesHanFirmado = $this->firmantesTotal ?: 1;
            $this->mensaje     = $this->firmantesTotal > 1
                ? Tools::lang()->trans('firmadoc-signed-complete-multi', ['%total%' => $this->firmantesTotal])
                : Tools::lang()->trans('firmadoc-signed-complete');
            $this->mensajeTipo = 'success';
            $this->calcularLinkDocumento();
            $this->notificarEmpresa();
            $this->procesarMultiFirmante();
            // Enviar email de confirmación a todos los firmantes y a la empresa
            try {
                $docModel = $this->cargarDocumento($this->firma->tipo_doc, $this->firma->id_doc);
                if ($docModel) {
                    FirmaDocMailer::enviarConfirmacionTodos($this->firma, $docModel);
                }
            } catch (\Exception $e) {
                Tools::log()->error(Tools::lang()->trans('firmadoc-error-confirmation', ['%error%' => $e->getMessage()]));
            }
        } else {
            $this->mensaje     = Tools::lang()->trans('firmadoc-save-error');
            $this->mensajeTipo = 'danger';
        }
    }


    /**
     * Pide el sello de tiempo a la autoridad configurada y lo guarda en la firma.
     */
    private function sellarSiProcede(): void
    {
        if (empty($this->config->sello_activo) || empty($this->firma->doc_hash)) {
            return;
        }

        // Solo se sella SHA-256: las firmas anteriores a la v1.4 llevan MD5, que ninguna
        // autoridad seria acepta y que tampoco tendría sentido certificar ahora.
        if (strlen($this->firma->doc_hash) !== 64) {
            return;
        }

        $sello = FirmaDocSelloTiempo::sellar(
            $this->firma->doc_hash,
            (string) ($this->config->sello_url ?? '')
        );

        if ($sello === null) {
            Tools::log()->warning(Tools::lang()->trans('firmadoc-tsa-failed'));
            return;
        }

        $this->firma->sello_tiempo    = $sello['token'];
        $this->firma->sello_fecha     = $sello['fecha'];
        $this->firma->sello_autoridad = $sello['autoridad'];
    }

    /**
     * Gestiona el avance secuencial tras una firma.
     * Busca el siguiente firmante en estado "esperando" y lo activa.
     */
    private function procesarMultiFirmante(): void
    {
        $firmantes = FirmaDocFirmante::porSolicitud($this->firma->id);
        if (empty($firmantes)) {
            return;
        }

        // Modo secuencial: activar al siguiente que está "esperando"
        if ($this->firma->modo_multifirma === FirmaDoc::MODO_SECUENCIAL) {
            $siguiente = FirmaDocFirmante::siguienteEsperando($this->firma->id);
            if ($siguiente) {
                $siguiente->estado = FirmaDocFirmante::ESTADO_PENDIENTE;
                $siguiente->save();
                FirmaDocMailer::enviarSiguiente($siguiente, $this->firma);
                Tools::log()->info(Tools::lang()->trans('firmadoc-next-signer-notified', ['%email%' => $siguiente->email]));
            }
        }
    }


    private function notificarEmpresa(): void
    {
        if (!$this->config->notif_empresa) {
            return;
        }

        $emailDestino = Tools::settings('email', 'email', '');
        if (empty($emailDestino)) {
            return;
        }

        try {
            $mail = new NewMail();

            if (!$mail->canSendMail()) {
                Tools::log()->warning(Tools::lang()->trans('firmadoc-no-email-config'));
                return;
            }

            // Destinatario principal
            $mail->addAddress($emailDestino);

            // Email adicional si está configurado
            if (!empty($this->config->email_adicional)) {
                $mail->addAddress($this->config->email_adicional);
            }

            // Asunto
            $mail->title = Tools::lang()->trans('firmadoc-email-signed-subject', ['%code%' => $this->firma->codigo_doc]);

            // Cuerpo con bloques nativos de FacturaScripts
            $logo = FirmaDocEmail::logo($this->cargarDocumento($this->firma->tipo_doc, (int) $this->firma->id_doc));
            if ($logo !== null) {
                $mail->addMainBlock($logo);
            }

            $mail->addMainBlock(new TitleBlock(
                Tools::lang()->trans('firmadoc-email-signed-title'),
                'h2'
            ));

            $mail->addMainBlock(new TextBlock(
                Tools::lang()->trans('firmadoc-email-signed-body', [
                    '%type%' => ucfirst($this->firma->tipo_doc),
                    '%code%' => $this->firma->codigo_doc
                ])
            ));

            // Tabla con los datos de la firma
            $filas = [
                [Tools::lang()->trans('firmadoc-field-signer'),    $this->firma->firma_nombre ?? '—'],
                [Tools::lang()->trans('firmadoc-field-nif'),       $this->firma->firma_nif    ?: '—'],
                [Tools::lang()->trans('firmadoc-field-position'),  $this->firma->firma_cargo  ?: '—'],
                [Tools::lang()->trans('firmadoc-field-sign-date'), $this->firma->fecha_firma ?? '—'],
                [Tools::lang()->trans('firmadoc-field-client-ip'), $this->firma->ip_cliente   ?? '—'],
                [Tools::lang()->trans('firmadoc-field-document'),  ucfirst($this->firma->tipo_doc) . ' ' . $this->firma->codigo_doc],
            ];

            $mail->addMainBlock(new TableBlock(
                [Tools::lang()->trans('firmadoc-field-field'), Tools::lang()->trans('firmadoc-field-value')],
                $filas
            ));

            $enlaceFirmado = FirmaDocUrl::firma($this->firma->token);
            if (!empty($enlaceFirmado)) {
                $mail->addMainBlock(FirmaDocEmail::boton(
                    $enlaceFirmado . '&action=ver_pdf',
                    Tools::lang()->trans('firmadoc-email-view-signed')
                ));
            }

            $mail->send();

        } catch (\Exception $e) {
            Tools::log()->error(Tools::lang()->trans('firmadoc-error-notification', ['%error%' => $e->getMessage()]));
        }
    }

    private function cargarDocumento(string $tipo, int $id): ?object
    {
        return FirmaDocDocumento::cargar($tipo, $id);
    }
}
