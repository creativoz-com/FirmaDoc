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
namespace FacturaScripts\Plugins\FirmaDoc\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

class FirmaDocConfig extends ModelClass
{
    use ModelTrait;

    // Valores para nif_modo y cargo_modo
    const CAMPO_NO       = 'no';
    const CAMPO_OPCIONAL = 'opcional';
    const CAMPO_OBLIGATORIO = 'obligatorio';

    // Valores para descarga_pdf
    const DESCARGA_SIEMPRE   = 'siempre';
    const DESCARGA_POSFIRMA  = 'posfirma';
    const DESCARGA_NO        = 'no';

    /** @var int */
    public $id;

    /** @var int Días de validez del link (por defecto 15) */
    public $dias_validez;

    /** @var bool Sistema de recordatorios activado */
    public $recordatorios_activo;

    /** @var string|null Días antes de expiración para enviar recordatorio (ej: "7,3,1") */
    public $recordatorio_dias;

    /** @var string Cuándo se permite descargar el PDF */
    public $descarga_pdf;

    /** @var bool Modo firma manuscrita activo */
    public $modo_manuscrita;

    /** @var bool Modo firma tipográfica activo */
    public $modo_tipografica;

    /** @var bool Modo certificado digital activo */
    public $modo_certificado;

    /** @var string Modo del campo NIF: no, opcional, obligatorio */
    public $nif_modo;

    /** @var string Modo del campo cargo: no, opcional, obligatorio */
    public $cargo_modo;

    /** @var bool Enviar notificación email a la empresa al firmar */
    public $notif_empresa;

    /** @var string|null Email adicional de notificación */
    public $email_adicional;

    /** @var bool Mostrar logo de empresa en página de firma */
    public $mostrar_logo;

    /** @var string|null URL al aviso legal / términos */
    public $legal_url;

    /** @var string|null Texto legal en formato HTML (RichText) */
    public $legal_texto;

    /** @var string Asunto del email al cliente */
    public $email_asunto;

    /** @var string|null Cuerpo del email al cliente (HTML) */
    public $email_cuerpo;

    /** @var string|null Mensaje de WhatsApp al cliente */
    public $whatsapp_mensaje;

    /** @var string|null Asunto del email de confirmación (todos firmaron) */
    public $confirm_asunto;

    /** @var string|null Cuerpo del email de confirmación */
    public $confirm_cuerpo;

    // ── Tipos de documento activos ─────────────────────────────────────────
    /** @var bool FirmaDoc activo para Presupuestos de cliente */
    public $doc_presupuesto;

    /** @var bool FirmaDoc activo para Albaranes de cliente */
    public $doc_albaran;

    /** @var bool FirmaDoc activo para Facturas de cliente */
    public $doc_factura;

    /** @var bool FirmaDoc activo para Pedidos de cliente */
    public $doc_pedido;

    public static function tableName(): string
    {
        return 'firmadoc_config';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function clear(): void
    {
        parent::clear();
        $this->dias_validez     = 15;
        $this->recordatorios_activo = false;
        $this->recordatorio_dias    = '7,3,1';
        $this->descarga_pdf     = self::DESCARGA_SIEMPRE;
        $this->modo_manuscrita  = true;
        $this->modo_tipografica = true;
        $this->modo_certificado = false;
        $this->nif_modo         = self::CAMPO_OPCIONAL;
        $this->cargo_modo       = self::CAMPO_OPCIONAL;
        $this->notif_empresa    = true;
        $this->mostrar_logo     = true;
        $this->email_asunto     = 'Pendiente firmar {{tipo_doc}}: {{codigo_doc}} de {{empresa}}';
        $this->email_cuerpo     = $this->getEmailPorDefecto();
        $this->whatsapp_mensaje = $this->getWhatsAppPorDefecto();
        $this->confirm_asunto   = '{{empresa}} Documento firmado';
        $this->confirm_cuerpo   = $this->getConfirmPorDefecto();

        // Tipos de documento — todos activos por defecto
        $this->doc_presupuesto = true;
        $this->doc_albaran     = true;
        $this->doc_factura     = true;
        $this->doc_pedido      = false;
    }

    /**
     * Devuelve la configuración activa (siempre hay una sola fila)
     * Si no existe, la crea con valores por defecto
     */
    public static function getConfig(): self
    {
        $config = new self();
        $lista  = $config->all([], [], 0, 1);

        if (empty($lista)) {
            $config->clear();
            $config->save();
            return $config;
        }

        $cfg = $lista[0];
        // Sanear textos que pudieron guardarse con escapes Unicode tipo \u00fa (PHP no los interpreta)
        foreach (['email_asunto', 'email_cuerpo', 'whatsapp_mensaje'] as $campo) {
            if (!empty($cfg->$campo) && strpos($cfg->$campo, '\u') !== false) {
                $cfg->$campo = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($m) {
                    return mb_convert_encoding(pack('H*', $m[1]), 'UTF-8', 'UCS-2BE');
                }, $cfg->$campo);
                $cfg->save();
            }
        }
        return $cfg;
    }

