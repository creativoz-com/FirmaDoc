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
use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;

/**
 * Registro histórico de envíos y reenvíos de solicitudes de firma.
 */
class FirmaDocReenvio extends ModelClass
{
    use ModelTrait;

    const TIPO_INICIAL    = 'inicial';
    const TIPO_REENVIO    = 'reenvio';
    const TIPO_SECUENCIAL = 'secuencial';

    const CANAL_EMAIL    = 'email';
    const CANAL_WHATSAPP = 'whatsapp';

    /** @var int */
    public $id;

    /** @var int */
    public $id_firmadoc;

    /** @var int|null */
    public $id_firmante;

    /** @var string */
    public $email_destino;

    /** @var string|null */
    public $nombre_destino;

    /** @var string inicial|reenvio|secuencial */
    public $tipo;

    /** @var string Fecha y hora del envío */
    public $fecha;

    /** @var string email|whatsapp */
    public $canal;

    /** @var string|null Usuario FS que hizo el reenvío manual */
    public $nick;

    public static function tableName(): string
    {
        return 'firmadoc_reenvio';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function clear(): void
    {
        parent::clear();
        $this->tipo  = self::TIPO_INICIAL;
        $this->canal = self::CANAL_EMAIL;
        $this->fecha = date('Y-m-d H:i:s');
    }

    /**
     * Registra un envío/reenvío y lo guarda en BD.
     */
    public static function registrar(
        int $idFirmadoc,
        string $emailDestino,
        string $tipo = self::TIPO_INICIAL,
        string $canal = self::CANAL_EMAIL,
        ?int $idFirmante = null,
        ?string $nombreDestino = null,
        ?string $nick = null
    ): void {
        $r = new self();
        $r->id_firmadoc    = $idFirmadoc;
        $r->id_firmante    = $idFirmante;
        $r->email_destino  = $emailDestino;
        $r->nombre_destino = $nombreDestino;
        $r->tipo           = $tipo;
        $r->canal          = $canal;
        $r->nick           = $nick;
        $r->fecha          = date('Y-m-d H:i:s');
        $r->save();
    }

    /**
     * Devuelve todos los reenvíos de una solicitud, del más reciente al más antiguo.
     */
    public static function porSolicitud(int $idFirmadoc): array
    {
        $r = new self();
        return $r->all(
            [new DataBaseWhere('id_firmadoc', $idFirmadoc)],
            ['fecha' => 'DESC'],
            0, 50
        );
    }
}
