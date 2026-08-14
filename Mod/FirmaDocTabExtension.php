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
namespace FacturaScripts\Plugins\FirmaDoc\Mod;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocConfig;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocFirmante;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocMailer;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocUrl;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocReenvio;

/**
 * Extensión para controladores de documento de venta.
 * Añade pestaña "Firmas" directamente en la ficha del documento.
 */
class FirmaDocTabExtension
{
    public function createViews()
    {
        return function () {
            // Init registra la extensión siempre (no puede consultar la BD en cada
            // petición del ERP); es aquí donde se decide si este tipo de documento
            // tiene FirmaDoc activado en la configuración.
            $tipo = FirmaDoc::getTipoDesdeControlador($this->getPageData()['name'] ?? '');
            if (!$tipo || !FirmaDocConfig::estaActivo($tipo)) {
                return;
            }

            $this->addHtmlView(
                'FirmaDocTab',          // nombre de la vista (id único)
                'FirmaDocTab',          // archivo Twig: View/FirmaDocTab.html.twig
                'FirmaDoc',             // modelo base (namespace Dinamic se añade automáticamente)
                \FacturaScripts\Core\Tools::lang()->trans('firmadoc-tab-signatures'),              // título de la pestaña
                'fas fa-signature'      // icono
            );
        };
    }

