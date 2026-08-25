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

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Lee un .docx y lo reduce a una lista de bloques sencillos.
 *
 * Un .docx no es más que un zip con XML dentro, así que no hace falta ninguna
 * librería de Office: basta ZipArchive y DOM, que vienen con PHP. Se leen las cosas
 * que aparecen en un contrato —párrafos, negrita, títulos, listas y tablas— y se
 * ignora lo demás, que es lo que no se puede reproducir con fidelidad de todos modos.
 *
 * La salida son bloques que FirmaDocWordPdf sabe pintar:
 *   ['tipo' => 'parrafo', 'titulo' => 0..6, 'alineacion' => ..., 'lista' => ..., 'trozos' => [...]]
 *   ['tipo' => 'tabla', 'filas' => [[texto, ...], ...]]
 *   ['tipo' => 'salto']
 */
class FirmaDocWordLector
{
    const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * Etiqueta que marca en la plantilla dónde va el recuadro de firma.
     *
     * Se admite {{firma.aqui}} y {{firma.aqui:2}} para decir de qué firmante es cuando
     * firman varios. Sin etiqueta, la firma va donde siempre: en el certificado final.
     */
    const ANCLA = '/\{\{\s*firma\.aqui(?::\s*(\d+))?\s*\}\}/i';

    /** Namespaces del dibujo y de las relaciones, para llegar a las imágenes */
    const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const NS_WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

    /** Word mide en EMU: 12700 por punto */
    const EMU = 12700;

    /** Tope por imagen. Una foto de varios megas no aporta nada a un contrato */
    const MAX_IMAGEN = 4194304;

    /** @var DOMXPath */
    private $xpath;

    /** @var array Formato de cada lista, por numId: 'vineta' o 'numero' */
    private $formatoListas = [];

    /** @var array Imágenes del documento, por identificador de relación */
    private $imagenes = [];

    /**
     * Los estilos del documento, por identificador.
     *
     * Hacen falta porque una plantilla seria no marca la negrita en cada trozo de
     * texto: define un estilo —«Clausula», «TituloContrato»— y lo aplica. Sin mirar
     * aquí, un contrato entero llega plano aunque en Word se vea con sus títulos.
     *
     * @var array
     */
    private $estilos = [];

    /** @var string Último error, para poder explicarlo */
    private $error = '';

    public function getError(): string
    {
        return $this->error;
    }

    /**
     * Devuelve los bloques del documento, o null si no se ha podido leer.
     */
    /**
     * Si el documento trae alguna etiqueta de firma, sin llegar a leerlo entero.
     *
     * Hace falta antes de convertir: con ancla no se puede delegar en LibreOffice,
     * porque entonces el PDF lo pinta él y aquí no se sabe dónde quedó el recuadro.
     */
    public static function tieneAncla(string $ruta): bool
    {
        if (!is_file($ruta)) {
            return false;
        }

        $zip = new ZipArchive();
        if (true !== $zip->open($ruta)) {
            return false;
        }

        $documento = $zip->getFromName('word/document.xml');
        $zip->close();

        if (false === $documento) {
            return false;
        }

        // Sobre el XML crudo: Word puede partir la etiqueta en varios runs, así que se
        // quitan las marcas de por medio antes de buscarla.
        $texto = preg_replace('/<[^>]+>/', '', $documento);

        return (bool) preg_match(self::ANCLA, (string) $texto);
    }

