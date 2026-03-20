<?php
/**
 * This file is part of FirmaDoc plugin for FacturaScripts.
 *
 * @author    Francisco José Matías Olivares <fmatias@creativoz.com>
 * @copyright 2025-2026 Francisco José Matías Olivares
 * @license   Acuerdo de Licencia de Usuario Final (EULA) — véase archivo LICENSE
 * @version   1.1
 * @link      https://creativoz.com
 */
namespace FacturaScripts\Plugins\FirmaDoc\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Model\User;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocConfig;

class FirmaDocSend extends Controller
{
    public $config;
    public $tipodoc;
    public $iddoc;
    public $documento;
    public $firma;
    public $linkFirma = '';
    public $linkWhatsApp = '';
    public $linkDocumento = '';
    public $mensajeWhatsApp = '';
    public $mensaje = '';
    public $mensajeTipo = '';
    public $emailCliente = '';
    public $telefonoCliente = '';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'ventas';
        $data['title'] = Tools::lang()->trans('firmadoc-send-title');
        $data['icon'] = 'fas fa-signature';
        $data['showonmenu'] = false;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->config  = FirmaDocConfig::getConfig();
        $this->tipodoc = $this->request->get('tipo', '');
        $this->iddoc   = (int) $this->request->get('id', 0);

        if (empty($this->tipodoc) || empty($this->iddoc)) {
            $this->redirect('Dashboard');
            return;
        }

        $this->documento = $this->cargarDocumento($this->tipodoc, $this->iddoc);
        $this->firma     = $this->getFirmaExistente() ?? new FirmaDoc();

        // Si la firma fue anulada por modificación del documento, avisar y resetear
        if ($this->firma->id && $this->firma->estado === FirmaDoc::ESTADO_ANULADO_MOD) {
            $this->mensaje     = Tools::lang()->trans('firmadoc-send-signature-voided');
            $this->mensajeTipo = 'warning';
            $this->firma       = new FirmaDoc(); // resetear para mostrar formulario de nuevo envío
        }

        // Pre-rellenar email y teléfono del cliente
        $datosContacto         = $this->getDatosContactoCliente();
        $this->emailCliente    = $this->firma->email_cliente ?: $datosContacto['email'];
        $this->telefonoCliente = $this->firma->telefono_cliente ?: $datosContacto['telefono'];

        $action = $this->request->request->get('action', '');
        if ($action === 'generar' && $this->documento) {
            $this->actionGenerar();
            // Recargar firma para que el token esté disponible al calcular links
            if ($this->firma->id) {
                $firmaRecargada = new FirmaDoc();
                if ($firmaRecargada->loadFromCode($this->firma->id)) {
                    $this->firma = $firmaRecargada;
                }
            }
        } elseif ($action === 'cancelar') {
            $this->actionCancelar();
        } elseif ($action === 'enviar_email' && !empty($this->firma->token)) {
            $this->actionEnviarEmail();
            return;
        }

        // Calcular URL base con PHP nativo
        $scheme  = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $subdir  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        $baseUrl = $scheme . '://' . $host . $subdir;

        // Cargar nombre de empresa
        $empresa = new \FacturaScripts\Core\Model\Empresa();
        $nombreEmpresa = '';
        if ($empresa->loadFromCode(1)) {
            $nombreEmpresa = $empresa->nombre;
        }

        // Calcular links
        if (!empty($this->firma->token)) {
            $this->linkFirma     = $baseUrl . '/FirmaDocPublic?token=' . $this->firma->token;
            $this->linkDocumento = $baseUrl . '/' . ($this->documento->url() ?? '');

            $datos = [
                'cliente'          => $this->documento->nombrecliente ?? '',
                'empresa'          => $nombreEmpresa,
                'tipo_doc'         => ucfirst($this->tipodoc),
                'codigo_doc'       => $this->firma->codigo_doc ?? '',
                'link_firma'       => $this->linkFirma,
                'fecha_expiracion' => $this->firma->fecha_expiracion ?? '',
                'importe'          => ($this->documento->total ?? '') . ' ' . ($this->documento->coddivisa ?? ''),
            ];

            if (!empty($this->firma->telefono_cliente)) {
                $texto = $this->config->reemplazarVariables($this->config->whatsapp_mensaje ?? '', $datos);
                // Limpiar saltos de línea extra
                $texto = preg_replace('/\r\n|\r/', "\n", $texto);
                $texto = preg_replace('/\n{3,}/', "\n\n", $texto);
                $this->mensajeWhatsApp = $texto;
                $telefono = preg_replace('/[^0-9+]/', '', $this->firma->telefono_cliente);
                // Encodear preservando emojis y caracteres UTF-8 (solo encodear lo necesario para URL)
                $textoCodificado = implode('%0A', array_map(function($linea) {
                    return preg_replace_callback('/[^\p{L}\p{N}\p{P}\p{S}\p{Zs}]/u', function($m) {
                        return rawurlencode($m[0]);
                    }, $linea);
                }, explode("\n", $texto)));
                $this->linkWhatsApp = 'https://wa.me/' . ltrim($telefono, '+') . '?text=' . $textoCodificado;
            }
        }

