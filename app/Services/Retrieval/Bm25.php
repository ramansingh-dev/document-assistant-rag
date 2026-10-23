<?php

namespace App\Services\Retrieval;

/** Okapi BM25 over a small in-memory corpus (one document's chunks). */
class Bm25
{
    private const STOPWORDS = [
        'the', 'a', 'an', 'of', 'in', 'on', 'at', 'to', 'for', 'and', 'or', 'is', 'are', 'was', 'were',
        'be', 'by', 'with', 'as', 'it', 'its', 'this', 'that', 'what', 'which', 'how', 'does', 'do',
        'did', 'from', 'about', 'has', 'have', 'had', 'much', 'many', 'than', 'their', 'there', 'says',
        'say', 'according',
    ];

    /** @var array<int, array<string,int>> term frequencies per document */
    private array $termFrequencies = [];

    /** @var array<int,int> */
    private array $lengths = [];

    /** @var array<string,int> */
    private array $documentFrequency = [];

    private float $averageLength = 0.0;

    public function __construct(array $texts, private float $k1 = 1.5, private float $b = 0.75)
    {
        foreach ($texts as $id => $text) {
            $tokens = self::tokenize($text);
            $this->lengths[$id] = count($tokens);
            $frequencies = array_count_values($tokens);
            $this->termFrequencies[$id] = $frequencies;
            foreach (array_keys($frequencies) as $term) {
                $this->documentFrequency[$term] = ($this->documentFrequency[$term] ?? 0) + 1;
            }
        }
        $this->averageLength = $this->lengths ? array_sum($this->lengths) / count($this->lengths) : 0.0;
    }

    /** @return string[] */
    public static function tokenize(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}.]*[\p{L}\p{N}]|[\p{L}\p{N}]/u', mb_strtolower($text), $m);

        return array_values(array_filter(
            array_map(fn ($t) => rtrim($t, '.'), $m[0]),
            fn ($t) => $t !== '' && ! in_array($t, self::STOPWORDS, true),
        ));
    }

    /**
     * @param  int[]|null  $only  restrict scoring to these document ids
     * @return array<int,float> id => score (descending, positive scores only)
     */
    public function scores(string $query, ?array $only = null): array
    {
        $terms = array_unique(self::tokenize($query));
        $count = count($this->lengths);
        $scores = [];

        foreach ($only ?? array_keys($this->lengths) as $id) {
            $score = 0.0;
            $length = $this->lengths[$id] ?? 0;
            foreach ($terms as $term) {
                $tf = $this->termFrequencies[$id][$term] ?? 0;
                if ($tf === 0) {
                    continue;
                }
                $df = $this->documentFrequency[$term];
                $idf = log(1 + ($count - $df + 0.5) / ($df + 0.5));
                $score += $idf * ($tf * ($this->k1 + 1))
                    / ($tf + $this->k1 * (1 - $this->b + $this->b * $length / max($this->averageLength, 1)));
            }
            if ($score > 0) {
                $scores[$id] = $score;
            }
        }

        arsort($scores);

        return $scores;
    }
}
