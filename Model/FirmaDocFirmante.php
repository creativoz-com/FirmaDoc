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

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

/**
 * Modelo para firmantes individuales en un proceso multi-firmante.
 * Cada registro representa un firmante dentro de una solicitud FirmaDoc.
 */
class FirmaDocFirmante extends ModelClass
{
    use ModelTrait;

    const ESTADO_PENDIENTE  = 'pendiente';
    const ESTADO_FIRMADO    = 'firmado';
    const ESTADO_RECHAZADO  = 'rechazado';
    const ESTADO_EXPIRADO   = 'expirado';
    const ESTADO_ESPERANDO  = 'esperando'; // En cola para modo secuencial

    /** @var int */
    public $id;

    /** @var int ID de la solicitud FirmaDoc padre */
    public $id_firmadoc;

    /** @var int Orden de firma (1=primero, 2=segundo...) — para modo secuencial */
    public $orden;

    /** @var string Token único del firmante para su link de firma */
    public $token;

    /** @var string Email del firmante */
    public $email;

    /** @var string|null Nombre del firmante */
    public $nombre;

    /** @var string|null Teléfono del firmante */
    public $telefono;

    /** @var string|null Nombre de referencia interno (ej: "Director Comercial") */
    public $nombre_referencia;

    /** @var string Estado: pendiente, firmado, rechazado, expirado */
    public $estado;

    /** @var string|null Fecha/hora de envío del email */
    public $fecha_envio;

    /** @var string|null Fecha/hora de firma */
    public $fecha_firma;

    /** @var string|null Nombre completo del firmante */
    public $firma_nombre;

    /** @var string|null NIF/CIF del firmante */
    public $firma_nif;

    /** @var string|null Cargo del firmante */
    public $firma_cargo;

    /** @var string|null Base64 de la imagen de firma */
    public $firma_imagen;

    /** @var string|null Modo usado: manuscrita, tipografica, certificado */
    public $modo_firma;

    /** @var string|null JSON con datos del certificado X.509 */
    public $firma_certificado_data;

    /** @var string|null IP del firmante */
    public $ip_cliente;

    /** @var string|null User agent del firmante */
    public $user_agent;

    /** @var string|null Hash MD5 del documento en el momento de firma */
    public $doc_hash;

    /** @var string|null Observaciones del firmante */
    public $observaciones;

    /** @var string|null Motivo de rechazo */
    public $motivo_rechazo;

    /** @var int Número de recordatorios enviados */
    public $recordatorios_enviados;

    /** @var string|null Fecha del último recordatorio */
    public $fecha_ultimo_recordatorio;

    /** @var int Número de veces que el firmante abrió el enlace */
    public $veces_visto;

    /** @var string|null Fecha en que el firmante abrió el enlace por primera vez */
    public $fecha_primera_apertura;

    public static function tableName(): string
    {
        return 'firmadoc_firmante';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function clear(): void
    {
        parent::clear();
        $this->orden                  = 1;
        $this->estado                 = self::ESTADO_PENDIENTE;
        $this->recordatorios_enviados = 0;
        $this->veces_visto            = 0;
    }

    /**
     * Genera un token único para este firmante.
     */
    public function generarToken(): void
    {
        $this->token = bin2hex(random_bytes(32));
    }

    /**
     * Devuelve todos los firmantes de una solicitud FirmaDoc, ordenados.
     */
    public static function porSolicitud(int $idFirmadoc): array
    {
        $firmante = new self();
        return $firmante->all(
            [new DataBaseWhere('id_firmadoc', $idFirmadoc)],
            ['orden' => 'ASC'],
            0, 50
        );
    }

    /**
     * Busca un firmante por token.
     */
    public static function getByToken(string $token): ?self
    {
        $firmante = new self();
        $lista = $firmante->all(
            [new DataBaseWhere('token', $token)],
            [], 0, 1
        );
        return $lista[0] ?? null;
    }

    /**
     * Indica si todos los firmantes de una solicitud han firmado.
     */
    public static function todosFirmaron(int $idFirmadoc): bool
    {
        $firmantes = self::porSolicitud($idFirmadoc);
        if (empty($firmantes)) {
            return false;
        }
        foreach ($firmantes as $f) {
            if ($f->estado !== self::ESTADO_FIRMADO) {
                return false;
            }
        }
        return true;
    }

    /**
     * En modo secuencial: devuelve el siguiente firmante en estado "esperando".
     */
    public static function siguienteEsperando(int $idFirmadoc): ?self
    {
        $firmantes = self::porSolicitud($idFirmadoc);
        foreach ($firmantes as $f) {
            if ($f->estado === self::ESTADO_ESPERANDO) {
                return $f;
            }
        }
        return null;
    }

    public function url(string $type = 'auto', string $list = ''): string
    {
        return 'FirmaDocPublic?token=' . $this->token;
    }
}
