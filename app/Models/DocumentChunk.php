<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    protected $fillable = [
        'document_id', 'chunk_index', 'parent_id', 'kind', 'section_path',
        'page_start', 'page_end', 'printed_page', 'content', 'search_text', 'embedding',
    ];

    protected $hidden = ['embedding'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @param  float[]  $vector */
    public static function packVector(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /** @return float[] */
    public static function unpackVector(mixed $blob): array
    {
        // SQLite may hand binary columns back as a stream resource.
        if (is_resource($blob)) {
            $blob = stream_get_contents($blob);
        }

        if (! is_string($blob) || $blob === '') {
            return [];
        }

        return array_values(unpack('g*', $blob));
    }

    /** Human readable page label, e.g. "Page 74 (PDF p.96)". */
    public function pageLabel(): string
    {
        $start = $this->page_start;
        $end = $this->page_end ?? $start;
        $pdf = $start === $end ? "PDF p.{$start}" : "PDF pp.{$start}-{$end}";

        if ($this->printed_page && $start === $end) {
            return "Page {$this->printed_page} ({$pdf})";
        }

        return $pdf;
    }
}
