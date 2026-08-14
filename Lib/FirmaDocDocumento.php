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

use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;

/**
 * Carga el documento de venta al que se refiere una firma.
 *
 * El mismo mapa de tipo → clase estaba repetido en cuatro sitios; aquí está una vez.
 */
class FirmaDocDocumento
{
    public static function cargar(string $tipo, int $id): ?object
    {
        $clases = [
            FirmaDoc::TIPO_FACTURA     => '\FacturaScripts\Dinamic\Model\FacturaCliente',
            FirmaDoc::TIPO_PRESUPUESTO => '\FacturaScripts\Dinamic\Model\PresupuestoCliente',
            FirmaDoc::TIPO_ALBARAN     => '\FacturaScripts\Dinamic\Model\AlbaranCliente',
            FirmaDoc::TIPO_PEDIDO      => '\FacturaScripts\Dinamic\Model\PedidoCliente',
        ];

        // En las firmas de PDF subido, `id_doc` apunta al AttachedFile donde
        // FacturaScripts custodia el fichero.
        if ($tipo === FirmaDoc::TIPO_EXTERNO) {
            return empty($id) ? null : \FacturaScripts\Core\Model\AttachedFile::find($id);
        }

        if (!isset($clases[$tipo]) || empty($id)) {
            return null;
        }

        $modelo = new $clases[$tipo]();
        return $modelo->loadFromCode($id) ? $modelo : null;
    }

    /**
     * Ruta en disco del PDF de una firma sobre documento externo.
     */
    public static function rutaFicheroExterno(FirmaDoc $firma): string
    {
        $fichero = $firma->getFichero();
        if (null === $fichero || empty($fichero->path)) {
            return '';
        }

        $ruta = FS_FOLDER . '/' . $fichero->path;
        return is_readable($ruta) ? $ruta : '';
    }
}
