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
use FacturaScripts\Core\Tools;
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

    // ── Verificación en dos pasos y sello de tiempo ────────────────────────
    /** @var bool Exigir código de un solo uso antes de firmar */
    public $otp_activo;

    /** @var int Minutos de validez del código */
    public $otp_minutos;

    /** @var bool Sellar la firma con una autoridad de tiempo (RFC 3161) */
    public $sello_activo;

    /** @var string|null URL de la TSA; vacío usa la pública por defecto */
    public $sello_url;

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
        // Las plantillas por defecto salen del fichero de idioma: antes estaban en
        // español fijo y una instalación en inglés recibía correos en español.
        $this->email_asunto     = Tools::lang()->trans('firmadoc-default-email-subject');
        $this->email_cuerpo     = $this->getEmailPorDefecto();
        $this->whatsapp_mensaje = $this->getWhatsAppPorDefecto();
        $this->confirm_asunto   = Tools::lang()->trans('firmadoc-default-confirm-subject');
        $this->confirm_cuerpo   = $this->getConfirmPorDefecto();

        // Tipos de documento — todos activos por defecto
        $this->doc_presupuesto = true;
        $this->doc_albaran     = true;
        $this->doc_factura     = true;
        $this->doc_pedido      = false;

        // Ambas desactivadas por defecto: añaden pasos al firmante y una dependencia
        // externa, así que es el usuario quien decide activarlas.
        $this->otp_activo   = false;
        $this->otp_minutos  = 10;
        $this->sello_activo = false;
    }

    /** @var self|null Config ya cargada en esta petición */
    private static $cache = null;

    /**
     * Devuelve true si FirmaDoc debe actuar sobre este tipo de documento.
     * Se consulta desde las extensiones, no desde Init::init(), para no
     * tocar la base de datos en el arranque de cada petición del ERP.
     */
    public static function estaActivo(string $tipoDoc): bool
    {
        $campo = 'doc_' . $tipoDoc;
        $config = self::getConfig();
        return property_exists($config, $campo) ? (bool)$config->$campo : false;
    }

    /**
     * Devuelve la configuración activa (siempre hay una sola fila)
     * Si no existe, la crea con valores por defecto.
     * El resultado se cachea durante la petición: esto se llama desde varios
     * puntos y antes provocaba un SELECT —y a veces un INSERT o UPDATE— en cada uno.
     */
    public static function getConfig(): self
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $config = new self();
        $lista  = $config->all([], [], 0, 1);

        if (empty($lista)) {
            $config->clear();
            $config->save();
            self::$cache = $config;
            return $config;
        }

        $cfg = $lista[0];
        // Sanear textos que pudieron guardarse con escapes Unicode tipo \u00fa (PHP no los interpreta)
        $saneado = false;
        foreach (['email_asunto', 'email_cuerpo', 'whatsapp_mensaje'] as $campo) {
            if (!empty($cfg->$campo) && strpos($cfg->$campo, '\u') !== false) {
                $cfg->$campo = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($m) {
                    return mb_convert_encoding(pack('H*', $m[1]), 'UTF-8', 'UCS-2BE');
                }, $cfg->$campo);
                $saneado = true;
            }
        }
        if ($saneado) {
            $cfg->save();
        }

        self::$cache = $cfg;
        return $cfg;
    }

    /**
     * Invalida la caché de petición. Llamar tras guardar cambios de configuración.
     */
    public static function limpiarCache(): void
    {
        self::$cache = null;
    }

    public function save(): bool
    {
        $guardado = parent::save();
        if ($guardado) {
            self::limpiarCache();
        }
        return $guardado;
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
            \FacturaScripts\Core\Tools::log()->error(\FacturaScripts\Core\Tools::lang()->trans('firmadoc-config-need-one-mode'));
            return false;
        }
        if ($this->otp_activo && ($this->otp_minutos < 1 || $this->otp_minutos > 120)) {
            \FacturaScripts\Core\Tools::log()->error(\FacturaScripts\Core\Tools::lang()->trans('firmadoc-config-otp-range'));
            return false;
        }
        if ($this->dias_validez < 1 || $this->dias_validez > 365) {
            \FacturaScripts\Core\Tools::log()->error(\FacturaScripts\Core\Tools::lang()->trans('firmadoc-config-days-range'));
            return false;
        }
        return parent::test();
    }

    private function getEmailPorDefecto(): string
    {
        return Tools::lang()->trans('firmadoc-default-email-body');
    }

    public function getConfirmPorDefecto(): string
    {
        return Tools::lang()->trans('firmadoc-default-confirm-body');
    }

    private function getWhatsAppPorDefecto(): string
    {
        return Tools::lang()->trans('firmadoc-default-whatsapp');
    }
}