    public function leer(string $ruta): ?array
    {
        if (!is_file($ruta)) {
            $this->error = 'firmadoc-word-not-found';
            return null;
        }

        $zip = new ZipArchive();
        if (true !== $zip->open($ruta)) {
            $this->error = 'firmadoc-word-not-readable';
            return null;
        }

        $documento = $zip->getFromName('word/document.xml');
        if (false === $documento) {
            // Un .doc antiguo o un .docx corrupto no traen esta pieza
            $zip->close();
            $this->error = 'firmadoc-word-not-docx';
            return null;
        }

        $this->leerEstilos($zip->getFromName('word/styles.xml'));
        $this->leerFormatoDeListas($zip->getFromName('word/numbering.xml'));
        $this->leerImagenes($zip);
        $membrete = $this->leerMembrete($zip);
        $zip->close();

        $dom = new DOMDocument();
        // Los avisos de XML mal formado se recogen aquí y no se escupen a la pantalla
        $anterior = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($documento, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        if (!$ok) {
            $this->error = 'firmadoc-word-not-readable';
            return null;
        }

        $this->xpath = new DOMXPath($dom);
        $this->xpath->registerNamespace('w', self::NS);

        $cuerpo = $this->xpath->query('//w:body')->item(0);
        if (null === $cuerpo) {
            $this->error = 'firmadoc-word-not-readable';
            return null;
        }

        $bloques = $membrete;
        foreach ($cuerpo->childNodes as $nodo) {
            if (!$nodo instanceof DOMElement) {
                continue;
            }

            if ($nodo->localName === 'p') {
                // Las imágenes salen antes que el texto del párrafo, que es donde las
                // pone Word cuando el párrafo es solo el logotipo o una firma escaneada
                foreach ($this->leerImagenesDe($nodo) as $imagen) {
                    $bloques[] = $imagen;
                }

                $bloque = $this->leerParrafo($nodo);
                if (null !== $bloque) {
                    foreach ($this->partirPorAncla($bloque) as $trozo) {
                        $bloques[] = $trozo;
                    }
                }
            } elseif ($nodo->localName === 'tbl') {
                $bloques[] = $this->leerTabla($nodo);
            }
        }

        return $bloques;
    }

    /**
     * Qué viñeta lleva cada lista. Sin esto todas se pintarían igual y un contrato
     * con cláusulas numeradas perdería la numeración, que es justo lo que se cita.
     */
    private function leerFormatoDeListas($xmlNumbering): void
    {
        if (empty($xmlNumbering)) {
            return;
        }

        $dom = new DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xmlNumbering, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        if (!$ok) {
            return;
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS);

        // numId -> abstractNumId -> formato del primer nivel
        $abstractos = [];
        foreach ($xp->query('//w:abstractNum') as $abstracto) {
            $id = $abstracto->getAttributeNS(self::NS, 'abstractNumId');
            $formato = $xp->query('.//w:lvl[@w:ilvl="0"]/w:numFmt', $abstracto)->item(0);
            $valor = $formato ? $formato->getAttributeNS(self::NS, 'val') : 'bullet';
            $abstractos[$id] = ($valor === 'bullet') ? 'vineta' : 'numero';
        }

        foreach ($xp->query('//w:num') as $num) {
            $numId = $num->getAttributeNS(self::NS, 'numId');
            $ref = $xp->query('./w:abstractNumId', $num)->item(0);
            $abstractoId = $ref ? $ref->getAttributeNS(self::NS, 'val') : '';
            $this->formatoListas[$numId] = $abstractos[$abstractoId] ?? 'vineta';
        }
    }

    /**
     * Un párrafo con sus trozos de texto. Devuelve null si está vacío y no es un
     * salto de página, para no arrastrar líneas en blanco de más.
     */
    private function leerParrafo(DOMElement $p): ?array
    {
        $bloque = [
            'tipo' => 'parrafo',
            'titulo' => 0,
            'alineacion' => 'left',
            'lista' => '',
            'nivel' => 0,
            'sangria' => 0,
            'trozos' => [],
        ];

        $bloque['negrita'] = false;
        $bloque['cursiva'] = false;
        $bloque['tamano'] = 0.0;

        $propiedades = $this->xpath->query('./w:pPr', $p)->item(0);
        if ($propiedades) {
            $estilo = $this->xpath->query('./w:pStyle', $propiedades)->item(0);
            if ($estilo) {
                $id = $estilo->getAttributeNS(self::NS, 'val');
                $definicion = $this->resolverEstilo($id);
                $bloque['titulo'] = $definicion['titulo'];
                $bloque['negrita'] = $definicion['negrita'];
                $bloque['cursiva'] = $definicion['cursiva'];
                $bloque['tamano'] = $definicion['tamano'];
                if ($definicion['alineacion'] !== '') {
                    $bloque['alineacion'] = $definicion['alineacion'];
                }
            }

            $jc = $this->xpath->query('./w:jc', $propiedades)->item(0);
            if ($jc) {
                $valor = $jc->getAttributeNS(self::NS, 'val');
                $bloque['alineacion'] = in_array($valor, ['center', 'right', 'both'], true)
                    ? ($valor === 'both' ? 'full' : $valor)
                    : 'left';
            }

            $numPr = $this->xpath->query('./w:numPr', $propiedades)->item(0);
            if ($numPr) {
                $ilvl = $this->xpath->query('./w:ilvl', $numPr)->item(0);
                $numId = $this->xpath->query('./w:numId', $numPr)->item(0);
                $bloque['nivel'] = $ilvl ? (int) $ilvl->getAttributeNS(self::NS, 'val') : 0;
                $id = $numId ? $numId->getAttributeNS(self::NS, 'val') : '';
                $bloque['lista'] = $this->formatoListas[$id] ?? 'vineta';
            }

            $ind = $this->xpath->query('./w:ind', $propiedades)->item(0);
            if ($ind) {
                // Word mide en veinteavos de punto
                $izquierda = $ind->getAttributeNS(self::NS, 'left') ?: $ind->getAttributeNS(self::NS, 'start');
                $bloque['sangria'] = $izquierda === '' ? 0 : (int) round(((int) $izquierda) / 20);
            }
        }

        // Un título va en negrita aunque su estilo no lo diga: es lo que se espera de
        // un encabezado, y así el formato viaja entero en los trozos.
        if ($bloque['titulo'] > 0) {
            $bloque['negrita'] = true;
        }

        foreach ($this->xpath->query('./w:r | ./w:hyperlink/w:r', $p) as $run) {
            foreach ($this->leerRun($run, $bloque) as $trozo) {
                $bloque['trozos'][] = $trozo;
            }
        }

        $vacio = trim(implode('', array_column($bloque['trozos'], 'texto'))) === '';
        $saltoDePagina = $this->xpath->query('.//w:br[@w:type="page"]', $p)->length > 0;
        if ($saltoDePagina) {
            $bloque['salto_antes'] = true;
        }

        return ($vacio && !$saltoDePagina) ? null : $bloque;
    }

    /**
     * Si el párrafo lleva la etiqueta de firma, se parte en lo que va antes, el ancla
     * y lo que va después. Word suele trocear el texto en varios runs, así que la
     * etiqueta se busca sobre el párrafo entero y no run a run.
     *
     * @return array[] los bloques en que se convierte
     */
    private function partirPorAncla(array $bloque): array
    {
        $completo = implode('', array_column($bloque['trozos'], 'texto'));
        if (!preg_match(self::ANCLA, $completo, $coincide, PREG_OFFSET_CAPTURE)) {
            return [$bloque];
        }

        $inicio = $coincide[0][1];
        $fin = $inicio + strlen($coincide[0][0]);
        $firmante = isset($coincide[1][0]) && $coincide[1][0] !== '' ? (int) $coincide[1][0] : 1;

        $salida = [];
        $antes = $this->recortarTrozos($bloque['trozos'], 0, $inicio);
        if (trim(implode('', array_column($antes, 'texto'))) !== '') {
            $salida[] = array_merge($bloque, ['trozos' => $antes]);
        }

        $salida[] = [
            'tipo' => 'ancla',
            'firmante' => max(1, $firmante),
            'alineacion' => $bloque['alineacion'],
            'sangria' => $bloque['sangria'],
            'salto_antes' => !empty($bloque['salto_antes']) && empty($salida),
        ];

        $despues = $this->recortarTrozos($bloque['trozos'], $fin, strlen($completo));
        if (trim(implode('', array_column($despues, 'texto'))) !== '') {
            $resto = array_merge($bloque, ['trozos' => $despues]);
            unset($resto['salto_antes']);
            $salida[] = $resto;
        }

        return $salida;
    }

    /**
     * Los trozos que caen entre dos posiciones del texto del párrafo, conservando su
     * formato: cortar por caracteres a secas se llevaría por delante la negrita.
     */
    private function recortarTrozos(array $trozos, int $desde, int $hasta): array
    {
        $salida = [];
        $posicion = 0;

        foreach ($trozos as $trozo) {
            $largo = strlen($trozo['texto']);
            $ini = max($desde, $posicion);
            $fin = min($hasta, $posicion + $largo);

            if ($fin > $ini) {
                $salida[] = array_merge($trozo, [
                    'texto' => substr($trozo['texto'], $ini - $posicion, $fin - $ini),
                ]);
            }

            $posicion += $largo;
            if ($posicion >= $hasta) {
                break;
            }
        }

        return $salida;
    }

    /**
     * Los trozos de texto de un run, con su formato.
     */
    private function leerRun(DOMElement $run, array $base = []): array
    {
        // Lo que diga el trozo manda sobre lo que traiga su estilo, y este sobre el
        // formato que venga del párrafo.
        $delEstilo = ['negrita' => false, 'cursiva' => false];
        $rStyle = $this->xpath->query('./w:rPr/w:rStyle', $run)->item(0);
        if ($rStyle) {
            $definicion = $this->resolverEstilo($rStyle->getAttributeNS(self::NS, 'val'));
            $delEstilo = ['negrita' => $definicion['negrita'], 'cursiva' => $definicion['cursiva']];
        }

        $formato = [
            'negrita' => $this->xpath->query('./w:rPr/w:b', $run)->length > 0
                || $delEstilo['negrita'] || !empty($base['negrita']),
            'cursiva' => $this->xpath->query('./w:rPr/w:i', $run)->length > 0
                || $delEstilo['cursiva'] || !empty($base['cursiva']),
            'subrayado' => $this->xpath->query('./w:rPr/w:u', $run)->length > 0,
        ];

        $trozos = [];
        foreach ($run->childNodes as $hijo) {
            if (!$hijo instanceof DOMElement) {
                continue;
            }

            switch ($hijo->localName) {
                case 't':
                    $trozos[] = array_merge($formato, ['texto' => $hijo->textContent]);
                    break;

                case 'tab':
                    $trozos[] = array_merge($formato, ['texto' => '    ']);
                    break;

                case 'br':
                    // El salto de página se trata en el párrafo; aquí solo el de línea
                    if ($hijo->getAttributeNS(self::NS, 'type') !== 'page') {
                        $trozos[] = array_merge($formato, ['texto' => "\n"]);
                    }
                    break;
            }
        }

        return $trozos;
    }

    /**
     * Nivel de título a partir del nombre del estilo. Word los nombra en el idioma
     * en que se creó el documento, así que se aceptan las variantes habituales.
     */
    private function nivelDeTitulo(string $estilo): int
    {
        $limpio = strtolower(str_replace([' ', '-', '_'], '', $estilo));

        if (in_array($limpio, ['title', 'ttulo', 'titulo'], true)) {
            return 1;
        }

        if (preg_match('/^(heading|ttulo|titulo|berschrift|titre|encabezado)([1-6])$/', $limpio, $coincide)) {
            return (int) $coincide[2];
        }

        return 0;
    }

    private function leerTabla(DOMElement $tabla): array
    {
        $filas = [];
        foreach ($this->xpath->query('./w:tr', $tabla) as $tr) {
            $celdas = [];
            foreach ($this->xpath->query('./w:tc', $tr) as $tc) {
                $textos = [];
                foreach ($this->xpath->query('.//w:t', $tc) as $t) {
                    $textos[] = $t->textContent;
                }
                $celdas[] = trim(implode('', $textos));
            }
            if (!empty($celdas)) {
                $filas[] = $celdas;
            }
        }

        return ['tipo' => 'tabla', 'filas' => $filas];
    }

    /**
     * Deja en memoria las imágenes del documento, indexadas por el identificador con el
     * que las referencia el texto.
     *
     * Van en base64 y no como ficheros sueltos porque los bloques se archivan para
     * poder repetir el documento al firmar: con rutas habría que cuidar que nadie las
     * borrara por el camino.
     */
    private function leerImagenes(ZipArchive $zip): void
    {
        $this->imagenes = [];
        $this->leerRelaciones($zip, 'word/_rels/document.xml.rels');
    }

    /**
     * Añade a la lista las imágenes que declare un fichero de relaciones.
     */
    private function leerRelaciones(ZipArchive $zip, string $fichero): void
    {
        $rels = $zip->getFromName($fichero);
        if (false === $rels) {
            return;
        }

        $dom = new DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($rels, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        if (!$ok) {
            return;
        }

        foreach ($dom->getElementsByTagName('Relationship') as $rel) {
            $destino = $rel->getAttribute('Target');
            if (strpos($destino, 'media/') === false) {
                continue;
            }

            $nombre = 'word/' . ltrim(str_replace('../', '', $destino), '/');
            $datos = $zip->getFromName($nombre);
            if (false === $datos || strlen($datos) > self::MAX_IMAGEN) {
                continue;
            }

            $tipo = $this->tipoDeImagen($datos);
            if ($tipo === '') {
                // EMF y WMF son dibujos de Windows que aquí no se pueden pintar
                continue;
            }

            $this->imagenes[$rel->getAttribute('Id')] = [
                'datos' => 'data:' . $tipo . ';base64,' . base64_encode($datos),
                'tipo' => $tipo,
            ];
        }
    }

    /**
     * El formato real de la imagen, por sus primeros bytes. Vacío si no es de los que
     * la librería de PDF sabe pintar.
     */
    private function tipoDeImagen(string $datos): string
    {
        if (strncmp($datos, "\x89PNG", 4) === 0) {
            return 'image/png';
        }

        if (strncmp($datos, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }

        return '';
    }

    /**
     * Las imágenes de un párrafo, con el tamaño que Word les dio.
     *
     * @return array[] bloques de tipo imagen
     */
    private function leerImagenesDe(DOMElement $p): array
    {
        $bloques = [];

        foreach ($this->xpath->query('.//w:drawing', $p) as $dibujo) {
            $blip = $dibujo->getElementsByTagNameNS(self::NS_A, 'blip')->item(0);
            if (null === $blip) {
                continue;
            }

            $id = $blip->getAttributeNS(self::NS_R, 'embed');
            if ($id === '' || !isset($this->imagenes[$id])) {
                continue;
            }

            $ancho = 0.0;
            $alto = 0.0;
            $extent = $dibujo->getElementsByTagNameNS(self::NS_WP, 'extent')->item(0);
            if ($extent) {
                $ancho = ((float) $extent->getAttribute('cx')) / self::EMU;
                $alto = ((float) $extent->getAttribute('cy')) / self::EMU;
            }

            $bloques[] = [
                'tipo' => 'imagen',
                'datos' => $this->imagenes[$id]['datos'],
                'ancho' => $ancho,
                'alto' => $alto,
                'alineacion' => $this->alineacionDe($p),
            ];
        }

        return $bloques;
    }

    private function alineacionDe(DOMElement $p): string
    {
        $jc = $this->xpath->query('./w:pPr/w:jc', $p)->item(0);
        if (null === $jc) {
            return 'left';
        }

        $valor = $jc->getAttributeNS(self::NS, 'val');

        return in_array($valor, ['center', 'right'], true) ? $valor : 'left';
    }

    /**
     * El logotipo de la cabecera del documento.
     *
     * La cabecera de Word no está en document.xml sino en su propio fichero, así que
     * un membrete corporativo se perdía entero. Se recuperan solo sus imágenes y se
     * ponen una vez al principio: repetirlas en cada página exigiría reservar el hueco
     * arriba y correr todo el texto, y el resultado no se parecería más al original.
     *
     * @return array[] bloques de imagen, vacío si no hay cabecera con logotipo
     */
    private function leerMembrete(ZipArchive $zip): array
    {
        for ($i = 1; $i <= 3; $i++) {
            $xml = $zip->getFromName('word/header' . $i . '.xml');
            if (false === $xml) {
                continue;
            }

            $dom = new DOMDocument();
            $anterior = libxml_use_internal_errors(true);
            $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOENT);
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
            if (!$ok) {
                continue;
            }

            // Las relaciones de la cabecera son las suyas, no las del documento
            $this->leerRelaciones($zip, 'word/_rels/header' . $i . '.xml.rels');

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', self::NS);

            $bloques = [];
            foreach ($xpath->query('//w:p') as $p) {
                $anterior = $this->xpath;
                $this->xpath = $xpath;
                foreach ($this->leerImagenesDe($p) as $imagen) {
                    $bloques[] = $imagen;
                }
                $this->xpath = $anterior;
            }

            if (!empty($bloques)) {
                return $bloques;
            }
        }

        return [];
    }

    /**
     * Lee las definiciones de estilo del documento.
     */
    private function leerEstilos($xmlEstilos): void
    {
        $this->estilos = [];
        if (empty($xmlEstilos)) {
            return;
        }

        $dom = new DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xmlEstilos, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        if (!$ok) {
            return;
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS);

        foreach ($xp->query('//w:style') as $estilo) {
            $id = $estilo->getAttributeNS(self::NS, 'styleId');
            if ($id === '') {
                continue;
            }

            $nombre = $xp->query('./w:name', $estilo)->item(0);
            $basado = $xp->query('./w:basedOn', $estilo)->item(0);
            $jc = $xp->query('./w:pPr/w:jc', $estilo)->item(0);
            $sz = $xp->query('./w:rPr/w:sz', $estilo)->item(0);

            $alineacion = $jc ? $jc->getAttributeNS(self::NS, 'val') : '';
            if ($alineacion === 'both') {
                $alineacion = 'full';
            }

            $this->estilos[$id] = [
                // El nombre canónico es más fiable que el identificador, que Word
                // escribe en el idioma en que se creó el documento
                'nombre' => $nombre ? $nombre->getAttributeNS(self::NS, 'val') : $id,
                'basado' => $basado ? $basado->getAttributeNS(self::NS, 'val') : '',
                'negrita' => $xp->query('./w:rPr/w:b', $estilo)->length > 0,
                'cursiva' => $xp->query('./w:rPr/w:i', $estilo)->length > 0,
                // Word cuenta el tamaño en medios puntos
                'tamano' => $sz ? ((float) $sz->getAttributeNS(self::NS, 'val')) / 2 : 0.0,
                'alineacion' => in_array($alineacion, ['center', 'right', 'full'], true) ? $alineacion : '',
            ];
        }
    }

    /**
     * Lo que acaba valiendo un estilo, siguiendo la cadena de los que hereda.
     */
    private function resolverEstilo(string $id): array
    {
        $resultado = [
            'titulo' => $this->nivelDeTitulo($id),
            'negrita' => false,
            'cursiva' => false,
            'tamano' => 0.0,
            'alineacion' => '',
        ];

        $visitados = [];
        $actual = $id;
        while ($actual !== '' && isset($this->estilos[$actual]) && !isset($visitados[$actual])) {
            $visitados[$actual] = true;
            $estilo = $this->estilos[$actual];

            // Un estilo puede llamarse «Clausula» y ser en realidad «heading 2»
            if ($resultado['titulo'] === 0) {
                $resultado['titulo'] = $this->nivelDeTitulo($estilo['nombre']);
            }

            // Lo primero que se encuentra gana: el estilo propio manda sobre el heredado
            $resultado['negrita'] = $resultado['negrita'] || $estilo['negrita'];
            $resultado['cursiva'] = $resultado['cursiva'] || $estilo['cursiva'];
            if ($resultado['tamano'] === 0.0) {
                $resultado['tamano'] = $estilo['tamano'];
            }
            if ($resultado['alineacion'] === '') {
                $resultado['alineacion'] = $estilo['alineacion'];
            }

            $actual = $estilo['basado'];
        }

        return $resultado;
    }
}
