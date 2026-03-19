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
namespace FacturaScripts\Plugins\FirmaDoc\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

class FirmaDoc extends ModelClass
{
    use ModelTrait;

    // Estados posibles del documento
    // Modos multi-firmante
    const MODO_UNICO      = 'unico';       // Un solo firmante (comportamiento actual)
    const MODO_PARALELO   = 'paralelo';    // Varios firmantes, cualquier orden
    const MODO_SECUENCIAL = 'secuencial';  // Varios firmantes, en orden definido

    const ESTADO_PENDIENTE = 'pendiente';
    const ESTADO_FIRMADO   = 'firmado';
    const ESTADO_EXPIRADO  = 'expirado';
    const ESTADO_CANCELADO = 'cancelado';
    const ESTADO_ANULADO_MOD = 'anulado_mod'; // Anulado por modificación del documento

    // Tipos de documento soportados
    const TIPO_FACTURA      = 'factura';
    const TIPO_PRESUPUESTO  = 'presupuesto';
    const TIPO_ALBARAN      = 'albaran';
    const TIPO_PEDIDO       = 'pedido';

    /** @var int Identificador único */
    public $id;

    /** @var string Tipo de documento: factura, presupuesto, albaran, pedido */
    public $tipo_doc;

    /** @var int ID del documento en FacturaScripts */
    public $id_doc;

    /** @var string Código visible del documento (ej: F-2025-001) */
    public $codigo_doc;

    /** @var string Token único SHA256 para el link público */
    public $token;

    /** @var string Email del cliente destinatario */
    public $email_cliente;

    /** @var string Teléfono del cliente para WhatsApp */
    public $telefono_cliente;

    /** @var string Fecha y hora de envío del link */
    public $fecha_envio;

    /** @var string Fecha y hora de expiración del link */
    public $fecha_expiracion;

    /** @var string|null Fecha y hora en que el cliente firmó */
    public $fecha_firma;

    /** @var string|null Imagen de la firma en Base64 */
    public $firma_imagen;

    /** @var string|null Nombre escrito por el cliente al firmar */
    public $firma_nombre;

    /** @var string|null IP desde donde se realizó la firma */
    public $ip_cliente;

    /** @var string|null User agent del navegador del cliente */
    public $user_agent;

    /** @var string Estado actual: pendiente, firmado, expirado, cancelado */
    public $estado;

    /** @var string|null Hash del documento al generar el token (para detectar modificaciones) */
    public $doc_hash;

    /** @var bool Si ya se notificó a la empresa por email */
    public $email_empresa_notificado;

    /** @var int Número de recordatorios enviados al cliente */
    public $recordatorios_enviados;

    /** @var string|null Fecha del último recordatorio enviado (YYYY-MM-DD) */
    public $fecha_ultimo_recordatorio;

    /** @var string|null Fecha en que el cliente abrió el enlace por primera vez */
    public $fecha_primera_apertura;

    /** @var int Número de veces que el cliente ha abierto el enlace */
    public $veces_visto;

    /** @var string|null Motivo de rechazo si el cliente rechazó firmar */
    public $motivo_rechazo;

    /** @var string|null NIF/CIF del firmante */
    public $firma_nif;

    /** @var string|null Cargo del firmante */
    public $firma_cargo;

    /** @var string|null Modo de firma usado: manuscrita, tipografica, certificado */
    public $modo_firma;

    /** @var string|null Observaciones del firmante al firmar */
    public $observaciones_firmante;

    /** @var bool Si el firmante aceptó el aviso legal */
    public $acepto_legal;

    /** @var string|null Fecha en que se aceptó el aviso legal */
    public $fecha_acepto_legal;

    /** @var string|null Datos del certificado digital (JSON con clave pública y firma) */
    public $firma_certificado_data;

    /** @var string Modo multi-firmante: unico, paralelo, secuencial */
    public $modo_multifirma;

    /**
     * Nombre de la tabla en la base de datos
     */
    public static function tableName(): string
    {
        return 'firmadoc';
    }

    /**
     * Clave primaria
     */
    public static function primaryColumn(): string
    {
        return 'id';
    }

    /**
     * Valores por defecto al crear un nuevo registro
     */
    public function clear(): void
    {
        parent::clear();
        $this->estado                   = self::ESTADO_PENDIENTE;
        $this->email_empresa_notificado = false;
        $this->fecha_envio              = date('d-m-Y H:i:s');
    }

    /**
     * Genera un token único SHA256 para el link público
     */
    public function generarToken(): string
    {
        $this->token = hash('sha256', uniqid('firmadoc_', true) . random_bytes(16));
        return $this->token;
    }

    /**
     * Comprueba si el link ha expirado
     */
    public function estaExpirado(): bool
    {
        if (empty($this->fecha_expiracion)) {
            return false;
        }
        $expiracion = \DateTime::createFromFormat('d-m-Y H:i:s', $this->fecha_expiracion);
        if (!$expiracion) {
            return false;
        }
        return time() >= $expiracion->getTimestamp();
    }

    /**
     * Comprueba si el documento ya está firmado
     */
    public function estaFirmado(): bool
    {
        return $this->estado === self::ESTADO_FIRMADO;
    }

