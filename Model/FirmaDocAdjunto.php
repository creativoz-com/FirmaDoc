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
namespace FacturaScripts\Plugins\FirmaDoc\Model;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\AttachedFile;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

/**
 * Cada fichero que acompaña a una solicitud de firma.
 *
 * Hay dos clases, y la diferencia es jurídica, no de presentación:
 *  - FIRMAR: el firmante los acepta. Entran en la huella del conjunto y se enumeran
 *    en el certificado, uno a uno con su propia huella.
 *  - ANEXO: solo se envían para que los consulte —condiciones, planos, un informe—.
 *    No se firman ni alteran la huella, y el certificado no los presenta como firmados.
 */
class FirmaDocAdjunto extends ModelClass
{
    use ModelTrait;

    const TIPO_FIRMAR = 'firmar';
    const TIPO_ANEXO  = 'anexo';

    /** @var int */
    public $id;

    /** @var int Solicitud a la que pertenece */
    public $id_firmadoc;

    /** @var int Fichero en AttachedFile */
    public $idfile;

    /** @var int Orden de presentación */
    public $orden;

    /** @var string firmar | anexo */
    public $tipo;

    /** @var string|null Nombre con el que se muestra */
    public $nombre;

    /** @var string|null SHA-256 del fichero */
    public $doc_hash;

    public static function tableName(): string
    {
        return 'firmadoc_documento';
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function clear(): void
    {
        parent::clear();
        $this->orden = 1;
        $this->tipo = self::TIPO_FIRMAR;
    }

    public function esFirmable(): bool
    {
        return $this->tipo === self::TIPO_FIRMAR;
    }

    public function getFichero(): ?AttachedFile
    {
        return empty($this->idfile) ? null : AttachedFile::find($this->idfile);
    }

    /**
     * Ruta en disco, o cadena vacía si el fichero ya no está.
     */
    public function getRuta(): string
    {
        $fichero = $this->getFichero();
        if (null === $fichero || empty($fichero->path)) {
            return '';
        }

        $ruta = FS_FOLDER . '/' . $fichero->path;
        return is_readable($ruta) ? $ruta : '';
    }

    public function getNombre(): string
    {
        $fichero = $this->getFichero();
        $real = $fichero ? (string) $fichero->filename : '';

        if (empty($this->nombre)) {
            return $real;
        }

        // El nombre que puso el usuario suele venir sin extensión («Contrato marco»).
        // Dentro del ZIP eso deja ficheros que el sistema no sabe abrir, así que se
        // le añade la del fichero real.
        if (pathinfo($this->nombre, PATHINFO_EXTENSION) !== '') {
            return $this->nombre;
        }

        $extension = pathinfo($real, PATHINFO_EXTENSION);
        return $extension === '' ? $this->nombre : $this->nombre . '.' . $extension;
    }

    /**
     * @return self[] Todos los adjuntos de una solicitud, en orden
     */
    public static function porSolicitud(int $idFirmadoc, string $tipo = ''): array
    {
        $where = [new DataBaseWhere('id_firmadoc', $idFirmadoc)];
        if ($tipo !== '') {
            $where[] = new DataBaseWhere('tipo', $tipo);
        }

        $model = new self();
        return $model->all($where, ['tipo' => 'ASC', 'orden' => 'ASC'], 0, 100);
    }

    public function test(): bool
    {
        if (!in_array($this->tipo, [self::TIPO_FIRMAR, self::TIPO_ANEXO], true)) {
            $this->tipo = self::TIPO_FIRMAR;
        }
        return parent::test();
    }
}
