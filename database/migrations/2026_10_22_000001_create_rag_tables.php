<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('filename');
            $table->string('original_extension', 10)->default('pdf');
            $table->string('profile', 20)->default('narrative');
            $table->string('status', 20)->default('processing');
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            // Child chunks point at their parent section (structured profile).
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('kind', 20)->default('paragraph'); // paragraph|section|table|chart|callout|footnote
            $table->string('section_path')->nullable();
            $table->unsignedInteger('page_start')->nullable();
            $table->unsignedInteger('page_end')->nullable();
            $table->string('printed_page', 12)->nullable();
            $table->longText('content');
            // Text actually embedded / BM25-indexed: breadcrumb + content.
            $table->longText('search_text');
            // float32 little-endian vector, packed. Parent sections have none.
            $table->binary('embedding')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'kind']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_uuid')->index();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10);
            $table->longText('content');
            $table->json('sources')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('document_chunks');
        Schema::dropIfExists('documents');
    }
};
