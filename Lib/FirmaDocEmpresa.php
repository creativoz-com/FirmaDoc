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

use FacturaScripts\Core\Model\AttachedFile;
use FacturaScripts\Core\Model\Empresa;
use FacturaScripts\Core\Tools;

/**
 * Datos de la empresa que emite el documento, para poder identificarla ante el firmante.
 *
 * Quien recibe un enlace de firma por email o WhatsApp tiene que ver de quién viene. Sin
 * nombre ni logo, la página es indistinguible de un intento de phishing y el cliente no firma.
 */
class FirmaDocEmpresa
{
    /** @var Empresa[] Empresas ya cargadas en esta petición, por id */
    private static $cache = [];

    /**
     * Empresa que emite el documento: la del propio documento y, si no se conoce,
     * la empresa por defecto de la instalación. Nunca la número 1 a pelo.
     */
    public static function get($mainModel = null): ?Empresa
    {
        $id = null;
        if ($mainModel !== null && !empty($mainModel->idempresa)) {
            $id = (int) $mainModel->idempresa;
        }
        if (empty($id)) {
            $id = (int) Tools::settings('default', 'idempresa', 0);
        }
        if (empty($id)) {
            return null;
        }

        if (!isset(self::$cache[$id])) {
            $empresa = new Empresa();
            self::$cache[$id] = $empresa->loadFromCode($id) ? $empresa : false;
        }

        return self::$cache[$id] ?: null;
    }

    public static function nombre($mainModel = null): string
    {
        $empresa = self::get($mainModel);
        return $empresa ? (string) $empresa->nombre : '';
    }

    /**
     * URL absoluta del logo de la empresa, o cadena vacía si no tiene.
     *
     * Se usa el token permanente de AttachedFile: la pantalla de firma es pública y
     * el firmante no tiene sesión, así que un token de descarga normal no le valdría.
     */
    public static function logoUrl($mainModel = null): string
    {
        $idLogo = self::idLogo($mainModel);
        if (empty($idLogo)) {
            return '';
        }

        $fichero = AttachedFile::find($idLogo);
        if (empty($fichero)) {
            return '';
        }

        $base = FirmaDocUrl::base();
        return empty($base) ? '' : $base . '/' . $fichero->url('download-permanent');
    }

    /**
     * Logo de la empresa que emite el documento, y solo de esa.
     *
     * Deliberadamente NO se cae al logo de la empresa por defecto cuando la del documento
     * no tiene ninguno: en multiempresa son sociedades distintas, y poner el logo de una
     * junto al nombre de otra —en la pantalla que precisamente busca dar confianza al
     * firmante— es peor que no poner ninguno. Si falta, se sube en Empresas → Logotipo.
     */
    private static function idLogo($mainModel = null): ?int
    {
        $empresa = self::get($mainModel);
        return ($empresa !== null && !empty($empresa->idlogo)) ? (int) $empresa->idlogo : null;
    }

    /**
     * Ruta en disco del logo, para incrustarlo en el PDF. Cadena vacía si no hay.
     */
    public static function logoPath($mainModel = null): string
    {
        $idLogo = self::idLogo($mainModel);
        if (empty($idLogo)) {
            return '';
        }

        $fichero = AttachedFile::find($idLogo);
        if (empty($fichero) || empty($fichero->path)) {
            return '';
        }

        $ruta = FS_FOLDER . '/' . $fichero->path;
        return is_readable($ruta) ? $ruta : '';
    }
}
