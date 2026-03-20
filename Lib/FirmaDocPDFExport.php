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

use FacturaScripts\Core\Lib\Export\PDFExport;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocFirmante;

/**
 * Extiende PDFExport para añadir páginas de certificado de firma.
 * Soporta multi-firmante: 2 bloques de firma por página.
 */
class FirmaDocPDFExport extends PDFExport
{
    public function getPdf()
    {
        return $this->pdf;
    }

    /**
     * Añade las páginas de certificado.
     * En modo multi-firmante muestra todos los firmantes que han firmado,
     * 2 por página. En modo único, muestra la firma del firmante principal.
     */
    public function addCertificadoFirma(FirmaDoc $firma, object $documento): void
    {
        // Recoger todos los firmantes que han firmado
        $firmantes = FirmaDocFirmante::porSolicitud($firma->id);
        $firmados  = array_values(array_filter($firmantes, fn($f) => $f->estado === FirmaDocFirmante::ESTADO_FIRMADO));

        if (empty($firmados)) {
            // Fallback: usar datos de la firma principal (modo único clásico)
            $firmados = [$this->firmanteDesdeDoc($firma)];
        }

        $total = count($firmados);
        $t = function(string $key, array $params = []): string {
            return Tools::lang()->trans($key, $params);
        };

        // Agrupar de 3 en 3
        for ($i = 0; $i < $total; $i += 3) {
            $this->pdf->ezNewPage();

            $pageW    = $this->pdf->ez['pageWidth'];
            $marginL  = $this->pdf->ez['leftMargin'];
            $marginR  = $this->pdf->ez['rightMargin'];
            $contentW = $pageW - $marginL - $marginR;
            $pageH    = $this->pdf->ez['pageHeight'];

            // ── CABECERA ──────────────────────────────────────────────────
            $this->pdf->setColor(0.13, 0.45, 0.25);
            $this->pdf->filledRectangle(0, $pageH - 45, $pageW, 45);
            $this->pdf->setColor(1, 1, 1);
            $this->pdf->addText($marginL, $pageH - 30, 14,
                $t('firmadoc-pdf-certificate-title'), $contentW, 'left');
            $subtitle = $total > 1
                ? $t('firmadoc-pdf-signers-x-of-y', ['%from%' => $i + 1, '%to%' => min($i + 3, $total), '%total%' => $total])
                : $t('firmadoc-pdf-certifies-signature');
            $this->pdf->addText($marginL, $pageH - 42, 9, $subtitle, $contentW, 'left');

            // Datos del documento
            $y = $pageH - 70;
            $this->pdf->setColor(0, 0, 0);
            $this->pdf->addText($marginL, $y, 11,
                strtoupper(ucfirst($firma->tipo_doc)) . ': ' . $firma->codigo_doc,
                $contentW, 'left');
            $y -= 14;
            $this->pdf->setStrokeColor(0.13, 0.45, 0.25);
            $this->pdf->line($marginL, $y, $pageW - $marginR, $y);
            $y -= 12;

            // Calcular altura disponible para cada bloque
            $bloquesEnPagina = min(3, $total - $i);
            $alturaBloque    = ($pageH - 45 - 100 - 50) / $bloquesEnPagina;

            for ($j = 0; $j < $bloquesEnPagina; $j++) {
                $firmante = $firmados[$i + $j];
                $yInicio  = $y;

                // Divisor entre bloques
                if ($j > 0) {
                    $y -= 10;
                    $this->pdf->setColor(0.85, 0.85, 0.85);
                    $this->pdf->filledRectangle($marginL, $y, $contentW, 1);
                    $y -= 16;
                }

                // Número de firmante
                $this->pdf->setColor(0.13, 0.45, 0.25);
                $signerLabel = $total > 1
                    ? $t('firmadoc-pdf-signer-x-of-y', ['%num%' => $i + $j + 1, '%total%' => $total])
                    : $t('firmadoc-pdf-signer') . ' ' . ($i + $j + 1);
                $this->pdf->addText($marginL, $y, 10, $signerLabel, $contentW, 'left');
                $y -= 14;

                // Datos del firmante
                $filas = [
                    [$t('firmadoc-pdf-full-name'), $firmante->firma_nombre ?? ($firmante->nombre ?? '—')],
                    [$t('firmadoc-pdf-nif-cif'),   $firmante->firma_nif   ?: '—'],
                    [$t('firmadoc-pdf-position'),   $firmante->firma_cargo ?: '—'],
                ];
                foreach ($filas as [$label, $valor]) {
                    $this->pdf->setColor(0.4, 0.4, 0.4);
                    $this->pdf->addText($marginL, $y, 9, $label . ':', 80, 'left');
                    $this->pdf->setColor(0, 0, 0);
                    $this->pdf->addText($marginL + 85, $y, 9, $valor, $contentW - 85, 'left');
                    $y -= 12;
                }

                $y -= 4;
                $this->pdf->setColor(0.13, 0.45, 0.25);
                $this->pdf->addText($marginL, $y, 10, $t('firmadoc-pdf-signature-data'), $contentW, 'left');
                $y -= 13;

                $datosFirma = [
                    [$t('firmadoc-pdf-date-time'),  $firmante->fecha_firma     ?? '—'],
                    [$t('firmadoc-pdf-signer-ip'),  $firmante->ip_cliente      ?? '—'],
                ];
                foreach ($datosFirma as [$label, $valor]) {
                    $this->pdf->setColor(0.4, 0.4, 0.4);
                    $this->pdf->addText($marginL, $y, 9, $label . ':', 80, 'left');
                    $this->pdf->setColor(0, 0, 0);
                    $this->pdf->addText($marginL + 85, $y, 9, $valor, $contentW - 85, 'left');
                    $y -= 12;
                }

                // Imagen de firma
                $y -= 6;
                $this->pdf->setColor(0.13, 0.45, 0.25);
                $this->pdf->addText($marginL, $y, 10, $t('firmadoc-pdf-signature'), $contentW, 'left');
                $y -= 8;

                $modoFirma   = $firmante->modo_firma ?? '';
                $firmaImagen = $firmante->firma_imagen ?? '';
                $certData    = $firmante->firma_certificado_data ?? '';

                if ($modoFirma === 'certificado') {
                    $this->dibujarBloqueCertificado($firmante, $marginL, $contentW, $y);
                    $y -= 70;
                } elseif (!empty($firmaImagen)) {
                    $imgPath = $this->guardarImagenTemporal($firmaImagen);
                    if ($imgPath) {
                        $this->pdf->setColor(0.95, 0.95, 0.95);
                        $this->pdf->filledRectangle($marginL, $y - 50, 150, 50);
                        $this->pdf->setStrokeColor(0.8, 0.8, 0.8);
                        $this->pdf->rectangle($marginL, $y - 50, 150, 50);
                        $this->pdf->addPngFromFile($imgPath, $marginL + 4, $y - 46, 142, 42);
                        @unlink($imgPath);
                    }
                    $y -= 58;
                } else {
                    $y -= 10;
                }

                // Texto legal compacto
                $y -= 6;
                $this->pdf->setColor(0.88, 0.93, 0.88);
                $this->pdf->filledRectangle($marginL, $y - 24, $contentW, 26);
                $this->pdf->setColor(0.3, 0.3, 0.3);
                $this->pdf->addText($marginL + 4, $y - 4, 7,
                    $t('firmadoc-pdf-legal-accepted'),
                    $contentW - 8, 'left');
                $this->pdf->addText($marginL + 4, $y - 14, 7,
                    $t('firmadoc-pdf-eidas-valid', ['%hash%' => $firma->doc_hash ?? '']),
                    $contentW - 8, 'left');
                $y -= 30;
            }

            // ── QR de verificación (solo en la última página del certificado) ──
            if ($i + 3 >= $total) {
                $this->addQrVerificacion($firma, $marginL, $contentW, $pageW, $marginR, $t);
            }

            // Pie de página
            $this->pdf->setStrokeColor(0.7, 0.7, 0.7);
            $this->pdf->line($marginL, 28, $pageW - $marginR, 28);
            $this->pdf->setColor(0.5, 0.5, 0.5);
            $this->pdf->addText($marginL, 20, 7,
                $t('firmadoc-pdf-generated-by') . ' · ' . $firma->codigo_doc . ' · ' . date('d/m/Y H:i'),
                $contentW, 'left');
        }

        // Protección PDF
        $ownerPass = 'FirmaDoc_' . substr(md5($firma->doc_hash ?? uniqid()), 0, 16);
        $this->pdf->setEncryption('', $ownerPass, ['print' => true, 'modify' => false, 'copy' => false], 2);
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    /**
     * Crea un objeto "firmante" pseudo con los datos de la firma principal (modo único clásico).
     */
    private function firmanteDesdeDoc(FirmaDoc $firma): object
    {
        return (object) [
            'nombre'                => $firma->email_cliente ?? '',
            'firma_nombre'          => $firma->firma_nombre,
            'firma_nif'             => $firma->firma_nif,
            'firma_cargo'           => $firma->firma_cargo,
            'firma_imagen'          => $firma->firma_imagen,
            'firma_certificado_data'=> $firma->firma_certificado_data,
            'modo_firma'            => $firma->modo_firma,
            'fecha_firma'           => $firma->fecha_firma,
            'ip_cliente'            => $firma->ip_cliente,
            'user_agent'            => $firma->user_agent,
        ];
    }

    private function dibujarBloqueCertificado(object $firmante, float $marginL, float $contentW, float $y): void
    {
        $t = function(string $key, array $params = []): string {
            return Tools::lang()->trans($key, $params);
        };

        $this->pdf->setColor(0.85, 0.93, 0.87);
        $this->pdf->filledRectangle($marginL, $y - 60, $contentW, 60);
        $this->pdf->setStrokeColor(0.13, 0.45, 0.25);
        $this->pdf->rectangle($marginL, $y - 60, $contentW, 60);
        $this->pdf->setColor(0.13, 0.45, 0.25);
        $this->pdf->filledRectangle($marginL, $y - 14, $contentW, 14);
        $this->pdf->setColor(1, 1, 1);
        $this->pdf->addText($marginL + 5, $y - 10, 8, $t('firmadoc-pdf-digital-cert'), $contentW - 10, 'left');

        $certData = [];
        if (!empty($firmante->firma_certificado_data)) {
            $decoded = json_decode($firmante->firma_certificado_data, true);
            if (is_array($decoded)) {
                $certData = $decoded;
            }
        }

        $yc = $y - 22;
        foreach ([
            [$t('firmadoc-pdf-holder'),     $firmante->firma_nombre ?? '—'],
            [$t('firmadoc-field-nif'),       $firmante->firma_nif    ?? '—'],
            [$t('firmadoc-pdf-valid-until'), $certData['expiry'] ?? '—'],
            [$t('firmadoc-pdf-sign-date'),   $firmante->fecha_firma ?? '—'],
        ] as [$lbl, $val]) {
            $this->pdf->setColor(0.13, 0.45, 0.25);
            $this->pdf->addText($marginL + 5, $yc, 8, $lbl . ':', 70, 'left');
            $this->pdf->setColor(0, 0, 0);
            $this->pdf->addText($marginL + 75, $yc, 8, $val, $contentW - 80, 'left');
            $yc -= 10;
        }
    }

    private function guardarImagenTemporal(string $dataUrl): ?string
    {
        if (strpos($dataUrl, 'data:image/png;base64,') !== 0) {
            return null;
        }
        $bytes = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')));
        if (empty($bytes)) {
            return null;
        }
        $path = sys_get_temp_dir() . '/firmadoc_sig_' . uniqid() . '.png';
        if (file_put_contents($path, $bytes) === false) {
            return null;
        }
        return $path;
    }

    /**
     * Añade un código QR de verificación en la esquina inferior derecha del certificado.
     */
    private function addQrVerificacion(FirmaDoc $firma, float $marginL, float $contentW, float $pageW, float $marginR, callable $t): void
    {
        try {
            $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $subdir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
            $verifyUrl = $scheme . '://' . $host . $subdir . '/FirmaDocVerify?hash=' . ($firma->doc_hash ?? '');

            $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($verifyUrl);

            $context = stream_context_create(['http' => ['timeout' => 5]]);
            $qrData = @file_get_contents($qrApiUrl, false, $context);
            if (empty($qrData)) {
                return;
            }

            $tmpFile = sys_get_temp_dir() . '/firmadoc_qr_' . uniqid() . '.png';
            if (file_put_contents($tmpFile, $qrData) === false) {
                return;
            }

            // Posición: inferior derecha, encima de la línea del pie de página
            $qrSize = 55;
            $qrX = $pageW - $marginR - $qrSize;
            $qrY = 34;

            $this->pdf->addPngFromFile($tmpFile, $qrX, $qrY, $qrSize, $qrSize);
            @unlink($tmpFile);

            // Texto debajo del QR
            $this->pdf->setColor(0.5, 0.5, 0.5);
            $this->pdf->addText($qrX, $qrY - 8, 6, $t('firmadoc-pdf-verify-qr'), $qrSize, 'center');
        } catch (\Exception $e) {
            // Silenciar errores para no romper el PDF
        }
    }

    private function resumirUserAgent(string $ua): string
    {
        if (empty($ua)) return '—';
        $unknown = Tools::lang()->trans('firmadoc-pdf-unknown');
        $browser = match (true) {
            str_contains($ua, 'Chrome')  => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari')  => 'Safari',
            str_contains($ua, 'Edge')    => 'Edge',
            default => $unknown,
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone')  => 'iPhone',
            str_contains($ua, 'iPad')    => 'iPad',
            str_contains($ua, 'Mac')     => 'macOS',
            str_contains($ua, 'Linux')   => 'Linux',
            default => $unknown,
        };
        return $browser . ' / ' . $os;
    }
}
