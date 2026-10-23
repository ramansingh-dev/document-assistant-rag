# 2. Architecture

## Components

```
                         ┌──────────────────────────── Livewire ChatPage ───────────────────────────┐
                         │ document picker · sidebar · messages · composer · Sources panel          │
                         └───────────────┬──────────────────────────────────────────────────────────┘
                                         │ submit() then generate()  (answer fragments via wire:stream)
                                   RagChatService
                       ┌─────────────────┼───────────────────────┐
                RetrievalService   OllamaChatService      persists Message rows
                ┌──────┴───────┐     (/api/chat, stream)
        QuestionRouter   VectorSearchService ── Bm25
                │         (cosine + BM25 + RRF)
        EmbeddingService (/api/embed)

  php artisan document:ingest
        └─ DocumentIngestionService
              ├─ PdfExtractorService ─► scripts/extract_pdf.py (PyMuPDF)
              ├─ NarrativeChunker | StructuredChunker | VisualChunker
              ├─ EmbeddingService
              └─ SQLite: documents, document_chunks, conversations, messages
```

## Flow 1 — ingesting a document

Entry point: `php artisan document:ingest <pdf> [name] [--profile=] [--force] [--dry-run] [--show=N]`.

1. **Create** a `documents` row with `status = processing`. The profile comes from `--profile` or is guessed from the file name
   (`gep`/`prospects` → structured, `imac`/`environmental` → visual, otherwise narrative).
2. **Extract.** `PdfExtractorService` runs `scripts/extract_pdf.py` and decodes its JSON (see below).
3. **Chunk.** The profile's chunker turns the extraction into `Chunk` objects (kind, content, search text, pages,
   section path, parent link).
4. **Embed.** `EmbeddingService::embedMany()` sends the `search_text` of every non-parent chunk to Ollama in batches.
5. **Store.** Parents are inserted first so children can reference their id; each embedding is bound as a real
   `BLOB` (a string would be cut at the first NUL byte by SQLite).
6. **Finish.** `status = ready` with page and chunk counts; any exception sets `status = failed` and stores the message.

`--dry-run` stops after step 3 (no database, no Ollama) and prints chunk statistics, optionally samples with `--show`.

### The extraction JSON (`scripts/extract_pdf.py`)

```
{ page_count, toc: [{level,title,page}],
  pages: [{ page, printed,
            blocks: [{x0,y0,x1,y1,size,bold,zone,text}],   // reading order, zone = header|footer|body
            rows:   [{y,x0,text,cells:[{x0,x1,text}]}] }]  // visual rows rebuilt from word coordinates
}
```

* **Reading order:** full-width blocks first, then left column, then right column.
* **`printed`:** the page number printed in a running header/footer (so "PDF p.96" can be shown as "Page 74").
* **`zone`:** running headers/footers are marked so chunkers can drop them.
* **`rows`:** words clustered by y position and merged into cells. This is what recovers table rows and chart labels.
* **`--superscripts`:** raised small digits become `[^18]` footnote markers; lowered digits (CO₂e) are left alone.
* **`toc`:** the PDF bookmarks, used for the section tree of the structured profile.

## Flow 2 — asking a question

1. `ChatPage::submit()` stores the question in `$pending` so it is shown immediately.
2. `ChatPage::generate()` (triggered by `wire:init`) creates the conversation if needed and iterates
   `RagChatService::askStream()`.
3. **Retrieve** (`RetrievalService`):
   * A short follow-up (fewer than 8 words) is prefixed with the previous user question.
   * `QuestionRouter` maps the question to section paths (structured documents only).
   * The question is embedded with Qwen3-Embedding's *query instruction* prefix (documents are embedded as-is).
   * `VectorSearchService` ranks the document's chunks by cosine similarity and by BM25, fuses the ranks with
     Reciprocal Rank Fusion (k = 60), applies small priors, and returns the top 8.
   * The best three paragraph hits also bring their next/previous sibling chunk, within a character budget.
4. **Prompt.** A strict system prompt, the last 6 messages of history, then
   `QUESTION … CONTEXT … repeated question`.
5. **Generate.** `OllamaChatService::stream()` yields NDJSON fragments; each is pushed to the browser with
   `$this->stream(to: 'answer', …)`.
6. **Persist.** The user message and the assistant message (with its sources as JSON) are saved; the conversation
   title is set from the first question.

### Ranking details

| Step | Detail |
|---|---|
| Dense | cosine similarity, vectors held in memory per request |
| Keyword | Okapi BM25 (k1 = 1.5, b = 0.75), stop words removed, over `search_text` |
| Fusion | RRF over the top 20 of each list |
| Quantity prior | on "how much / total / percent / growth / forecast …" questions: tables and charts ×1.6 in the visual profile, ×1.05 otherwise |
| Footnote prior | footnote chunks ×0.6 unless the question asks about a note, definition, methodology or calculation |
| Tiny-fragment prior | chunks under 60 characters with no digit ×0.75 |
| Section filter | when the router matched, only chunks under the matched section paths compete (if at least 3 remain) |