    /**
     * Comprueba si el link es válido (no expirado, no firmado, no cancelado)
     */
    public function esValido(): bool
    {
        if ($this->estaFirmado()) {
            return false;
        }
        if ($this->estado === self::ESTADO_CANCELADO) {
            return false;
        }
        if ($this->estaExpirado()) {
            // Actualizamos el estado si ha expirado
            $this->estado = self::ESTADO_EXPIRADO;
            $this->save();
            return false;
        }
        return true;
    }

    /**
     * Registra la firma del cliente
     * Guarda imagen, nombre, IP, user agent y marca como firmado
     */
    public function registrarFirma(string $imagen, string $nombre, string $ip, string $userAgent): bool
    {
        $this->firma_imagen  = $imagen;
        $this->firma_nombre  = $nombre;
        $this->ip_cliente    = $ip;
        $this->user_agent    = $userAgent;
        $this->fecha_firma   = date('d-m-Y H:i:s');
        $this->estado        = self::ESTADO_FIRMADO;

        return $this->save();
    }

    /**
     * Convierte modelClassName a tipo FirmaDoc
     */
    public static function getTipoDesdeClase(string $className): ?string
    {
        $mapa = [
            'FacturaCliente'     => self::TIPO_FACTURA,
            'PresupuestoCliente' => self::TIPO_PRESUPUESTO,
            'AlbaranCliente'     => self::TIPO_ALBARAN,
            'PedidoCliente'      => self::TIPO_PEDIDO,
        ];
        return $mapa[$className] ?? null;
    }

    public static function getTipoDesdeControlador(string $controllerName): ?string
    {
        $mapa = [
            'EditFacturaCliente'     => self::TIPO_FACTURA,
            'EditPresupuestoCliente' => self::TIPO_PRESUPUESTO,
            'EditAlbaranCliente'     => self::TIPO_ALBARAN,
            'EditPedidoCliente'      => self::TIPO_PEDIDO,
        ];
        return $mapa[$controllerName] ?? null;
    }

    /**
     * Busca firma activa (pendiente o firmada) para un documento
     */
    public static function getActivaPorDocumento(string $tipo, int $idDoc): ?self
    {
        $model = new self();
        $where = [
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('tipo_doc', $tipo),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('id_doc', $idDoc),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('estado', self::ESTADO_CANCELADO, '!='),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('estado', self::ESTADO_ANULADO_MOD, '!='),
            new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('estado', self::ESTADO_EXPIRADO, '!='),
        ];
        $lista = $model->all($where, ['fecha_envio' => 'DESC'], 0, 1);
        return empty($lista) ? null : $lista[0];
    }

    /**
     * Calcula un hash del documento para detectar modificaciones posteriores
     */
    public static function calcularHashDoc(object $documento): string
    {
        // Campos de cabecera
        $datos = [
            round((float)($documento->total ?? 0), 4),
            round((float)($documento->neto ?? 0), 4),
            round((float)($documento->totaliva ?? 0), 4),
            round((float)($documento->totalirpf ?? 0), 4),
            round((float)($documento->totalrecargo ?? 0), 4),
            (string)($documento->nombrecliente ?? ''),
            (string)($documento->cifnif ?? ''),
            (string)($documento->direccion ?? ''),
            (string)($documento->observaciones ?? ''),
        ];
        // Incluir líneas: descripción, cantidad, precio y descuento
        if (method_exists($documento, 'getLines')) {
            foreach ($documento->getLines() as $linea) {
                $datos[] = (string)($linea->descripcion ?? '');
                $datos[] = round((float)($linea->cantidad ?? 0), 4);
                $datos[] = round((float)($linea->pvpunitario ?? 0), 4);
                $datos[] = round((float)($linea->dtopor ?? 0), 4);
            }
        }
        return md5(json_encode($datos));
    }

    /**
     * Anula la firma por modificación del documento
     */
    public function anularPorModificacion(): bool
    {
        $this->estado = self::ESTADO_ANULADO_MOD;
        return $this->save();
    }

    /**
     * Busca un registro por su token
     */
    public static function getByToken(string $token): ?self
    {
        $model = new self();
        $lista = $model->all(
            [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('token', $token)],
            [],
            0,
            1
        );
        return empty($lista) ? null : $lista[0];
    }

    /**
     * Devuelve todos los documentos pendientes de firma
     */
    public static function getPendientes(): array
    {
        $model = new self();
        return $model->all(
            [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere('estado', self::ESTADO_PENDIENTE)],
            ['fecha_envio' => 'DESC']
        );
    }

    /**
     * Validaciones antes de guardar
     */
    public function test(): bool
    {
        if (empty($this->tipo_doc)) {
            \FacturaScripts\Core\Tools::log()->error('FirmaDoc: tipo_doc es obligatorio');
            return false;
        }
        if (empty($this->id_doc)) {
            \FacturaScripts\Core\Tools::log()->error('FirmaDoc: id_doc es obligatorio');
            return false;
        }
        if (empty($this->token)) {
            $this->generarToken();
        }
        return parent::test();
    }
}
