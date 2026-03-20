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
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocPDFExport;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocConfig;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocFirmante;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocMailer;

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
        if ($this->request->get('action', '') === 'ver_pdf') {
            $this->servirPdf($response);
            return;
        }
        $this->procesarFirma();
        $this->setTemplate('FirmaDocPublic');
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        if ($this->request->get('action', '') === 'ver_pdf') {
            $this->servirPdf($response);
            return;
        }
        $this->procesarFirma();
        $this->setTemplate('FirmaDocPublic');
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

        // Generar PDF — si está firmado añade página de certificado
        $export = new FirmaDocPDFExport();
        $export->newDoc($documento->codigo ?? '', 0, '');
        $export->addBusinessDocPage($documento);

        if ($firma->estado === FirmaDoc::ESTADO_FIRMADO) {
            $export->addCertificadoFirma($firma, $documento);
        }

        // Generar y servir el PDF directamente sin caché
        $pdfContent = $export->getDoc();
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'inline; filename=' . $documento->codigo . '.pdf');
        $response->setContent($pdfContent);
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
                $this->mensaje     = Tools::lang()->trans('firmadoc-already-signed-thanks');
                $this->mensajeTipo = 'success';
                $this->calcularLinkDocumento();
                return;
            }
        }

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
        if ($action === 'firmar') {
            $this->registrarFirma();
        } elseif ($action === 'rechazar') {
            $this->procesarRechazo();
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

        $scheme  = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $subdir  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

        $this->urlBase = $scheme . '://' . $host . $subdir;

        // El link del PDF usa el token maestro (ver_pdf busca en firmadoc directamente)
        $this->linkDocumento = $scheme . '://' . $host . $subdir
            . '/FirmaDocPublic?token=' . $this->firma->token . '&action=ver_pdf';
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
        if ($certData) {
            $this->firma->modo_firma = 'certificado';
        }
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
                Tools::log()->info('FirmaDoc: Enlace enviado al siguiente firmante: ' . $siguiente->email);
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

            $mail->send();

        } catch (\Exception $e) {
            Tools::log()->error(Tools::lang()->trans('firmadoc-error-notification', ['%error%' => $e->getMessage()]));
        }
    }

    private function cargarDocumento(string $tipo, int $id): ?object
    {
        $clases = [
            FirmaDoc::TIPO_FACTURA     => '\FacturaScripts\Core\Model\FacturaCliente',
            FirmaDoc::TIPO_PRESUPUESTO => '\FacturaScripts\Core\Model\PresupuestoCliente',
            FirmaDoc::TIPO_ALBARAN     => '\FacturaScripts\Core\Model\AlbaranCliente',
            FirmaDoc::TIPO_PEDIDO      => '\FacturaScripts\Core\Model\PedidoCliente',
        ];
        if (!isset($clases[$tipo])) return null;
        $modelo = new $clases[$tipo]();
        return $modelo->loadFromCode($id) ? $modelo : null;
    }
}
