<?php
/**
 * This file is part of FirmaDoc plugin for FacturaScripts.
 *
 * @author    Francisco José Matías Olivares <fmatias@creativoz.com>
 * @copyright 2025-2026 Francisco José Matías Olivares
 * @license   Acuerdo de Licencia de Usuario Final (EULA) — véase archivo LICENSE
 * @version   1.0
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
        $data['title'] = 'Firma de documento';
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
            $response->setContent('<h1>Token no válido</h1>');
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
            $response->setContent('<h1>Enlace no válido</h1>');
            return;
        }

        $config = FirmaDocConfig::getConfig();

        if ($config->descarga_pdf === FirmaDocConfig::DESCARGA_NO) {
            $response->setContent('<h1>Descarga no permitida</h1>');
            return;
        }
        if ($config->descarga_pdf === FirmaDocConfig::DESCARGA_POSFIRMA
            && $firma->estado !== FirmaDoc::ESTADO_FIRMADO) {
            $response->setContent('<h1>El documento estará disponible tras la firma</h1>');
            return;
        }

        $documento = $this->cargarDocumento($firma->tipo_doc, $firma->id_doc);
        if (!$documento) {
            $response->setContent('<h1>Documento no encontrado</h1>');
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
            $this->mensaje     = 'Link de firma no válido.';
            $this->mensajeTipo = 'danger';
            return;
        }

        // Buscar primero en firmadoc (token maestro)
        $this->firma = FirmaDoc::getByToken($token);

        if (!$this->firma) {
            // Buscar en firmadoc_firmante (token de firmante individual)
            $firmante = FirmaDocFirmante::getByToken($token);
            if (!$firmante) {
                $this->mensaje     = 'Este enlace de firma no existe.';
                $this->mensajeTipo = 'danger';
                return;
            }
            // Cargar la firma padre
            $firmaPadre = new FirmaDoc();
            if (!$firmaPadre->loadFromCode($firmante->id_firmadoc)) {
                $this->mensaje     = 'Este enlace de firma no existe.';
                $this->mensajeTipo = 'danger';
                return;
            }
            $this->firma          = $firmaPadre;
            $this->firmanteActual = $firmante;

            // Si el firmante está en estado "esperando", no le toca aún
            if ($firmante->estado === FirmaDocFirmante::ESTADO_ESPERANDO) {
                $this->mensaje     = 'Todavía no es tu turno para firmar. Recibirás un email cuando sea el momento.';
                $this->mensajeTipo = 'info';
                return;
            }
            // Si el firmante ya firmó
            if ($firmante->estado === FirmaDocFirmante::ESTADO_FIRMADO) {
                $this->mensaje     = 'Ya has firmado este documento. ¡Gracias!';
                $this->mensajeTipo = 'success';
                $this->calcularLinkDocumento();
                return;
            }
        }

        if ($this->firma->estado === FirmaDoc::ESTADO_FIRMADO) {
            $this->mensaje     = 'Este documento ya ha sido firmado.';
            $this->mensajeTipo = 'success';
            $this->calcularLinkDocumento();
            return;
        }

        if ($this->firma->estado === FirmaDoc::ESTADO_CANCELADO) {
            $motivo = $this->firma->motivo_rechazo ?? '';
            $this->mensaje = empty($motivo)
                ? 'Este enlace ha sido cancelado.'
                : 'Este documento fue rechazado con el siguiente motivo: <em>' . htmlspecialchars($motivo) . '</em>';
            $this->mensajeTipo = 'warning';
            return;
        }

        if ($this->firma->estaExpirado()) {
            $this->firma->estado = FirmaDoc::ESTADO_EXPIRADO;
            $this->firma->save();
            $this->mensaje     = 'Este enlace ha caducado. Contacta con la empresa para recibir uno nuevo.';
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
            $this->mensaje     = 'Por favor indica tu nombre y el motivo del rechazo.';
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
        $this->mensaje     = 'Has rechazado el documento. Hemos notificado a la empresa.';
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

            $mail->title = 'Documento rechazado: ' . $this->firma->codigo_doc;
            $mail->addMainBlock(new TitleBlock('Documento rechazado por el cliente', 'h2'));
            $mail->addMainBlock(new TextBlock(
                'El cliente ha rechazado firmar el documento <strong>'
                . ucfirst($this->firma->tipo_doc) . ' ' . $this->firma->codigo_doc . '</strong>.'
            ));
            $filas = [
                ['Documento', ucfirst($this->firma->tipo_doc) . ' ' . $this->firma->codigo_doc],
                ['Motivo',    nl2br(htmlspecialchars($motivo))],
                ['Fecha',     date('d/m/Y H:i')],
                ['IP',        $this->firma->ip_cliente ?? '—'],
            ];
            $mail->addMainBlock(new TableBlock(['Campo', 'Detalle'], $filas));
            $mail->send();
        } catch (\Exception $e) {
            Tools::log()->error('FirmaDoc: Error notificando rechazo - ' . $e->getMessage());
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
            $this->mensaje     = 'Por favor, introduce tu firma para continuar.';
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
                $this->mensaje     = '¡Has firmado correctamente! ('
                    . $this->firmantesHanFirmado . ' de ' . $this->firmantesTotal
                    . ' firmantes). El proceso continuará con el siguiente firmante.';
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
                ? '¡Documento firmado completamente! (' . $this->firmantesTotal . ' de ' . $this->firmantesTotal . ' firmantes). ¡Gracias a todos!'
                : '¡Documento firmado correctamente! Gracias.';
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
                Tools::log()->error('FirmaDoc: Error enviando confirmación - ' . $e->getMessage());
            }
        } else {
            $this->mensaje     = 'Error al guardar la firma. Inténtalo de nuevo.';
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
                Tools::log()->warning('FirmaDoc: No hay configuración de email para notificar a la empresa.');
                return;
            }

            // Destinatario principal
            $mail->addAddress($emailDestino);

            // Email adicional si está configurado
            if (!empty($this->config->email_adicional)) {
                $mail->addAddress($this->config->email_adicional);
            }

            // Asunto
            $mail->title = 'Documento firmado: ' . $this->firma->codigo_doc;

            // Cuerpo con bloques nativos de FacturaScripts
            $mail->addMainBlock(new TitleBlock(
                'Nuevo documento firmado',
                'h2'
            ));

            $mail->addMainBlock(new TextBlock(
                'El cliente ha firmado el documento ' . ucfirst($this->firma->tipo_doc)
                . ' <strong>' . $this->firma->codigo_doc . '</strong>.'
            ));

            // Tabla con los datos de la firma
            $filas = [
                ['Firmante',   $this->firma->firma_nombre ?? '—'],
                ['NIF',        $this->firma->firma_nif    ?: '—'],
                ['Cargo',      $this->firma->firma_cargo  ?: '—'],
                ['Fecha firma', $this->firma->fecha_firma ?? '—'],
                ['IP cliente', $this->firma->ip_cliente   ?? '—'],
                ['Documento',  ucfirst($this->firma->tipo_doc) . ' ' . $this->firma->codigo_doc],
            ];

            $mail->addMainBlock(new TableBlock(
                ['Campo', 'Valor'],
                $filas
            ));

            $mail->send();

        } catch (\Exception $e) {
            Tools::log()->error('FirmaDoc: Error al enviar notificación a empresa: ' . $e->getMessage());
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
