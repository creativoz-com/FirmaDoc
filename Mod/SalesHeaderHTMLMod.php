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
namespace FacturaScripts\Plugins\FirmaDoc\Mod;

use FacturaScripts\Core\Contract\SalesModInterface;
use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;

class SalesHeaderHTMLMod implements SalesModInterface
{
    public function newFields(): array
    {
        return [];
    }

    public function newModalFields(): array
    {
        return [];
    }

    public function newBtnFields(): array
    {
        return ['btnEnviarFirma'];
    }

    public function renderField(SalesDocument $model, string $field): ?string
    {
        if ($field === 'btnEnviarFirma') {
            return self::renderBotonFirma($model);
        }
        return null;
    }

    private static function renderBotonFirma(SalesDocument $model): string
    {
        // Solo mostrar si el documento ya está guardado (tiene id)
        if (empty($model->primaryColumnValue())) {
            return '';
        }

        // Detectar tipo de documento
        $clase    = get_class($model);
        $tipodoc  = self::getTipoDoc($clase);
        if (empty($tipodoc)) {
            return '';
        }

        // Comprobar si ya existe una firma activa para este documento
        $firmaExistente = self::getFirmaActiva($tipodoc, $model->primaryColumnValue());
        $idDoc          = $model->primaryColumnValue();
        $urlEnviar      = 'FirmaDocSend?tipo=' . $tipodoc . '&id=' . $idDoc;

        if ($firmaExistente && $firmaExistente->estado === FirmaDoc::ESTADO_FIRMADO) {
            // Documento ya firmado — botón verde informativo
            $html  = '<div class="col-sm-auto"><div class="form-group">';
            $html .= '<a href="' . $urlEnviar . '" class="btn btn-success btn-sm" title="Ver firma">';
            $html .= '<i class="fas fa-check-circle mr-1"></i> ' . Tools::lang()->trans('firmadoc-btn-signed');
            $html .= '</a>';
            $html .= '</div></div>';
        } elseif ($firmaExistente && $firmaExistente->estado === FirmaDoc::ESTADO_PENDIENTE) {
            // Pendiente de firma — botón naranja
            $html  = '<div class="col-sm-auto"><div class="form-group">';
            $html .= '<a href="' . $urlEnviar . '" class="btn btn-warning btn-sm" title="Pendiente de firma">';
            $html .= '<i class="fas fa-clock mr-1"></i> ' . Tools::lang()->trans('firmadoc-btn-pending');
            $html .= '</a>';
            $html .= '</div></div>';
        } elseif ($firmaExistente && $firmaExistente->estado === FirmaDoc::ESTADO_ANULADO_MOD) {
            // Anulado por modificación — botón rojo de alerta
            $html  = '<div class="col-sm-auto"><div class="form-group">';
            $html .= '<a href="' . $urlEnviar . '" class="btn btn-danger btn-sm" title="Firma anulada por modificación del documento">';
            $html .= '<i class="fas fa-exclamation-triangle mr-1"></i> ' . Tools::lang()->trans('firmadoc-btn-resend');
            $html .= '</a>';
            $html .= '</div></div>';
        } else {
            // Sin firma — botón principal para enviar
            $html  = '<div class="col-sm-auto"><div class="form-group">';
            $html .= '<a href="' . $urlEnviar . '" class="btn btn-primary btn-sm">';
            $html .= '<i class="fas fa-signature mr-1"></i> ' . Tools::lang()->trans('firmadoc-btn-send');
            $html .= '</a>';
            $html .= '</div></div>';
        }

        return $html;
    }

    private static function getTipoDoc(string $clase): string
    {
        $mapa = [
            'FacturaCliente'      => FirmaDoc::TIPO_FACTURA,
            'PresupuestoCliente'  => FirmaDoc::TIPO_PRESUPUESTO,
            'AlbaranCliente'      => FirmaDoc::TIPO_ALBARAN,
            'PedidoCliente'       => FirmaDoc::TIPO_PEDIDO,
        ];
        foreach ($mapa as $nombre => $tipo) {
            if (str_contains($clase, $nombre)) {
                return $tipo;
            }
        }
        return '';
    }

    private static function getFirmaActiva(string $tipodoc, int $idDoc): ?FirmaDoc
    {
        $model = new FirmaDoc();
        $lista = $model->all([
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('tipo_doc', $tipodoc),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('id_doc', $idDoc),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('estado', FirmaDoc::ESTADO_CANCELADO, '!='),
        ], ['fecha_envio' => 'DESC'], 0, 1);
        return empty($lista) ? null : $lista[0];
    }

    public function apply(SalesDocument &$model, array $formData): void
    {
    }

    public function applyBefore(SalesDocument &$model, array $formData): void
    {
    }

    public function assets(): void
    {
    }
}
