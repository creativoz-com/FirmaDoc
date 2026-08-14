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

use FacturaScripts\Core\Lib\Email\HtmlBlock;
use FacturaScripts\Core\Lib\Email\NewMail;
use FacturaScripts\Core\Lib\Email\TextBlock;
use FacturaScripts\Core\Tools;

/**
 * Piezas comunes de todos los correos que envía FirmaDoc.
 *
 * Existe porque cada envío se montaba a mano y salía distinto: el enlace aparecía como
 * texto plano que había que copiar y pegar, los saltos de línea se perdían y el logo
 * de la empresa no salía por ninguna parte.
 */
class FirmaDocEmail
{
    /** Verde corporativo del plugin, el mismo de la pantalla de firma */
    const COLOR = '#1a7a4a';

    /**
     * Monta el cuerpo completo de un correo: logo, texto y botón de acción.
     *
     * @param NewMail $mail
     * @param string $cuerpo Texto de la plantilla, con las variables ya sustituidas
     * @param string $enlace URL del botón; si va vacía no se pinta botón
     * @param string $etiqueta Texto del botón
     * @param object|null $documento Documento, para saber de qué empresa es el logo
     */
    public static function montar(
        NewMail $mail,
        string $cuerpo,
        string $enlace = '',
        string $etiqueta = '',
        $documento = null
    ): void {
        $logo = self::logo($documento);
        if ($logo !== null) {
            $mail->addMainBlock($logo);
        }

        $mail->addMainBlock(self::bloqueTexto($cuerpo, $enlace));

        if (!empty($enlace)) {
            $mail->addMainBlock(self::boton($enlace, $etiqueta));
        }
    }

    /**
     * Logo de la empresa emisora.
     *
     * FacturaScripts pinta su propio logo en la cabecera del correo solo si el usuario
     * ha configurado uno en Panel de control → Email. Cuando no lo ha hecho, el correo
     * sale sin ninguna identificación, así que se usa el logo de la empresa.
     */
    public static function logo($documento = null): ?HtmlBlock
    {
        // Si ya hay logo configurado para el correo, lo pone el núcleo: no duplicar
        if (!empty(Tools::settings('email', 'idlogo'))) {
            return null;
        }

        $url = FirmaDocEmpresa::logoUrl($documento);
        if (empty($url)) {
            return null;
        }

        return new HtmlBlock(
            '<div style="text-align:center;padding:8px 0 16px;">'
            . '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '"'
            . ' alt="' . htmlspecialchars(FirmaDocEmpresa::nombre($documento), ENT_QUOTES) . '"'
            . ' style="max-height:60px;max-width:240px;">'
            . '</div>'
        );
    }

    /**
     * Cuerpo del mensaje.
     *
     * Las plantillas que trae el plugin son texto plano: sin esto los saltos de línea
     * se perdían y la URL del enlace salía como texto suelto, imposible de pulsar.
     * Si el usuario ha escrito HTML en su plantilla, se respeta tal cual.
     */
    private static function bloqueTexto(string $cuerpo, string $enlace = ''): TextBlock
    {
        $esHtml = $cuerpo !== strip_tags($cuerpo);

        if (!$esHtml) {
            $cuerpo = nl2br(htmlspecialchars($cuerpo, ENT_QUOTES, 'UTF-8'), false);
        }

        // La URL suelta se convierte en enlace pulsable. Se hace también sobre las
        // plantillas HTML, saltándose las que ya están dentro de un href.
        $cuerpo = preg_replace_callback(
            '#(?<!href=")(?<!href=\')(https?://[^\s<"\']+)#',
            fn($m) => '<a href="' . $m[1] . '" style="color:' . self::COLOR . ';">' . $m[1] . '</a>',
            $cuerpo
        );

        return new TextBlock($cuerpo);
    }

    /**
     * Botón de acción. Se escribe con estilos en línea y una tabla porque es lo único
     * que pintan igual Outlook, Gmail y el resto de clientes de correo.
     */
    public static function boton(string $enlace, string $etiqueta): HtmlBlock
    {
        if (empty($etiqueta)) {
            $etiqueta = Tools::lang()->trans('firmadoc-email-sign-now');
        }

        return new HtmlBlock(
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
            . ' style="margin:22px auto 8px;"><tr><td align="center"'
            . ' style="border-radius:6px;background:' . self::COLOR . ';">'
            . '<a href="' . htmlspecialchars($enlace, ENT_QUOTES) . '"'
            . ' style="display:inline-block;padding:14px 34px;font-family:Arial,sans-serif;'
            . 'font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;">'
            . htmlspecialchars($etiqueta, ENT_QUOTES)
            . '</a></td></tr></table>'
        );
    }
}
