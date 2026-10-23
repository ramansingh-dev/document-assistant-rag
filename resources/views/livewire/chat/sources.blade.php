@if (! empty($sources))
    <details class="max-w-[92%] text-xs text-stone-600 dark:text-stone-400">
        <summary class="cursor-pointer select-none">
            Sources ({{ count(array_filter($sources, fn ($s) => $s['primary'] ?? true)) }}) —
            {{ collect($sources)->where('primary', true)->pluck('page_label')->unique()->take(4)->implode(', ') }}
        </summary>
        <ol class="mt-2 space-y-2">
            @foreach ($sources as $source)
                <li class="rounded-lg border border-stone-200 bg-white p-2 dark:border-stone-800 dark:bg-stone-900">
                    <div class="font-medium text-stone-800 dark:text-stone-200">
                        {{ $source['page_label'] }}
                        @if (! empty($source['section']))
                            · <span class="font-normal">{{ $source['section'] }}</span>
                        @endif
                        <span class="ml-1 rounded bg-stone-100 px-1.5 py-0.5 font-normal dark:bg-stone-800">{{ $source['kind'] }}</span>
                        @if (empty($source['primary']))
                            <span class="font-normal text-stone-500">(surrounding text)</span>
                        @endif
                    </div>
                    <p class="answer-text mt-1">{{ $source['excerpt'] }}</p>
                </li>
            @endforeach
        </ol>
    </details>
@endif