    public function loadData()
    {
        return function (string $viewName, $view) {
            if ($viewName !== 'FirmaDocTab') {
                return;
            }

            // Obtener el documento principal
            $mainModel = $this->getModel();
            $tipo = FirmaDoc::getTipoDesdeClase($mainModel->modelClassName());
            if (!$tipo) {
                return;
            }

            $idDoc = $mainModel->primaryColumnValue();

            // Cargar historial de firmas del documento
            $firmaModel = new FirmaDoc();
            $view->cursor = $firmaModel->all(
                [
                    new DataBaseWhere('tipo_doc', $tipo),
                    new DataBaseWhere('id_doc', $idDoc),
                ],
                ['fecha_envio' => 'DESC']
            );
            $view->count = count($view->cursor);

            // Cargar firmantes individuales para cada solicitud (multi-firmante)
            foreach ($view->cursor as $firma) {
                $firma->firmantes = FirmaDocFirmante::porSolicitud($firma->id);
            }

            // Datos adicionales para la vista
            $view->model->tipo_doc = $tipo;
            $view->model->id_doc   = $idDoc;

            // Configuración del plugin
            $config = FirmaDocConfig::getConfig();

            $baseUrl = FirmaDocUrl::base();

            // Pre-calcular links WhatsApp para firmas pendientes
            $nombreEmpresa = FirmaDocMailer::getNombreEmpresaPublic($mainModel);

            $whatsappLinks = [];
            foreach ($view->cursor as $f) {
                if ($f->estado !== FirmaDoc::ESTADO_PENDIENTE) {
                    continue;
                }

                // Función helper para generar el link de WA
                $buildWaLink = function(string $nombre, string $telefono, string $token, $firma) use ($config, $baseUrl, $mainModel, $nombreEmpresa) {
                    if (empty($telefono)) return null;
                    $datos = [
                        'cliente'          => $nombre ?: ($mainModel->nombrecliente ?? ''),
                        'empresa'          => $nombreEmpresa,
                        'tipo_doc'         => ucfirst($firma->tipo_doc),
                        'codigo_doc'       => $firma->codigo_doc ?? '',
                        'link_firma'       => $baseUrl . '/FirmaDocPublic?token=' . $token,
                        'fecha_expiracion' => $firma->fecha_expiracion ?? '',
                        'importe'          => ($mainModel->total ?? '') . ' ' . ($mainModel->coddivisa ?? ''),
                    ];
                    $texto = $config->reemplazarVariables($config->whatsapp_mensaje ?? '', $datos);
                    $texto = preg_replace('/
|
/', "
", $texto);
                    $texto = preg_replace('/
{3,}/', "

", $texto);
                    $tel = preg_replace('/[^0-9+]/', '', $telefono);
                    $codificado = implode('%0A', array_map(function($linea) {
                        return preg_replace_callback('/[^\p{L}\p{N}\p{P}\p{S}\p{Zs}]/u', function($m) {
                            return rawurlencode($m[0]);
                        }, $linea);
                    }, explode("
", $texto)));
                    return 'https://wa.me/' . ltrim($tel, '+') . '?text=' . $codificado;
                };

                if (!empty($f->firmantes) && $f->modo_multifirma !== FirmaDoc::MODO_UNICO) {
                    // Multi-firmante: link por firmante individual (token propio)
                    foreach ($f->firmantes as $firmante) {
                        if ($firmante->estado === FirmaDocFirmante::ESTADO_PENDIENTE && !empty($firmante->telefono)) {
                            $link = $buildWaLink($firmante->nombre, $firmante->telefono, $firmante->token, $f);
                            if ($link) $whatsappLinks['firmante_' . $firmante->id] = $link;
                        }
                    }
                } else {
                    // Modo único: token maestro, usa telefono del firmante o del cliente
                    $telefono = $f->telefono_cliente;
                    if (empty($telefono) && !empty($f->firmantes)) {
                        $telefono = $f->firmantes[0]->telefono ?? '';
                    }
                    if (!empty($telefono)) {
                        $link = $buildWaLink($mainModel->nombrecliente ?? '', $telefono, $f->token, $f);
                        if ($link) $whatsappLinks[$f->id] = $link;
                    }
                }
            }

            // Cargar nombre, email y teléfono del cliente para prellenar formulario
            $nombrePrellenado   = $mainModel->nombrecliente ?? '';
            $emailPrellenado    = $mainModel->email ?? '';
            $telefonoPrellenado = $mainModel->telefono1 ?? '';
            $codcliente = $mainModel->codcliente ?? '';
            if ($codcliente && (empty($emailPrellenado) || empty($telefonoPrellenado))) {
                $clienteModel = new \FacturaScripts\Core\Model\Cliente();
                if ($clienteModel->loadFromCode($codcliente)) {
                    $emailPrellenado    = $emailPrellenado ?: ($clienteModel->email ?? '');
                    $telefonoPrellenado = $telefonoPrellenado ?: ($clienteModel->telefono1 ?? '');
                }
            }

            // Pasar datos extra como propiedades dinámicas de la vista
            $view->firmadocConfig       = $config;
            $view->firmadocBaseUrl      = $baseUrl;
            $view->firmadocTipo         = $tipo;
            $view->firmadocIdDoc        = $idDoc;
            $view->firmadocDocCodigo    = $mainModel->codigo ?? '';
            $view->firmadocWaLinks      = $whatsappLinks;
            $view->firmadocNombre       = $nombrePrellenado;
            $view->firmadocEmail        = $emailPrellenado;
            $view->firmadocTelefono     = $telefonoPrellenado;
            $view->firmadocEditable     = property_exists($mainModel, 'editable') ? (bool)$mainModel->editable : true;

            // Historial de reenvíos para la firma activa
            $firmaActual = FirmaDoc::getActivaPorDocumento($tipo, $mainModel->primaryColumnValue());
            $view->firmadocReenvios = $firmaActual ? FirmaDocReenvio::porSolicitud($firmaActual->id) : [];
        };
    }

    public function execPreviousAction()
    {
        return function (string $action) {
            if (!in_array($action, ['firmadoc-generar', 'firmadoc-cancelar', 'firmadoc-email', 'firmadoc-email-firmante'])) {
                return;
            }

            // getModel() carga el documento desde request 'code' aunque sea execPreviousAction
            $mainModel = $this->getModel();
            if (!$mainModel->id()) {
                return; // documento no encontrado
            }
            $tipo = FirmaDoc::getTipoDesdeClase($mainModel->modelClassName());
            if (!$tipo) {
                return;
            }

            if ($action === 'firmadoc-generar') {
                $this->actionFirmadocGenerar($tipo, $mainModel);
            } elseif ($action === 'firmadoc-cancelar') {
                $this->actionFirmadocCancelar($tipo, $mainModel);
            } elseif ($action === 'firmadoc-email') {
                $this->actionFirmadocEmail($tipo, $mainModel);
            } elseif ($action === 'firmadoc-email-firmante') {
                $this->actionFirmadocEmailFirmante($tipo, $mainModel);
            }

            // PRG: redirigir por GET para evitar reenvío del POST con F5.
            // Se usa redirect() del framework y no header()+exit(): con exit() se salta
            // el cierre de index.php, que es donde se guardan los mensajes del log, así
            // que el usuario no llegaba a ver el «enlace generado y enviado a...».
            $code = $this->request->request->get('code', '');
            $activetab = $this->request->request->get('activetab', 'FirmaDocTab');
            $controller = $this->getPageData()['name'] ?? '';
            // El retardo de 3 s es el mismo que usa AdminPlugins del núcleo: da tiempo
            // a que se vea el mensaje antes de que el navegador recargue por GET.
            $this->redirect($controller . '?code=' . urlencode($code) . '&activetab=' . urlencode($activetab), 3);
        };
    }

    public function actionFirmadocEmail()
    {
        return function (string $tipo, $mainModel) {
            $idFirma = (int) $this->request->request->get('firmadoc_id', 0);
            if (!$idFirma) {
                return;
            }
            $firma = new FirmaDoc();
            if (!$firma->loadFromCode($idFirma)) {
                return;
            }

            $nick = $this->user->nick ?? null;
            FirmaDocMailer::reenviarEmail($firma, $mainModel, null, $nick);
        };
    }

    /**
     * Envía el email de solicitud de firma a un firmante concreto.
     * Usa la plantilla propia del plugin a través de FirmaDocMailer; no toca
     * ningún registro EmailNotification del núcleo.
     */
    public function actionFirmadocEmailFirmante()
    {
        return function (string $tipo, $mainModel) {
            $idFirmante = (int) $this->request->request->get('firmante_id', 0);
            $idFirma    = (int) $this->request->request->get('firmadoc_id', 0);
            if (!$idFirmante || !$idFirma) {
                return;
            }
            $firmante = new FirmaDocFirmante();
            if (!$firmante->loadFromCode($idFirmante)) {
                return;
            }
            $firma = new FirmaDoc();
            if (!$firma->loadFromCode($idFirma)) {
                return;
            }
            $nick = $this->user->nick ?? null;
            FirmaDocMailer::reenviarEmail($firma, $mainModel, $firmante, $nick);
        };
    }

    public function actionFirmadocGenerar()
    {
        return function (string $tipo, $mainModel) {
            $config  = FirmaDocConfig::getConfig();
            $request = $this->request;

            // Bloquear si el documento no es editable
            if (property_exists($mainModel, 'editable') && !$mainModel->editable) {
                \FacturaScripts\Core\Tools::log()->warning(\FacturaScripts\Core\Tools::lang()->trans('firmadoc-doc-not-editable'));
                return;
            }

            // No permitir nueva firma si ya existe una activa
            $firmaExistente = FirmaDoc::getActivaPorDocumento($tipo, $mainModel->primaryColumnValue());
            if ($firmaExistente && in_array($firmaExistente->estado, [FirmaDoc::ESTADO_FIRMADO, FirmaDoc::ESTADO_PENDIENTE])) {
                return;
            }

            // Modo multi-firmante
            $modoMulti = $request->request->get('modo_multifirma', FirmaDoc::MODO_UNICO);

            // Recoger lista de firmantes del formulario
            // all('firmantes') devuelve el array asociativo correctamente cuando el form usa firmantes[0][email]
            $allPost       = $request->request->all();
            $firmantesData = isset($allPost['firmantes']) && is_array($allPost['firmantes'])
                ? array_values($allPost['firmantes'])
                : [];

            if (empty($firmantesData)) {
                // Compatibilidad: campo único legacy
                $firmantesData = [[
                    'nombre'            => $request->request->get('firmadoc_nombre', ''),
                    'email'             => $request->request->get('firmadoc_email', ''),
                    'telefono'          => $request->request->get('firmadoc_telefono', ''),
                    'nombre_referencia' => '',
                ]];
            }

            // Filtrar firmantes vacíos
            $firmantesData = array_values(array_filter($firmantesData, function($f) {
                return !empty($f['email']);
            }));

            if (empty($firmantesData)) {
                // Fallback a email del cliente
                $email    = $mainModel->email ?? '';
                $telefono = $mainModel->telefono1 ?? '';
                $codcliente = $mainModel->codcliente ?? '';
                if ($codcliente) {
                    $cliente = new \FacturaScripts\Core\Model\Cliente();
                    if ($cliente->loadFromCode($codcliente)) {
                        $email    = $email ?: $cliente->email;
                        $telefono = $telefono ?: $cliente->telefono1;
                    }
                }
                $firmantesData = [['nombre' => $mainModel->nombrecliente ?? '', 'email' => $email, 'telefono' => $telefono, 'nombre_referencia' => '']];
            }

            // Si solo hay un firmante, forzar modo unico
            if (count($firmantesData) === 1) {
                $modoMulti = FirmaDoc::MODO_UNICO;
            }

            // Crear solicitud principal FirmaDoc
            $firma = new FirmaDoc();
            $firma->tipo_doc         = $tipo;
            $firma->id_doc           = $mainModel->primaryColumnValue();
            $firma->codigo_doc       = $mainModel->codigo ?? '';
            $firma->email_cliente    = $firmantesData[0]['email'];
            $firma->telefono_cliente = $firmantesData[0]['telefono'] ?? '';
            $firma->fecha_envio      = date('d-m-Y H:i:s');
            $firma->fecha_expiracion = date('d-m-Y H:i:s', strtotime('+' . $config->dias_validez . ' days'));
            $firma->estado           = FirmaDoc::ESTADO_PENDIENTE;
            $firma->doc_hash         = FirmaDoc::calcularHashDoc($mainModel);
            $firma->modo_multifirma  = $modoMulti;
            $firma->generarToken();

            if (!$firma->save()) {
                \FacturaScripts\Core\Tools::log()->error(\FacturaScripts\Core\Tools::lang()->trans('firmadoc-link-generate-error'));
                return;
            }

            // Crear registros de firmantes
            $firmantesGuardados = [];
            foreach ($firmantesData as $idx => $fd) {
                $firmante = new FirmaDocFirmante();
                $firmante->id_firmadoc       = $firma->id;
                $firmante->orden             = $idx + 1;
                $firmante->nombre            = $fd['nombre'] ?? '';
                $firmante->email             = $fd['email'];
                $firmante->telefono          = $fd['telefono'] ?? '';
                $firmante->nombre_referencia = $fd['nombre_referencia'] ?? '';
                $firmante->generarToken();

                // En modo secuencial: solo el primero queda "pendiente", el resto "esperando"
                // En modo paralelo/unico: todos pendientes desde el inicio
                $firmante->estado = ($modoMulti === FirmaDoc::MODO_SECUENCIAL && $idx > 0)
                    ? FirmaDocFirmante::ESTADO_ESPERANDO
                    : FirmaDocFirmante::ESTADO_PENDIENTE;

                $firmante->save();
                $firmantesGuardados[] = $firmante;
            }

            // Envío automático de emails tras generar la solicitud
            $emailsEnviados = FirmaDocMailer::enviarAlGenerar($firma, $mainModel, $firmantesGuardados, $modoMulti);

            // Notificación visible en la UI con los emails destinatarios
            $n = count($firmantesData);
            if (!empty($emailsEnviados)) {
                \FacturaScripts\Core\Tools::log()->notice(
                    \FacturaScripts\Core\Tools::lang()->trans('firmadoc-link-generated-sent', ['%mode%' => $modoMulti, '%emails%' => implode(', ', $emailsEnviados)])
                );
            } else {
                \FacturaScripts\Core\Tools::log()->notice(\FacturaScripts\Core\Tools::lang()->trans('firmadoc-link-generated-ok'));
                \FacturaScripts\Core\Tools::log()->warning(\FacturaScripts\Core\Tools::lang()->trans('firmadoc-email-send-failed'));
            }
        };
    }

    public function actionFirmadocCancelar()
    {
        return function (string $tipo, $mainModel) {
            $idFirma = (int) $this->request->request->get('firmadoc_id', 0);
            if (!$idFirma) {
                return;
            }

            $firma = new FirmaDoc();
            if ($firma->loadFromCode($idFirma) && $firma->tipo_doc === $tipo) {
                $firma->estado = FirmaDoc::ESTADO_CANCELADO;
                $firma->save();
                \FacturaScripts\Core\Tools::log()->notice(\FacturaScripts\Core\Tools::lang()->trans('firmadoc-link-cancelled-notice'));
            }
        };
    }

    /**
     * Intercepta la exportación PDF para añadir el certificado de firma si procede.
     * Se ejecuta tras exportAction() pero antes de enviar la respuesta al cliente.
     */
    public function execAfterAction()
    {
        return function (string $action) {
            if ($action !== 'export') {
                return;
            }

            $option = $this->request->queryOrInput('option', '');

            // ── Envío de EMAIL nativo ──────────────────────────────────────────
            // Si el documento ya está firmado, se sustituye el PDF adjunto por el
            // que incluye el certificado de firma. Nada más: la plantilla de correo
            // del núcleo ('sendmail-*') no se toca, ni para escribirla ni para
            // borrarla — es global y puede llevar texto del usuario. El enlace de
            // firma se manda con el botón del plugin, que usa su propia plantilla.
            if ($option === 'MAIL') {
                $controllerName = $this->getPageData()['name'] ?? '';
                $tipo = FirmaDoc::getTipoDesdeControlador($controllerName);
                $mainModel = $this->getModel();
                if ($tipo && $mainModel && $mainModel->id()) {
                    $firmaEmail = FirmaDoc::getActivaPorDocumento($tipo, $mainModel->primaryColumnValue());

                    if ($firmaEmail && $firmaEmail->estado === FirmaDoc::ESTADO_FIRMADO) {
                        try {
                            $tmpDir = FS_FOLDER . '/' . \FacturaScripts\Core\Lib\Email\NewMail::ATTACHMENTS_TMP_PATH;

                            // El núcleo nombra el adjunto '<nombre_documento>_mail_<time>_<random>.pdf'.
                            // Se acota al prefijo de ESTE documento y a los últimos 60 segundos:
                            // un glob de '*_mail_*.pdf' cogía el fichero más reciente de todo el
                            // directorio, que en una instalación con varios usuarios enviando a la
                            // vez puede ser el adjunto de otro.
                            $prefijo = str_replace([' ', '"', "'", '/', '\\', ','], '_', $mainModel->codigo ?? '');
                            $archivos = $prefijo === '' ? [] : glob($tmpDir . $prefijo . '_mail_*.pdf');
                            $archivos = array_filter($archivos, fn($f) => filemtime($f) >= time() - 60);

                            if (!empty($archivos)) {
                                usort($archivos, fn($a, $b) => filemtime($b) - filemtime($a));
                                $pdfExport = new \FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocPDFExport();
                                $pdfExport->newDoc($mainModel->codigo ?? '', 0, '');
                                $pdfExport->addBusinessDocPage($mainModel);
                                $pdfExport->addCertificadoFirma($firmaEmail, $mainModel);
                                $pdfBytes = $pdfExport->getDoc();
                                if (!empty($pdfBytes)) {
                                    file_put_contents(reset($archivos), $pdfBytes);
                                }
                            }
                        } catch (\Exception $e) {
                            \FacturaScripts\Core\Tools::log()->error(
                                \FacturaScripts\Core\Tools::lang()->trans('firmadoc-mail-pdf-error', ['%error%' => $e->getMessage()])
                            );
                        }
                    }
                }
            }

            // Solo continuar para PDF
            if ($option !== 'PDF') {
                return;
            }

            // Identificar el tipo y modelo del documento
            $controllerName = $this->getPageData()['name'] ?? '';
            $tipo = FirmaDoc::getTipoDesdeControlador($controllerName);
            if (!$tipo) {
                return;
            }

            $mainModel = $this->getModel();
            if (!$mainModel || !$mainModel->id()) {
                return;
            }

            // Comprobar si hay firma activa con al menos un firmante que haya firmado
            $firma = FirmaDoc::getActivaPorDocumento($tipo, $mainModel->primaryColumnValue());
            if (!$firma) {
                return;
            }

            // Incluir certificado si está firmado o si hay firmantes parciales en multi-firmante
            $tieneAlgunaFirma = $firma->estado === FirmaDoc::ESTADO_FIRMADO;
            if (!$tieneAlgunaFirma && $firma->estado === FirmaDoc::ESTADO_PENDIENTE) {
                $firmantes = FirmaDocFirmante::porSolicitud($firma->id);
                foreach ($firmantes as $f) {
                    if ($f->estado === FirmaDocFirmante::ESTADO_FIRMADO) {
                        $tieneAlgunaFirma = true;
                        break;
                    }
                }
            }
            if (!$tieneAlgunaFirma) {
                return;
            }

            // Regenerar el PDF con certificados (todos los firmantes que hayan firmado)
            $export = new \FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocPDFExport();
            $export->newDoc($mainModel->codigo ?? '', 0, '');
            $export->addBusinessDocPage($mainModel);
            $export->addCertificadoFirma($firma, $mainModel);

            $pdfBytes = $export->getDoc();
            $this->response->headers->set('Content-Type', 'application/pdf');
            $this->response->headers->set(
                'Content-Disposition',
                'inline; filename="' . ($mainModel->codigo ?? 'documento') . '.pdf"'
            );
            $this->response->setContent($pdfBytes);
        };
    }

}