        $this->setTemplate('FirmaDocSend');
    }

    private function actionGenerar(): void
    {
        $diasValidez = $this->config->dias_validez ?? 15;

        // Email y teléfono: formulario > documento > cliente
        $emailCliente    = $this->request->request->get('email_cliente', '');
        $telefonoCliente = $this->request->request->get('telefono_cliente', '');

        if (empty($emailCliente) || empty($telefonoCliente)) {
            $datosContacto   = $this->getDatosContactoCliente();
            $emailCliente    = $emailCliente ?: $datosContacto['email'];
            $telefonoCliente = $telefonoCliente ?: $datosContacto['telefono'];
        }

        $this->firma->tipo_doc         = $this->tipodoc;
        $this->firma->id_doc           = $this->iddoc;
        $this->firma->codigo_doc       = $this->documento->codigo ?? '';
        $this->firma->email_cliente    = $emailCliente;
        $this->firma->telefono_cliente = $telefonoCliente;
        $this->firma->fecha_envio      = date('d-m-Y H:i:s');
        $this->firma->fecha_expiracion = date('d-m-Y H:i:s', strtotime('+' . $diasValidez . ' days'));
        $this->firma->estado           = FirmaDoc::ESTADO_PENDIENTE;
        $this->firma->doc_hash         = FirmaDoc::calcularHashDoc($this->documento);
        $this->firma->generarToken();

        if ($this->firma->save()) {
            $this->mensaje     = Tools::lang()->trans('firmadoc-send-link-generated');
            $this->mensajeTipo = 'success';
        } else {
            $this->mensaje     = Tools::lang()->trans('firmadoc-send-link-error');
            $this->mensajeTipo = 'danger';
        }
    }

    private function getDatosContactoCliente(): array
    {
        $email    = $this->documento->email ?? '';
        $telefono = $this->documento->telefono1 ?? '';

        // Intentar cargar desde la ficha del cliente
        $codcliente = $this->documento->codcliente ?? '';
        if (!empty($codcliente)) {
            $cliente = new \FacturaScripts\Core\Model\Cliente();
            if ($cliente->loadFromCode($codcliente)) {
                $email    = $email ?: $cliente->email;
                $telefono = $telefono ?: $cliente->telefono1;
            }
        }

        return ['email' => $email, 'telefono' => $telefono];
    }

    private function actionCancelar(): void
    {
        if ($this->firma && $this->firma->id) {
            $this->firma->estado = FirmaDoc::ESTADO_CANCELADO;
            $this->firma->save();
            $this->mensaje     = Tools::lang()->trans('firmadoc-send-cancelled');
            $this->mensajeTipo = 'warning';
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
        if (!isset($clases[$tipo])) {
            return null;
        }
        $modelo = new $clases[$tipo]();
        return $modelo->loadFromCode($id) ? $modelo : null;
    }

    private function getFirmaExistente(): ?FirmaDoc
    {
        $model = new FirmaDoc();
        $lista = $model->all([
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('tipo_doc', $this->tipodoc),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('id_doc', $this->iddoc),
        ], ['fecha_envio' => 'DESC'], 0, 1);
        return empty($lista) ? null : $lista[0];
    }

    private function actionEnviarEmail(): void
    {
        if (empty($this->documento)) {
            $this->mensaje     = Tools::lang()->trans('firmadoc-send-doc-not-loaded');
            $this->mensajeTipo = 'danger';
            $this->setTemplate('FirmaDocSend');
            return;
        }

        // Calcular URL base y link de firma
        $scheme    = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $subdir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        $baseUrl   = $scheme . '://' . $host . $subdir;
        $linkFirma = $baseUrl . '/FirmaDocPublic?token=' . $this->firma->token;

        // Cargar empresa
        $empresa = new \FacturaScripts\Core\Model\Empresa();
        $nombreEmpresa = $empresa->loadFromCode(1) ? $empresa->nombre : '';

        // Preparar variables para la plantilla
        $datos = [
            'cliente'          => $this->documento->nombrecliente ?? '',
            'empresa'          => $nombreEmpresa,
            'tipo_doc'         => ucfirst($this->tipodoc),
            'codigo_doc'       => $this->firma->codigo_doc ?? '',
            'link_firma'       => $linkFirma,
            'fecha_expiracion' => $this->firma->fecha_expiracion ?? '',
            'importe'          => ($this->documento->total ?? '') . ' ' . ($this->documento->coddivisa ?? ''),
        ];

        // Si hay plantilla propia en config, inyectarla en EmailNotification
        // SendMail busca 'sendmail-FacturaCliente', 'sendmail-PresupuestoCliente', etc.
        $asunto = $this->config->reemplazarVariables($this->config->email_asunto ?? '', $datos);
        $cuerpo = $this->config->reemplazarVariables($this->config->email_cuerpo ?? '', $datos);

        if (!empty($asunto) && !empty($cuerpo)) {
            $notifName = 'sendmail-' . $this->documento->modelClassName();
            $notif = new \FacturaScripts\Core\Model\EmailNotification();
            $where = [\FacturaScripts\Core\Where::eq('name', $notifName)];
            if (!$notif->loadWhere($where)) {
                $notif->name    = $notifName;
                $notif->enabled = true;
            }
            $notif->subject = $asunto;
            $notif->body    = $cuerpo;
            $notif->save();
        }

        // Generar PDF y redirigir a SendMail nativo
        $exportManager = new \FacturaScripts\Core\Lib\ExportManager();
        $exportManager->newDoc('MAIL', $this->documento->codigo ?? '', 0, '');
        $exportManager->addBusinessDocPage($this->documento);
        $exportManager->show($this->response);
    }
}
