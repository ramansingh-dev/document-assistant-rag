<?php

namespace Tests\Unit;

/** Tiny hand-made stand-ins for scripts/extract_pdf.py output. */
final class ChunkerFixtures
{
    public static function block(string $text, float $size = 11, float $y0 = 100, float $x0 = 90, string $zone = 'body', bool $bold = false): array
    {
        return [
            'x0' => $x0, 'y0' => $y0, 'x1' => $x0 + 200, 'y1' => $y0 + 12,
            'size' => $size, 'bold' => $bold, 'zone' => $zone, 'text' => $text,
        ];
    }

    public static function row(float $y, array $cells): array
    {
        $out = [];
        $x = 40.0;
        foreach ($cells as $text) {
            $out[] = ['x0' => $x, 'x1' => $x + 60, 'text' => $text];
            $x += 90;
        }

        return ['y' => $y, 'x0' => 40.0, 'text' => implode(' ', $cells), 'cells' => $out];
    }

    public static function page(int $n, array $blocks, array $rows = [], ?string $printed = null): array
    {
        return ['page' => $n, 'printed' => $printed, 'blocks' => $blocks, 'rows' => $rows];
    }
}
