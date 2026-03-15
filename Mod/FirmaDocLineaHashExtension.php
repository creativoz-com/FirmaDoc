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

use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;

/**
 * Extensión para LÍNEAS de documentos de venta.
 * Detecta cambios en descripción, cantidad, precio o descuento.
 * Cuando se guarda una línea, recalcula el hash del documento padre.
 */
class FirmaDocLineaHashExtension
{
    public function onUpdate()
    {
        return function () {
            $this->firmadocCheckLinea();
        };
    }

    public function onInsert()
    {
        return function () {
            $this->firmadocCheckLinea();
        };
    }

    public function onDelete()
    {
        return function () {
            $this->firmadocCheckLinea();
        };
    }

    public function firmadocCheckLinea()
    {
        return function () {
            if (!method_exists($this, 'getDocument')) {
                return;
            }

            $documento = $this->getDocument();
            if (!$documento || !$documento->id()) {
                return;
            }

            $tipo = FirmaDoc::getTipoDesdeClase($documento->modelClassName());
            if (!$tipo) {
                return;
            }

            $firma = FirmaDoc::getActivaPorDocumento($tipo, $documento->primaryColumnValue());
            if (!$firma || empty($firma->doc_hash)) {
                return;
            }

            // Recalcular hash con líneas actualizadas
            $hashActual = FirmaDoc::calcularHashDoc($documento);
            if ($firma->doc_hash !== $hashActual) {
                $firma->anularPorModificacion();
                \FacturaScripts\Core\Tools::log()->warning(
                    'Firma anulada: El documento ' . ($documento->codigo ?? '') . ' fue modificado después de ser firmado.'
                );
            }
        };
    }
}
