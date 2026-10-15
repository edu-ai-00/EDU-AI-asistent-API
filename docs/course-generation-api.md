# AI Course Assistant API (BR-D4PE8R)

Turns photos and PDFs of teaching materials into a draft course structure the
admin editor can insert into a Block V2 course.

## Flow

```
admin editor                        API                          phone
     │                               │                             │
     ├─ POST /admin/generation/sessions ─▶ issue token + QR url     │
     │◀── { token, capture_url } ─────┤                             │
     │                               │◀── GET /generation/capture/{token}
     │                               │◀── POST …/attachments ───────┤
     ├─ GET  …/sessions/{token} (poll) ─▶ attachment list           │
     ├─ POST …/attachments (desktop upload)                         │
     ├─ GET  …/sessions/{token}/stream?mode=… ─▶ SSE progress + result
     └─ DELETE …/sessions/{token} ────▶ purge uploads               │
```

Nothing is written to the course. The endpoint returns a *draft*; the admin
frontend inflates it with `src/lib/defaults.ts` and appends it to the in-memory
course. It reaches R2 only when the author hits **Save** in the editor.

## Why no queue job

There is no `queue:work` process — a dispatched job would sit in the `jobs`
table forever. Generation therefore runs inline on an SSE connection (same
pattern as `ChatMessageController::stream`). Expired sessions are pruned
opportunistically whenever a new one is issued, and `start.sh` does supervise
`schedule:work`, so the hourly `generation:prune` sweep runs too.

## Two things the long-lived stream needs from the runtime

Both were found the hard way, on staging, and neither reproduces locally.

**1. `set_time_limit(0)` in the stream action.** The Railway PHP image caps
`max_execution_time` at 30 seconds, so PHP killed the worker mid-generation:

```
PHP Fatal error: Maximum execution time of 30 seconds exceeded
  in vendor/guzzlehttp/psr7/src/Stream.php on line 234
```

A fatal kills the process, so the client receives neither `[DONE]` nor an
`error` event — just a dead socket it can only report as "connection lost". The
CLI SAPI defaults `max_execution_time` to 0, which is why `php artisan serve` on
a laptop never hits this. Guzzle's 300s per-call timeout remains the real
ceiling.

**2. More than one server worker.** `php artisan serve` runs PHP's built-in
server, which handles one request at a time unless `PHP_CLI_SERVER_WORKERS` is
set — and Laravel only honours that variable when `--no-reload` is passed. With
a single worker an SSE generation holds the *only* worker for minutes, so every
other caller, the Flutter app included, blocks behind it. `start.sh` now passes
`--no-reload` and defaults the worker count to 4.

## Endpoints

### Admin (`admin` middleware)

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/api/admin/generation/sessions` | Issue a session. Returns the one-time plaintext token and `capture_url`. |
| GET | `/api/admin/generation/sessions/{token}` | Current state; polled while the phone uploads. |
| POST | `/api/admin/generation/sessions/{token}/attachments` | Desktop upload (`files[]`). |
| DELETE | `/api/admin/generation/sessions/{token}/attachments/{index}` | Remove one attachment. |
| GET | `/api/admin/generation/sessions/{token}/stream?mode=ocr\|full` | SSE run. |
| DELETE | `/api/admin/generation/sessions/{token}` | Close the session and purge R2. |

### Public — phone capture (`throttle:generation-capture`)

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/generation/capture/{token}` | Course name, upload count, limits. |
| POST | `/api/generation/capture/{token}/attachments` | Upload (`files[]`). |

These two are **unauthenticated on purpose** — the phone that scanned the QR has
no admin session. Security rests on the token: `Str::random(48)`, stored only as
a SHA-256 hash, valid for `AI_GEN_TTL_MINUTES` (default 15), scoped to one
session, rate limited per IP, and capable of nothing but appending a file. The
capture responses never echo R2 paths or the course id.

## Modes

| Mode | Calls | Output |
| --- | --- | --- |
| `ocr` | 1 (`AI_OCR_MODEL`) | Pages transcribed verbatim into `display` blocks. Nothing invented. |
| `full` | 2 (`AI_OCR_MODEL` → `AI_GEN_MODEL`) | Transcribe, then author lessons with display blocks, questions and practice exercises. |

## SSE events

```
data: {"stage":"ocr","status":"start"}
data: {"stage":"ocr","token":"…"}          ← repeated
data: {"stage":"ocr","status":"done"}
data: {"stage":"generate","status":"start"} ← full mode only
data: {"stage":"generate","token":"…"}
data: {"stage":"generate","status":"done"}
data: {"stage":"result","result":{"lessons":[…]}}
data: [DONE]
```

