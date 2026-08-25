<?php
/**
 * This file is part of FirmaDoc plugin for FacturaScripts.
 *
 * @author    Francisco José Matías Olivares <fmatias@creativoz.com>
 * @copyright 2025-2026 Francisco José Matías Olivares
 * @license   Acuerdo de Licencia de Usuario Final (EULA) — véase archivo LICENSE
 * @version   1.6
 * @link      https://creativoz.com
 */
namespace FacturaScripts\Plugins\FirmaDoc\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\MiniLog;
use FacturaScripts\Core\Tools;

/**
 * Trabajo que se hace después de contestar al navegador.
 *
 * Abrir una sesión SMTP cuesta más de medio segundo —saludo, EHLO, STARTTLS y
 * autenticación— y se paga entera por cada destinatario, así que mandar dos enlaces
 * de firma deja la pantalla parada más de un segundo antes de pintar nada. El
 * documento no tiene la culpa: generarlo y convertirlo son milisegundos.
 *
 * Aquí la respuesta se cierra primero y el correo sale después, con el navegador ya
 * libre. No se usa la cola de trabajos del núcleo a propósito: esa la vacía el cron, y
 * en una instalación donde el cron no esté puesto —que son muchas— los enlaces no
 * saldrían nunca. Esto no depende de nada externo.
 *
 * Si el servidor no permite cerrar la respuesta por su cuenta, el trabajo se hace igual
 * al final de la petición: nunca se pierde, como mucho no se gana tiempo.
 */
class FirmaDocDiferido
{
    /** @var callable[] */
    private static $tareas = [];

    /** @var bool */
    private static $registrado = false;

    /**
     * Apunta un trabajo para después de contestar.
     */
    public static function tras(callable $tarea): void
    {
        self::$tareas[] = $tarea;

        if (false === self::$registrado) {
            self::$registrado = true;
            register_shutdown_function([self::class, 'ejecutar']);
        }
    }

    /**
     * Si el servidor sabe cerrar la respuesta y seguir trabajando.
     */
    public static function sePuede(): bool
    {
        return function_exists('fastcgi_finish_request')
            || function_exists('litespeed_finish_request');
    }

    /**
     * Cierra la respuesta y hace lo apuntado. La llama PHP al terminar la petición.
     */
    public static function ejecutar(): void
    {
        if (empty(self::$tareas)) {
            return;
        }

        $tareas = self::$tareas;
        self::$tareas = [];

        // Que cerrar el navegador no deje el correo a medias
        @ignore_user_abort(true);

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }

        // index.php cierra la base de datos en cuanto el controlador termina, y esto
        // corre después: hay que volver a abrirla o no se podría anotar el envío.
        $db = new DataBase();
        $abiertaAqui = false;
        if (false === $db->connected()) {
            $abiertaAqui = $db->connect();
        }

        foreach ($tareas as $tarea) {
            try {
                $tarea();
            } catch (\Throwable $e) {
                // Ya no hay pantalla donde avisar: queda en el registro del ERP
                Tools::log()->error('FirmaDoc: ' . $e->getMessage());
            }
        }

        if ($abiertaAqui) {
            // Los avisos de esta parte se guardan aquí: los de la petición ya se
            // escribieron antes de que empezara
            MiniLog::save();
            $db->close();
        }
    }
}