### The prompt rules (`RagChatService::SYSTEM_TEMPLATE`)

Use only the CONTEXT; reply exactly *"I don't have that information in {document}."* when it is not there; keep numbers
with their label/year/region/row; a figure belongs only to the item it is stated about; give related figures together;
lead with the headline figure; cite the page; 1–4 sentences. Generation uses temperature 0, `num_ctx` 8192, up to 320 tokens.

## Database schema (SQLite)

| Table | Columns |
|---|---|
| `documents` | `name`, `filename`, `original_extension`, `profile`, `status` (processing/ready/failed), `page_count`, `chunk_count`, `error_message` |
| `document_chunks` | `document_id` (cascade), `chunk_index`, `parent_id`, `kind`, `section_path`, `page_start`, `page_end`, `printed_page`, `content` (shown), `search_text` (embedded + BM25), `embedding` (BLOB, 4096 bytes) |
| `conversations` | `session_uuid`, `document_id` (null on delete), `title` |
| `messages` | `conversation_id` (cascade), `role`, `content`, `sources` (JSON, assistant only) |

Chunk `kind`: `paragraph`, `section` (a parent, no embedding), `table`, `chart`, `callout`, `footnote`.
Conversations belong to a browser session (`rag_session_uuid`); there is no authentication.

## File reference

### Console commands (`app/Console/Commands`)

| File | Command | Purpose |
|---|---|---|
| `IngestDocument.php` | `document:ingest` | parse, chunk, embed and store a PDF; `--dry-run` for inspection |
| `ExportDocumentSeed.php` | `document:export-seed` | dump documents and chunks (embeddings as base64) to `database/seeders/data` |
| `AskDocument.php` | `rag:ask` | ask one question from the terminal; `--retrieval` shows the retrieved chunks and their dense/BM25 ranks |
| `EvaluateRag.php` | `rag:eval` | run the use-case questions plus controls and check retrieval, sections and answers |

### Services (`app/Services`)

| File | Responsibility |
|---|---|
| `PdfExtractorService.php` | run `extract_pdf.py` and decode its JSON |
| `DocumentIngestionService.php` | orchestrate extract → chunk → embed → store; choose the chunker by profile |
| `EmbeddingService.php` | Ollama `/api/embed`, batched; `embedQuery()` adds the query instruction |
| `OllamaChatService.php` | Ollama `/api/chat`, streaming and non-streaming |
| `RagChatService.php` | the RAG loop, prompt, history and persistence |
| `Chunking/Chunk.php`, `ChunkerInterface.php` | shared chunk shape and chunker contract |
| `Chunking/NarrativeChunker.php` | sentence-packed chunks with page ranges and overlap |
| `Chunking/StructuredChunker.php` | section tree, boxes, sub-headings, parents/children, tables |
| `Chunking/VisualChunker.php` | label/value tables, grid tables, chart labels, call-outs, footnotes, paragraphs |
| `Chunking/TableFacts.php` | turn year-column table rows into self-describing lines |
| `Chunking/TextCleaner.php` | ligature repair, hyphen handling, sentence splitting, acronym-list filter |
| `Retrieval/Bm25.php` | BM25 index over one document |
| `Retrieval/QuestionRouter.php` | rule-based question → section mapping |
| `Retrieval/VectorSearchService.php` | cosine + BM25 + RRF, priors, sibling lookup |
| `Retrieval/RetrievalService.php` | route, search, expand and format the context and the source list |

### Other

| File | Purpose |
|---|---|
| `app/Livewire/Chat/ChatPage.php` | the whole UI component (state, actions, streaming) |
| `resources/views/livewire/chat/chat-page.blade.php`, `sources.blade.php` | page layout and the collapsible Sources panel |
| `resources/views/layouts/app.blade.php`, `resources/css/app.css` | layout, Tailwind, dark mode follows the OS |
| `app/Models/*` | `Document`, `DocumentChunk` (vector pack/unpack, page label), `Conversation`, `Message` |
| `config/rag.php` | models, Python path, chunk sizes, retrieval and generation settings |
| `config/rag_documents.php` | picker copy and sample questions per profile |
| `database/migrations/2026_10_22_000001_create_rag_tables.php` | schema |
| `database/seeders/DocumentSeeder.php`, `data/*.json` | committed ingestion output |
| `scripts/extract_pdf.py`, `scripts/requirements.txt` | PyMuPDF extractor |
| `tests/Unit`, `tests/Feature` | Pest tests |

## UI behaviour

* Empty state lists the ingested documents with their use-case blurb; picking one shows three sample questions.
* A document switcher is always visible; changing it starts a new conversation (a conversation never changes document).
* The sidebar groups past conversations (Today / Previous 7 days / Older) with delete buttons.
* Each answer has a collapsible **Sources** panel (page label, section, chunk kind, excerpt; neighbouring text is marked).
* Errors from Ollama are shown inline ("Is Ollama running?").
* A `MutationObserver` keeps the newest content scrolled into view, including while an answer streams.
