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
namespace FacturaScripts\Plugins\FirmaDoc;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\Email\NewMail;
use FacturaScripts\Core\Lib\Email\TextBlock;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocMailer;
use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocConfig;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocFirmante;

class Cron extends CronClass
{
    public function run(): void
    {
        $config = FirmaDocConfig::getConfig();

        // Marcar enlaces expirados — siempre, cada día a las 8h
        $this->job('firmadoc-expirar')
            ->everyDayAt(8)
            ->run(function () {
                $this->expirarEnlaces();
            });

        // Recordatorios — solo si el usuario los ha activado
        if ($config->recordatorios_activo) {
            $this->job('firmadoc-recordatorios')
                ->everyDayAt(9)
                ->run(function () use ($config) {
                    $this->procesarRecordatorios($config);
                });

            // Recordatorios para firmantes individuales (multi-firmante)
            $this->job('firmadoc-recordatorios-firmantes')
                ->everyDayAt(9, 30)
                ->run(function () use ($config) {
                    $this->procesarRecordatoriosFirmantes($config);
                });
        }
    }

    private function expirarEnlaces(): void
    {
        $firma = new FirmaDoc();
        $pendientes = $firma->all([
            new DataBaseWhere('estado', FirmaDoc::ESTADO_PENDIENTE),
        ], [], 0, 500);

        $count = 0;
        foreach ($pendientes as $f) {
            if ($f->estaExpirado()) {
                $f->estado = FirmaDoc::ESTADO_EXPIRADO;
                $f->save();
                $count++;
            }
        }

        if ($count > 0) {
            Tools::log()->info(Tools::lang()->trans('firmadoc-cron-expired-count', ['%count%' => $count]));
        }
    }

    private function procesarRecordatorios(FirmaDocConfig $config): void
    {
        $diasConfig = $config->getDiasRecordatorio();
        if (empty($diasConfig)) {
            return;
        }

        $firma = new FirmaDoc();
        $pendientes = $firma->all([
            new DataBaseWhere('estado', FirmaDoc::ESTADO_PENDIENTE),
        ], ['fecha_envio' => 'ASC'], 0, 500);

        $count = 0;
        foreach ($pendientes as $f) {
            if ($this->debeEnviarRecordatorio($f, $diasConfig)) {
                if ($this->enviarRecordatorio($f, $config)) {
                    $f->recordatorios_enviados    = ((int)$f->recordatorios_enviados) + 1;
                    $f->fecha_ultimo_recordatorio = date('Y-m-d');
                    $f->save();
                    $count++;
                }
            }
        }

        if ($count > 0) {
            Tools::log()->info(Tools::lang()->trans('firmadoc-cron-reminders-count', ['%count%' => $count]));
        }
    }

    /**
     * Determina si hay que enviar recordatorio hoy para esta firma.
     */
    private function debeEnviarRecordatorio(FirmaDoc $firma, array $diasConfig): bool
    {
        if (empty($firma->email_cliente) || empty($firma->fecha_expiracion)) {
            return false;
        }

        // No enviar más de uno por día
        if ($firma->fecha_ultimo_recordatorio === date('Y-m-d')) {
            return false;
        }

        $ahora      = new \DateTime();
        $expiracion = \DateTime::createFromFormat('d-m-Y H:i:s', $firma->fecha_expiracion);

        if (!$expiracion || $ahora >= $expiracion) {
            return false;
        }

        $diasRestantes = (int)$ahora->diff($expiracion)->days;

        return in_array($diasRestantes, $diasConfig, true);
    }

    private function enviarRecordatorio(FirmaDoc $firma, FirmaDocConfig $config): bool
    {
        try {
            $mail = new NewMail();
            if (!$mail->canSendMail()) {
                return false;
            }

            $expiracion    = \DateTime::createFromFormat('d-m-Y H:i:s', $firma->fecha_expiracion);
            $diasRestantes = $expiracion ? max(0, (int)(new \DateTime())->diff($expiracion)->days) : 0;

            $scheme        = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
            $host          = $_SERVER['HTTP_HOST'] ?? Tools::config('webserver_host', 'localhost');
            $linkFirma     = $scheme . '://' . $host . '/FirmaDocPublic?token=' . $firma->token;
            $nombreEmpresa = FirmaDocMailer::getNombreEmpresaPublic();

            $datos = [
                'cliente'          => $firma->email_cliente,
                'empresa'          => $nombreEmpresa,
                'tipo_doc'         => ucfirst($firma->tipo_doc),
                'codigo_doc'       => $firma->codigo_doc ?? '',
                'link_firma'       => $linkFirma,
                'link_documento'   => $linkFirma,
                'fecha_expiracion' => $firma->fecha_expiracion ?? '',
                'importe'          => '',
                'dias_restantes'   => (string)$diasRestantes,
            ];

            $asunto = $config->reemplazarVariables($config->email_asunto ?? '', $datos);
            $cuerpo = $config->reemplazarVariables($config->email_cuerpo ?? '', $datos);

            $asunto = Tools::lang()->trans('firmadoc-email-reminder-prefix', ['%subject%' => $asunto]);

            $mail->addAddress($firma->email_cliente);
            $mail->title = $asunto;
            $mail->addMainBlock(new TextBlock($cuerpo));
            $mail->addMainBlock(new TextBlock(
                '<p style="margin-top:12px;font-size:0.9em;color:#555;">'
                . Tools::lang()->trans('firmadoc-email-expires-in', ['%days%' => $diasRestantes, '%date%' => $firma->fecha_expiracion ?? ''])
                . '</p>'
                . '<a href="' . $linkFirma . '" style="background:#28a745;color:#fff;padding:10px 24px;'
                . 'border-radius:4px;text-decoration:none;display:inline-block;margin-top:8px;font-weight:600;">'
                . Tools::lang()->trans('firmadoc-email-sign-now-btn') . '</a>'
            ));
            return $mail->send();

        } catch (\Exception $e) {
            Tools::log()->error(Tools::lang()->trans('firmadoc-cron-reminder-error', ['%error%' => $e->getMessage()]));
            return false;
        }
    }

