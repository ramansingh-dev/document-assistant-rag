<?php

namespace App\Services\Chunking;

/**
 * One retrievable unit produced by a chunker.
 *
 * `content` is what the user sees and what the model is told; `searchText` is
 * what gets embedded and BM25-indexed (content plus breadcrumb/context).
 * Parent chunks (structured profile) are stored without an embedding and are
 * only used to give the model the surrounding section text.
 */
final class Chunk
{
    public function __construct(
        public string $kind,
        public string $content,
        public string $searchText,
        public ?int $pageStart = null,
        public ?int $pageEnd = null,
        public ?string $printedPage = null,
        public ?string $sectionPath = null,
        public ?string $key = null,
        public ?string $parentKey = null,
        public bool $isParent = false,
    ) {}
}
