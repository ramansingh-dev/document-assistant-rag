<?php

return [

    /*
    | Everything runs against the local Ollama server. Only models that are
    | already installed (`ollama list`) are used.
    */
    'ollama' => [
        'host' => env('OLLAMA_HOST', 'http://127.0.0.1:11434'),
        'embedding_model' => env('RAG_EMBEDDING_MODEL', 'qwen3-embedding:0.6b'),
        'chat_model' => env('RAG_CHAT_MODEL', 'qwen2.5:1.5b'),
        'keep_alive' => env('RAG_KEEP_ALIVE', '10m'),
        'timeout' => (int) env('RAG_OLLAMA_TIMEOUT', 300),
        'embed_batch' => (int) env('RAG_EMBED_BATCH', 16),
    ],

    // Python (PyMuPDF) helper used only during ingestion.
    'python' => [
        'binary' => env('RAG_PYTHON', base_path('.venv-rag/Scripts/python.exe')),
        'extract_script' => base_path('scripts/extract_pdf.py'),
    ],

    'chunking' => [
        // narrative profile (plain text reports)
        'narrative_target' => 600,
        'narrative_overlap' => 120,
        // structured profile (long, sectioned reports)
        'child_target' => 700,
        'parent_max' => 3000,
        // visual profile
        'visual_paragraph_target' => 600,
    ],

    'retrieval' => [
        'top_k' => 8,
        'candidates' => 20,
        'rrf_k' => 60,
        // Characters of retrieved text sent to the model (a 1.5B model needs a tight context).
        'context_budget' => 5500,
        'history_messages' => 6,
    ],

    'generation' => [
        'temperature' => 0,
        'num_predict' => 320,
        'num_ctx' => 8192,
    ],

    /*
    | Ingestion profiles: how a document is parsed and chunked.
    |   narrative  - plain narrative report (use case 1)
    |   structured - long report with chapters/sections (use case 2)
    |   visual     - short document whose facts sit in charts/tables/call-outs (use case 3)
    */
    'profiles' => ['narrative', 'structured', 'visual'],

    'documents_path' => env('RAG_DOCUMENTS_PATH', base_path('../Doc')),
];
