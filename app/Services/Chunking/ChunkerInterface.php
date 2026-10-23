<?php

namespace App\Services\Chunking;

interface ChunkerInterface
{
    /**
     * @param  array  $extract  decoded output of scripts/extract_pdf.py
     * @return Chunk[]
     */
    public function chunk(array $extract, string $documentName): array;
}
