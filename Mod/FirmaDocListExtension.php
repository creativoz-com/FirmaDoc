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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;

class FirmaDocListExtension
{
    private static array $tipoMap = [
        'ListFacturaCliente'     => FirmaDoc::TIPO_FACTURA,
        'ListPresupuestoCliente' => FirmaDoc::TIPO_PRESUPUESTO,
        'ListAlbaranCliente'     => FirmaDoc::TIPO_ALBARAN,
        'ListPedidoCliente'      => FirmaDoc::TIPO_PEDIDO,
    ];

    private static array $badges = [
        FirmaDoc::ESTADO_FIRMADO   => ['Firmado',   '#28a745', '#fff'],
        FirmaDoc::ESTADO_PENDIENTE => ['Pendiente', '#ffc107', '#212529'],
        FirmaDoc::ESTADO_EXPIRADO  => ['Expirado',  '#6c757d', '#fff'],
        FirmaDoc::ESTADO_CANCELADO => ['Rechazado', '#dc3545', '#fff'],
        'anulado_mod'              => ['Anulado',   '#343a40', '#fff'],
    ];

    public function loadData(): \Closure
    {
        $tipoMap = self::$tipoMap;
        $badges  = self::$badges;

        return function (string $viewName, $view) use ($tipoMap, $badges) {

            // Determinar tipo de documento por el nombre del controller
            $controllerName = $this->getPageData()['name'] ?? '';
            $tipo = $tipoMap[$controllerName] ?? null;
            if (!$tipo) {
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
            $idsStr = implode(',', $ids);
            $safe   = $db->escapeString($tipo);
            $sql    = "SELECT id_doc, estado FROM firmadoc
                       WHERE tipo_doc = '{$safe}' AND id_doc IN ({$idsStr})
                       ORDER BY id DESC";

            $estados = [];
            foreach ($db->select($sql) as $row) {
                $idDoc = (int)$row['id_doc'];
                if (!isset($estados[$idDoc])) {
                    $estados[$idDoc] = $row['estado'];
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
