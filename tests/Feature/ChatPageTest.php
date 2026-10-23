<?php

use App\Livewire\Chat\ChatPage;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\RagChatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** A 3-dimensional "embedding" is enough: the fake Ollama returns fixed vectors. */
function seedDocument(string $name, string $profile, array $chunks): Document
{
    $document = Document::create([
        'name' => $name, 'filename' => "{$name}.pdf", 'profile' => $profile,
        'status' => 'ready', 'page_count' => 5, 'chunk_count' => count($chunks),
    ]);

    foreach ($chunks as $i => [$content, $vector, $kind, $page]) {
        $row = DocumentChunk::create([
            'document_id' => $document->id, 'chunk_index' => $i, 'kind' => $kind,
            'page_start' => $page, 'page_end' => $page, 'printed_page' => (string) $page,
            'content' => $content, 'search_text' => $content,
        ]);
        $statement = DB::connection()->getPdo()->prepare('UPDATE document_chunks SET embedding = ? WHERE id = ?');
        $statement->bindValue(1, DocumentChunk::packVector($vector), PDO::PARAM_LOB);
        $statement->bindValue(2, $row->id);
        $statement->execute();
    }

    return $document;
}

function fakeOllama(string $answer = 'iMac uses 58 percent less energy (page 7).'): void
{
    $stream = collect(explode(' ', $answer))
        ->map(fn ($w) => json_encode(['message' => ['content' => $w.' '], 'done' => false]))
        ->push(json_encode(['message' => ['content' => ''], 'done' => true]))
        ->implode("\n");

    Http::fake([
        '*/api/embed' => Http::response(['embeddings' => [[1.0, 0.0, 0.0]]]),
        '*/api/chat' => Http::response($stream),
    ]);
}

it('lists only ready documents in the picker', function () {
    seedDocument('Ready doc', 'visual', [['x', [1, 0, 0], 'paragraph', 1]]);
    Document::create(['name' => 'Broken doc', 'filename' => 'b.pdf', 'status' => 'failed']);

    Livewire::test(ChatPage::class)
        ->assertSee('Ready doc')
        ->assertDontSee('Broken doc')
        ->assertSee('Choose a document');
});

it('answers from the selected document, cites sources and saves the conversation', function () {
    fakeOllama();
    $document = seedDocument('iMac report', 'visual', [
        ['iMac uses 58 percent less energy than the requirement for ENERGY STAR.', [1, 0, 0], 'paragraph', 7],
        ['Unrelated packaging sentence.', [0, 1, 0], 'paragraph', 6],
    ]);

    Livewire::test(ChatPage::class)
        ->call('selectDocument', $document->id)
        ->set('question', 'How much less energy does iMac use?')
        ->call('submit')
        ->assertSet('pending', 'How much less energy does iMac use?')
        ->call('generate')
        ->assertSet('pending', null)
        ->assertSee('58 percent less energy')
        ->assertSee('Sources');

    $conversation = Conversation::first();
    expect($conversation->document_id)->toBe($document->id)
        ->and($conversation->messages)->toHaveCount(2)
        ->and($conversation->messages[1]->sources[0]['page_label'])->toContain('p.7');

    // The model only ever saw the question and context from this document.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/chat')) {
            return false;
        }
        $messages = $request['messages'];

        return str_contains($messages[0]['content'], 'iMac report')
            && str_contains($messages[array_key_last($messages)]['content'], 'ENERGY STAR');
    });
});

it('keeps retrieval scoped to the conversation document', function () {
    fakeOllama('ok');
    $a = seedDocument('Doc A', 'narrative', [['Secret of document A.', [1, 0, 0], 'paragraph', 1]]);
    $b = seedDocument('Doc B', 'narrative', [['Secret of document B.', [1, 0, 0], 'paragraph', 1]]);

    $conversation = Conversation::create(['session_uuid' => 'x', 'document_id' => $a->id]);
    $result = app(RagChatService::class)->ask($conversation, 'What is the secret?');

    expect(collect($result['sources'])->pluck('excerpt')->implode(' '))
        ->toContain('document A')->not->toContain('document B');
});

it('switching document starts a fresh conversation', function () {
    $a = seedDocument('Doc A', 'narrative', [['A text.', [1, 0, 0], 'paragraph', 1]]);
    $b = seedDocument('Doc B', 'narrative', [['B text.', [1, 0, 0], 'paragraph', 1]]);

    $existing = Conversation::create(['session_uuid' => 'x', 'document_id' => $a->id]);

    Livewire::test(ChatPage::class)
        ->call('selectDocument', $a->id)
        ->set('conversationId', $existing->id)
        ->call('selectDocument', $b->id)
        ->assertSet('documentId', $b->id)
        ->assertSet('conversationId', null);
});

it('shows a helpful error when Ollama is unreachable', function () {
    Http::fake(['*' => Http::response('boom', 500)]);
    $document = seedDocument('iMac report', 'visual', [['text', [1, 0, 0], 'paragraph', 1]]);

    Livewire::test(ChatPage::class)
        ->call('selectDocument', $document->id)
        ->set('question', 'anything')
        ->call('submit')
        ->call('generate')
        ->assertSet('pending', null)
        ->assertSee('Is Ollama running?');
});
