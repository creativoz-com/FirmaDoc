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

use FacturaScripts\Core\Lib\Email\NewMail;
use FacturaScripts\Core\Tools;

/**
 * Verificación en dos pasos antes de firmar.
 *
 * Sin esto, quien recibe el enlace reenviado firma. El código de un solo uso va a la
 * dirección que la empresa registró para el firmante —no a una que el visitante pueda
 * escribir—, así que acredita que quien firma tiene acceso a ese buzón. Es lo que
 * convierte la firma en algo defendible frente a un «yo no fui».
 */
class FirmaDocOtp
{
    /** Intentos permitidos antes de invalidar el código */
    const MAX_INTENTOS = 5;

    /** Longitud del código */
    const DIGITOS = 6;

    /**
     * Genera un código nuevo, lo guarda en el registro y lo envía por email.
     *
     * @param object $registro FirmaDoc o FirmaDocFirmante
     * @param string $destino Dirección a la que enviarlo
     * @param int $minutos Validez del código
     * @param object|null $documento Para el logo y el nombre de la empresa
     */
    public static function enviar(object $registro, string $destino, int $minutos, $documento = null): bool
    {
        if (empty($destino)) {
            return false;
        }

        $mail = new NewMail();
        if (!$mail->canSendMail()) {
            Tools::log()->warning(Tools::lang()->trans('firmadoc-no-email-setup'));
            return false;
        }

        $codigo = self::generarCodigo();

        // Se guarda con hash: si alguien accede a la base de datos no puede firmar
        // por otro. Al comprobar se vuelve a hashear y se comparan.
        $registro->otp_codigo = password_hash($codigo, PASSWORD_DEFAULT);
        $registro->otp_expira = date('d-m-Y H:i:s', strtotime('+' . max(1, $minutos) . ' minutes'));
        $registro->otp_intentos = 0;
        $registro->otp_verificado = false;
        if (property_exists($registro, 'otp_enviado_a')) {
            $registro->otp_enviado_a = $destino;
        }
        if (!$registro->save()) {
            Tools::log()->error(Tools::lang()->trans('firmadoc-otp-save-failed'));
            return false;
        }

        $cuerpo = Tools::lang()->trans('firmadoc-otp-email-body', [
            '%code%' => $codigo,
            '%minutes%' => $minutos,
        ]);

        $mail->addAddress($destino);
        $mail->title = Tools::lang()->trans('firmadoc-otp-email-subject');
        FirmaDocEmail::montar($mail, $cuerpo, '', '', $documento);
        $mail->send();

        Tools::log()->info(Tools::lang()->trans('firmadoc-otp-sent', ['%email%' => self::ocultar($destino)]));
        return true;
    }

    /**
     * Comprueba el código introducido.
     *
     * @return string '' si es correcto, o la clave de idioma del error
     */
    public static function comprobar(object $registro, string $introducido): string
    {
        if (empty($registro->otp_codigo)) {
            return 'firmadoc-otp-not-sent';
        }

        if ((int) $registro->otp_intentos >= self::MAX_INTENTOS) {
            return 'firmadoc-otp-too-many';
        }

        if (!empty($registro->otp_expira)) {
            $expira = \DateTime::createFromFormat('d-m-Y H:i:s', $registro->otp_expira);
            if ($expira && time() >= $expira->getTimestamp()) {
                return 'firmadoc-otp-expired';
            }
        }

        $introducido = preg_replace('/[^0-9]/', '', $introducido);
        if (!password_verify($introducido, $registro->otp_codigo)) {
            $registro->otp_intentos = (int) $registro->otp_intentos + 1;
            $registro->save();
            return 'firmadoc-otp-wrong';
        }

        // Válido: se marca y se invalida el código para que no se pueda reutilizar
        $registro->otp_verificado = true;
        $registro->otp_codigo = null;
        $registro->save();
        return '';
    }

    /**
     * Devuelve true si este registro ya pasó la verificación.
     */
    public static function verificado(object $registro): bool
    {
        return !empty($registro->otp_verificado);
    }

    private static function generarCodigo(): string
    {
        $codigo = '';
        for ($i = 0; $i < self::DIGITOS; $i++) {
            $codigo .= (string) random_int(0, 9);
        }
        return $codigo;
    }

    /**
     * «ana.ruiz@ejemplo.com» → «an•••••z@ejemplo.com», para poder decir a dónde se
     * envió el código sin publicar la dirección entera en una pantalla pública.
     */
    public static function ocultar(string $email): string
    {
        $partes = explode('@', $email);
        if (count($partes) !== 2) {
            return '•••';
        }

        $usuario = $partes[0];
        $largo = mb_strlen($usuario);
        if ($largo <= 3) {
            $visible = mb_substr($usuario, 0, 1) . str_repeat('•', max(1, $largo - 1));
        } else {
            $visible = mb_substr($usuario, 0, 2) . str_repeat('•', $largo - 3) . mb_substr($usuario, -1);
        }

        return $visible . '@' . $partes[1];
    }
}
