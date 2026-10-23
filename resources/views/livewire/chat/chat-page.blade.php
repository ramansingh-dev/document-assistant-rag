<div class="flex h-screen" x-data="{ sidebar: false }">

    {{-- ───────────── Sidebar: past conversations ───────────── --}}
    <aside class="fixed inset-y-0 left-0 z-30 flex w-72 -translate-x-full flex-col border-r border-stone-200 bg-white transition-transform md:static md:translate-x-0 dark:border-stone-800 dark:bg-stone-900"
           :class="{ 'translate-x-0': sidebar }">
        <div class="p-3">
            <button wire:click="newChat" x-on:click="sidebar = false"
                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-stone-900 px-3 py-2 text-sm font-medium text-white hover:bg-stone-700 dark:bg-stone-100 dark:text-stone-900 dark:hover:bg-stone-300">
                <span aria-hidden="true">+</span> New chat
            </button>
        </div>

        <nav class="flex-1 space-y-4 overflow-y-auto px-2 pb-4" aria-label="Conversations">
            @forelse ($this->groupedConversations as $label => $group)
                <div>
                    <h3 class="px-2 pb-1 text-xs font-semibold tracking-wide text-stone-500 uppercase">{{ $label }}</h3>
                    <ul class="space-y-0.5">
                        @foreach ($group as $conversation)
                            <li wire:key="conv-{{ $conversation->id }}" class="group relative">
                                <button wire:click="openConversation({{ $conversation->id }})" x-on:click="sidebar = false"
                                        class="w-full rounded-lg px-2 py-2 pr-8 text-left text-sm hover:bg-stone-100 dark:hover:bg-stone-800 {{ $conversationId === $conversation->id ? 'bg-stone-100 dark:bg-stone-800' : '' }}">
                                    <span class="block truncate font-medium">{{ $conversation->title ?? 'New conversation' }}</span>
                                    <span class="block truncate text-xs text-stone-500">{{ $conversation->document?->name ?? 'Document removed' }}</span>
                                </button>
                                <button wire:click="deleteConversation({{ $conversation->id }})"
                                        wire:confirm="Delete this conversation?"
                                        class="absolute top-2 right-1 hidden rounded p-1 text-stone-400 group-hover:block hover:text-red-600"
                                        aria-label="Delete conversation">&times;</button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="px-2 text-sm text-stone-500">No conversations yet.</p>
            @endforelse
        </nav>

        <div class="border-t border-stone-200 p-3 text-xs text-stone-500 dark:border-stone-800">
            Runs fully local: Ollama
            <span class="font-mono">{{ config('rag.ollama.chat_model') }}</span> +
            <span class="font-mono">{{ config('rag.ollama.embedding_model') }}</span>
        </div>
    </aside>

    {{-- ───────────── Main column ───────────── --}}
    <main class="flex min-w-0 flex-1 flex-col">
        <header class="flex items-center gap-3 border-b border-stone-200 bg-white px-4 py-3 dark:border-stone-800 dark:bg-stone-900">
            <button class="rounded-md border border-stone-300 px-2 py-1 text-sm md:hidden dark:border-stone-700"
                    x-on:click="sidebar = !sidebar" aria-label="Toggle conversations">&#9776;</button>

            <h1 class="text-base font-semibold">Document Assistant</h1>

            {{-- Document switcher: always visible; changing it starts a new, document-scoped chat. --}}
            <label class="ml-auto flex items-center gap-2 text-sm">
                <span class="hidden text-stone-500 sm:inline">Document</span>
                <select wire:change="selectDocument($event.target.value)"
                        class="max-w-[16rem] rounded-lg border border-stone-300 bg-white px-2 py-1.5 text-sm dark:border-stone-700 dark:bg-stone-800">
                    <option value="" @selected(! $documentId) disabled>Choose a document…</option>
                    @foreach ($this->documents as $doc)
                        <option value="{{ $doc->id }}" @selected($documentId === $doc->id)>{{ $doc->name }}</option>
                    @endforeach
                </select>
            </label>
        </header>

        {{-- Keep the newest message (and the streaming answer) in view. --}}
        <div x-ref="scroller" class="flex-1 overflow-y-auto"
             x-init="new MutationObserver(() => { $el.scrollTop = $el.scrollHeight }).observe($el, { childList: true, subtree: true, characterData: true })">
            <div class="mx-auto w-full max-w-3xl space-y-6 px-4 py-6">

                @if (! $documentId)
                    {{-- ───── Empty state: pick one of the three documents ───── --}}
                    <section>
                        <h2 class="text-2xl font-semibold">Ask a document</h2>
                        <p class="mt-1 text-stone-600 dark:text-stone-400">
                            Each chat is scoped to one document and answers only from it. Pick a document to start.
                        </p>

                        <div class="mt-6 grid gap-4">
                            @forelse ($this->documents as $doc)
                                @php($copy = $this->profileCopy($doc))
                                <div wire:key="doc-{{ $doc->id }}" class="rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
                                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                                        <h3 class="font-semibold">{{ $doc->name }}</h3>
                                        <span class="text-xs text-stone-500">{{ $doc->page_count }} pages · {{ $doc->chunk_count }} chunks · {{ $doc->profile }} profile</span>
                                    </div>
                                    @if ($copy)
                                        <p class="mt-1 text-xs font-medium text-indigo-700 dark:text-indigo-300">{{ $copy['use_case'] }}</p>
                                        <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ $copy['blurb'] }}</p>
                                    @endif
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button wire:click="selectDocument({{ $doc->id }})"
                                                class="rounded-lg bg-stone-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-stone-700 dark:bg-stone-100 dark:text-stone-900">
                                            Chat with this document
                                        </button>
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-xl border border-dashed border-stone-300 p-6 text-sm text-stone-600 dark:border-stone-700 dark:text-stone-400">
                                    No documents are ingested yet. Run
                                    <code class="rounded bg-stone-100 px-1 py-0.5 dark:bg-stone-800">php artisan document:ingest path/to/file.pdf</code>
                                    or <code class="rounded bg-stone-100 px-1 py-0.5 dark:bg-stone-800">php artisan db:seed</code>.
                                </div>
                            @endforelse
                        </div>
                    </section>
                @else
                    @php($copy = $this->profileCopy($this->document))

                    @if ($this->messages->isEmpty() && $pending === null)
                        <section class="rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
                            <h2 class="font-semibold">{{ $this->document?->name }}</h2>
                            @if ($copy)
                                <p class="mt-1 text-sm text-stone-600 dark:text-stone-400">{{ $copy['blurb'] }}</p>
                                <p class="mt-4 text-xs font-semibold tracking-wide text-stone-500 uppercase">Try a question</p>
                                <div class="mt-2 flex flex-col gap-2">
                                    @foreach ($copy['questions'] as $sample)
                                        <button wire:click="submit(@js($sample))" wire:key="sample-{{ $loop->index }}"
                                                class="rounded-lg border border-stone-200 px-3 py-2 text-left text-sm hover:bg-stone-50 dark:border-stone-700 dark:hover:bg-stone-800">
                                            {{ $sample }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    @endif

                    {{-- ───── Messages ───── --}}
                    @foreach ($this->messages as $message)
                        @if ($message->role === 'user')
                            <div wire:key="msg-{{ $message->id }}" class="flex justify-end">
                                <div class="max-w-[85%] rounded-2xl rounded-br-sm bg-stone-900 px-4 py-2.5 text-sm text-white dark:bg-stone-100 dark:text-stone-900">
                                    <p class="answer-text">{{ $message->content }}</p>
                                </div>
                            </div>
                        @else
                            <div wire:key="msg-{{ $message->id }}" class="flex flex-col items-start gap-2">
                                <div class="max-w-[92%] rounded-2xl rounded-bl-sm border border-stone-200 bg-white px-4 py-2.5 text-sm dark:border-stone-800 dark:bg-stone-900">
                                    <p class="answer-text">{{ $message->content }}</p>
                                </div>
                                @include('livewire.chat.sources', ['sources' => $message->sources ?? []])
                            </div>
                        @endif
                    @endforeach

                    {{-- ───── In-flight turn: the question, then the streamed answer ───── --}}
                    @if ($pending !== null)
                        <div class="flex justify-end">
                            <div class="max-w-[85%] rounded-2xl rounded-br-sm bg-stone-900 px-4 py-2.5 text-sm text-white dark:bg-stone-100 dark:text-stone-900">
                                <p class="answer-text">{{ $pending }}</p>
                            </div>
                        </div>
                        <div class="flex items-start" wire:init="generate">
                            <div class="max-w-[92%] rounded-2xl rounded-bl-sm border border-stone-200 bg-white px-4 py-2.5 text-sm dark:border-stone-800 dark:bg-stone-900">
                                <p class="answer-text" wire:stream="answer"><span class="animate-pulse text-stone-400">Searching the document…</span></p>
                            </div>
                        </div>
                    @endif

                    @if ($error)
                        <div role="alert" class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                            {{ $error }}
                        </div>
                    @endif
                @endif
            </div>
        </div>

        {{-- ───── Composer ───── --}}
        <footer class="border-t border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
            <form wire:submit="submit" class="mx-auto flex max-w-3xl items-end gap-2">
                <textarea wire:model="question" rows="1" required
                          x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"
                          @disabled(! $documentId || $pending !== null)
                          placeholder="{{ $documentId ? 'Ask a question about this document…' : 'Choose a document first' }}"
                          class="max-h-40 min-h-[2.5rem] flex-1 resize-none rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/30 focus:outline-none disabled:opacity-60 dark:border-stone-700 dark:bg-stone-800"></textarea>
                <button type="submit" @disabled(! $documentId || $pending !== null)
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50">
                    Send
                </button>
            </form>
        </footer>
    </main>

    <div x-show="sidebar" x-cloak x-on:click="sidebar = false" class="fixed inset-0 z-20 bg-black/40 md:hidden"></div>
</div>
