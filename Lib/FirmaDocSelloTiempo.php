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

use FacturaScripts\Core\Tools;

/**
 * Sello de tiempo contra una autoridad TSA (RFC 3161).
 *
 * Sin esto, la fecha de la firma es «lo que decía el reloj del servidor del emisor»,
 * que es exactamente la parte que discute quien niega haber firmado. Con el sello, la
 * fecha la certifica un tercero independiente sobre la huella del documento firmado.
 *
 * La petición y la respuesta son ASN.1/DER. Aquí se construye la petición a mano —es
 * una estructura corta y fija salvo la huella— y de la respuesta solo se extrae la
 * fecha certificada; el token completo se guarda tal cual para poder validarlo después
 * con herramientas estándar (`openssl ts -verify`).
 */
class FirmaDocSelloTiempo
{
    /** TSA pública por defecto, gratuita y sin registro */
    const TSA_POR_DEFECTO = 'https://freetsa.org/tsr';

    const TIMEOUT = 10;

    /**
     * Sella la huella indicada.
     *
     * @return array|null ['token' => base64, 'fecha' => 'd-m-Y H:i:s', 'autoridad' => url]
     *                    o null si no se pudo sellar.
     */
    public static function sellar(string $hashHex, string $url = ''): ?array
    {
        $url = $url ?: self::TSA_POR_DEFECTO;

        $binario = @hex2bin($hashHex);
        if ($binario === false || strlen($binario) !== 32) {
            // Solo se sella SHA-256; las firmas antiguas con MD5 no se sellan
            return null;
        }

        $peticion = self::construirPeticion($binario);

        $respuesta = self::enviar($url, $peticion);
        if ($respuesta === null) {
            return null;
        }

        $fecha = self::extraerFecha($respuesta);
        if ($fecha === null) {
            Tools::log()->warning(Tools::lang()->trans('firmadoc-tsa-bad-response'));
            return null;
        }

        return [
            'token' => base64_encode($respuesta),
            'fecha' => $fecha,
            'autoridad' => $url,
        ];
    }

    /**
     * Construye la TimeStampReq del RFC 3161 para un digest SHA-256.
     *
     * Estructura:
     *   SEQUENCE {
     *     INTEGER 1                       -- version
     *     SEQUENCE {                      -- MessageImprint
     *       SEQUENCE { OID sha256, NULL } -- algoritmo
     *       OCTET STRING digest
     *     }
     *     INTEGER nonce
     *     BOOLEAN TRUE                    -- certReq: que devuelva el certificado
     *   }
     */
    private static function construirPeticion(string $digest): string
    {
        // OID 2.16.840.1.101.3.4.2.1 (sha256) + parámetros NULL
        $algoritmo = self::secuencia(
            "\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01" . "\x05\x00"
        );

        $imprint = self::secuencia($algoritmo . "\x04" . self::longitud(32) . $digest);

        // Nonce aleatorio: ata la respuesta a esta petición concreta
        $nonce = random_bytes(8);
        // Se fuerza el primer byte por debajo de 0x80 para que el INTEGER sea positivo
        $nonce[0] = chr(ord($nonce[0]) & 0x7F);
        $enteroNonce = "\x02" . self::longitud(strlen($nonce)) . $nonce;

        $version = "\x02\x01\x01";
        $certReq = "\x01\x01\xFF";

        return self::secuencia($version . $imprint . $enteroNonce . $certReq);
    }

    private static function secuencia(string $contenido): string
    {
        return "\x30" . self::longitud(strlen($contenido)) . $contenido;
    }

    /**
     * Codifica una longitud en DER (forma corta o larga).
     */
    private static function longitud(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }

        $bytes = '';
        while ($n > 0) {
            $bytes = chr($n & 0xFF) . $bytes;
            $n >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function enviar(string $url, string $peticion): ?string
    {
        if (!function_exists('curl_init')) {
            Tools::log()->warning(Tools::lang()->trans('firmadoc-tsa-no-curl'));
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $peticion,
            CURLOPT_HTTPHEADER => ['Content-Type: application/timestamp-query'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $cuerpo = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($cuerpo === false || $codigo !== 200 || empty($cuerpo)) {
            Tools::log()->warning(Tools::lang()->trans('firmadoc-tsa-unreachable', [
                '%error%' => $error ?: ('HTTP ' . $codigo),
            ]));
            return null;
        }

        return $cuerpo;
    }

    /**
     * Extrae del token la fecha certificada (genTime del TSTInfo).
     *
     * Se busca el GeneralizedTime (etiqueta 0x18) cuyo contenido tenga forma de fecha
     * AAAAMMDDHHMMSS. Es una lectura deliberadamente tolerante: el token se guarda
     * entero, así que si esto falla no se pierde nada verificable, solo la fecha que
     * se muestra en el certificado.
     */
    private static function extraerFecha(string $der): ?string
    {
        $largo = strlen($der);
        for ($i = 0; $i < $largo - 2; $i++) {
            if ($der[$i] !== "\x18") {
                continue;
            }

            $n = ord($der[$i + 1]);
            if ($n < 14 || $n > 32 || $i + 2 + $n > $largo) {
                continue;
            }

            $texto = substr($der, $i + 2, $n);
            if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $texto, $m)) {
                continue;
            }

            // El genTime del RFC 3161 viene siempre en UTC
            $utc = new \DateTime(
                "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}",
                new \DateTimeZone('UTC')
            );
            $utc->setTimezone(new \DateTimeZone(date_default_timezone_get()));

            return $utc->format('d-m-Y H:i:s');
        }

        return null;
    }
}
