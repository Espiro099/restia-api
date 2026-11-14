<?php

namespace App\Fiscal;

/**
 * PDF mínimo de solo texto, sin dependencias: páginas carta con Courier (ancho fijo, para alinear
 * columnas). Basta para la representación impresa; un diseño más cuidado puede llegar con el panel Web.
 */
final class SimplePdf
{
    private const WIDTH = 612;

    private const HEIGHT = 792;

    private const MARGIN = 50;

    /** @param list<array{0: string, 1?: int}> $lines texto y tamaño de letra (9 por omisión) */
    public static function render(array $lines): string
    {
        $pages = [];
        $y = self::HEIGHT - self::MARGIN;
        $stream = '';
        foreach ($lines as $line) {
            $size = $line[1] ?? 9;
            $y -= $size + 5;
            if ($y < self::MARGIN) {
                $pages[] = $stream;
                $stream = '';
                $y = self::HEIGHT - self::MARGIN - $size - 5;
            }
            if ($line[0] !== '') {
                $stream .= sprintf("BT /F1 %d Tf %d %d Td (%s) Tj ET\n", $size, self::MARGIN, $y, self::escape($line[0]));
            }
        }
        $pages[] = $stream;

        // Objetos: 1 catálogo, 2 páginas, 3 fuente, y por página su objeto y su contenido.
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>'];
        $kids = [];
        foreach ($pages as $i => $content) {
            $page = 4 + $i * 2;
            $kids[] = "$page 0 R";
            $objects[$page] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R >> >> /Contents %d 0 R >>',
                self::WIDTH, self::HEIGHT, $page + 1);
            $objects[$page + 1] = '<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream';
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "$n 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    /** WinAnsi para acentos y eñes; paréntesis y diagonales invertidas escapados. */
    private static function escape(string $text): string
    {
        $text = (string) iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }
}
