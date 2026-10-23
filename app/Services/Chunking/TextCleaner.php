<?php

namespace App\Services\Chunking;

/** Shared text normalisation for PDF-extracted text. */
final class TextCleaner
{
    /**
     * Some PDFs in the corpus lose the "Th" ligature, so "The" is extracted as "Te".
     */
    private const LIGATURE_FIXES = [
        'Te' => 'The', 'Tese' => 'These', 'Tis' => 'This', 'Tere' => 'There',
        'Tat' => 'That', 'Tey' => 'They', 'Tus' => 'Thus', 'Tose' => 'Those',
        'Tird' => 'Third', 'Terefore' => 'Therefore',
    ];

    /**
     * Collapse a block of PDF lines into continuous prose.
     *
     * @param  array<string,true>|null  $vocabulary  lower-case words seen unbroken in the document;
     *                                               a word split by a line-end hyphen is re-joined
     *                                               only when the joined form is in it
     */
    public static function prose(string $text, ?array $vocabulary = null): string
    {
        $text = str_replace(
            ["\r", "\u{00A0}", "\u{FB03}", "\u{FB04}", "\u{FB00}", "\u{FB01}", "\u{FB02}"],
            ["\n", ' ', 'ffi', 'ffl', 'ff', 'fi', 'fl'],
            $text
        );

        if ($vocabulary !== null) {
            $text = preg_replace_callback(
                '/([A-Za-z]+)-\n\s*([a-z]+)/',
                fn (array $m) => isset($vocabulary[strtolower($m[1].$m[2])]) ? $m[1].$m[2] : $m[1].'-'.$m[2],
                $text
            );
        }

        $lines = [];
        foreach (preg_split('/\n+/', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // A bare page number is never content.
            if (preg_match('/^\d{1,3}$/', $line)) {
                continue;
            }
            $lines[] = $line;
        }

        $text = implode(' ', $lines);

        // Drop-cap split ("T he") and the lost "Th" ligature.
        $text = preg_replace('/\bT\s+he\b/', 'The', $text);
        $text = self::fixLigatures($text);

        // A hyphen left at a line break: keep the hyphen, drop the gap.
        $text = preg_replace('/(?<=[A-Za-z0-9])-\s+(?=[a-z])/', '-', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    public static function fixLigatures(string $text): string
    {
        $pattern = '/\b('.implode('|', array_keys(self::LIGATURE_FIXES)).')\b/';

        return preg_replace_callback(
            $pattern,
            fn (array $m) => self::LIGATURE_FIXES[$m[1]],
            $text,
        );
    }

    /** Words of the document that were not broken across lines (lower-case, 4+ letters). */
    public static function vocabulary(array $texts): array
    {
        $vocabulary = [];
        foreach ($texts as $text) {
            $text = preg_replace('/[A-Za-z]+-\n\s*[a-z]+/', ' ', $text);
            if (preg_match_all('/\b[A-Za-z]{4,}\b/', $text, $matches)) {
                foreach ($matches[0] as $word) {
                    $vocabulary[strtolower($word)] = true;
                }
            }
        }

        return $vocabulary;
    }

    /** A line of acronyms / abbreviations is not prose worth retrieving. */
    public static function isAcronymList(string $text): bool
    {
        $tokens = preg_split('/\s+/', trim($text));
        if (count($tokens) < 12) {
            return false;
        }
        $acronyms = array_filter($tokens, fn ($t) => preg_match('/^[A-Z0-9&\/.-]{2,8}$/', $t));

        return count($acronyms) / count($tokens) >= 0.7;
    }

    /** Remove the "[^18]" footnote markers inserted by the extractor. */
    public static function stripFootnoteMarkers(string $text): string
    {
        return trim(preg_replace('/\s*\[\^[\d,]+\]/', '', $text));
    }

    /**
     * Split prose into sentences, never leaving a piece longer than $max
     * characters (some key/value lists have no sentence punctuation).
     *
     * @return string[]
     */
    public static function sentences(string $text, int $max): array
    {
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"\'(])/', $text) ?: [$text];

        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            while (mb_strlen($part) > $max) {
                $cut = mb_strrpos(mb_substr($part, 0, $max), ' ') ?: $max;
                $out[] = trim(mb_substr($part, 0, $cut));
                $part = trim(mb_substr($part, $cut));
            }
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }
}
