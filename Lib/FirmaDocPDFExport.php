<?php
/**
 * This file is part of FirmaDoc plugin for FacturaScripts.
 *
 * @author    Francisco José Matías Olivares <fmatias@creativoz.com>
 * @copyright 2025-2026 Francisco José Matías Olivares
 * @license   Acuerdo de Licencia de Usuario Final (EULA) — véase archivo LICENSE
 * @version   1.11
 * @link      https://creativoz.com
 */
namespace FacturaScripts\Plugins\FirmaDoc\Lib;

use FacturaScripts\Core\Lib\Export\PDFExport;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\FirmaDoc\Lib\FirmaDocOtp;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDoc;
use FacturaScripts\Plugins\FirmaDoc\Model\FirmaDocFirmante;

/**
 * Extiende PDFExport para añadir páginas de certificado de firma.
 * Soporta multi-firmante: 2 bloques de firma por página.
 */
class FirmaDocPDFExport extends PDFExport
{
    public function getPdf()
    {
        return $this->pdf;
    }

    /**
     * Genera el certificado de firma como PDF independiente.
     *
     * Se usa con los documentos externos: el PDF que subió el usuario no se puede
     * modificar desde PHP con la librería que trae FacturaScripts, y además conviene
     * que el documento firmado conserve su fichero original intacto.
     */
    public static function certificadoSuelto(FirmaDoc $firma, object $documento): string
    {
        $export = new self();
        $export->newDoc($firma->getTitulo(), 0, '');
        // Sin proteger: el certificado suelto se une después al PDF original, y las
        // herramientas de unión se niegan a trabajar con ficheros cifrados. La
        // protección de rospdf es de clave de propietario, que cualquier utilidad
        // quita en un segundo, así que aquí no se pierde nada real.
        $export->addCertificadoFirma($firma, $documento, false);
        return $export->getDoc();
    }

