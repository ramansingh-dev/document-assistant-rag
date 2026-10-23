# Document Assistant — project documentation

A local, document-scoped RAG chatbot built with **Laravel 13**, **Livewire 4**, **SQLite** and **Ollama**.
It answers questions about three very different PDFs and is the single app that covers the three use cases of
*RAG Hands-On Use Cases*.

| Read this | When you want to know |
|---|---|
| [01-overview.md](01-overview.md) | What the app is, the requirements it meets, the technology and the decisions behind it |
| [02-architecture.md](02-architecture.md) | How ingestion and chat work, the database schema, every file and what it does |
| [03-use-cases.md](03-use-cases.md) | How each of the three documents is handled, with the results of the evaluation |
| [04-operations.md](04-operations.md) | Setup, commands, configuration, testing, troubleshooting and known limits |
| [05-development-log.md](05-development-log.md) | Problems found while building it and how they were fixed |

Quick start: see the project [README](../README.md).

## The three tasks at a glance

| # | Document | Challenge | Profile |
|---|---|---|---|
| 1 | GPO 2018 Annual Report (82 pp) | Plain narrative with numbers inside sentences | `narrative` |
| 2 | World Bank Global Economic Prospects, June 2026 (200 pp) | Chapters, regions, boxes and tables; sections must not be mixed up | `structured` |
| 3 | Apple iMac Product Environmental Report (12 pp) | Facts in charts, small tables, call-outs and footnotes | `visual` |

Everything runs on the local Ollama server using only models that were already installed:
`qwen2.5:1.5b` (answers) and `qwen3-embedding:0.6b` (embeddings).
