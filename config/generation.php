<?php

/**
 * AI Course Assistant (BR-D4PE8R).
 *
 * Reuses the OpenRouter credentials from config/chat.php but keeps its own
 * models: OCR wants a cheap vision model, block generation wants a strong
 * reasoning model. Both are env-overridable so models can be swapped on
 * Railway without a deploy.
 */
return [
    // Vision / OCR pass — reads photos and PDFs.
    //
    // Chosen for extraction rather than transcription: we want structured
    // worksheet content, not a verbatim page dump, and 3.7-flash leads on
    // extraction while staying the cheapest credible option with 1M context
    // (whole PDF in one call).
    //
    // Cost warning: OpenRouter currently lists this at a 50% promo rate.
    // Budget against Google's list price (~$0.75/M in, ~$3.75/M out), which
    // itself rises at the start of 2027.
    //
    // Escalation tier for handwriting- or math-heavy pages:
    //   AI_OCR_MODEL=anthropic/claude-sonnet-5
    'ocr_model' => env('AI_OCR_MODEL', 'google/gemini-3.7-flash'),

    // Generation pass — turns the extracted text into Block V2 JSON.
    // Sonnet 5 supersedes 4.5 and is both newer and cheaper ($2/$10 vs $3/$15).
    'gen_model' => env('AI_GEN_MODEL', 'anthropic/claude-sonnet-5'),

    // PDF parsing engine used by OpenRouter's file-parser plugin.
    //
    // ALWAYS send this explicitly. OpenRouter's own default is mistral-ocr,
    // which adds ~$2 per 1,000 pages AND flattens the page to text before the
    // model ever sees it — worse on handwriting, which is most of what gets
    // photographed here.
    //
    //   native      — hand the PDF straight to the vision model. No surcharge
    //                 (billed as ordinary input tokens) and the only engine
    //                 that preserves layout, handwriting and math end to end.
    //   mistral-ocr — dedicated OCR, ~$2/1,000 pages, max 8 images per PDF.
    //                 Strong on printed formulas, weak on handwriting.
    //   cloudflare-ai — free PDF→markdown. Fine for born-digital text PDFs,
    //                 useless for scans. (The old `pdf-text` value is
    //                 deprecated and now silently redirects here.)
    'pdf_engine' => env('AI_PDF_ENGINE', 'native'),

    'max_tokens' => (int) env('AI_GEN_MAX_TOKENS', 16000),

    // How long a capture session (and its QR code) stays valid.
    'ttl_minutes' => (int) env('AI_GEN_TTL_MINUTES', 15),

    // Upload limits — enforced on both the admin and the public capture route.
    'max_attachments' => (int) env('AI_GEN_MAX_ATTACHMENTS', 10),
    'max_file_bytes' => (int) env('AI_GEN_MAX_FILE_BYTES', 15 * 1024 * 1024),

    // HEIC/HEIF are deliberately absent: vision models reject them and we have
    // no transcode step. iOS Safari converts camera captures to JPEG on upload,
    // so this only bites when someone picks an untouched HEIC from the library.
    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ],

    // Public base URL of the admin app — the QR code points at
    // {ADMIN_APP_URL}/capture/{token}.
    //
    // Deliberately no fallback. A wrong default would produce a QR code that
    // scans fine and lands on a dead host, which is a silent failure nobody
    // can diagnose from the phone. Missing config fails loudly instead — see
    // CourseGenerationController::captureBaseUrl().
    'capture_base_url' => env('ADMIN_APP_URL'),
];