    /**
     * Añade las páginas de certificado.
     * En modo multi-firmante muestra todos los firmantes que han firmado,
     * 2 por página. En modo único, muestra la firma del firmante principal.
     */
    public function addCertificadoFirma(FirmaDoc $firma, object $documento, bool $proteger = true): void
    {
        // Recoger todos los firmantes que han firmado
        $firmantes = FirmaDocFirmante::porSolicitud($firma->id);
        $firmados  = array_values(array_filter($firmantes, fn($f) => $f->estado === FirmaDocFirmante::ESTADO_FIRMADO));

        if (empty($firmados)) {
            // Fallback: usar datos de la firma principal (modo único clásico)
            $firmados = [$this->firmanteDesdeDoc($firma)];
        }

        $total = count($firmados);
        $t = function(string $key, array $params = []): string {
            return Tools::lang()->trans($key, $params);
        };

        // Agrupar de 3 en 3
        for ($i = 0; $i < $total; $i += 3) {
            // Al generar el certificado suelto todavía no hay documento: newPage() lo
            // crea y deja abierta la primera página. Si ya existe —el caso normal, tras
            // el documento de venta— se abre una nueva. Sin esta distinción el
            // certificado suelto salía con una página en blanco delante, o reventaba
            // por llamar a ezNewPage() sobre un objeto que aún no existía.
            if ($this->pdf === null) {
                // La librería de PDF escribe su caché de fuentes en MyFiles/Cache, y esa
                // carpeta se borra entera al reconstruir los plugins. Si falta o no es
                // escribible, Cezpdf muere con un «fwrite(): Argument #1 must be of type
                // resource» que no dice nada; mejor un aviso que explique qué mirar.
                $rutaCache = FS_FOLDER . '/MyFiles/Cache';
                Tools::folderCheckOrCreate($rutaCache);
                if (!is_writable($rutaCache)) {
                    Tools::log()->error(Tools::lang()->trans('firmadoc-cache-not-writable', [
                        '%folder%' => 'MyFiles/Cache',
                    ]));
                }
                $this->newPage();
            } else {
                $this->pdf->ezNewPage();
            }

            $pageW    = $this->pdf->ez['pageWidth'];
            $marginL  = $this->pdf->ez['leftMargin'];
            $marginR  = $this->pdf->ez['rightMargin'];
            $contentW = $pageW - $marginL - $marginR;
            $pageH    = $this->pdf->ez['pageHeight'];

            // ── CABECERA ──────────────────────────────────────────────────
            $this->pdf->setColor(0.13, 0.45, 0.25);
            $this->pdf->filledRectangle(0, $pageH - 45, $pageW, 45);
            $this->pdf->setColor(1, 1, 1);
            $this->pdf->addText($marginL, $pageH - 30, 14,
                $t('firmadoc-pdf-certificate-title'), $contentW, 'left');
            $subtitle = $total > 1
                ? $t('firmadoc-pdf-signers-x-of-y', ['%from%' => $i + 1, '%to%' => min($i + 3, $total), '%total%' => $total])
                : $t('firmadoc-pdf-certifies-signature');
            $this->pdf->addText($marginL, $pageH - 42, 9, $subtitle, $contentW, 'left');

            // Datos del documento
            $y = $pageH - 70;
            $this->pdf->setColor(0, 0, 0);
            $encabezado = $firma->esExterno()
                ? $firma->getTitulo()
                : strtoupper(ucfirst($firma->tipo_doc)) . ': ' . $firma->codigo_doc;
            $this->pdf->addText($marginL, $y, 11, $encabezado, $contentW, 'left');
            $y -= 14;
            $this->pdf->setStrokeColor(0.13, 0.45, 0.25);
            $this->pdf->line($marginL, $y, $pageW - $marginR, $y);
            $y -= 12;

            // Calcular altura disponible para cada bloque
            $bloquesEnPagina = min(3, $total - $i);
            $alturaBloque    = ($pageH - 45 - 100 - 50) / $bloquesEnPagina;

            for ($j = 0; $j < $bloquesEnPagina; $j++) {
                $firmante = $firmados[$i + $j];
                $yInicio  = $y;

                // Divisor entre bloques
                if ($j > 0) {
                    $y -= 10;
                    $this->pdf->setColor(0.85, 0.85, 0.85);
                    $this->pdf->filledRectangle($marginL, $y, $contentW, 1);
                    $y -= 16;
                }

                // Número de firmante
                $this->pdf->setColor(0.13, 0.45, 0.25);
                $signerLabel = $total > 1
                    ? $t('firmadoc-pdf-signer-x-of-y', ['%num%' => $i + $j + 1, '%total%' => $total])
                    : $t('firmadoc-pdf-signer') . ' ' . ($i + $j + 1);
                $this->pdf->addText($marginL, $y, 10, $signerLabel, $contentW, 'left');
                $y -= 14;

                // Datos del firmante
                $filas = [
                    [$t('firmadoc-pdf-full-name'), $firmante->firma_nombre ?? ($firmante->nombre ?? '—')],
                    [$t('firmadoc-pdf-nif-cif'),   $firmante->firma_nif   ?: '—'],
                    [$t('firmadoc-pdf-position'),   $firmante->firma_cargo ?: '—'],
                ];
                foreach ($filas as [$label, $valor]) {
                    $this->pdf->setColor(0.4, 0.4, 0.4);
                    $this->pdf->addText($marginL, $y, 9, $label . ':', 105, 'left');
                    $this->pdf->setColor(0, 0, 0);
                    $this->pdf->addText($marginL + 110, $y, 9, $valor, $contentW - 110, 'left');
                    $y -= 12;
                }

                $y -= 4;
                $this->pdf->setColor(0.13, 0.45, 0.25);
                $this->pdf->addText($marginL, $y, 10, $t('firmadoc-pdf-signature-data'), $contentW, 'left');
                $y -= 13;

                $modoFirma   = $firmante->modo_firma ?? '';
                $firmaImagen = $firmante->firma_imagen ?? '';

                $datosFirma = [
                    [$t('firmadoc-pdf-date-time'),  $firmante->fecha_firma     ?? '—'],
                    [$t('firmadoc-pdf-signer-ip'),  $firmante->ip_cliente      ?? '—'],
                    [$t('firmadoc-pdf-mode'),       $this->nombreModo($modoFirma, $t)],
                ];
                foreach ($datosFirma as [$label, $valor]) {
                    $this->pdf->setColor(0.4, 0.4, 0.4);
                    $this->pdf->addText($marginL, $y, 9, $label . ':', 105, 'left');
                    $this->pdf->setColor(0, 0, 0);
                    $this->pdf->addText($marginL + 110, $y, 9, $valor, $contentW - 110, 'left');
                    $y -= 12;
                }

                // ── Trazabilidad ────────────────────────────────────────────────
                // Estos datos se venían guardando y no se enseñaban en ninguna parte,
                // que es justo donde tienen valor probatorio.
                $trazas = $this->filasTrazabilidad($firmante, $firma, $t);
                if (!empty($trazas)) {
                    $y -= 4;
                    $this->pdf->setColor(0.13, 0.45, 0.25);
                    $this->pdf->addText($marginL, $y, 10, $t('firmadoc-pdf-traceability'), $contentW, 'left');
                    $y -= 13;
                    foreach ($trazas as [$label, $valor]) {
                        $this->pdf->setColor(0.4, 0.4, 0.4);
                        $this->pdf->addText($marginL, $y, 9, $label . ':', 105, 'left');
                        $this->pdf->setColor(0, 0, 0);
                        $this->pdf->addText($marginL + 110, $y, 9, $valor, $contentW - 110, 'left');
                        $y -= 12;
                    }
                }

                // Imagen de firma
                $y -= 6;
                $this->pdf->setColor(0.13, 0.45, 0.25);
                $this->pdf->addText($marginL, $y, 10, $t('firmadoc-pdf-signature'), $contentW, 'left');
                $y -= 8;

                if ($modoFirma === 'certificado') {
                    $this->dibujarBloqueCertificado($firmante, $marginL, $contentW, $y);
                    $y -= 82;
                } elseif (!empty($firmaImagen)) {
                    // Con un solo firmante hay sitio de sobra: la firma se dibuja al doble
                    // de tamaño en vez de dejar dos tercios de página en blanco.
                    $anchoFirma = $total === 1 ? 300 : 150;
                    $altoFirma  = $total === 1 ? 100 : 50;
                    $imgPath = $this->guardarImagenTemporal($firmaImagen);
                    if ($imgPath) {
                        $this->pdf->setColor(0.95, 0.95, 0.95);
                        $this->pdf->filledRectangle($marginL, $y - $altoFirma, $anchoFirma, $altoFirma);
                        $this->pdf->setStrokeColor(0.8, 0.8, 0.8);
                        $this->pdf->rectangle($marginL, $y - $altoFirma, $anchoFirma, $altoFirma);
                        $this->pdf->addPngFromFile(
                            $imgPath,
                            $marginL + 4,
                            $y - $altoFirma + 4,
                            $anchoFirma - 8,
                            $altoFirma - 8
                        );
                        @unlink($imgPath);
                    }
                    $y -= $altoFirma + 8;
                } else {
                    $y -= 10;
                }

                // ── Sello de tiempo ─────────────────────────────────────────────
                if (!empty($firma->sello_fecha)) {
                    $y -= 8;
                    $this->pdf->setColor(0.13, 0.45, 0.25);
                    $this->pdf->addText($marginL, $y, 10, $t('firmadoc-pdf-timestamp'), $contentW, 'left');
                    $y -= 13;
                    foreach ([
                        [$t('firmadoc-pdf-timestamp-date'), $firma->sello_fecha],
                        [$t('firmadoc-pdf-timestamp-authority'), $firma->sello_autoridad ?? '—'],
                    ] as [$label, $valor]) {
                        $this->pdf->setColor(0.4, 0.4, 0.4);
                        $this->pdf->addText($marginL, $y, 9, $label . ':', 105, 'left');
                        $this->pdf->setColor(0, 0, 0);
                        $this->pdf->addText($marginL + 110, $y, 9, $valor, $contentW - 110, 'left');
                        $y -= 12;
                    }
                }

                // Texto legal compacto
                $y -= 6;
                $this->pdf->setColor(0.88, 0.93, 0.88);
                $this->pdf->filledRectangle($marginL, $y - 24, $contentW, 26);
                $this->pdf->setColor(0.3, 0.3, 0.3);
                $this->pdf->addText($marginL + 4, $y - 4, 7,
                    $t('firmadoc-pdf-legal-accepted'),
                    $contentW - 8, 'left');
                $this->pdf->addText($marginL + 4, $y - 14, 7,
                    $t('firmadoc-pdf-eidas-valid', ['%hash%' => $firma->doc_hash ?? '']),
                    $contentW - 8, 'left');
                $y -= 30;

                // Relación de documentos cuando la firma cubre un paquete: el
                // certificado tiene que decir exactamente qué se firmó, pieza a pieza,
                // y con la huella de cada una.
                $adjuntos = $firma->getAdjuntos();
                if (count($adjuntos) > 1) {
                    $this->pdf->setColor(0.13, 0.45, 0.25);
                    $this->pdf->addText($marginL, $y, 9, $t('firmadoc-pdf-documents'), $contentW, 'left');
                    $y -= 12;
                    foreach ($adjuntos as $adj) {
                        $etiqueta = $adj->esFirmable()
                            ? $t('firmadoc-to-sign')
                            : $t('firmadoc-annex');
                        $this->pdf->setColor(0.35, 0.35, 0.35);
                        $this->pdf->addText($marginL + 6, $y, 7,
                            '[' . $etiqueta . '] ' . $adj->getNombre()
                            . '  ' . substr((string) $adj->doc_hash, 0, 24) . '...',
                            $contentW - 12, 'left');
                        $y -= 10;
                    }
                    $y -= 4;
                }

                // Código de verificación: es lo que se teclea en el portal público,
                // y a diferencia de la huella se puede leer y copiar sin equivocarse.
                if (!empty($firma->codigo_verificacion)) {
                    $this->pdf->setColor(0.13, 0.45, 0.25);
                    $this->pdf->addText($marginL, $y, 9,
                        $t('firmadoc-pdf-verification-code') . ': ' . $firma->codigo_verificacion,
                        $contentW, 'left');
                    $y -= 16;
                }
            }

            // ── QR de verificación (solo en la última página del certificado) ──
            if ($i + 3 >= $total) {
                $this->addQrVerificacion($firma, $marginL, $contentW, $pageW, $marginR, $t);
            }

            // Pie de página — incluye la empresa emisora: el certificado identificaba
            // al firmante pero no decía de quién era el documento.
            $this->pdf->setStrokeColor(0.7, 0.7, 0.7);
            $this->pdf->line($marginL, 28, $pageW - $marginR, 28);
            $this->pdf->setColor(0.5, 0.5, 0.5);
            $pie = $t('firmadoc-pdf-generated-by') . ' · ' . $firma->getTitulo() . ' · ' . date('d/m/Y H:i');
            // En documentos externos el AttachedFile no lleva empresa: se usa la de la instalación
            $emisor = FirmaDocEmpresa::nombre($firma->esExterno() ? null : $documento);
            if (!empty($emisor)) {
                $pie = $emisor . ' · ' . $pie;
            }
            $this->pdf->addText($marginL, 20, 7, $pie, $contentW, 'left');
        }

        // Protección PDF
        if ($proteger) {
            $ownerPass = 'FirmaDoc_' . substr(md5($firma->doc_hash ?? uniqid()), 0, 16);
            $this->pdf->setEncryption('', $ownerPass, ['print' => true, 'modify' => false, 'copy' => false], 2);
        }
    }

