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

    /** @var DOMXPath */
    private $xpath;

    /** @var array Formato de cada lista, por numId: 'vineta' o 'numero' */
    private $formatoListas = [];

    /** @var string Último error, para poder explicarlo */
    private $error = '';

    public function getError(): string
    {
        return $this->error;
    }

    /**
     * Devuelve los bloques del documento, o null si no se ha podido leer.
     */
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

        $this->leerFormatoDeListas($zip->getFromName('word/numbering.xml'));
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

        $bloques = [];
        foreach ($cuerpo->childNodes as $nodo) {
            if (!$nodo instanceof DOMElement) {
                continue;
            }

            if ($nodo->localName === 'p') {
                $bloque = $this->leerParrafo($nodo);
                if (null !== $bloque) {
                    $bloques[] = $bloque;
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

        $propiedades = $this->xpath->query('./w:pPr', $p)->item(0);
        if ($propiedades) {
            $estilo = $this->xpath->query('./w:pStyle', $propiedades)->item(0);
            if ($estilo) {
                $bloque['titulo'] = $this->nivelDeTitulo($estilo->getAttributeNS(self::NS, 'val'));
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

        foreach ($this->xpath->query('./w:r | ./w:hyperlink/w:r', $p) as $run) {
            foreach ($this->leerRun($run) as $trozo) {
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
     * Los trozos de texto de un run, con su formato.
     */
    private function leerRun(DOMElement $run): array
    {
        $formato = [
            'negrita' => $this->xpath->query('./w:rPr/w:b', $run)->length > 0,
            'cursiva' => $this->xpath->query('./w:rPr/w:i', $run)->length > 0,
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

        if (preg_match('/^(heading|ttulo|titulo|berschrift|titre)([1-6])$/', $limpio, $coincide)) {
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
}
