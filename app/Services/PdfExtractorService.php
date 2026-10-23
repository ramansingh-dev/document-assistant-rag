<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Runs scripts/extract_pdf.py (PyMuPDF) and returns its decoded JSON. */
class PdfExtractorService
{
    public function extract(string $path, bool $superscripts = false): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("PDF not found: {$path}");
        }

        $python = config('rag.python.binary');
        if (! is_file($python)) {
            throw new RuntimeException("Python interpreter not found at {$python}. Set RAG_PYTHON and `pip install pymupdf`.");
        }

        $command = [$python, '-I', config('rag.python.extract_script'), $path];
        if ($superscripts) {
            $command[] = '--superscripts';
        }

        $result = Process::timeout(600)->run($command);
        if ($result->failed()) {
            throw new RuntimeException('PDF extraction failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        $payload = json_decode($result->output(), true);
        if (! is_array($payload) || ! isset($payload['pages'])) {
            throw new RuntimeException('PDF extraction returned invalid JSON.');
        }

        return $payload;
    }
}
