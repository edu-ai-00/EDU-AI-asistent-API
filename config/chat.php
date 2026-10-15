<?php

return [
    'api_key' => env('AI_API_KEY'),
    'api_url' => env('AI_API_URL', 'https://openrouter.ai/api/v1'),
    'model' => env('AI_MODEL', 'openai/gpt-4o'),
    'max_tokens' => (int) env('AI_MAX_TOKENS', 2048),
    'max_context' => (int) env('AI_MAX_CONTEXT', 128000),

    // ─── Open-answer AI evaluation (BR-FFVVYR) ───────────────────────────────
    // Stateless grading of free-text answers. Falls back to the chat model when
    // no dedicated eval model is configured.
    'eval_model' => env('AI_EVAL_MODEL'),
    // Three-part formative feedback plus up to four criteria costs far more
    // output than the single sentence BR-FFVVYR returned. 400 truncated the
    // JSON mid-string, which the parser then threw away as unparseable.
    'eval_max_tokens' => (int) env('AI_EVAL_MAX_TOKENS', 900),
    'eval_timeout' => (int) env('AI_EVAL_TIMEOUT', 20),
    // score >= threshold counts as correct; also drives the pass/fail flag the
    // client feeds into ELO and FSRS.
    //
    // BR-P745Q8: the student never sees this. It stays internal — teacher view,
    // ELO and FSRS only — because feedback here is formative, not a grade.
    'eval_pass_threshold' => (float) env('AI_EVAL_PASS_THRESHOLD', 0.7),

    // BR-DF88P7: an answer that already matches `correct_answer` once both are
    // normalized is answered without a model call — 10–15 s of waiting for a
    // verdict string equality had already settled. Off sends every answer to
    // the model again, which is the only reason to touch this.
    'eval_exact_match' => (bool) env('AI_EVAL_EXACT_MATCH', true),

    // ─── Formative feedback + photo answers (BR-P745Q8) ──────────────────────
    // Stamped onto every stored attempt so a feedback rewrite stays traceable:
    // an answer graded under v1 must remain interpretable after v2 ships.
    // Bump whenever SYSTEM_PROMPT or the response contract changes.
    'eval_prompt_version' => env('AI_EVAL_PROMPT_VERSION', 'p745q8-v4'),

    // Vision model for photographed answers. Falls back to the OCR model the
    // course assistant already uses (config/generation.php) so there is one
    // place to swap vision models, not two.
    'eval_vision_model' => env('AI_EVAL_VISION_MODEL'),

    // A photographed answer is a whole page of handwriting to describe before
    // any feedback is written, so it needs more room than a typed one.
    'eval_vision_max_tokens' => (int) env('AI_EVAL_VISION_MAX_TOKENS', 1400),
    'eval_vision_timeout' => (int) env('AI_EVAL_VISION_TIMEOUT', 45),

    // Student photo uploads. Deliberately tighter than the admin capture flow
    // (config/generation.php): one photo per answer, taken on a phone.
    'answer_photo_max_bytes' => (int) env('AI_EVAL_PHOTO_MAX_BYTES', 8 * 1024 * 1024),
    // HEIC/HEIF stay out for the same reason as the capture flow: vision models
    // reject them and there is no transcode step.
    'answer_photo_mimes' => ['image/jpeg', 'image/png', 'image/webp'],

    // ─── End-of-quiz AI comment ("Quiz Feedback v1") ─────────────────────────
    // 3–5 sentences on how the block went, shown in the feedback popup. Falls
    // back to the chat model when no dedicated model is configured. The prompt
    // itself is NOT here: it lives on the mentors row with slug
    // 'quiz-feedback' so it is editable from the admin without a deploy
    // (QuizFeedbackService::DEFAULT_PROMPT is the code fallback).
    'feedback_model' => env('AI_FEEDBACK_MODEL'),
    'feedback_max_tokens' => (int) env('AI_FEEDBACK_MAX_TOKENS', 500),
    'feedback_timeout' => (int) env('AI_FEEDBACK_TIMEOUT', 25),
];
