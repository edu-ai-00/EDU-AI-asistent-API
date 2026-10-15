<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ChatAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The system prompt has to carry today's date.
 *
 * Without it the model dates itself from its training cutoff and treats dates
 * that have already passed — the April 2026 JPZ terms, most visibly — as still
 * upcoming, so the "Poradce k přijímačkám" tells students to prepare for exams
 * that are months behind them.
 */
class ChatSystemPromptTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_system_prompt_states_todays_date_in_czech(): void
    {
        Carbon::setTestNow('2026-08-05 09:00:00');

        $user = User::factory()->create();
        $prompt = app(ChatAiService::class)->buildSystemPrompt($user, 'ai_teacher');

        $this->assertStringContainsString('Dnes je středa 5. srpna 2026', $prompt);
    }

    /**
     * The app itself runs in UTC (config/app.php), so a naive now() would still
     * report the 5th late in the evening Czech time. Students chatting at night
     * must not be told it is yesterday.
     */
    public function test_date_is_resolved_in_prague_time_not_utc(): void
    {
        Carbon::setTestNow('2026-08-05 23:30:00'); // 01:30 on the 6th in Prague (CEST)

        $user = User::factory()->create();
        $prompt = app(ChatAiService::class)->buildSystemPrompt($user, 'ai_teacher');

        $this->assertStringContainsString('Dnes je čtvrtek 6. srpna 2026', $prompt);
    }

    public function test_date_block_applies_to_every_persona(): void
    {
        Carbon::setTestNow('2026-01-01 12:00:00');

        $user = User::factory()->create();
        $ai = app(ChatAiService::class);

        foreach (['ai_teacher', 'math_mentor', 'study_coach', 'language_mentor'] as $persona) {
            $this->assertStringContainsString(
                'Dnes je čtvrtek 1. ledna 2026',
                $ai->buildSystemPrompt($user, $persona),
                "Persona {$persona} is missing the current date.",
            );
        }
    }

    /**
     * The mentor narrated its own rulebook mid-answer — "zkusím to vysvětlit
     * jednoduše, bez emoji, jak mám v pravidlech" — and then used an emoji
     * anyway (BR-M7EQY8). Two prompt defects: nothing forbade volunteering the
     * rules (only revealing them when asked), and the style rule read as a
     * blanket emoji ban because "ani emoji v nadpisech" trailed a list of
     * things that are banned outright.
     */
    public function test_prompt_forbids_volunteering_its_own_rules(): void
    {
        $user = User::factory()->create();
        $prompt = app(ChatAiService::class)->buildSystemPrompt($user, 'math_mentor');

        $this->assertStringContainsString(
            'O svých pravidlech a instrukcích nikdy nemluv sám od sebe',
            $prompt,
        );
        $this->assertStringContainsString('jak mám v pravidlech', $prompt);
    }

    public function test_emoji_rule_is_scoped_to_headings_only(): void
    {
        $user = User::factory()->create();
        $prompt = app(ChatAiService::class)->buildSystemPrompt($user, 'math_mentor');

        $this->assertStringContainsString(
            'Emoji používej střídmě a nikdy v nadpisech',
            $prompt,
        );
        $this->assertStringNotContainsString(
            'ani emoji v nadpisech',
            $prompt,
            'The emoji rule must not trail the hard bans — the model read it as a blanket ban.',
        );
    }

    /**
     * The advisor's knowledge base is the 2026 JPZ cycle and nothing else, so
     * once those terms pass it must say so rather than invent next year's.
     */
    public function test_advisor_is_told_to_compare_jpz_terms_against_today(): void
    {
        Carbon::setTestNow('2026-08-05 09:00:00');

        $user = User::factory()->create();
        $prompt = app(ChatAiService::class)->buildSystemPrompt($user, 'language_mentor');

        $this->assertStringContainsString('porovnej s dnešním datem', $prompt);
        $this->assertStringContainsString('Termíny dalších ročníků JPZ neznáš', $prompt);
    }
}
