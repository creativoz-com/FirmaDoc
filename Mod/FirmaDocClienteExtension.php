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
use FacturaScripts\Core\UploadedFile;
use FacturaScripts\Plugins\FirmaDoc\Controller\FirmaDocSubir;

/**
 * Pestañas de firma en la ficha de cliente y de proveedor.
 *
 * «Documentos firmados» reúne el histórico del tercero: tanto los PDF subidos y
 * asociados a su ficha como las firmas nacidas de una factura, un presupuesto o un
 * albarán suyos. «Enviar a firma» es el formulario de envío, dentro de la propia ficha
 * para no perder su menú lateral.
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

            // El formulario de envío, como una pestaña más de la ficha
            $this->addHtmlView(
                'FirmaDocEnviarTercero',
                'FirmaDocEnviarTercero',
                'FirmaDoc',
                'firmadoc-upload-document',
                'fas fa-paper-plane'
            );
        };
    }

    public function loadData()
    {
        return function (string $viewName, $view) {
            if (!in_array($viewName, ['ListFirmaDocTercero', 'FirmaDocEnviarTercero'])) {
                return;
            }

            // El campo de enlace depende de la ficha: cliente o proveedor
            $codigo = $this->getModel()->primaryColumnValue();
            if (empty($codigo)) {
                return;
            }

            $esProveedor = $this->getModelClassName() === 'Proveedor';
            $campo = $esProveedor ? 'codproveedor' : 'codcliente';

            if ($viewName === 'ListFirmaDocTercero') {
                $view->loadData('', [new DataBaseWhere($campo, $codigo)]);

                // El botón «+» del listado se construye con el url('new') de este modelo:
                // dejándole el código, lleva a la pestaña de envío de esta misma ficha.
                $view->model->{$campo} = $codigo;
                return;
            }

            // El formulario necesita saber para quién es y cuánto admite el servidor.
            // Van por settings y no por propiedades del controlador: escribir una
            // propiedad que EditCliente no declara es una deprecación en PHP 8.3.
            $view->settings['firmadocCampo'] = $campo;
            $view->settings['firmadocCodigo'] = $codigo;
            $view->settings['firmadocNombre'] = $this->getModel()->nombre ?? $codigo;
            $view->settings['firmadocVolver'] = ($esProveedor ? 'EditProveedor?code=' : 'EditCliente?code=')
                . rawurlencode($codigo);
            $view->settings['firmadocMaxSubida'] = (int) floor(
                min(FirmaDocSubir::MAX_BYTES, UploadedFile::getMaxFilesize()) / 1024 / 1024
            );
        };
    }
}
