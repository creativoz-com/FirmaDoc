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
 * Extensión para MODELOS de documento de venta.
 * Detecta modificaciones comparando el hash antes y después del guardado.
 * 
 * El hook saveInsertBefore captura el estado ANTES de guardar.
 * El hook save captura el estado DESPUÉS (con totales recalculados).
 * 
 * Usamos saveUpdateBefore para leer el hash actual de BD y guardarlo en memoria,
 * y luego onUpdate para comparar con el nuevo hash post-Calculator.
 */
class FirmaDocHashExtension
{
    public function saveUpdateBefore()
    {
        return function () {
            // Guardar el hash actual (pre-modificación) en propiedad temporal
            $tipo = FirmaDoc::getTipoDesdeClase($this->modelClassName());
            if (!$tipo) {
                return true;
            }

            $firma = FirmaDoc::getActivaPorDocumento($tipo, $this->primaryColumnValue());
            if (!$firma || empty($firma->doc_hash)) {
                return true;
            }

            // Guardar la firma en propiedad estática para accederla en onUpdate
            FirmaDocHashExtension::$firmaEnProceso[$this->primaryColumnValue()] = $firma;
            return true;
        };
    }

    public function onUpdate()
    {
        return function () {
            $tipo = FirmaDoc::getTipoDesdeClase($this->modelClassName());
            if (!$tipo) {
                return;
            }

            $idDoc = $this->primaryColumnValue();
            if (!isset(FirmaDocHashExtension::$firmaEnProceso[$idDoc])) {
                return;
            }

            $firma = FirmaDocHashExtension::$firmaEnProceso[$idDoc];
            unset(FirmaDocHashExtension::$firmaEnProceso[$idDoc]);

            // Si solo cambió el estado (editable pasó de true a false),
            // es un cambio de flujo normal — no anular la firma
            if (method_exists($this, 'getOriginal')) {
                $editableAntes = $this->getOriginal('editable');
                $editableAhora = $this->editable ?? true;
                if ($editableAntes === true && $editableAhora === false) {
                    return; // Solo cambió el estado del documento
                }
            }

            // Comparar hash guardado vs hash actual (post-Calculator)
            $hashActual = FirmaDoc::calcularHashDoc($this);
            if ($firma->doc_hash !== $hashActual) {
                $firma->anularPorModificacion();
                \FacturaScripts\Core\Tools::log()->warning(
                    \FacturaScripts\Core\Tools::lang()->trans('firmadoc-signature-voided-doc', ['%code%' => $this->codigo ?? $idDoc])
                );
            }
        };
    }

    /** @var array Almacena temporalmente la firma durante el ciclo de guardado */
    public static array $firmaEnProceso = [];
}
