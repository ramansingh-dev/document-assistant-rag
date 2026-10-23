# 4. Operations guide

## Requirements

* PHP 8.3+ with `pdo_sqlite`, Composer, Node.js
* Python 3 with PyMuPDF (needed for ingestion only)
* Ollama running locally with `qwen2.5:1.5b` and `qwen3-embedding:0.6b` (`ollama list` to confirm)

## Installation

```powershell
composer install
npm install ; npm run build
copy .env.example .env ; php artisan key:generate
python -m venv .venv-rag
.\.venv-rag\Scripts\python.exe -m pip install -r scripts\requirements.txt
php artisan migrate
```

## Getting documents in

**Fast path** — restore the committed ingestion output (no Ollama needed for ingestion):

```powershell
php artisan db:seed
```

**From the PDFs** (CPU bound; roughly 1 minute for the iMac report, 3–5 for GPO, about 19 minutes for the 200-page report on the
development machine):

```powershell
php artisan document:ingest ..\Doc\GPO-Anual-report.pdf "GPO 2018 Annual Report" --force
php artisan document:ingest ..\Doc\GEP-Jun-2026.pdf "Global Economic Prospects, June 2026" --force
php artisan document:ingest ..\Doc\iMac_PER_Oct2024.pdf "iMac Product Environmental Report" --force
php artisan document:export-seed        # refresh database/seeders/data after changing documents
```

* `--force` replaces a document with the same file name.
* `--profile=narrative|structured|visual` overrides the guess from the file name.
* `--dry-run --show=3` extracts and chunks only and prints samples; use it to inspect chunk quality without touching
  the database or Ollama.
* Run one ingestion at a time and close heavy applications: embedding the large report keeps Ollama and PHP busy.

To add a new PDF choose the closest profile. A new document shape means a new `ChunkerInterface` implementation and an entry
in `DocumentIngestionService::chunker()` and `config/rag_documents.php`.

## Running the app

```powershell
php artisan serve --port=8089          # http://127.0.0.1:8089
# or with Herd:  herd link rag-chatbot  ->  http://rag-chatbot.test
```

The first answer is slow while Ollama loads the models (20–30 s on CPU); later answers take roughly 7–30 s.

## Commands

| Command | Purpose |
|---|---|
| `php artisan document:ingest` | ingest a PDF |
| `php artisan document:export-seed` | write `database/seeders/data/*.json` |
| `php artisan db:seed` | restore documents from those files |
| `php artisan rag:ask <doc> "<question>"` | answer one question in the terminal |
| `php artisan rag:ask <doc> "<question>" --retrieval` | show retrieved chunks with `[dense rank / bm25 rank]` |
| `php artisan rag:eval [--only=text] [--no-answer]` | evaluate the use-case questions |
| `php artisan test` | Pest tests |

`<doc>` is a document id or part of its name (`GPO`, `iMac`, `Global`).

## Configuration (`config/rag.php`, overridable in `.env`)

| Key | Default | Meaning |
|---|---|---|
| `OLLAMA_HOST` | `http://127.0.0.1:11434` | Ollama server |
| `RAG_CHAT_MODEL` | `qwen2.5:1.5b` | answer model |
| `RAG_EMBEDDING_MODEL` | `qwen3-embedding:0.6b` | embedding model (changing it requires re-ingesting) |
| `RAG_PYTHON` | `.venv-rag/Scripts/python.exe` | interpreter with PyMuPDF |
| `RAG_KEEP_ALIVE` | `10m` | how long Ollama keeps models loaded |
| `chunking.*` | 600 / 120 / 700 / 3000 | narrative target + overlap, structured child + parent size |
| `retrieval.top_k` | 8 | passages sent to the model |
| `retrieval.candidates` | 20 | per-ranking candidates before fusion |
| `retrieval.context_budget` | 5500 | characters of context |
| `retrieval.history_messages` | 6 | earlier messages sent with a question |
| `generation.*` | temp 0, 320 tokens, 8192 context | answer settings |

After changing chunk sizes or the extractor, re-ingest the affected documents and re-export the seed.

## Testing

**Automated** (`php artisan test`, 15 tests, no Ollama needed):

* chunkers: ligature repair, hyphen re-joining, narrative page ranges, structured section assignment (early bookmark, box pages,
  table rows), visual table/chart/call-out/footnote reconstruction
* retrieval: BM25 ranking, router behaviour for regions, acronyms, chapters, boxes and the executive summary
* chat page (Ollama faked): picker lists only ready documents, answers carry sources and are saved, retrieval is scoped to the
  conversation's document, switching document starts a new chat, an Ollama failure is shown to the user

**Quality** (`php artisan rag:eval`, uses the real models, about 4 minutes): 11 cases, each checking retrieval, section
scoping where relevant, and label-bound answer patterns.

**Manual checklist for the UI**

1. Open the app, pick each document, click a sample question.
2. The answer should stream; the Sources panel should list pages and sections.
3. Switch document in the header: a new empty chat starts and the old one stays in the sidebar.
4. Ask something outside the document: the reply must be "I don't have that information in …".
5. Stop Ollama and ask: an inline error should appear.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| "The local model could not answer … Is Ollama running?" | start Ollama; check `ollama list`; check `OLLAMA_HOST` |
| Page shows *Cannot call [x()] computed property method directly* | a view calls a computed property from JavaScript (`$wire.x`); use a plain method or data property |
| Ingestion: "Python interpreter not found" | create `.venv-rag`, install `pymupdf`, or set `RAG_PYTHON` |
| Ingestion killed part-way, document stuck on `processing` | re-run with `--force` (it replaces the half-written document) |
| Retrieval is poor after changing models | embeddings from different models are not comparable; re-ingest everything |
| Answer appears only at the end | the web server is buffering the stream; Livewire sends `X-Accel-Buffering: no`, but check proxies |
| Every answer is slow | first request loads the models; keep `RAG_KEEP_ALIVE` high; a bigger model is slower still |
| Herd site name does not resolve | use `php artisan serve` |

## Known limits

* **Model size.** A 1.5B model can mix up neighbouring facts or add an extra one. Mitigations are in the chunks, the retrieval
  priors and the prompt; `rag:eval` and the Sources panel make remaining errors visible.
* **No vision model.** Charts drawn as images cannot be read. In the iMac report the values are in the text layer.
* **Profiles are tuned to these documents.** The router's acronym list and the visual chunker's endnote layout assume documents like
  these; a new document family needs its own checks.
* **Index loading.** A document's vectors are loaded per request (about a second for the 200-page report). Fine at this scale.
* **No authentication.** One shared space; conversations belong to the browser session.
* **Evaluation size.** Eleven cases show these documents work, not that every question will.
