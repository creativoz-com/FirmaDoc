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
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocFirmante;

/**
 * Lo que FirmaDoc ofrece a otros plugins.
 *
 * Un plugin de contratos, de obras o de lo que sea no debería tener que entrar en los
 * controladores ni en las tablas de este: aquí están las tres cosas que hacen falta
 * desde fuera —recuperar el documento firmado, saber cómo va, y montar el enlace—,
 * y son las únicas que se mantienen estables entre versiones.
 *
 * Ejemplo:
 *   $pdf = FirmaDocApi::pdfFirmado($idFirma);
 *   if (null !== $pdf) { file_put_contents($ruta, $pdf); }
 */
class FirmaDocApi
{
    /**
     * El documento firmado, con su certificado detrás, listo para guardar o enviar.
     * Devuelve null si la solicitud no existe, no está firmada o falta el documento.
     */
    public static function pdfFirmado(int $idFirma): ?string
    {
        $firma = new FirmaDoc();
        if (!$firma->loadFromCode($idFirma)) {
            return null;
        }

        return self::pdfDeFirma($firma);
    }

    /**
     * Igual que pdfFirmado(), pero partiendo de una solicitud ya cargada.
     */
    public static function pdfDeFirma(FirmaDoc $firma): ?string
    {
        if (empty($firma->id) || $firma->estado !== FirmaDoc::ESTADO_FIRMADO) {
            return null;
        }

        $documento = FirmaDocDocumento::cargar($firma->tipo_doc, (int) $firma->id_doc);
        if (null === $documento) {
            return null;
        }

        // Documento subido: el original no se toca, se le añade el certificado detrás
        // si el servidor puede unir PDF; si no, se entrega el original tal cual.
        if ($firma->esExterno()) {
            $ruta = FirmaDocDocumento::rutaFicheroExterno($firma);
            if ($ruta === '') {
                return null;
            }
            $certificado = FirmaDocPDFExport::certificadoSuelto($firma, $documento);
            return FirmaDocPdfUnir::unir($ruta, $certificado) ?? (string) file_get_contents($ruta);
        }

        $export = new FirmaDocPDFExport();
        $export->newDoc($documento->codigo ?? '', 0, '');
        $export->addBusinessDocPage($documento);
        $export->addCertificadoFirma($firma, $documento);
        // Después del certificado: así la marca alcanza también a sus páginas
        $export->estamparMarcaLateral($firma, FirmaDocPDFExport::textoMarcaLateral($firma));

        return $export->getDoc();
    }

    /**
     * Cómo va una solicitud, sin tener que conocer las tablas.
     *
     * Devuelve null si no existe. En caso contrario:
     *   estado, firmado (bool), fecha_envio, fecha_firma, fecha_expiracion,
     *   codigo_verificacion, enlace_firma, enlace_verificacion,
     *   firmantes => [['nombre','email','estado','fecha_firma'], ...]
     */
    public static function estado(int $idFirma): ?array
    {
        $firma = new FirmaDoc();
        if (!$firma->loadFromCode($idFirma)) {
            return null;
        }

        $firmantes = [];
        foreach (FirmaDocFirmante::porSolicitud($firma->id) as $f) {
            $firmantes[] = [
                'nombre' => $f->firma_nombre ?: ($f->nombre ?? ''),
                'email' => $f->email ?? '',
                'estado' => $f->estado ?? '',
                'fecha_firma' => $f->fecha_firma ?? '',
            ];
        }

        return [
            'id' => (int) $firma->id,
            'estado' => $firma->estado,
            'firmado' => $firma->estado === FirmaDoc::ESTADO_FIRMADO,
            'titulo' => $firma->getTitulo(),
            'fecha_envio' => $firma->fecha_envio ?? '',
            'fecha_firma' => $firma->fecha_firma ?? '',
            'fecha_expiracion' => $firma->fecha_expiracion ?? '',
            'codigo_verificacion' => $firma->codigo_verificacion ?? '',
            'enlace_firma' => FirmaDocUrl::firma($firma->token),
            'enlace_verificacion' => FirmaDocUrl::verificacion($firma->codigo_verificacion ?? ''),
            'firmantes' => $firmantes,
        ];
    }

    /**
     * Las solicitudes de un cliente o de un proveedor, de la más reciente a la más
     * antigua. Sirve para enseñar en una ficha ajena lo que ya se le mandó a firmar.
     *
     * @return FirmaDoc[]
     */
    public static function porTercero(string $codcliente = '', string $codproveedor = ''): array
    {
        $campo = $codcliente !== '' ? 'codcliente' : 'codproveedor';
        $valor = $codcliente !== '' ? $codcliente : $codproveedor;
        if ($valor === '') {
            return [];
        }

        return (new FirmaDoc())->all(
            [new \FacturaScripts\Core\Base\DataBase\DataBaseWhere($campo, $valor)],
            ['fecha_envio' => 'DESC'],
            0,
            0
        );
    }

    /**
     * Convierte un .docx en PDF y devuelve el resultado, por si el plugin que llama
     * quiere enseñarlo antes de mandarlo a firmar.
     *
     * Devuelve null si no se ha podido; el motivo, en $error.
     */
    public static function wordAPdf(string $rutaDocx, string &$error = ''): ?string
    {
        $conversor = new FirmaDocWordPdf();
        $pdf = $conversor->convertir($rutaDocx);
        if (null === $pdf) {
            $error = Tools::lang()->trans($conversor->getError() ?: 'firmadoc-word-failed');
            return null;
        }

        $error = '';
        return $pdf;
    }
}