    /**
     * Devuelve los modos de firma activos como array
     */
    public function getModosFirmaActivos(): array
    {
        $modos = [];
        if ($this->modo_manuscrita)  $modos[] = 'manuscrita';
        if ($this->modo_tipografica) $modos[] = 'tipografica';
        if ($this->modo_certificado) $modos[] = 'certificado';
        return $modos;
    }

    /**
     * Si certificado digital está activo, el NIF siempre es obligatorio
     */
    public function getNifModoEfectivo(string $modoFirma): string
    {
        if ($modoFirma === 'certificado') {
            return self::CAMPO_OBLIGATORIO;
        }
        return $this->nif_modo;
    }

    /**
     * Devuelve los días de recordatorio como array de enteros ordenados DESC.
     * Ej: "7,3,1" → [7, 3, 1]
     */
    public function getDiasRecordatorio(): array
    {
        if (empty($this->recordatorio_dias)) {
            return [];
        }
        $dias = array_map('intval', explode(',', $this->recordatorio_dias));
        $dias = array_filter($dias, fn($d) => $d > 0);
        rsort($dias);
        return array_values($dias);
    }

    /**
     * Reemplaza variables en el asunto/cuerpo del email o mensaje WhatsApp
     */
    public function reemplazarVariables(string $texto, array $datos): string
    {
        $variables = [
            '{{cliente}}'          => $datos['cliente']          ?? '',
            '{{empresa}}'          => $datos['empresa']          ?? '',
            '{{tipo_doc}}'         => $datos['tipo_doc']         ?? '',
            '{{codigo_doc}}'       => $datos['codigo_doc']       ?? '',
            '{{link_firma}}'       => $datos['link_firma']       ?? '',
            '{{link_documento}}'   => $datos['link_documento']   ?? '',
            '{{fecha_expiracion}}' => $datos['fecha_expiracion'] ?? '',
            '{{dias_restantes}}'   => $datos['dias_restantes']   ?? '',
            '{{importe}}'          => $datos['importe']          ?? '',
        ];

        return str_replace(
            array_keys($variables),
            array_values($variables),
            $texto
        );
    }

    /**
     * Comprueba si hay al menos un modo de firma activo
     */
    public function test(): bool
    {
        if (!$this->modo_manuscrita && !$this->modo_tipografica && !$this->modo_certificado) {
            \FacturaScripts\Core\Tools::log()->error('FirmaDoc: debe haber al menos un modo de firma activo.');
            return false;
        }
        if ($this->dias_validez < 1 || $this->dias_validez > 365) {
            \FacturaScripts\Core\Tools::log()->error('FirmaDoc: los días de validez deben estar entre 1 y 365.');
            return false;
        }
        return parent::test();
    }

    private function getEmailPorDefecto(): string
    {
        return 'Hola {{cliente}},' . "\n\n"
            . 'Te enviamos el documento {{tipo_doc}} Núm. {{codigo_doc}} para que lo revises y firmes.' . "\n\n"
            . 'Accede desde el siguiente enlace:' . "\n"
            . '{{link_firma}}' . "\n\n"
            . 'El enlace estará disponible hasta el {{fecha_expiracion}}.' . "\n\n"
            . 'Un saludo,' . "\n"
            . '{{empresa}}';
    }

    public function getConfirmPorDefecto(): string
    {
        return 'Hola {{cliente}},' . "\n\n"
            . 'Puede acceder al documento firmado pulsando en el siguiente enlace:' . "\n"
            . '{{link_documento}}' . "\n\n"
            . 'Saludos cordiales.';
    }

    private function getWhatsAppPorDefecto(): string
    {
        return 'Hola {{cliente}},' . "\n\n"
            . 'Te enviamos el {{tipo_doc}} Núm. {{codigo_doc}} de {{empresa}} para que lo revises y firmes.' . "\n\n"
            . 'Accede aqui: {{link_firma}}' . "\n\n"
            . 'El enlace caduca el {{fecha_expiracion}}.' . "\n\n"
            . 'Gracias.';
    }
}
