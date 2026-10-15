<?php

namespace App\Services;

use App\Models\GenerationSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * AI Course Assistant (BR-D4PE8R) — turns uploaded photos/PDFs into a draft
 * course structure.
 *
 * Two modes:
 *   ocr  — one vision call; the pages become display blocks, verbatim.
 *   full — two calls: transcribe first, then author a course (display blocks,
 *          questions and extra practice exercises) from the transcription.
 *
 * The service deliberately returns a *draft* shape (lessons → blocks → steps)
 * rather than finished Block V2 objects. The admin frontend inflates it with
 * `src/lib/defaults.ts`, so GPF/FSRS/learning defaults have exactly one home.
 */
class CourseGenerationService
{
    /**
     * JSON Schema handed to OpenRouter's structured-output mode.
     *
     * Only content the model can actually know about — no ids, no GPF vectors,
     * no FSRS parameters. Those are filled in client-side from the shared
     * defaults so the two sides cannot drift apart.
     */
    public static function draftSchema(): array
    {
        $questionSchema = [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'enum' => ['open', 'multiple_choice', 'true_false', 'numeric'],
                ],
                'options' => [
                    'type' => 'array',
                    'description' => 'Required for multiple_choice and true_false.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'is_correct' => ['type' => 'boolean'],
                            'feedback' => ['type' => 'string'],
                        ],
                        'required' => ['text', 'is_correct'],
                    ],
                ],
                'correct_answer' => [
                    'type' => 'string',
                    'description' => 'Required for open questions.',
                ],
                'correct_number' => [
                    'type' => 'number',
                    'description' => 'Required for numeric questions.',
                ],
                'tolerance' => ['type' => 'number'],
                'solution' => [
                    'type' => 'string',
                    'description' => 'Worked solution shown after answering. Markdown + LaTeX.',
                ],
            ],
            'required' => ['type'],
        ];

        $stepSchema = [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['text', 'question']],
                'content' => [
                    'type' => 'string',
                    'description' => 'Markdown with LaTeX ($...$ inline, $$...$$ block). For question steps this is the question wording.',
                ],
                'hint' => ['type' => 'string'],
                'help' => ['type' => 'string'],
                'question' => $questionSchema,
            ],
            'required' => ['type', 'content'],
        ];

        $blockSchema = [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'enum' => ['display', 'question', 'exercise'],
                    'description' => 'display = explanation only; question = single graded question; exercise = practice task with hints.',
                ],
                'steps' => [
                    'type' => 'array',
                    'items' => $stepSchema,
                ],
                'concepts' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'difficulty' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                'bloom_level' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6],
            ],
            'required' => ['type', 'steps'],
        ];

        return [
            'type' => 'object',
            'properties' => [
                'lessons' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'blocks' => ['type' => 'array', 'items' => $blockSchema],
                        ],
                        'required' => ['name', 'blocks'],
                    ],
                ],
            ],
            'required' => ['lessons'],
        ];
    }

    /**
     * Run the pipeline for a session, yielding progress events for SSE.
     *
     * Yields arrays shaped like:
     *   ['stage' => 'ocr'|'generate', 'token' => '...']
     *   ['stage' => 'ocr'|'generate', 'status' => 'start'|'done']
     *   ['stage' => 'result', 'result' => [...]]
     *
     * @return \Generator<array<string, mixed>>
     */
    public function run(GenerationSession $session): \Generator
    {
        $parts = $this->buildAttachmentParts($session);

        if ($parts === []) {
            throw new \RuntimeException('Session has no usable attachments.');
        }

        $hasPdf = collect($session->attachments ?? [])
            ->contains(fn (array $a) => ($a['mime_type'] ?? '') === 'application/pdf');

        if ($session->mode === GenerationSession::MODE_OCR) {
            yield ['stage' => 'ocr', 'status' => 'start'];

            $raw = '';
            foreach ($this->stream(
                model: config('generation.ocr_model'),
                messages: [
                    ['role' => 'system', 'content' => $this->ocrDirectPrompt()],
                    ['role' => 'user', 'content' => $parts],
                ],
                jsonSchema: self::draftSchema(),
                withPdfPlugin: $hasPdf,
            ) as $token) {
                $raw .= $token;
                yield ['stage' => 'ocr', 'token' => $token];
            }

            yield ['stage' => 'ocr', 'status' => 'done'];
            yield ['stage' => 'result', 'result' => $this->normalizeDraft($this->decodeDraft($raw))];

            return;
        }

        // Full mode — transcribe, then author.
        yield ['stage' => 'ocr', 'status' => 'start'];

        $transcript = '';
        foreach ($this->stream(
            model: config('generation.ocr_model'),
            messages: [
                ['role' => 'system', 'content' => $this->transcribePrompt()],
                ['role' => 'user', 'content' => $parts],
            ],
            withPdfPlugin: $hasPdf,
        ) as $token) {
            $transcript .= $token;
            yield ['stage' => 'ocr', 'token' => $token];
        }

        yield ['stage' => 'ocr', 'status' => 'done'];

        if (trim($transcript) === '') {
            throw new \RuntimeException('Z nahraných souborů se nepodařilo přečíst žádný text.');
        }

        yield ['stage' => 'generate', 'status' => 'start'];

        $raw = '';
        foreach ($this->stream(
            model: config('generation.gen_model'),
            messages: [
                ['role' => 'system', 'content' => $this->authorPrompt()],
                ['role' => 'user', 'content' => "Podklady přepsané z nahraných materiálů:\n\n" . $transcript],
            ],
            jsonSchema: self::draftSchema(),
        ) as $token) {
            $raw .= $token;
            yield ['stage' => 'generate', 'token' => $token];
        }

        yield ['stage' => 'generate', 'status' => 'done'];
        yield ['stage' => 'result', 'result' => $this->normalizeDraft($this->decodeDraft($raw))];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Prompts
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * LaTeX conventions, pinned deliberately hard.
     *
     * Models are consistent about *rendering* maths and wildly inconsistent
     * about *spelling* it — `\frac{1}{2}`, `1/2` and `\dfrac{1}{2}` all look
     * right and all diff differently. Since this output ends up in Block V2
     * content that authors then hand-edit, pick one form and repeat it in every
     * prompt that can emit maths.
     */
    private const LATEX_RULES = <<<'TXT'
    Matematiku piš v LaTeXu podle těchto pravidel — drž se jich doslova:
    - inline vzorec: $...$ ; samostatný vzorec na vlastním řádku: $$...$$
    - zlomky vždy \frac{a}{b}, nikdy a/b a nikdy \dfrac
    - násobení \cdot, nikdy * ani ×
    - desetinná čárka jako {,} uvnitř vzorce (např. $3{,}14$)
    - běžný text nikdy nebal do $...$
    TXT;

    private function transcribePrompt(): string
    {
        return <<<TXT
        Jsi přesný přepisovač studijních materiálů. Dostaneš fotky nebo PDF stránek učebnice,
        pracovního listu nebo zápisu z hodiny.

        Přepiš VŠECHNO, co na stránkách je, do strukturovaného markdownu:
        - zachovej nadpisy, pořadí a číslování úloh
        - tabulky přepiš jako markdown tabulky
        - u obrázků/diagramů, které nelze přepsat, napiš `[obrázek: stručný popis]`
        - nic nedomýšlej a nic nevynechávej; když je něco nečitelné, napiš `[nečitelné]`
        - pozor na české diakritické znaky (ř, ě, ů, č, š, ž) — často se zamění

        {$this->latexRules()}

        Odpověz POUZE přepsaným markdownem, bez úvodu a bez komentáře.
        TXT;
    }

    private function latexRules(): string
    {
        return self::LATEX_RULES;
    }

    private function ocrDirectPrompt(): string
    {
        return <<<TXT
        Jsi přesný přepisovač studijních materiálů. Dostaneš fotky nebo PDF stránek.

        Přepiš obsah do struktury lekcí a bloků a vrať JSON podle zadaného schématu.

        Pravidla:
        - Přepisuj VĚRNĚ. Nic nepřidávej, nevymýšlej si otázky ani příklady.
        - Používej POUZE bloky typu "display" se stepy typu "text".
        - Každý logický celek (nadpis + jeho text, jedna úloha) = jeden blok.
        - Souvislý celek stránek = jedna lekce; `name` odvoď z nadpisu na stránce.
        - Nečitelné části označ `[nečitelné]`.
        - Pozor na české diakritické znaky (ř, ě, ů, č, š, ž) — často se zamění.
        - Odpovídej česky, pokud předloha není v jiném jazyce.

        {$this->latexRules()}
        TXT;
    }

    private function authorPrompt(): string
    {
        return <<<TXT
        Jsi zkušený autor digitálních výukových kurzů. Z dodaných podkladů vytvoř návrh
        kurzu a vrať JSON podle zadaného schématu.

        Struktura:
        - Rozděl látku do lekcí podle témat. Každá lekce má výstižný `name` a krátký `description`.
        - Střídej bloky: nejdřív "display" blok, který látku vysvětlí, pak "question" nebo
          "exercise" bloky, které ji procvičí.
        - `display` blok = jen stepy typu "text" (výklad, příklad s postupem, shrnutí).
        - `question` blok = jeden text step s kontextem (volitelně) + jeden step typu "question".
        - `exercise` blok = totéž jako question, ale s vyplněným `hint` a `help` u text stepu
          a s `solution` v otázce — slouží k procvičování.

        Kvalita:
        - Vycházej z podkladů. Doplňuj navíc pouze PODOBNÉ procvičovací příklady na stejnou
          látku; nikdy nezaváděj nové téma, které v podkladech není.
        - U `multiple_choice` dej 3–4 možnosti, právě jednu správnou, a ke každé krátký `feedback`
          vysvětlující, proč je (ne)správná.
        - U `numeric` vyplň `correct_number` a rozumnou `tolerance`.
        - Vždy vyplň `solution` s postupem řešení, ne jen výsledek.
        - `difficulty` 1–5 a `bloom_level` 1–6 nastav podle náročnosti, `concepts` pojmenuj česky.
        - Piš česky, pokud podklady nejsou v jiném jazyce.

        {$this->latexRules()}
        TXT;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OpenRouter plumbing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build the multimodal user content parts from the session's attachments.
     *
     * Files are inlined as base64 data URLs rather than R2 URLs so the bucket
     * never has to be reachable by a third party.
     *
     * @return list<array<string, mixed>>
     */
    private function buildAttachmentParts(GenerationSession $session): array
    {
        $parts = [];

        foreach ($session->attachments ?? [] as $attachment) {
            $path = $attachment['path'] ?? null;
            $mime = $attachment['mime_type'] ?? null;

            if (! $path || ! $mime) {
                continue;
            }

            try {
                $bytes = Storage::disk('s3')->get($path);
            } catch (\Throwable $e) {
                Log::warning('Generation attachment unreadable', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($bytes === null || $bytes === '') {
                continue;
            }

            $dataUrl = "data:{$mime};base64," . base64_encode($bytes);

            if ($mime === 'application/pdf') {
                $parts[] = [
                    'type' => 'file',
                    'file' => [
                        'filename' => $attachment['filename'] ?? 'document.pdf',
                        'file_data' => $dataUrl,
                    ],
                ];
            } else {
                $parts[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => $dataUrl],
                ];
            }
        }

        return $parts;
    }

    /**
     * Stream a chat completion from OpenRouter. Yields content deltas.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>|null  $jsonSchema
     * @return \Generator<string>
     */
    private function stream(
        string $model,
        array $messages,
        ?array $jsonSchema = null,
        bool $withPdfPlugin = false,
    ): \Generator {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'max_tokens' => config('generation.max_tokens'),
            'temperature' => 0.2,
        ];

        if ($jsonSchema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'course_draft',
                    'strict' => false,
                    'schema' => $jsonSchema,
                ],
            ];
        }

        if ($withPdfPlugin) {
            $payload['plugins'] = [[
                'id' => 'file-parser',
                'pdf' => ['engine' => config('generation.pdf_engine')],
            ]];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('chat.api_key'),
            'HTTP-Referer' => 'https://edu-ai.eu',
            'X-Title' => 'EDU-AI Course Assistant',
            'Content-Type' => 'application/json',
        ])
            ->timeout(300)
            ->withOptions(['stream' => true])
            ->post(config('chat.api_url') . '/chat/completions', $payload);

        if (! $response->successful()) {
            Log::error('OpenRouter generation error', [
                'model' => $model,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('OpenRouter request failed: ' . $response->status());
        }

        $body = $response->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(8192);

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line === '' || ! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = substr($line, 6);

                if ($data === '[DONE]') {
                    return;
                }

                $json = json_decode($data, true);
                $token = $json['choices'][0]['delta']['content'] ?? null;

                if ($token !== null && $token !== '') {
                    yield $token;
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Result handling
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Decode the model's JSON answer, tolerating markdown fences and preamble.
     *
     * @return array<string, mixed>
     */
    public function decodeDraft(string $raw): array
    {
        $text = trim($raw);

        // Strip ```json fences some models add despite response_format.
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-z]*\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            // Last resort: grab the outermost {...}.
            $start = strpos($text, '{');
            $end = strrpos($text, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($decoded)) {
            Log::error('Generation returned unparsable JSON', ['head' => mb_substr($raw, 0, 500)]);
            throw new \RuntimeException('Model nevrátil platný JSON.');
        }

        return $decoded;
    }

    /**
     * Coerce the model output into the exact draft shape the admin expects.
     *
     * Everything unknown is dropped and everything missing gets a sane value,
     * so the frontend never has to defend against a creative model.
     *
     * @param  array<string, mixed>  $decoded
     * @return array{lessons: list<array<string, mixed>>}
     */
    public function normalizeDraft(array $decoded): array
    {
        $lessons = [];

        foreach ($decoded['lessons'] ?? [] as $rawLesson) {
            if (! is_array($rawLesson)) {
                continue;
            }

            $blocks = [];

            foreach ($rawLesson['blocks'] ?? [] as $rawBlock) {
                $block = $this->normalizeBlock(is_array($rawBlock) ? $rawBlock : []);

                if ($block !== null) {
                    $blocks[] = $block;
                }
            }

            if ($blocks === []) {
                continue;
            }

            $lessons[] = [
                'name' => $this->str($rawLesson['name'] ?? '') ?: 'Nová lekce',
                'description' => $this->str($rawLesson['description'] ?? ''),
                'blocks' => $blocks,
            ];
        }

        if ($lessons === []) {
            throw new \RuntimeException('Model nevygeneroval žádný použitelný obsah.');
        }

        return ['lessons' => $lessons];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function normalizeBlock(array $raw): ?array
    {
        $type = in_array($raw['type'] ?? null, ['display', 'question', 'exercise'], true)
            ? $raw['type']
            : 'display';

        $steps = [];

        foreach ($raw['steps'] ?? [] as $rawStep) {
            if (! is_array($rawStep)) {
                continue;
            }

            $stepType = ($rawStep['type'] ?? 'text') === 'question' ? 'question' : 'text';
            $content = $this->str($rawStep['content'] ?? '');

            // A question step without a question config is useless; a text step
            // without content is noise.
            if ($stepType === 'text' && $content === '') {
                continue;
            }

            $step = ['type' => $stepType, 'content' => $content];

            if ($hint = $this->str($rawStep['hint'] ?? '')) {
                $step['hint'] = $hint;
            }

            if ($help = $this->str($rawStep['help'] ?? '')) {
                $step['help'] = $help;
            }

            if ($stepType === 'question') {
                $question = $this->normalizeQuestion(
                    is_array($rawStep['question'] ?? null) ? $rawStep['question'] : []
                );

                if ($question === null) {
                    continue;
                }

                $step['question'] = $question;
            }

            $steps[] = $step;
        }

        if ($steps === []) {
            return null;
        }

        // Display blocks never carry question steps; a mislabelled block is
        // promoted rather than silently losing its question.
        $hasQuestion = collect($steps)->contains(fn (array $s) => $s['type'] === 'question');

        if ($type === 'display' && $hasQuestion) {
            $type = 'question';
        } elseif ($type !== 'display' && ! $hasQuestion) {
            $type = 'display';
        }

        return [
            'type' => $type,
            'steps' => $steps,
            'concepts' => array_values(array_filter(array_map(
                fn ($c) => $this->str($c),
                is_array($raw['concepts'] ?? null) ? $raw['concepts'] : []
            ))),
            'difficulty' => $this->clampInt($raw['difficulty'] ?? 3, 1, 5),
            'bloom_level' => $this->clampInt($raw['bloom_level'] ?? 2, 1, 6),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function normalizeQuestion(array $raw): ?array
    {
        $type = in_array($raw['type'] ?? null, ['open', 'multiple_choice', 'true_false', 'numeric'], true)
            ? $raw['type']
            : 'open';

        $question = ['type' => $type];

        if ($solution = $this->str($raw['solution'] ?? '')) {
            $question['solution'] = $solution;
        }

        if ($type === 'multiple_choice' || $type === 'true_false') {
            $options = [];

            foreach ($raw['options'] ?? [] as $index => $rawOption) {
                if (! is_array($rawOption)) {
                    continue;
                }

                $text = $this->str($rawOption['text'] ?? '');

                if ($text === '') {
                    continue;
                }

                $option = [
                    // Stable, human-readable ids: a, b, c, …
                    'id' => chr(97 + min($index, 25)),
                    'text' => $text,
                    'is_correct' => (bool) ($rawOption['is_correct'] ?? false),
                ];

                if ($feedback = $this->str($rawOption['feedback'] ?? '')) {
                    $option['feedback'] = $feedback;
                }

                $options[] = $option;
            }

            // Unanswerable without at least one option and one correct answer.
            if (count($options) < 2 || ! collect($options)->contains(fn (array $o) => $o['is_correct'])) {
                return null;
            }

            $question['options'] = $options;
            $question['show_answers'] = true;

            return $question;
        }

        if ($type === 'numeric') {
            if (! isset($raw['correct_number']) || ! is_numeric($raw['correct_number'])) {
                return null;
            }

            $question['correct_number'] = (float) $raw['correct_number'];
            $question['tolerance'] = is_numeric($raw['tolerance'] ?? null)
                ? (float) $raw['tolerance']
                : 0;

            return $question;
        }

        $question['correct_answer'] = $this->str($raw['correct_answer'] ?? '');

        return $question;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function clampInt(mixed $value, int $min, int $max): int
    {
        $int = is_numeric($value) ? (int) $value : $min;

        return max($min, min($max, $int));
    }
}
