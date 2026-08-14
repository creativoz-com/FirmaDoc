<?php
/**
 * This file is part of FirmaDoc plugin for FacturaScripts.
 *
 * @author    Francisco José Matías Olivares <fmatias@creativoz.com>
 * @copyright 2025-2026 Francisco José Matías Olivares
 * @license   Acuerdo de Licencia de Usuario Final (EULA) — véase archivo LICENSE
 * @version   1.2
 * @link      https://creativoz.com
 */
namespace FacturaScripts\Plugins\FirmaDoc\Lib;

use FacturaScripts\Core\Tools;

/**
 * Resuelve la URL base de la instalación.
 *
 * Existe porque calcularla con $_SERVER['HTTP_HOST'] falla justo donde más duele:
 * el cron corre por CLI, ahí no hay HTTP_HOST, y los enlaces de los recordatorios
 * salían apuntando a «localhost». Aquí manda siempre la URL configurada en
 * Panel de control → site_url, y $_SERVER solo se usa como último recurso.
 */
class FirmaDocUrl
{
    public static function base(): string
    {
        // 1. La URL que el usuario ha configurado en FacturaScripts. Es la única
        //    que funciona igual desde web y desde CLI.
        $configurada = Tools::settings('default', 'site_url', '');
        if (!empty($configurada)) {
            return rtrim($configurada, '/');
        }

        // 2. Variable de entorno, si la instalación la define.
        $entorno = Tools::env('FS_SITE_URL', '');
        if (!empty($entorno)) {
            return rtrim($entorno, '/');
        }

        // 3. Petición web en curso.
        if (!empty($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $subdir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
            return rtrim($scheme . '://' . $_SERVER['HTTP_HOST'] . $subdir, '/');
        }

        // 4. Sin nada de lo anterior no se puede construir un enlace utilizable.
        //    Se devuelve vacío a propósito: quien llama debe avisar en vez de
        //    enviar al cliente un enlace roto a localhost.
        return '';
    }

    /**
     * Enlace público de firma para un token.
     */
    public static function firma(string $token): string
    {
        $base = self::base();
        return empty($base) ? '' : $base . '/FirmaDocPublic?token=' . $token;
    }

    /**
     * Enlace público de verificación a partir del código de verificación.
     */
    public static function verificacion(string $codigo): string
    {
        $base = self::base();
        return empty($base) ? '' : $base . '/FirmaDocVerify?codigo=' . $codigo;
    }
}
