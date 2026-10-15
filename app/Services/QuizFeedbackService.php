<?php

namespace App\Services;

use App\Models\Mentor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI comment for the end-of-quiz feedback popup ("Quiz Feedback v1").
 *
 * After a block of questions the app shows outcome counts and domain ELO
 * movement offline; when online it adds a short, non-punitive comment on how
 * the student worked — praise for progress, what to practise next, that hints
 * are a normal part of learning, and a gentle note on guessed (gamed)
 * attempts. Strictly best-effort: any failure returns null and the popup
 * hides the section.
 *
 * The prompt is admin-editable: it lives on the mentors row with slug
 * {@see self::PROMPT_SLUG} (hidden, so it never appears in the chat list) and
 * {@see self::DEFAULT_PROMPT} is only the fallback when that row is gone.
 */
class QuizFeedbackService
{
    /** Mentors-table slug whose ai_context is the live prompt. */
    public const PROMPT_SLUG = 'quiz-feedback';

    /**
     * Verbatim from the brief ("Quiz Feedback Dialog v1"). Edits belong on the
     * mentors row, not here — this only covers a deleted/blank row. The same
     * text is seeded by the add_quiz_feedback_prompt_mentor migration.
     */
    public const DEFAULT_PROMPT = 'Vytvoř stručnou zpětnou vazbu pro žáka 15 let (cca 3-5 vět), '
        .'který vyřešil v rámci výukové aplikace následujících 10 úloh. Zpětná vazba by měla '
        .'obsahovat stručné vyjádření k chování žáka (gaming/využívání nápověd), odpovídající '
        .'povzbuzení nebo pochvalu a vyjádření k tomu, čemu by se měl dále věnovat. '
        .'Charakter zpětné vazby: Zpětná vazba nemá uživatele trestat. Má: '
        .'- ocenit pokrok, '
        .'- upozornit na oblast, kterou je vhodné procvičit, '
        .'- doporučit další postup, '
        .'- vysvětlit, že nápověda je běžnou součástí učení, '
        .'- u gaming pokusů dát jemnou zpětnou vazbu typu: „Tahle odpověď byla příliš rychlá, '
        .'proto ji raději nepočítáme do výsledků."';

    /**
     * Output contract appended after the (editable) prompt. Fixed in code on
     * purpose: however the prompt is reworded, the popup renders plain text.
     */
    private const OUTPUT_CONTRACT = 'Odpověz pouze samotným textem zpětné vazby — '
        .'bez nadpisů, odrážek a formátování, žákovi tykej.';

    /**
     * The comment, or null when the model call failed.
     *
     * @param  array<int, array{assignment?: ?string, outcome: string, hint_used?: bool, help_used?: bool, duration_ms?: ?int}>  $tasks
     * @param  array<string, float|int|string>  $domainDeltas  domain code => avg ELO delta
     */
    public function comment(array $tasks, array $domainDeltas = [], ?string $language = null): ?string
    {
        $messages = [
            ['role' => 'system', 'content' => $this->prompt()."\n\n".self::OUTPUT_CONTRACT],
            ['role' => 'user', 'content' => $this->userTurn($tasks, $domainDeltas, $language)],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('chat.api_key'),
                'HTTP-Referer' => 'https://edu-ai.eu',
                'X-Title' => 'EDU-AI',
                'Content-Type' => 'application/json',
            ])
                ->timeout((int) config('chat.feedback_timeout', 25))
                ->post(config('chat.api_url').'/chat/completions', [
                    'model' => config('chat.feedback_model') ?: config('chat.model'),
                    // Warmer than the evaluator's 0: this is encouragement, not
                    // grading, and identical praise every block reads canned.
                    'temperature' => 0.4,
                    'max_tokens' => (int) config('chat.feedback_max_tokens', 500),
                    'messages' => $messages,
                ]);

            if (! $response->successful()) {
                Log::warning('Quiz feedback comment failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            $text = trim((string) $response->json('choices.0.message.content', ''));

            return $text === '' ? null : $text;
        } catch (\Throwable $e) {
            Log::warning('Quiz feedback comment threw', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function prompt(): string
    {
        $stored = Mentor::query()->where('slug', self::PROMPT_SLUG)->value('ai_context');

        return is_string($stored) && trim($stored) !== '' ? trim($stored) : self::DEFAULT_PROMPT;
    }

    /**
     * The block's results as one user turn. Data goes inside a delimiter tag —
     * assignments are course content and could try to talk to the model.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     * @param  array<string, float|int|string>  $domainDeltas
     */
    private function userTurn(array $tasks, array $domainDeltas, ?string $language): string
    {
        $rows = [];
        foreach (array_values($tasks) as $i => $task) {
            $rows[] = [
                'uloha' => $i + 1,
                'zadani' => mb_substr(trim((string) ($task['assignment'] ?? '')), 0, 500),
                'vysledek' => (string) $task['outcome'],
                'napoveda' => (bool) ($task['hint_used'] ?? false),
                'reseni_zobrazeno' => (bool) ($task['help_used'] ?? false),
                'cas_ms' => isset($task['duration_ms']) ? (int) $task['duration_ms'] : null,
            ];
        }

        $parts = [
            'Výsledky bloku úloh (vysledek: correct_no_hint = správně bez nápovědy, '
            .'correct_with_hint = správně s nápovědou, wrong = špatně, '
            .'unanswered = nezodpovězeno, gamed = tipováno / příliš rychlá odpověď):',
            '<vysledky>',
            json_encode($rows, JSON_UNESCAPED_UNICODE),
            '</vysledky>',
        ];

        if ($domainDeltas !== []) {
            $parts[] = 'Průměrná změna ELO podle domén (N=Číslo, M=Míra v geometrii, '
                .'G=Geometrie, S=Statistika a pravděpodobnost, A=Algebra):';
            $parts[] = '<zmeny_elo>';
            $parts[] = json_encode($domainDeltas, JSON_UNESCAPED_UNICODE);
            $parts[] = '</zmeny_elo>';
        }

        if ($language === 'sk') {
            $parts[] = 'Odpověz slovensky.';
        } elseif ($language === 'en') {
            $parts[] = 'Odpověz anglicky.';
        }

        return implode("\n", $parts);
    }
}
