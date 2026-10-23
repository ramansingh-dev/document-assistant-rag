<?php

namespace App\Services\Retrieval;

use App\Models\Document;
use Illuminate\Support\Facades\DB;

/**
 * Rule-based (no LLM) mapping from a question to the part of a structured
 * document it is about, so "South Asia" never retrieves "Sub-Saharan Africa".
 *
 * Signals: "Chapter N", "Box N.N", "executive summary", section names and their
 * acronyms (SAR, EAP, ...). The result is a list of section_path prefixes.
 */
class QuestionRouter
{
    /** Acronyms used inside the corpus for long section names. */
    private const ACRONYMS = [
        'EAP' => 'East Asia and Pacific',
        'ECA' => 'Europe and Central Asia',
        'LAC' => 'Latin America and the Caribbean',
        'MNA' => 'Middle East, North Africa',
        'SAR' => 'South Asia',
        'SSA' => 'Sub-Saharan Africa',
    ];

    /**
     * @return array{prefixes:string[],labels:string[],chapter:?string}|null
     */
    public function route(Document $document, string $question): ?array
    {
        if ($document->profile !== 'structured') {
            return null;
        }

        $paths = DB::table('document_chunks')
            ->where('document_id', $document->id)
            ->where('kind', 'section')
            ->distinct()
            ->pluck('section_path')
            ->all();

        return $this->match($paths, $question);
    }

    /**
     * @param  string[]  $paths  distinct section paths of the document
     * @return array{prefixes:string[],labels:string[],chapter:?string}|null
     */
    public function match(array $paths, string $question): ?array
    {
        $q = ' '.mb_strtolower($question).' ';
        $prefixes = [];
        $labels = [];
        $chapter = null;

        // Chapter N
        if (preg_match('/\b(?:chapter|ch\.?)\s*(\d+)\b/i', $question, $m)) {
            foreach ($paths as $path) {
                if (str_starts_with($path, "Ch.{$m[1]} ") && ! str_contains($path, ' > ')) {
                    $prefixes[] = $path;
                    $labels[] = $path;
                    $chapter = $path;
                }
            }
        }

        // Box N.N
        if (preg_match('/\bbox\s*(\d+\.\d+)\b/i', $question, $m)) {
            foreach ($paths as $path) {
                if (str_contains($path, "Box {$m[1]} ")) {
                    $prefixes[] = $path;
                    $labels[] = $path;
                }
            }
        }

        if (str_contains($q, 'executive summary')) {
            foreach ($paths as $path) {
                if (str_starts_with($path, 'Executive Summary')) {
                    $prefixes[] = $path;
                    $labels[] = $path;
                }
            }
        }

        // Named sections (regions) and their acronyms. Only leaf sections qualify.
        foreach ($paths as $path) {
            if (! str_contains($path, ' > ') || str_contains($path, 'Box ')) {
                continue;
            }
            $leaf = trim(substr($path, strrpos($path, ' > ') + 3));
            $names = [mb_strtolower($leaf)];
            $first = trim(preg_split('/,| and /', $leaf)[0]);
            if (str_word_count($first) >= 2 || mb_strlen($first) >= 8) {
                $names[] = mb_strtolower($first);
            }
            foreach (self::ACRONYMS as $acronym => $title) {
                if (stripos($leaf, explode(',', $title)[0]) === 0 && preg_match('/\b'.$acronym.'\b/i', $question)) {
                    $names[] = mb_strtolower($acronym);
                }
            }

            foreach ($names as $name) {
                if (preg_match('/(?<![a-z-])'.preg_quote($name, '/').'(?![a-z-])/i', $q)) {
                    // "Asia" alone must not match; names are phrases or acronyms.
                    $prefixes[] = $path;
                    $labels[] = $leaf;
                    break;
                }
            }
        }

        $prefixes = array_values(array_unique($prefixes));
        if (! $prefixes) {
            return null;
        }

        // "South Asia" must not also pull in "East Asia and Pacific" etc.: keep the most specific.
        return [
            'prefixes' => $prefixes,
            'labels' => array_values(array_unique($labels)),
            'chapter' => $chapter,
        ];
    }

    public static function matches(array $route, ?string $sectionPath): bool
    {
        if ($sectionPath === null) {
            return false;
        }
        foreach ($route['prefixes'] as $prefix) {
            if (str_starts_with($sectionPath, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
