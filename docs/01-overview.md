# 1. Overview

## Goal

Build one Laravel application, with a Livewire front end, that answers questions about three documents and
satisfies the requirements in `RAG-Hands-On-Use-Cases.docx` and the app description in `ARCHITECTURE.pdf`.
A Python prototype (`advanced-rag-challenge`) already existed for the same documents; its ideas were ported
into one application rather than three scripts.

## Requirements and how they are met

| Requirement | Source | How it is met |
|---|---|---|
| Laravel app with a Livewire UI | request | `App\Livewire\Chat\ChatPage` (one component, two Blade partials) |
| Only installed Ollama models, for the LLM and for chunking support | request | `qwen2.5:1.5b` for answers, `qwen3-embedding:0.6b` for embeddings; no other model is called |
| Offline ingestion by console command | ARCHITECTURE.pdf | `php artisan document:ingest` |
| Document-scoped chat | ARCHITECTURE.pdf | every `Conversation` has one `document_id`; search is filtered to it |
| Cosine similarity in PHP over SQLite, no external vector DB | ARCHITECTURE.pdf | `VectorSearchService` |
| Streamed answers | ARCHITECTURE.pdf | Ollama NDJSON → `wire:stream` |
| Answer only from the document, exact "don't know" line | ARCHITECTURE.pdf | `RagChatService::SYSTEM_TEMPLATE` |
| Seed data committed so no re-ingestion is needed | ARCHITECTURE.pdf | `document:export-seed` + `DocumentSeeder` |
| UC1: exact fact from paragraph text, nothing added | use-case doc | `NarrativeChunker`, strict prompt, page citations |
| UC2: right chapter/section, no region mix-ups | use-case doc | `StructuredChunker` + `QuestionRouter` |
| UC3: numbers from charts, tables and footnotes | use-case doc | `VisualChunker` (geometry-based reconstruction) |

## Deliberate differences from ARCHITECTURE.pdf

ARCHITECTURE.pdf describes an earlier build. These parts changed because of the "only installed models" and
"Livewire" requirements:

| ARCHITECTURE.pdf | This project | Why |
|---|---|---|
| React + Tailwind SPA with JSON/SSE API | Livewire 4 component with `wire:stream` | requested |
| Google Gemini for chat answers | Ollama `qwen2.5:1.5b` | only installed models |
| `nomic-embed-text` embeddings | `qwen3-embedding:0.6b` | only installed models |
| `qwen2.5vl:3b` vision model reads chart pages | no vision model; `VisualChunker` rebuilds charts/tables from the PDF text layer | not installed |
| Embeddings stored as JSON | embeddings stored as packed float32 BLOBs | smaller and much faster to decode |
| Cosine only | cosine + BM25 fused with RRF, section routing | the 1.5B model needs better retrieval to be reliable |
| `smalot/pdfparser` for PDF text | PyMuPDF helper script | needs page geometry, bookmarks and word positions |

## Technology

| Layer | Choice |
|---|---|
| Backend | Laravel 13.38, PHP 8.4 (Herd) |
| Front end | Livewire 4, Tailwind CSS 4 (Vite), Alpine (bundled with Livewire) |
| Database | SQLite (`database/database.sqlite`) |
| PDF extraction | Python + PyMuPDF, called as a subprocess during ingestion only |
| Embeddings | Ollama `qwen3-embedding:0.6b` (1024 dimensions) |
| Answer model | Ollama `qwen2.5:1.5b`, temperature 0 |
| Tests | Pest 5 |

## Design principles

1. **Quality comes from retrieval, not the model.** A 1.5B model cannot be trusted to pick the right figure out of a
   noisy context, so chunks are built to be self-explanatory (breadcrumbs, spelled-out table rows, resolved
   footnotes) and the search is hybrid and section-aware.
2. **One pipeline, three chunkers.** Extraction, embedding, storage, retrieval and chat are shared; only the chunker
   differs per document shape (the *profile*).
3. **Never guess silently.** If the context lacks the answer the model must say so; every answer shows its sources
   with page and section; `rag:eval` measures the result.
4. **Local and reproducible.** No network calls other than to the local Ollama; ingestion output can be exported and
   re-seeded.
