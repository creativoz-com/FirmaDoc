<?php
/**
 * This file is part of FirmaDoc plugin for FacturaScripts.
 *
 * @author    Francisco José Matías Olivares <fmatias@creativoz.com>
 * @copyright 2025-2026 Francisco José Matías Olivares
 * @license   Acuerdo de Licencia de Usuario Final (EULA) — véase archivo LICENSE
 * @version   1.0
 * @link      https://creativoz.com
 */
namespace FacturaScripts\Plugins\FirmaDoc;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocConfig;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocFirmante;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocReenvio;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocMailer;

class Init extends InitClass
{
    public function init(): void
    {
        $config = FirmaDocConfig::getConfig();

        // ── Mapa de tipos de documento → controladores ────────────────────
        $mapaTab = [
            'doc_factura'     => [
                'edit' => \FacturaScripts\Dinamic\Controller\EditFacturaCliente::class,
                'list' => \FacturaScripts\Dinamic\Controller\ListFacturaCliente::class,
                'model' => \FacturaScripts\Dinamic\Model\FacturaCliente::class,
                'linea' => \FacturaScripts\Dinamic\Model\LineaFacturaCliente::class,
            ],
            'doc_presupuesto' => [
                'edit' => \FacturaScripts\Dinamic\Controller\EditPresupuestoCliente::class,
                'list' => \FacturaScripts\Dinamic\Controller\ListPresupuestoCliente::class,
                'model' => \FacturaScripts\Dinamic\Model\PresupuestoCliente::class,
                'linea' => \FacturaScripts\Dinamic\Model\LineaPresupuestoCliente::class,
            ],
            'doc_albaran'     => [
                'edit' => \FacturaScripts\Dinamic\Controller\EditAlbaranCliente::class,
                'list' => \FacturaScripts\Dinamic\Controller\ListAlbaranCliente::class,
                'model' => \FacturaScripts\Dinamic\Model\AlbaranCliente::class,
                'linea' => \FacturaScripts\Dinamic\Model\LineaAlbaranCliente::class,
            ],
            'doc_pedido'      => [
                'edit' => \FacturaScripts\Dinamic\Controller\EditPedidoCliente::class,
                'list' => \FacturaScripts\Dinamic\Controller\ListPedidoCliente::class,
                'model' => \FacturaScripts\Dinamic\Model\PedidoCliente::class,
                'linea' => \FacturaScripts\Dinamic\Model\LineaPedidoCliente::class,
            ],
        ];

        foreach ($mapaTab as $configKey => $clases) {
            // Solo si el tipo de documento está activo en configuración
            if (!$config->$configKey) {
                continue;
            }

            // Tab de firma en ficha del documento
            if (class_exists($clases['edit'])) {
                $clases['edit']::addExtension(
                    new \FacturaScripts\Plugins\FirmaDoc\Mod\FirmaDocTabExtension()
                );
            }

            // Columna de estado en listado
            if (class_exists($clases['list'])) {
                $clases['list']::addExtension(
                    new \FacturaScripts\Plugins\FirmaDoc\Mod\FirmaDocListExtension()
                );
            }

            // Detección de modificaciones post-firma en modelo
            if (class_exists($clases['model'])) {
                $clases['model']::addExtension(
                    new \FacturaScripts\Plugins\FirmaDoc\Mod\FirmaDocHashExtension()
                );
            }

            // Detección de cambios en líneas
            if (class_exists($clases['linea'])) {
                $clases['linea']::addExtension(
                    new \FacturaScripts\Plugins\FirmaDoc\Mod\FirmaDocLineaHashExtension()
                );
            }
        }

    }

    public function update(): void
    {
        new FirmaDoc();
        new FirmaDocConfig();
        new FirmaDocFirmante();
        new FirmaDocReenvio();
        // Limpiar posibles registros EmailNotification contaminados de versiones anteriores
        FirmaDocMailer::limpiarPlantillasContaminadas();
    }

    public function uninstall(): void
    {
    }
}
