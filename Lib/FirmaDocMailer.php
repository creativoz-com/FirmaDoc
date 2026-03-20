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
namespace FacturaScripts\Plugins\FirmaDoc\Lib;

use FacturaScripts\Core\Lib\Email\NewMail;
use FacturaScripts\Core\Lib\Email\TextBlock;
use FacturaScripts\Core\Lib\Email\TitleBlock;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocConfig;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocFirmante;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocReenvio;

/**
 * Gestión centralizada de emails de FirmaDoc.
 *
 * IMPORTANTE: No modifica nunca EmailNotification('sendmail-*') para no interferir
 * con la plantilla nativa de FacturaScripts usada al enviar documentos sin firma.
 */
class FirmaDocMailer
{
    /**
     * Envío automático al generar la solicitud.
     * - Paralelo/único: envía a todos los firmantes pendientes
     * - Secuencial: solo al primero (los demás están en "esperando")
     *
     * @return string[] Emails a los que se envió (para mostrar al usuario)
     */
    public static function enviarAlGenerar(FirmaDoc $firma, $mainModel, array $firmantes, string $modoMulti): array
    {
        $enviados = [];

        try {
            $config        = FirmaDocConfig::getConfig();
            $baseUrl       = self::getBaseUrl();
            $nombreEmpresa = self::getNombreEmpresaPublic();

            foreach ($firmantes as $firmante) {
                if ($modoMulti === FirmaDoc::MODO_SECUENCIAL && $firmante->estado === FirmaDocFirmante::ESTADO_ESPERANDO) {
                    continue;
                }

                $datos = self::getDatos($firma, $mainModel, $firmante, $baseUrl, $nombreEmpresa);
                if (self::enviarConPlantilla($firmante->email, $datos, $config)) {
                    $enviados[] = $firmante->email;
                    FirmaDocReenvio::registrar(
                        $firma->id,
                        $firmante->email,
                        FirmaDocReenvio::TIPO_INICIAL,
                        FirmaDocReenvio::CANAL_EMAIL,
                        $firmante->id,
                        $firmante->nombre
                    );
                }
            }

        } catch (\Exception $e) {
            Tools::log()->error(Tools::lang()->trans('firmadoc-error-sending-emails', ['%error%' => $e->getMessage()]));
        }

        return $enviados;
    }

    /**
     * Reenvía el email de firma a un firmante (botón manual "Reenviar email").
     */
    public static function reenviarEmail(FirmaDoc $firma, $mainModel, ?FirmaDocFirmante $firmante = null, ?string $nick = null): bool
    {
        try {
            $config        = FirmaDocConfig::getConfig();
            $baseUrl       = self::getBaseUrl();
            $nombreEmpresa = self::getNombreEmpresaPublic();

            $emailDest  = $firmante ? $firmante->email : $firma->email_cliente;
            $datos      = self::getDatos($firma, $mainModel, $firmante, $baseUrl, $nombreEmpresa);

            if (!self::enviarConPlantilla($emailDest, $datos, $config)) {
                return false;
            }

            FirmaDocReenvio::registrar(
                $firma->id,
                $emailDest,
                FirmaDocReenvio::TIPO_REENVIO,
                FirmaDocReenvio::CANAL_EMAIL,
                $firmante ? $firmante->id : null,
                $firmante ? $firmante->nombre : ($mainModel->nombrecliente ?? null),
                $nick
            );

            Tools::log()->notice(Tools::lang()->trans('firmadoc-email-resent-to', ['%email%' => $emailDest]));
            return true;

        } catch (\Exception $e) {
            Tools::log()->error(Tools::lang()->trans('firmadoc-error-resending-email', ['%error%' => $e->getMessage()]));
            return false;
        }
    }

