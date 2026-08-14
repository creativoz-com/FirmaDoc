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
namespace FacturaScripts\Plugins\FirmaDoc\Lib;

use FacturaScripts\Core\Tools;

/**
 * Une el PDF subido con la página de certificado de firma.
 *
 * La librería de PDF que trae FacturaScripts (rospdf) sabe crear documentos pero no
 * importar uno existente, así que un PDF que sube el usuario no se puede modificar desde
 * PHP. Cuando el servidor tiene una herramienta de unión se aprovecha; cuando no, cada
 * cosa se entrega por separado y no pasa nada: el contrato conserva su PDF original
 * intacto —que para un documento firmado es lo correcto— y el certificado va aparte.
 */
class FirmaDocPdfUnir
{
    /** Herramientas soportadas, en orden de preferencia */
    const HERRAMIENTAS = ['pdfunite', 'qpdf'];

    /**
     * @return string|null PDF combinado, o null si este servidor no puede unirlos
     */
    public static function unir(string $rutaOriginal, string $pdfCertificado): ?string
    {
        $herramienta = self::disponible();
        if ($herramienta === null || !is_readable($rutaOriginal) || empty($pdfCertificado)) {
            return null;
        }

        $tmpCert = tempnam(sys_get_temp_dir(), 'fdcert_');
        $tmpSalida = tempnam(sys_get_temp_dir(), 'fdout_');
        if ($tmpCert === false || $tmpSalida === false) {
            return null;
        }

        try {
            if (file_put_contents($tmpCert, $pdfCertificado) === false) {
                return null;
            }

            $comando = $herramienta === 'pdfunite'
                ? sprintf('pdfunite %s %s %s', escapeshellarg($rutaOriginal), escapeshellarg($tmpCert), escapeshellarg($tmpSalida))
                : sprintf('qpdf --empty --pages %s %s -- %s', escapeshellarg($rutaOriginal), escapeshellarg($tmpCert), escapeshellarg($tmpSalida));

            exec($comando . ' 2>/dev/null', $salida, $codigo);

            if ($codigo !== 0 || !is_readable($tmpSalida) || filesize($tmpSalida) < 100) {
                return null;
            }

            return file_get_contents($tmpSalida) ?: null;

        } catch (\Throwable $e) {
            Tools::log()->warning(Tools::lang()->trans('firmadoc-merge-failed', ['%error%' => $e->getMessage()]));
            return null;
        } finally {
            @unlink($tmpCert);
            @unlink($tmpSalida);
        }
    }

    /**
     * Nombre de la herramienta disponible, o null si no hay ninguna.
     */
    public static function disponible(): ?string
    {
        static $encontrada = false;

        if ($encontrada !== false) {
            return $encontrada;
        }

        $encontrada = null;

        // Muchos alojamientos compartidos desactivan exec(): hay que comprobarlo
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return null;
        }

        foreach (self::HERRAMIENTAS as $h) {
            exec('command -v ' . escapeshellarg($h) . ' 2>/dev/null', $salida, $codigo);
            if ($codigo === 0 && !empty($salida)) {
                $encontrada = $h;
                break;
            }
            $salida = [];
        }

        return $encontrada;
    }
}
