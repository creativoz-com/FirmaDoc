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
namespace FacturaScripts\Plugins\FirmaDoc\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;

/**
 * Todas las solicitudes de firma, vengan de donde vengan.
 *
 * Las que salen de una factura, un presupuesto o un albarán aparecen aquí junto a los
 * PDF subidos: hasta ahora las primeras solo se veían entrando en cada documento, así
 * que no había forma de saber qué estaba pendiente de firma en toda la empresa.
 */
class ListFirmaDoc extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        // Menú propio: esto no es solo de ventas — un contrato o una autorización
        // pueden ir igual a un proveedor.
        $data['menu'] = 'firmadoc';
        $data['title'] = 'firmadoc-sent-documents';
        $data['icon'] = 'fas fa-file-signature';
        return $data;
    }

    protected function createViews(): void
    {
        $this->createViewsFirmas();
        $this->createViewsPendientes();
    }

    protected function createViewsFirmas(string $viewName = 'ListFirmaDoc'): void
    {
        $this->addView($viewName, 'FirmaDoc', 'firmadoc-all', 'fas fa-list')
            ->addSearchFields(['titulo', 'codigo_doc', 'email_cliente', 'firma_nombre', 'codigo_verificacion'])
            ->addOrderBy(['fecha_envio'], 'date', 2)
            ->addOrderBy(['fecha_firma'], 'firmadoc-sign-date')
            ->addOrderBy(['codigo_doc'], 'code')
            ->addFilterSelectWhere('estado', [
                ['label' => Tools::lang()->trans('firmadoc-filter-all-status'), 'where' => []],
                ['label' => Tools::lang()->trans('firmadoc-badge-pending'), 'where' => [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('estado', FirmaDoc::ESTADO_PENDIENTE)]],
                ['label' => Tools::lang()->trans('firmadoc-badge-signed'), 'where' => [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('estado', FirmaDoc::ESTADO_FIRMADO)]],
                ['label' => Tools::lang()->trans('firmadoc-badge-expired'), 'where' => [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('estado', FirmaDoc::ESTADO_EXPIRADO)]],
                ['label' => Tools::lang()->trans('firmadoc-badge-rejected'), 'where' => [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('estado', FirmaDoc::ESTADO_CANCELADO)]],
                ['label' => Tools::lang()->trans('firmadoc-badge-voided'), 'where' => [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('estado', FirmaDoc::ESTADO_ANULADO_MOD)]],
            ])
            ->addFilterSelect('tipo_doc', 'firmadoc-doc-type', 'tipo_doc', [
                ['code' => '', 'description' => Tools::lang()->trans('firmadoc-filter-all-types')],
                ['code' => FirmaDoc::TIPO_EXTERNO, 'description' => Tools::lang()->trans('firmadoc-type-external')],
                ['code' => FirmaDoc::TIPO_FACTURA, 'description' => Tools::lang()->trans('invoice')],
                ['code' => FirmaDoc::TIPO_PRESUPUESTO, 'description' => Tools::lang()->trans('estimation')],
                ['code' => FirmaDoc::TIPO_ALBARAN, 'description' => Tools::lang()->trans('delivery-note')],
                ['code' => FirmaDoc::TIPO_PEDIDO, 'description' => Tools::lang()->trans('order')],
            ])
            ->addFilterAutocomplete('codcliente', 'customer', 'codcliente', 'Cliente')
            ->addFilterAutocomplete('codproveedor', 'supplier', 'codproveedor', 'Proveedor')
            ->addFilterPeriod('fecha_envio', 'date', 'fecha_envio')
            ->addFilterCheckbox('sello_fecha', 'firmadoc-filter-timestamped', 'sello_fecha', 'IS NOT', null)
            ->addFilterCheckbox('otp_verificado', 'firmadoc-filter-two-factor', 'otp_verificado');
    }

    /**
     * Pestaña con lo que está esperando firma, que es lo que se consulta a diario.
     */
    protected function createViewsPendientes(string $viewName = 'ListFirmaDoc-pendientes'): void
    {
        $this->addView($viewName, 'FirmaDoc', 'firmadoc-badge-pending', 'fas fa-clock')
            ->addSearchFields(['titulo', 'codigo_doc', 'email_cliente'])
            ->addOrderBy(['fecha_expiracion'], 'firmadoc-send-expires', 1)
            ->addOrderBy(['fecha_envio'], 'date')
            ->addFilterAutocomplete('codcliente', 'customer', 'codcliente', 'Cliente')
            ->addFilterPeriod('fecha_envio', 'date', 'fecha_envio');

        $this->views[$viewName]->where[] = new \FacturaScripts\Core\Base\DataBase\DataBaseWhere(
            'estado',
            FirmaDoc::ESTADO_PENDIENTE
        );
    }
}
