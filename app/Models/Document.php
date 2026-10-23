<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $fillable = [
        'name', 'filename', 'original_extension', 'profile', 'status',
        'page_count', 'chunk_count', 'error_message',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
    }
}
