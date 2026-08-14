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
namespace FacturaScripts\Plugins\FirmaDoc\Mod;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocConfig;

class FirmaDocListExtension
{
    private static array $tipoMap = [
        'ListFacturaCliente'     => FirmaDoc::TIPO_FACTURA,
        'ListPresupuestoCliente' => FirmaDoc::TIPO_PRESUPUESTO,
        'ListAlbaranCliente'     => FirmaDoc::TIPO_ALBARAN,
        'ListPedidoCliente'      => FirmaDoc::TIPO_PEDIDO,
    ];

    /** Colores de cada estado; la clave de idioma se traduce en loadData(). */
    private static array $colores = [
        FirmaDoc::ESTADO_FIRMADO     => ['firmadoc-badge-signed',   '#28a745', '#fff'],
        FirmaDoc::ESTADO_PENDIENTE   => ['firmadoc-badge-pending',  '#ffc107', '#212529'],
        FirmaDoc::ESTADO_EXPIRADO    => ['firmadoc-badge-expired',  '#6c757d', '#fff'],
        FirmaDoc::ESTADO_CANCELADO   => ['firmadoc-badge-rejected', '#dc3545', '#fff'],
        FirmaDoc::ESTADO_ANULADO_MOD => ['firmadoc-badge-voided',          '#343a40', '#fff'],
    ];

    public function loadData(): \Closure
    {
        // OJO: esta clase no puede tener ningún método auxiliar. FacturaScripts invoca
        // por reflexión TODOS los métodos de una extensión —públicos, protegidos y
        // privados— y exige que cada uno devuelva un Closure (Core/Template/ExtensionsTrait.php).
        // Un simple helper aquí revienta el arranque de la aplicación entera.
        $tipoMap = self::$tipoMap;

        $badges = [];
        foreach (self::$colores as $estado => [$clave, $bg, $color]) {
            $badges[$estado] = [Tools::lang()->trans($clave), $bg, $color];
        }

        return function (string $viewName, $view) use ($tipoMap, $badges) {

            // Determinar tipo de documento por el nombre del controller
            $controllerName = $this->getPageData()['name'] ?? '';
            $tipo = $tipoMap[$controllerName] ?? null;
            if (!$tipo || !FirmaDocConfig::estaActivo($tipo)) {
                return;
            }

            // Solo actuar en la vista principal
            $firstView = array_key_first($this->views ?? []);
            if ($viewName !== $firstView) {
                return;
            }

            if (empty($view->cursor)) {
                return;
            }

            // Recoger IDs de todos los documentos de la página
            $ids = [];
            foreach ($view->cursor as $model) {
                $ids[] = (int)$model->primaryColumnValue();
            }

            if (empty($ids)) {
                return;
            }

            // Consulta batch: un solo SELECT para toda la página
            $db     = new DataBase();
            $safe   = $db->escapeString($tipo);
            $idsStr = implode(',', array_map('intval', $ids));
            $sql    = "SELECT id, id_doc, estado, doc_hash FROM firmadoc
                       WHERE tipo_doc = '" . $safe . "' AND id_doc IN (" . $idsStr . ")
                       ORDER BY id DESC";

            $estados = [];
            $hashes  = [];
            foreach ($db->select($sql) as $row) {
                $idDoc = (int)$row['id_doc'];
                if (!isset($estados[$idDoc])) {
                    $estados[$idDoc] = $row['estado'];
                    $hashes[$idDoc]  = $row['doc_hash'];
                }
            }

            // Asignar badge HTML a cada modelo
            foreach ($view->cursor as $model) {
                $id     = (int)$model->primaryColumnValue();
                $estado = $estados[$id] ?? null;

                if ($estado === null) {
                    $model->firmadoc_estado_badge = '';
                    continue;
                }

                // Si el documento cambió después de firmarse, el estado guardado sigue
                // diciendo «firmado»: eso depende de que el hook de modificación se
                // disparara. Aquí se recalcula la huella y manda lo que diga la huella.
                if ($estado === FirmaDoc::ESTADO_FIRMADO && !empty($hashes[$id])) {
                    $firmaComp = new FirmaDoc();
                    $firmaComp->doc_hash = $hashes[$id];
                    if ($firmaComp->documentoSinModificar($model) === false) {
                        $estado = FirmaDoc::ESTADO_ANULADO_MOD;
                    }
                }

                [$label, $bg, $color] = $badges[$estado] ?? [$estado, '#6c757d', '#fff'];
                $model->firmadoc_estado_badge =
                    '<span style="background:' . $bg . ';color:' . $color . ';'
                    . 'padding:2px 8px;border-radius:4px;font-size:0.78em;'
                    . 'font-weight:600;white-space:nowrap;">'
                    . $label . '</span>';
            }
        };
    }
}
