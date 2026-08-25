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

use Cezpdf;
use FacturaScripts\Core\Tools;

/**
 * Convierte un documento de Word en el PDF que se manda a firmar.
 *
 * Se firma un PDF y no el .docx original a propósito: el firmante tiene que ver
 * exactamente lo mismo que queda sellado, y un .docx se abre distinto en cada
 * ordenador según fuentes, versión de Word y plantilla.
 *
 * Si el servidor tiene LibreOffice se usa, porque conserva la maquetación tal cual.
 * Si no lo tiene —que es lo normal en un alojamiento compartido—, se pinta aquí con
 * la librería de PDF que ya trae FacturaScripts: sale un documento limpio con los
 * párrafos, la negrita, los títulos, las listas y las tablas, pero no reproduce
 * columnas, cabeceras ni imágenes. Por eso conviene revisar el PDF antes de enviarlo.
 */
class FirmaDocWordPdf
{
    /** Márgenes de la página, en puntos */
    const MARGEN = 50;

    /** Tamaño del texto normal */
    const TAMANO = 10;

    /** @var string */
    private $error = '';

    /** @var bool Si la última conversión la hizo LibreOffice */
    private $conLibreOffice = false;

    /** @var array Los bloques de la última conversión propia */
    private $bloques = [];

    /** @var array Firmas a estampar en las anclas, por número de firmante */
    private $firmas = [];

    /**
     * Los bloques que se pintaron, para poder repetir el documento tal cual más
     * adelante con las firmas puestas. Se guardan junto al PDF: repetirlos desde aquí
     * da siempre el mismo resultado, mientras que volver a convertir el .docx dependería
     * de que nadie lo hubiera tocado.
     */
    public function getBloques(): array
    {
        return $this->bloques;
    }

    /**
     * Si el documento reserva sitio para la firma con {{firma.aqui}}.
     */
    public function tieneAnclas(): bool
    {
        foreach ($this->bloques as $bloque) {
            if (($bloque['tipo'] ?? '') === 'ancla') {
                return true;
            }
        }

        return false;
    }

