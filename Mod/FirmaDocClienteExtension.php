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
namespace FacturaScripts\Plugins\FirmaDoc\Mod;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

/**
 * Pestaña «Documentos firmados» en la ficha de cliente y de proveedor.
 *
 * Aparecen tanto los PDF subidos y asociados a esa ficha como las firmas generadas
 * desde una factura, un presupuesto o un albarán suyos: todo el histórico de firma de
 * ese tercero en un mismo sitio.
 *
 * OJO: esta clase no puede tener métodos auxiliares. FacturaScripts invoca por reflexión
 * todos los métodos de una extensión —incluidos los privados— y exige que cada uno
 * devuelva un Closure.
 */
class FirmaDocClienteExtension
{
    public function createViews()
    {
        return function () {
            $this->addListView(
                'ListFirmaDocTercero',
                'FirmaDoc',
                'firmadoc-signed-documents',
                'fas fa-file-signature'
            )
                ->addOrderBy(['fecha_envio'], 'date', 2)
                ->addOrderBy(['fecha_firma'], 'firmadoc-sign-date')
                ->addSearchFields(['titulo', 'codigo_doc', 'email_cliente']);
        };
    }

    public function loadData()
    {
        return function (string $viewName, $view) {
            if ($viewName !== 'ListFirmaDocTercero') {
                return;
            }

            // El campo de enlace depende de la ficha: cliente o proveedor
            $codigo = $this->getModel()->primaryColumnValue();
            if (empty($codigo)) {
                return;
            }

            $campo = $this->getModelClassName() === 'Proveedor' ? 'codproveedor' : 'codcliente';
            $view->loadData('', [new DataBaseWhere($campo, $codigo)]);

            // El botón «+» del listado se construye con el url('new') de este modelo:
            // dejándole el código, la pantalla de subida llega con el tercero elegido.
            $view->model->{$campo} = $codigo;
        };
    }
}
