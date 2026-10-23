# Document Assistant — local RAG chatbot (Laravel 13 + Livewire 4)

One app that covers the three use cases of *RAG Hands-On Use Cases*, running **fully locally** on Ollama
(`qwen2.5:1.5b` for answers, `qwen3-embedding:0.6b` for embeddings — only models that are already installed).

| Use case | Document | Profile | What is different |
|---|---|---|---|
| 1 | GPO 2018 Annual Report | `narrative` | Page-aware sentence chunks; exact fact + page citation |
| 2 | World Bank GEP, June 2026 | `structured` | Section tree from PDF bookmarks, boxes, tables; question routing so regions/chapters never mix |
| 3 | Apple iMac Environmental Report | `visual` | Chart labels, label/value tables, call-outs and footnotes rebuilt from word geometry |

Chat is **document-scoped**: every conversation belongs to one document and can only retrieve from it.

Full project documentation (overview, architecture, per-document design and results, operations, development log) is in
[`docs/`](docs/README.md).

## How it works

```
Livewire UI (ChatPage)  --wire:stream-->  RagChatService
                                            ├─ RetrievalService: QuestionRouter → VectorSearchService (cosine + BM25 + RRF)
                                            ├─ EmbeddingService  (Ollama /api/embed)
                                            └─ OllamaChatService (Ollama /api/chat, streamed)

php artisan document:ingest → PdfExtractorService (scripts/extract_pdf.py, PyMuPDF)
                            → NarrativeChunker | StructuredChunker | VisualChunker
                            → EmbeddingService → SQLite (documents, document_chunks, conversations, messages)
```

* **Retrieval** is hybrid: dense cosine similarity computed in PHP over one document's chunks (vectors are packed
  float32 BLOBs in SQLite, no external vector DB) + BM25, fused with Reciprocal Rank Fusion. Tables/charts win ties on
  quantity questions; footnote chunks only surface for note/definition questions.
* **Structured profile**: `QuestionRouter` (rules, no LLM) maps "South Asia", "SAR", "Chapter 3", "Box 1.1", "executive
  summary" to section paths and restricts the search to them. Text is chunked small (embedded, with a breadcrumb such as
  `Ch.2 Regional Outlooks > South Asia > Outlook`) under larger section-part parents. Tables become chunks whose rows spell
  out the year columns (`EMDE SAR, GDP: 2023: 6.6; …; 2026f: 6.3`).
* **Visual profile** (no vision model needed): the PDF text layer holds the chart/table text but block extraction scrambles
  it, so rows are rebuilt from word coordinates; `[^18]`-style superscript markers are resolved against the Endnotes and the
  note text is attached to the chunk it qualifies.
* **Answers** use a strict prompt: only the retrieved context, exact fallback line
  `I don't have that information in {document}.`, numbers kept with their year/region/row, page cited. Temperature 0.

## Setup

Requirements: PHP 8.3+, Composer, Node, Python 3 (ingestion only), Ollama with the two models.

```powershell
composer install
npm install && npm run build
copy .env.example .env ; php artisan key:generate
python -m venv .venv-rag ; .\.venv-rag\Scripts\python.exe -m pip install -r scripts\requirements.txt
php artisan migrate
```

### Get the documents in

Fast (no Ollama ingestion, uses the committed export):

```powershell
php artisan db:seed
```

Or ingest from the PDFs (CPU-bound; a few minutes for the 200-page report):

```powershell
php artisan document:ingest ..\Doc\GPO-Anual-report.pdf "GPO 2018 Annual Report" --force
php artisan document:ingest ..\Doc\GEP-Jun-2026.pdf "Global Economic Prospects, June 2026" --force
php artisan document:ingest ..\Doc\iMac_PER_Oct2024.pdf "iMac Product Environmental Report" --force
```

`--profile=narrative|structured|visual` overrides the profile guessed from the file name.
`--dry-run --show=3` extracts and chunks only (no database, no Ollama) so chunk quality can be inspected.
After ingesting, `php artisan document:export-seed` refreshes `database/seeders/data/*.json`.

### Run

Herd: `herd link rag-chatbot` → <http://rag-chatbot.test>. Otherwise `php artisan serve`.

## Checking quality

```powershell
php artisan rag:eval                 # the 9 questions from the use-case document + a region check + an out-of-document control
php artisan rag:eval --no-answer     # retrieval checks only (fast)
php artisan rag:ask GPO "How many depository libraries did GPO staff visit in FY 2018?"
php artisan rag:ask iMac "…" --retrieval   # show retrieved chunks with dense/BM25 ranks
composer test                         # Pest: chunkers, router, BM25, Livewire chat flow (Ollama faked)
```

## Known limits

* No vision model is installed, so a chart whose numbers are drawn as images (not text) cannot be read. The iMac report's
  values are in the text layer.
* A 1.5B model can still slip on multi-fact answers or add a neighbouring fact; `rag:eval` and the Sources panel make that visible.
* The vector index of a document is loaded per request (about a second for the 200-page report); fine at this scale.
* No authentication: one shared space; conversations are tied to the browser session.