    private function procesarRecordatoriosFirmantes(FirmaDocConfig $config): void
    {
        $diasConfig = $config->getDiasRecordatorio();
        if (empty($diasConfig)) {
            return;
        }

        $db = new \FacturaScripts\Core\Base\DataBase();
        $sql = "SELECT ff.* FROM firmadoc_firmante ff
                INNER JOIN firmadoc f ON f.id = ff.id_firmadoc
                WHERE ff.estado = 'pendiente'
                AND f.estado = 'pendiente'
                AND f.fecha_expiracion IS NOT NULL";

        $count = 0;
        foreach ($db->select($sql) as $row) {
            $firmante = new FirmaDocFirmante();
            if (!$firmante->loadFromCode($row['id'])) {
                continue;
            }

            // No enviar más de uno por día
            if ($firmante->fecha_ultimo_recordatorio === date('Y-m-d')) {
                continue;
            }

            // Cargar solicitud padre para obtener fecha expiración
            $firma = new FirmaDoc();
            if (!$firma->loadFromCode($firmante->id_firmadoc)) {
                continue;
            }

            $ahora      = new \DateTime();
            $expiracion = \DateTime::createFromFormat('d-m-Y H:i:s', $firma->fecha_expiracion);
            if (!$expiracion || $ahora >= $expiracion) {
                continue;
            }

            $diasRestantes = (int)$ahora->diff($expiracion)->days;
            if (!in_array($diasRestantes, $diasConfig, true)) {
                continue;
            }

            if ($this->enviarRecordatorioFirmante($firmante, $firma, $config, $diasRestantes)) {
                $firmante->recordatorios_enviados    = ((int)$firmante->recordatorios_enviados) + 1;
                $firmante->fecha_ultimo_recordatorio = date('Y-m-d');
                $firmante->save();
                $count++;
            }
        }

        if ($count > 0) {
            Tools::log()->info(Tools::lang()->trans('firmadoc-cron-signer-reminders-count', ['%count%' => $count]));
        }
    }

    private function enviarRecordatorioFirmante(
        FirmaDocFirmante $firmante,
        FirmaDoc $firma,
        FirmaDocConfig $config,
        int $diasRestantes
    ): bool {
        try {
            $mail = new NewMail();
            if (!$mail->canSendMail()) {
                return false;
            }

            $scheme        = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
            $host          = $_SERVER['HTTP_HOST'] ?? Tools::config('webserver_host', 'localhost');
            $linkFirma     = $scheme . '://' . $host . '/FirmaDocPublic?token=' . $firmante->token;
            $nombreEmpresa = FirmaDocMailer::getNombreEmpresaPublic();

            $datos = [
                'cliente'          => $firmante->nombre ?: $firmante->email,
                'empresa'          => $nombreEmpresa,
                'tipo_doc'         => ucfirst($firma->tipo_doc),
                'codigo_doc'       => $firma->codigo_doc ?? '',
                'link_firma'       => $linkFirma,
                'link_documento'   => $linkFirma,
                'fecha_expiracion' => $firma->fecha_expiracion ?? '',
                'importe'          => '',
                'dias_restantes'   => (string)$diasRestantes,
            ];

            $asunto = $config->reemplazarVariables($config->email_asunto ?? '', $datos);
            $cuerpo = $config->reemplazarVariables($config->email_cuerpo ?? '', $datos);
            $asunto = Tools::lang()->trans('firmadoc-email-reminder-prefix', ['%subject%' => $asunto]);

            $mail->addAddress($firmante->email);
            $mail->title = $asunto;
            $mail->addMainBlock(new TextBlock($cuerpo));
            $mail->addMainBlock(new TextBlock(
                '<p style="margin-top:12px;font-size:0.9em;color:#555;">'
                . Tools::lang()->trans('firmadoc-email-expires-in-short', ['%days%' => $diasRestantes])
                . '</p>'
                . '<a href="' . $linkFirma . '" style="background:#28a745;color:#fff;padding:10px 24px;'
                . 'border-radius:4px;text-decoration:none;display:inline-block;margin-top:8px;font-weight:600;">'
                . Tools::lang()->trans('firmadoc-email-sign-now-btn') . '</a>'
            ));
            return $mail->send();
        } catch (\Exception $e) {
            Tools::log()->error(Tools::lang()->trans('firmadoc-cron-signer-reminder-error', ['%error%' => $e->getMessage()]));
            return false;
        }
    }

}