    /**
     * Envía el email al siguiente firmante en modo secuencial.
     */
    public static function enviarSiguiente(FirmaDocFirmante $firmante, FirmaDoc $firma): void
    {
        try {
            $mail = new NewMail();
            if (!$mail->canSendMail()) {
                return;
            }

            $baseUrl = self::getBaseUrl();
            $link    = $baseUrl . '/FirmaDocPublic?token=' . $firmante->token;

            $mail->addAddress($firmante->email);
            $mail->title = Tools::lang()->trans('firmadoc-email-sign-request', ['%code%' => $firma->codigo_doc]);
            $mail->addMainBlock(new TitleBlock(Tools::lang()->trans('firmadoc-email-your-turn'), 'h2'));
            $mail->addMainBlock(new TextBlock(
                Tools::lang()->trans('firmadoc-email-doc-ready', [
                    '%type%' => ucfirst($firma->tipo_doc),
                    '%code%' => $firma->codigo_doc
                ])
            ));
            if ($firma->fecha_expiracion) {
                $mail->addMainBlock(new TextBlock(
                    Tools::lang()->trans('firmadoc-email-valid-until', ['%date%' => $firma->fecha_expiracion])
                ));
            }
            $mail->addMainBlock(new TextBlock(
                '<a href="' . $link . '" style="background:#007bff;color:#fff;padding:10px 24px;'
                . 'border-radius:4px;text-decoration:none;display:inline-block;margin-top:12px;font-weight:600;">'
                . Tools::lang()->trans('firmadoc-email-sign-now') . '</a>'
            ));
            $mail->send();

            FirmaDocReenvio::registrar(
                $firma->id,
                $firmante->email,
                FirmaDocReenvio::TIPO_SECUENCIAL,
                FirmaDocReenvio::CANAL_EMAIL,
                $firmante->id,
                $firmante->nombre
            );

            Tools::log()->info(Tools::lang()->trans('firmadoc-email-next-signer', ['%email%' => $firmante->email]));

        } catch (\Exception $e) {
            Tools::log()->error(Tools::lang()->trans('firmadoc-error-next-signer', ['%error%' => $e->getMessage()]));
        }
    }

    /**
     * Elimina los registros EmailNotification que FirmaDoc pudo haber creado en versiones anteriores.
     * Usa el modelo de FS en lugar de SQL directo para compatibilidad MySQL/PostgreSQL.
     */
    public static function limpiarPlantillasContaminadas(): void
    {
        try {
            $notif = new \FacturaScripts\Core\Model\EmailNotification();
            $todos = $notif->all([], [], 0, 100);
            foreach ($todos as $n) {
                if (strpos($n->name, 'firmadoc-') === 0) {
                    $n->delete();
                    continue;
                }
                if (strpos($n->name, 'sendmail-') === 0
                    && (strpos($n->body ?? '', 'FirmaDocPublic') !== false
                        || strpos($n->body ?? '', 'link_firma') !== false)) {
                    $n->delete();
                }
            }
        } catch (\Exception $e) {
            Tools::log()->warning(Tools::lang()->trans('firmadoc-could-not-clean-templates', ['%error%' => $e->getMessage()]));
        }
    }