    /**
     * Estampa en el margen izquierdo de todas las páginas una línea vertical con quién
     * firmó, cuándo y el código de verificación.
     *
     * Sin esto, una página suelta de un contrato de veinte no dice nada: hay que llegar
     * al certificado del final para saber que está firmado. La marca va dentro del
     * margen, girada 90º, así que no pisa el texto; si el margen es tan estrecho que no
     * cabe, se deja el documento como está antes que escribir encima.
     *
     * Solo sirve para los documentos que genera FacturaScripts. Un PDF subido por el
     * usuario no se puede tocar: la librería de PDF del núcleo no sabe abrir un PDF
     * existente, y por eso esos documentos llevan el certificado unido aparte.
     */
    public function estamparMarcaLateral(FirmaDoc $firma, string $texto): void
    {
        if ($this->pdf === null || $texto === '') {
            return;
        }

        $marginL = $this->pdf->ez['leftMargin'];
        $pageH = $this->pdf->ez['pageHeight'];

        // 14pt de separación al borde para que ninguna impresora la recorte, y el resto
        // del margen libre para el texto del documento.
        $x = 14.0;
        if ($marginL < 28) {
            return;
        }

        // Las páginas ya creadas no se pueden alcanzar con addObject(), que solo llega a
        // la actual y a las futuras. Los identificadores de página son internos de la
        // librería, así que se leen atados a ella; escribir en ellas ya es API pública.
        $paginas = \Closure::bind(function () {
            $resultado = [];
            foreach ($this->objects as $id => $objeto) {
                if (($objeto['t'] ?? '') === 'page' && !empty($objeto['info']['contents'][0])) {
                    $resultado[(int) $objeto['info']['pageNum']] = $objeto['info']['contents'][0];
                }
            }
            ksort($resultado);
            return $resultado;
        }, $this->pdf, get_class($this->pdf))();

        $total = count($paginas);
        if ($total === 0) {
            return;
        }

        $tamano = 6.0;
        $desde = 40.0;
        $hasta = $pageH - 40.0;

        foreach ($paginas as $numero => $idContenido) {
            $this->pdf->reopenObject($idContenido);
            $this->pdf->saveState();
            $this->pdf->setColor(0.45, 0.45, 0.45);

            $completo = $texto . '  ·  ' . Tools::lang()->trans('firmadoc-pdf-page-of', [
                '%page%' => $numero,
                '%total%' => $total,
            ]);

            // Girado, la librería de PDF solo pinta los primeros 40 puntos de cada
            // llamada y descarta el resto en silencio, así que el texto se escribe por
            // trozos cortos encadenando la posición. A -90 grados avanza hacia arriba,
            // que es como se lee un lateral izquierdo.
            $y = $desde;
            $trozos = preg_split('/(.{10})/u', $completo, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            foreach ($trozos as $trozo) {
                $ancho = $this->pdf->getTextWidth($tamano, $trozo);
                if ($y + $ancho > $hasta) {
                    break;
                }
                $this->pdf->addText($x, $y, $tamano, $trozo, 0, 'left', -90);
                $y += $ancho;
            }

            $this->pdf->setColor(0, 0, 0);
            $this->pdf->restoreState();
            $this->pdf->closeObject();
        }
    }

    /**
     * Texto de la marca lateral: quién ha firmado, cuándo y con qué código se comprueba.
     */
    public static function textoMarcaLateral(FirmaDoc $firma): string
    {
        $firmantes = FirmaDocFirmante::porSolicitud($firma->id);
        $nombres = [];
        foreach ($firmantes as $f) {
            if ($f->estado !== FirmaDocFirmante::ESTADO_FIRMADO) {
                continue;
            }
            $nombres[] = trim((string) ($f->firma_nombre ?: $f->nombre ?: $f->email));
        }
        if (empty($nombres) && !empty($firma->firma_nombre)) {
            $nombres[] = (string) $firma->firma_nombre;
        }
        if (empty($nombres)) {
            return '';
        }

        $partes = [
            Tools::lang()->trans('firmadoc-pdf-side-signed-by', [
                '%signers%' => implode(', ', $nombres),
            ]),
        ];
        if (!empty($firma->fecha_firma)) {
            $partes[] = $firma->fecha_firma;
        }
        if (!empty($firma->codigo_verificacion)) {
            $partes[] = Tools::lang()->trans('firmadoc-verification-code')
                . ': ' . $firma->codigo_verificacion;
        }

        return implode('  ·  ', $partes);
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    /**
     * Crea un objeto "firmante" pseudo con los datos de la firma principal (modo único clásico).
     */
    private function firmanteDesdeDoc(FirmaDoc $firma): object
    {
        return (object) [
            'nombre'                => $firma->email_cliente ?? '',
            'firma_nombre'          => $firma->firma_nombre,
            'firma_nif'             => $firma->firma_nif,
            'firma_cargo'           => $firma->firma_cargo,
            'firma_imagen'          => $firma->firma_imagen,
            'firma_certificado_data'=> $firma->firma_certificado_data,
            'modo_firma'            => $firma->modo_firma,
            'fecha_firma'           => $firma->fecha_firma,
            'ip_cliente'            => $firma->ip_cliente,
            'user_agent'            => $firma->user_agent,
        ];
    }

    private function dibujarBloqueCertificado(object $firmante, float $marginL, float $contentW, float $y): void
    {
        $t = function(string $key, array $params = []): string {
            return Tools::lang()->trans($key, $params);
        };

        $this->pdf->setColor(0.85, 0.93, 0.87);
        $this->pdf->filledRectangle($marginL, $y - 72, $contentW, 72);
        $this->pdf->setStrokeColor(0.13, 0.45, 0.25);
        $this->pdf->rectangle($marginL, $y - 72, $contentW, 72);
        $this->pdf->setColor(0.13, 0.45, 0.25);
        $this->pdf->filledRectangle($marginL, $y - 14, $contentW, 14);
        $this->pdf->setColor(1, 1, 1);
        $this->pdf->addText($marginL + 5, $y - 10, 8, $t('firmadoc-pdf-digital-cert'), $contentW - 10, 'left');

        $certData = [];
        if (!empty($firmante->firma_certificado_data)) {
            $decoded = json_decode($firmante->firma_certificado_data, true);
            if (is_array($decoded)) {
                $certData = $decoded;
            }
        }

        $yc = $y - 22;
        foreach ([
            [$t('firmadoc-pdf-holder'),     $firmante->firma_nombre ?? '—'],
            [$t('firmadoc-field-nif'),       $firmante->firma_nif    ?? '—'],
            [$t('firmadoc-pdf-valid-until'), $certData['expiry'] ?? '—'],
            [$t('firmadoc-pdf-sign-date'),   $firmante->fecha_firma ?? '—'],
        ] as [$lbl, $val]) {
            $this->pdf->setColor(0.13, 0.45, 0.25);
            $this->pdf->addText($marginL + 5, $yc, 8, $lbl . ':', 70, 'left');
            $this->pdf->setColor(0, 0, 0);
            $this->pdf->addText($marginL + 75, $yc, 8, $val, $contentW - 80, 'left');
            $yc -= 10;
        }

        // Aclaración: el certificado identifica al firmante, no firma criptográficamente
        $this->pdf->setColor(0.35, 0.35, 0.35);
        $this->pdf->addText($marginL + 5, $yc - 2, 6, $t('firmadoc-pdf-cert-note'), $contentW - 10, 'left');
    }

    private function guardarImagenTemporal(string $dataUrl): ?string
    {
        if (strpos($dataUrl, 'data:image/png;base64,') !== 0) {
            return null;
        }
        $bytes = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')));
        if (empty($bytes)) {
            return null;
        }
        $path = sys_get_temp_dir() . '/firmadoc_sig_' . uniqid() . '.png';
        if (file_put_contents($path, $bytes) === false) {
            return null;
        }
        return $path;
    }

    /**
     * Añade un código QR de verificación en la esquina inferior derecha del certificado.
     */
    private function addQrVerificacion(FirmaDoc $firma, float $marginL, float $contentW, float $pageW, float $marginR, callable $t): void
    {
        try {
            // El QR lleva el código de verificación, no la huella: identifica la firma
            // de forma unívoca y no revela la huella del contenido del documento.
            $verifyUrl = FirmaDocUrl::verificacion($firma->codigo_verificacion ?? '');
            if (empty($verifyUrl)) {
                // Sin URL de sitio configurada el QR llevaría a ninguna parte
                return;
            }

            // El QR se genera en local con la librería que ya trae FacturaScripts
            // (chillerlan/php-qrcode, la misma que usa el núcleo para el 2FA).
            // Nunca se envía el documento ni su hash a un servicio externo.
            if (!class_exists('\chillerlan\QRCode\QRCode')) {
                return;
            }

            $options = new \chillerlan\QRCode\QROptions([
                'version'    => \chillerlan\QRCode\QRCode::VERSION_AUTO,
                'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
                'eccLevel'   => \chillerlan\QRCode\QRCode::ECC_M,
                'scale'      => 5,
                'imageBase64' => false,
            ]);
            $qrData = (new \chillerlan\QRCode\QRCode($options))->render($verifyUrl);
            if (empty($qrData)) {
                return;
            }

            $tmpFile = sys_get_temp_dir() . '/firmadoc_qr_' . uniqid() . '.png';
            if (file_put_contents($tmpFile, $qrData) === false) {
                return;
            }

            // Inferior derecha, con hueco propio: antes el QR estaba en y=34 y su texto
            // en y=26, justo por debajo de la línea del pie (y=28), que lo atravesaba.
            $qrSize = 58;
            $qrX = $pageW - $marginR - $qrSize;
            $qrY = 52;

            $this->pdf->addPngFromFile($tmpFile, $qrX, $qrY, $qrSize, $qrSize);
            @unlink($tmpFile);

            // Texto debajo del QR, todavía por encima de la línea del pie
            $this->pdf->setColor(0.5, 0.5, 0.5);
            $this->pdf->addText($qrX - 10, $qrY - 9, 6, $t('firmadoc-pdf-verify-qr'), $qrSize + 20, 'center');
        } catch (\Exception $e) {
            // Silenciar errores para no romper el PDF
        }
    }

    /**
     * Nombre legible del modo de firma empleado.
     */
    private function nombreModo(string $modo, callable $t): string
    {
        return match ($modo) {
            'manuscrita'  => $t('firmadoc-pdf-mode-handwritten'),
            'tipografica' => $t('firmadoc-pdf-mode-typographic'),
            'certificado' => $t('firmadoc-pdf-mode-certificate'),
            default       => '—',
        };
    }

    /**
     * Evidencias de trazabilidad del proceso de firma.
     * Todo esto ya se guardaba en base de datos; simplemente no se publicaba.
     */
    private function filasTrazabilidad(object $firmante, FirmaDoc $firma, callable $t): array
    {
        $filas = [];

        $apertura = $firmante->fecha_primera_apertura ?? $firma->fecha_primera_apertura ?? '';
        if (!empty($apertura)) {
            $filas[] = [$t('firmadoc-pdf-first-open'), $apertura];
        }

        $vistas = (int) ($firmante->veces_visto ?? $firma->veces_visto ?? 0);
        if ($vistas > 0) {
            $filas[] = [$t('firmadoc-pdf-times-seen'), (string) $vistas];
        }

        $ua = $firmante->user_agent ?? $firma->user_agent ?? '';
        if (!empty($ua)) {
            $filas[] = [$t('firmadoc-pdf-device'), $this->resumirUserAgent($ua)];
        }

        if (!empty($firma->acepto_legal) && !empty($firma->fecha_acepto_legal)) {
            $filas[] = [$t('firmadoc-pdf-consent-accepted'), $firma->fecha_acepto_legal];
        }

        // La verificación en dos pasos es la evidencia más fuerte del certificado:
        // acredita que quien firmó tenía acceso al buzón que registró la empresa.
        if (!empty($firma->otp_verificado) && !empty($firma->otp_enviado_a)) {
            $filas[] = [
                $t('firmadoc-pdf-second-factor'),
                $t('firmadoc-pdf-otp-verified', ['%email%' => FirmaDocOtp::ocultar($firma->otp_enviado_a)]),
            ];
        }

        $observaciones = $firmante->observaciones ?? $firma->observaciones_firmante ?? '';
        if (!empty($observaciones)) {
            $filas[] = [$t('firmadoc-pdf-signer-notes'), mb_substr((string) $observaciones, 0, 90)];
        }

        return $filas;
    }

    private function resumirUserAgent(string $ua): string
    {
        if (empty($ua)) return '—';
        $unknown = Tools::lang()->trans('firmadoc-pdf-unknown');
        $browser = match (true) {
            str_contains($ua, 'Chrome')  => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari')  => 'Safari',
            str_contains($ua, 'Edge')    => 'Edge',
            default => $unknown,
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone')  => 'iPhone',
            str_contains($ua, 'iPad')    => 'iPad',
            str_contains($ua, 'Mac')     => 'macOS',
            str_contains($ua, 'Linux')   => 'Linux',
            default => $unknown,
        };
        return $browser . ' / ' . $os;
    }
}
