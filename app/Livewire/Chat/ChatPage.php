<?php

namespace App\Livewire\Chat;

use App\Models\Conversation;
use App\Models\Document;
use App\Services\RagChatService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * The whole chat UI: document picker, conversation sidebar, message list,
 * composer, and the streamed answer. A conversation is permanently scoped to
 * one document; switching document starts a new conversation.
 */
#[Title('Document Assistant')]
class ChatPage extends Component
{
    public ?int $documentId = null;

    public ?int $conversationId = null;

    public string $question = '';

    /** Question whose answer is currently being generated and streamed. */
    public ?string $pending = null;

    public ?string $error = null;

    public function mount(): void
    {
        if (! session()->has('rag_session_uuid')) {
            session(['rag_session_uuid' => (string) Str::uuid()]);
        }
    }

    // ---------------------------------------------------------------- state

    #[Computed]
    public function documents(): Collection
    {
        return Document::ready()->orderBy('id')->get();
    }

    #[Computed]
    public function document(): ?Document
    {
        return $this->documentId ? Document::find($this->documentId) : null;
    }

    #[Computed]
    public function conversations(): Collection
    {
        return Conversation::with('document')
            ->where('session_uuid', session('rag_session_uuid'))
            ->has('messages')
            ->orderByDesc('updated_at')
            ->limit(60)
            ->get();
    }

    /** @return array<string, Collection> sidebar groups by recency */
    #[Computed]
    public function groupedConversations(): array
    {
        $groups = ['Today' => collect(), 'Previous 7 days' => collect(), 'Older' => collect()];
        foreach ($this->conversations as $conversation) {
            $key = match (true) {
                $conversation->updated_at->isToday() => 'Today',
                $conversation->updated_at->greaterThan(now()->subDays(7)) => 'Previous 7 days',
                default => 'Older',
            };
            $groups[$key]->push($conversation);
        }

        return array_filter($groups, fn ($group) => $group->isNotEmpty());
    }

    #[Computed]
    public function messages(): Collection
    {
        return $this->conversationId
            ? Conversation::findOrFail($this->conversationId)->messages()->get()
            : collect();
    }

    public function profileCopy(?Document $document): array
    {
        return config('rag_documents.'.($document?->profile ?? 'narrative'), []);
    }

    // -------------------------------------------------------------- actions

    public function selectDocument(int $id): void
    {
        $this->reset('conversationId', 'question', 'pending', 'error');
        $this->documentId = $id;
        $this->resetComputed();
    }

    public function newChat(): void
    {
        $this->reset('documentId', 'conversationId', 'question', 'pending', 'error');
        $this->resetComputed();
    }

    public function openConversation(int $id): void
    {
        $conversation = Conversation::where('session_uuid', session('rag_session_uuid'))->findOrFail($id);
        $this->reset('question', 'pending', 'error');
        $this->conversationId = $conversation->id;
        $this->documentId = $conversation->document_id;
        $this->resetComputed();
    }

    public function deleteConversation(int $id): void
    {
        Conversation::where('session_uuid', session('rag_session_uuid'))->where('id', $id)->delete();
        if ($this->conversationId === $id) {
            $this->reset('conversationId', 'pending');
        }
        $this->resetComputed();
    }

    /** Step 1: show the question immediately; generate() then streams the answer. */
    public function submit(?string $preset = null): void
    {
        $text = trim($preset ?? $this->question);
        if ($text === '' || ! $this->documentId || $this->pending !== null) {
            return;
        }

        $this->error = null;
        $this->question = '';
        $this->pending = $text;
        $this->resetComputed();
    }

    /** Step 2: retrieve, call the local model, stream fragments, persist. */
    public function generate(RagChatService $chat): void
    {
        if ($this->pending === null || ! $this->documentId) {
            return;
        }

        $question = $this->pending;

        try {
            $conversation = $this->conversationId
                ? Conversation::findOrFail($this->conversationId)
                : Conversation::create([
                    'session_uuid' => session('rag_session_uuid'),
                    'document_id' => $this->documentId,
                ]);
            $this->conversationId = $conversation->id;

            $buffer = '';
            foreach ($chat->askStream($conversation, $question) as $event) {
                if ($event['type'] === 'delta') {
                    $buffer .= $event['text'];
                    $this->stream(to: 'answer', content: $event['text']);
                }
            }
        } catch (Throwable $e) {
            report($e);
            $this->error = 'The local model could not answer: '.Str::limit($e->getMessage(), 200)
                .' Is Ollama running?';
        }

        $this->pending = null;
        $this->resetComputed();
    }

    private function resetComputed(): void
    {
        unset($this->documents, $this->document, $this->conversations, $this->groupedConversations, $this->messages);
    }

    public function render()
    {
        return view('livewire.chat.chat-page')->layout('layouts::app');
    }
}