    /**
     * Envía email de confirmación a todos los firmantes y a la empresa
     * cuando todos han firmado. También funciona con un solo firmante.
     */
    public static function enviarConfirmacionTodos(FirmaDoc $firma, $mainModel): void
    {
        try {
            $config        = FirmaDocConfig::getConfig();
            $baseUrl       = self::getBaseUrl();
            $nombreEmpresa = self::getNombreEmpresaPublic();

            $linkDoc = $baseUrl . '/FirmaDocPublic?token=' . $firma->token . '&action=ver_pdf';

            $firmantes = FirmaDocFirmante::porSolicitud($firma->id);
            if (empty($firmantes)) {
                $f = new FirmaDocFirmante();
                $f->nombre = $firma->email_cliente;
                $f->email  = $firma->email_cliente;
                $firmantes = [$f];
            }

            $tplAsunto = !empty($config->confirm_asunto)
                ? $config->confirm_asunto
                : Tools::lang()->trans('firmadoc-email-default-confirm-subject');
            $tplCuerpo = !empty($config->confirm_cuerpo)
                ? $config->confirm_cuerpo
                : $config->getConfirmPorDefecto();

            foreach ($firmantes as $firmante) {
                $datosFirmante = [
                    'cliente'          => $firmante->nombre ?: ($mainModel->nombrecliente ?? ''),
                    'empresa'          => $nombreEmpresa,
                    'tipo_doc'         => ucfirst($firma->tipo_doc),
                    'codigo_doc'       => $firma->codigo_doc ?? '',
                    'link_firma'       => $baseUrl . '/FirmaDocPublic?token=' . ($firmante->token ?: $firma->token),
                    'link_documento'   => $linkDoc,
                    'fecha_expiracion' => $firma->fecha_expiracion ?? '',
                    'importe'          => ($mainModel->total ?? '') . ' ' . ($mainModel->coddivisa ?? ''),
                ];
                $emailAsunto = $config->reemplazarVariables($tplAsunto, $datosFirmante);
                $emailCuerpo = $config->reemplazarVariables($tplCuerpo, $datosFirmante);

                $mailFirmante = new NewMail();
                if (!$mailFirmante->canSendMail()) break;
                $mailFirmante->addAddress($firmante->email);
                $mailFirmante->title = $emailAsunto;
                $mailFirmante->addMainBlock(new TextBlock($emailCuerpo));
                $mailFirmante->send();

                FirmaDocReenvio::registrar(
                    $firma->id, $firmante->email,
                    'confirmacion', FirmaDocReenvio::CANAL_EMAIL,
                    $firmante->id ?? null, $firmante->nombre ?? null
                );
            }

            $emailEmpresa = Tools::settings('email', 'email', '');
            if (!empty($emailEmpresa)) {
                $datosEmpresa = [
                    'cliente'          => $mainModel->nombrecliente ?? '',
                    'empresa'          => $nombreEmpresa,
                    'tipo_doc'         => ucfirst($firma->tipo_doc),
                    'codigo_doc'       => $firma->codigo_doc ?? '',
                    'link_firma'       => $baseUrl . '/FirmaDocPublic?token=' . $firma->token,
                    'link_documento'   => $linkDoc,
                    'fecha_expiracion' => $firma->fecha_expiracion ?? '',
                    'importe'          => ($mainModel->total ?? '') . ' ' . ($mainModel->coddivisa ?? ''),
                ];
                $asuntoEmpresa = $config->reemplazarVariables($tplAsunto, $datosEmpresa);
                $cuerpoEmpresa = $config->reemplazarVariables($tplCuerpo, $datosEmpresa);

                $mailEmpresa = new NewMail();
                if ($mailEmpresa->canSendMail()) {
                    $mailEmpresa->addAddress($emailEmpresa);
                    $mailEmpresa->title = Tools::lang()->trans('firmadoc-email-company-copy', ['%subject%' => $asuntoEmpresa]);
                    $mailEmpresa->addMainBlock(new TextBlock($cuerpoEmpresa));
                    $mailEmpresa->send();
                }
            }

            Tools::log()->notice(Tools::lang()->trans('firmadoc-email-confirmation-sent'));

        } catch (\Exception $e) {
            Tools::log()->error(Tools::lang()->trans('firmadoc-error-confirmation-all', ['%error%' => $e->getMessage()]));
        }
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private static function enviarConPlantilla(string $emailDest, array $datos, FirmaDocConfig $config): bool
    {
        $mail = new NewMail();
        if (!$mail->canSendMail()) {
            Tools::log()->warning(Tools::lang()->trans('firmadoc-no-email-setup'));
            return false;
        }

        $asunto = $config->reemplazarVariables($config->email_asunto ?? '', $datos);
        $cuerpo = $config->reemplazarVariables($config->email_cuerpo ?? '', $datos);

        $mail->addAddress($emailDest);
        $mail->title = $asunto ?: (Tools::lang()->trans('firmadoc-email-pending-sign', ['%code%' => ($datos['codigo_doc'] ?? '')]));
        $mail->addMainBlock(new TextBlock($cuerpo));
        $mail->send();

        return true;
    }

    private static function getBaseUrl(): string
    {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? Tools::config('webserver_host', 'localhost');
        $subdir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        return rtrim($scheme . '://' . $host . $subdir, '/');
    }

    public static function getNombreEmpresaPublic(): string
    {
        $empresa = new \FacturaScripts\Core\Model\Empresa();
        return $empresa->loadFromCode(1) ? $empresa->nombre : '';
    }

    private static function getDatos(FirmaDoc $firma, $mainModel, $firmante, string $baseUrl, string $nombreEmpresa): array
    {
        $token     = $firmante ? $firmante->token : $firma->token;
        $nombreDest = $firmante
            ? ($firmante->nombre ?: ($mainModel->nombrecliente ?? ''))
            : ($mainModel->nombrecliente ?? '');

        return [
            'cliente'          => $nombreDest,
            'empresa'          => $nombreEmpresa,
            'tipo_doc'         => ucfirst($firma->tipo_doc),
            'codigo_doc'       => $firma->codigo_doc ?? '',
            'link_firma'       => $baseUrl . '/FirmaDocPublic?token=' . $token,
            'fecha_expiracion' => $firma->fecha_expiracion ?? '',
            'importe'          => ($mainModel->total ?? '') . ' ' . ($mainModel->coddivisa ?? ''),
        ];
    }
}
