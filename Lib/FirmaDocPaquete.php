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
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocAdjunto;

/**
 * Empaqueta en un ZIP los documentos de una solicitud junto a su certificado.
 *
 * Cuando la firma cubre varios documentos no se pueden entregar fusionados en un solo
 * PDF: los originales no se tocan —alterarlos invalidaría su huella— y unirlos daría
 * un fichero que ya no es ninguno de los firmados. El ZIP conserva cada pieza tal cual
 * y añade el certificado, que es quien acredita el conjunto.
 */
class FirmaDocPaquete
{
    /**
     * @return string|null Contenido del ZIP, o null si no se pudo crear
     */
    public static function crear(FirmaDoc $firma, string $certificado): ?string
    {
        if (!class_exists('ZipArchive')) {
            Tools::log()->warning(Tools::lang()->trans('firmadoc-no-zip'));
            return null;
        }

        $adjuntos = $firma->getAdjuntos();
        if (empty($adjuntos)) {
            return null;
        }

        $ruta = tempnam(sys_get_temp_dir(), 'fdzip_');
        if ($ruta === false) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($ruta);
            return null;
        }

        try {
            // Los que se firman y los anexos van en carpetas separadas: quien reciba
            // el paquete tiene que poder distinguir qué aceptó y qué solo se le envió.
            foreach ($adjuntos as $adjunto) {
                $origen = $adjunto->getRuta();
                if ($origen === '') {
                    continue;
                }
                $carpeta = $adjunto->esFirmable()
                    ? Tools::lang()->trans('firmadoc-to-sign')
                    : Tools::lang()->trans('firmadoc-annex');
                $zip->addFile($origen, $carpeta . '/' . $adjunto->getNombre());
            }

            if (!empty($certificado)) {
                $zip->addFromString(
                    Tools::lang()->trans('firmadoc-pdf-certificate-title') . '.pdf',
                    $certificado
                );
            }

            $zip->close();

            return is_readable($ruta) ? (string) file_get_contents($ruta) : null;

        } catch (\Throwable $e) {
            Tools::log()->error(Tools::lang()->trans('firmadoc-zip-failed', ['%error%' => $e->getMessage()]));
            return null;
        } finally {
            @unlink($ruta);
        }
    }
}