Failures arrive as `{"stage":"error","error":"…"}` followed by `[DONE]`.
Re-running a finished session replays the stored result instead of paying for a
second generation.

## Draft shape

Deliberately thin — only what a model can actually know. Ids, GPF vectors, FSRS
parameters and learning metadata are added client-side so those defaults have a
single home.

```jsonc
{
  "lessons": [{
    "name": "Zlomky",
    "description": "…",
    "blocks": [{
      "type": "display" | "question" | "exercise",
      "concepts": ["Zlomky"],
      "difficulty": 1-5,
      "bloom_level": 1-6,
      "steps": [
        { "type": "text", "content": "…", "hint": "…", "help": "…" },
        { "type": "question", "content": "…", "question": {
            "type": "open" | "multiple_choice" | "true_false" | "numeric",
            "options": [{ "id": "a", "text": "…", "is_correct": true, "feedback": "…" }],
            "correct_answer": "…", "correct_number": 3.5, "tolerance": 0.1,
            "solution": "…"
        }}
      ]
    }]
  }]
}
```

`CourseGenerationService::normalizeDraft()` is the gate: unknown block types are
coerced, blank steps dropped, out-of-range metadata clamped, multiple-choice
questions without a correct answer discarded, and a `display` block that
smuggled in a question is promoted to `question`. A lesson left with no usable
block disappears.

## Configuration

See `config/generation.php`. All env-overridable so models can change on Railway
without a deploy:

```
AI_OCR_MODEL=google/gemini-3.7-flash
AI_GEN_MODEL=anthropic/claude-sonnet-5
AI_PDF_ENGINE=native            # native | mistral-ocr | cloudflare-ai
AI_GEN_MAX_TOKENS=16000
AI_GEN_TTL_MINUTES=15
AI_GEN_MAX_ATTACHMENTS=10
AI_GEN_MAX_FILE_BYTES=15728640
ADMIN_APP_URL=https://app-admin-stage.edu-ai.eu   # QR target: {ADMIN_APP_URL}/capture/{token}
```

`ADMIN_APP_URL` has **no fallback** and must be set per environment. Mirroring
`ConsentService::baseUrl()`, an unset or relative value throws at session
creation rather than handing back a QR code that scans fine and opens nothing —
a failure the person holding the phone cannot diagnose.

Credentials are shared with the chat feature (`AI_API_KEY`, `AI_API_URL`).

### Model choice

`google/gemini-3.7-flash` is picked for *extraction* rather than verbatim
transcription — the job is structured worksheet content, not a page dump, and it
leads on extraction while staying the cheapest credible option with 1M context
(a whole PDF in one call). Escalate to `anthropic/claude-sonnet-5` for
handwriting- or math-heavy material; the Claude line leads the OCR leaderboards
and is strongest on handwriting.

Two cost traps:

- OpenRouter currently lists 3.7-flash at a **50% promo rate**. Budget against
  Google's list price (~$0.75/M in, ~$3.75/M out), which rises again in 2027.
- **Never omit `AI_PDF_ENGINE`.** OpenRouter's own default is `mistral-ocr`,
  which adds ~$2 per 1,000 pages *and* flattens the page to text before the
  model sees it — worse on handwriting, which is most of what gets photographed
  here. `native` has no surcharge and preserves layout, handwriting and math.

**Unverified: Czech accuracy.** No OCR benchmark for Czech appears to exist. The
nearest datapoint is Polish, where specialised fine-tunes cut diacritic error
substantially — implying base models do degrade on Central European diacritics
(ř, ě, ů, č, š, ž). Validate on real worksheets before trusting the output.

Possible future saving: PDF responses carry `file_annotations` (a parsed-content
hash) that can be replayed to skip re-parsing the same file. Not used yet — the
current flow never re-reads an attachment.

Accepted uploads: JPEG, PNG, WEBP, PDF. HEIC/HEIF are rejected — vision models
cannot read them and there is no transcode step; iOS converts camera captures to
JPEG on upload, so this only affects picking an untouched HEIC from the library.

## Privacy note

Uploaded photos and PDFs are sent to OpenRouter as base64 inside the request
(never as a public R2 URL). Check that `eduai-app/assets/legal/*/ai.md` and the
privacy policy cover this before enabling the feature for external authors.