    /**
     * Repite el documento a partir de sus bloques, poniendo cada firma en su ancla.
     *
     * $firmas es [numeroDeFirmante => ['imagen' => dataUri, 'nombre' => , 'nif' => ,
     * 'fecha' => ]]. Las anclas sin firma se quedan con su línea vacía.
     */
    public function repintar(array $bloques, array $firmas = []): ?string
    {
        $this->bloques = $bloques;
        $this->firmas = $firmas;

        return $this->pintar($bloques);
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function conLibreOffice(): bool
    {
        return $this->conLibreOffice;
    }

    /**
     * Devuelve el PDF como cadena, o null si no se ha podido convertir.
     */
    public function convertir(string $rutaDocx): ?string
    {
        $this->error = '';
        $this->conLibreOffice = false;

        // Con etiqueta de firma no se puede delegar: el PDF lo pintaría LibreOffice y
        // aquí no se sabría dónde ha quedado el recuadro que hay que rellenar después.
        if (false === FirmaDocWordLector::tieneAncla($rutaDocx)) {
            $pdf = $this->conversorDelSistema($rutaDocx);
            if (null !== $pdf) {
                $this->conLibreOffice = true;
                return $pdf;
            }
        }

        $lector = new FirmaDocWordLector();
        $bloques = $lector->leer($rutaDocx);
        if (null === $bloques) {
            $this->error = $lector->getError();
            return null;
        }

        if (empty($bloques)) {
            $this->error = 'firmadoc-word-empty';
            return null;
        }

        $this->bloques = $bloques;

        return $this->pintar($bloques);
    }

    /**
     * LibreOffice, si está instalado y se le puede llamar.
     */
    private function conversorDelSistema(string $rutaDocx): ?string
    {
        $binario = $this->buscarLibreOffice();
        if ('' === $binario) {
            return null;
        }

        // Perfil propio y desechable: sin él, LibreOffice intenta escribir en el HOME
        // del usuario del servidor web, que casi nunca existe, y se queda colgado.
        $temporal = FS_FOLDER . '/MyFiles/Cache/firmadoc-office-' . uniqid();
        Tools::folderCheckOrCreate($temporal);

        $orden = escapeshellcmd($binario)
            . ' -env:UserInstallation=file://' . escapeshellarg($temporal)
            . ' --headless --norestore --invisible'
            . ' --convert-to pdf --outdir ' . escapeshellarg($temporal)
            . ' ' . escapeshellarg($rutaDocx);

        // Un documento retorcido puede dejar a LibreOffice dando vueltas
        if ($this->hayBinario('timeout')) {
            $orden = 'timeout 60 ' . $orden;
        }

        @exec($orden . ' 2>&1', $salida, $codigo);

        $generado = $temporal . '/' . pathinfo($rutaDocx, PATHINFO_FILENAME) . '.pdf';
        $pdf = is_file($generado) ? (string) file_get_contents($generado) : null;

        $this->borrarCarpeta($temporal);

        return empty($pdf) ? null : $pdf;
    }

    private function buscarLibreOffice(): string
    {
        foreach (['soffice', 'libreoffice'] as $binario) {
            if ($this->hayBinario($binario)) {
                return $binario;
            }
        }

        return '';
    }

    /**
     * Muchos alojamientos capan exec() entero; preguntar antes evita un aviso feo.
     */
    private function hayBinario(string $binario): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $desactivadas = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $desactivadas, true)) {
            return false;
        }

        @exec('command -v ' . escapeshellarg($binario) . ' 2>/dev/null', $salida, $codigo);
        return $codigo === 0 && !empty($salida);
    }

    private function borrarCarpeta(string $carpeta): void
    {
        if (!is_dir($carpeta)) {
            return;
        }

        foreach (glob($carpeta . '/*') ?: [] as $hijo) {
            is_dir($hijo) ? $this->borrarCarpeta($hijo) : @unlink($hijo);
        }

        @rmdir($carpeta);
    }

    /**
     * Pinta los bloques con la librería de PDF del núcleo.
     */
    private function pintar(array $bloques): ?string
    {
        // La librería guarda su caché de fuentes aquí y la carpeta se borra al
        // reconstruir los plugins
        $cache = FS_FOLDER . '/MyFiles/Cache';
        Tools::folderCheckOrCreate($cache);
        if (!is_writable($cache)) {
            $this->error = 'firmadoc-cache-not-writable';
            return null;
        }

        $pdf = new Cezpdf('a4', 'portrait');
        $pdf->tempPath = $cache;
        $pdf->ezSetMargins(self::MARGEN, self::MARGEN, self::MARGEN, self::MARGEN);
        $pdf->selectFont('Helvetica');

        $contadores = [];
        foreach ($bloques as $bloque) {
            if (!empty($bloque['salto_antes'])) {
                $pdf->ezNewPage();
            }

            if ($bloque['tipo'] === 'tabla') {
                $contadores = [];
                $this->pintarTabla($pdf, $bloque['filas']);
                continue;
            }

            if ($bloque['tipo'] === 'ancla') {
                $contadores = [];
                $this->pintarAncla($pdf, $bloque);
                continue;
            }

            // Las listas numeradas cuentan por nivel, y el contador se reinicia en
            // cuanto aparece algo que no es de la lista.
            if (empty($bloque['lista'])) {
                $contadores = [];
            }

            $this->pintarParrafo($pdf, $bloque, $contadores);
        }

        return $pdf->ezOutput();
    }

    private function pintarParrafo(Cezpdf $pdf, array $bloque, array &$contadores): void
    {
        $texto = '';
        foreach ($bloque['trozos'] as $trozo) {
            $texto .= $this->trozoAMarcado($trozo);
        }

        if (trim(strip_tags($texto)) === '') {
            return;
        }

        $nivel = (int) $bloque['nivel'];
        $sangria = (int) $bloque['sangria'];

        if (!empty($bloque['lista'])) {
            if ($bloque['lista'] === 'numero') {
                $contadores[$nivel] = ($contadores[$nivel] ?? 0) + 1;
                // Al bajar de nivel, los de dentro vuelven a empezar
                foreach (array_keys($contadores) as $n) {
                    if ($n > $nivel) {
                        unset($contadores[$n]);
                    }
                }
                $marca = $contadores[$nivel] . '. ';
            } else {
                $marca = '- ';
            }
            $texto = $marca . $texto;
            $sangria = max($sangria, 20 + $nivel * 18);
        }

        $titulo = (int) $bloque['titulo'];
        if ($titulo > 0) {
            $tamanos = [1 => 17, 2 => 14, 3 => 12, 4 => 11, 5 => 10, 6 => 10];
            $pdf->ezSetDy(-6);
            $pdf->ezText('<b>' . $texto . '</b>', $tamanos[$titulo] ?? 11, [
                'justification' => $bloque['alineacion'],
                'left' => $sangria,
            ]);
            $pdf->ezSetDy(-2);
            return;
        }

        $pdf->ezText($texto, self::TAMANO, [
            'justification' => $bloque['alineacion'],
            'left' => $sangria,
        ]);
        $pdf->ezSetDy(-3);
    }

    /**
     * Texto listo para la librería: primero se neutraliza lo que parecería una
     * directiva suya —un «<» del propio contrato— y después se añaden las de verdad.
     */
    private function trozoAMarcado(array $trozo): string
    {
        $texto = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $trozo['texto']);
        $texto = str_replace("\n", "\n", $texto);

        if (!empty($trozo['negrita'])) {
            $texto = '<b>' . $texto . '</b>';
        }

        if (!empty($trozo['cursiva'])) {
            $texto = '<i>' . $texto . '</i>';
        }

        return $texto;
    }

    private function pintarTabla(Cezpdf $pdf, array $filas): void
    {
        if (empty($filas)) {
            return;
        }

        $columnas = 0;
        foreach ($filas as $fila) {
            $columnas = max($columnas, count($fila));
        }

        $datos = [];
        foreach ($filas as $fila) {
            $registro = [];
            for ($i = 0; $i < $columnas; $i++) {
                $valor = $fila[$i] ?? '';
                $registro['c' . $i] = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $valor);
            }
            $datos[] = $registro;
        }

        $pdf->ezSetDy(-4);
        $pdf->ezTable($datos, null, '', [
            'showHeadings' => 0,
            'showLines' => 2,
            'fontSize' => self::TAMANO - 1,
            'xOrientation' => 'right',
            'xPos' => self::MARGEN,
            'width' => $pdf->ez['pageWidth'] - (self::MARGEN * 2),
        ]);
        $pdf->ezSetDy(-6);
    }

    /**
     * El recuadro de firma que reserva {{firma.aqui}}.
     *
     * Mientras nadie ha firmado se ve la línea con su rótulo, que es lo que el firmante
     * espera encontrar. Cuando ya hay firma, se pinta encima la rúbrica y debajo el
     * nombre, el documento de identidad y la fecha.
     */
    private function pintarAncla(Cezpdf $pdf, array $bloque): void
    {
        $numero = (int) ($bloque['firmante'] ?? 1);
        $firma = $this->firmas[$numero] ?? null;

        $ancho = 200.0;
        $alto = 58.0;
        $margen = (float) self::MARGEN;
        $anchoUtil = $pdf->ez['pageWidth'] - ($margen * 2);

        // El recuadro no puede partirse entre dos páginas
        if ($pdf->y - ($alto + 34) < $margen) {
            $pdf->ezNewPage();
        }

        switch ($bloque['alineacion'] ?? 'left') {
            case 'center':
                $x = $margen + (($anchoUtil - $ancho) / 2);
                break;
            case 'right':
                $x = $margen + $anchoUtil - $ancho;
                break;
            default:
                $x = $margen + (float) ($bloque['sangria'] ?? 0);
        }

        $pdf->ezSetDy(-10);
        $arriba = $pdf->y;
        $base = $arriba - $alto;

        if ($firma && !empty($firma['imagen'])) {
            $this->pintarImagenFirma($pdf, (string) $firma['imagen'], $x, $base + 4, $ancho, $alto - 6);
        }

        // La línea sobre la que se firma
        $pdf->setStrokeColor(0.4, 0.4, 0.4);
        $pdf->setLineStyle(0.7);
        $pdf->line($x, $base, $x + $ancho, $base);

        $pdf->setColor(0.35, 0.35, 0.35);
        $rotulo = $firma
            ? trim((string) ($firma['nombre'] ?? ''))
            : Tools::lang()->trans('firmadoc-word-sign-here');
        $pdf->addText($x, $base - 11, self::TAMANO - 1, $this->limpiar($rotulo), $ancho, 'left');

        if ($firma) {
            $pie = [];
            if (!empty($firma['nif'])) {
                $pie[] = (string) $firma['nif'];
            }
            if (!empty($firma['fecha'])) {
                $pie[] = (string) $firma['fecha'];
            }
            if (!empty($pie)) {
                $pdf->setColor(0.5, 0.5, 0.5);
                $pdf->addText($x, $base - 21, self::TAMANO - 2, $this->limpiar(implode('  ·  ', $pie)), $ancho, 'left');
            }
        }

        $pdf->setColor(0, 0, 0);
        $pdf->ezSetY($base - 30);
    }

    /**
     * Pinta la rúbrica, que llega como data URI desde el navegador del firmante.
     */
    private function pintarImagenFirma(Cezpdf $pdf, string $dataUri, float $x, float $y, float $ancho, float $alto): void
    {
        if (strpos($dataUri, 'base64,') === false) {
            return;
        }

        $binario = base64_decode(substr($dataUri, strpos($dataUri, 'base64,') + 7), true);
        if (empty($binario)) {
            return;
        }

        // La librería solo sabe leer imágenes de fichero, así que hay que dejarla en uno
        $temporal = FS_FOLDER . '/MyFiles/Cache/firmadoc-rubrica-' . uniqid() . '.png';
        if (false === @file_put_contents($temporal, $binario)) {
            return;
        }

        $medidas = @getimagesize($temporal);
        if (false === $medidas || empty($medidas[0])) {
            @unlink($temporal);
            return;
        }

        // Se encaja dentro del recuadro sin deformarla
        $escala = min($ancho / $medidas[0], $alto / $medidas[1], 1.0);
        $w = $medidas[0] * $escala;
        $h = $medidas[1] * $escala;

        try {
            if (($medidas[2] ?? 0) === IMAGETYPE_JPEG) {
                $pdf->addJpegFromFile($temporal, $x, $y, $w, $h);
            } else {
                $pdf->addPngFromFile($temporal, $x, $y, $w, $h);
            }
        } catch (\Throwable $e) {
            Tools::log()->warning('FirmaDoc: ' . $e->getMessage());
        }

        @unlink($temporal);
    }

    private function limpiar(string $texto): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $texto);
    }
}
