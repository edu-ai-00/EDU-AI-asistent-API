<?php

namespace Tests\Feature;

use App\Services\ChatAiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatTitleGenerationTest extends TestCase
{
    private function fakeTitleResponse(string $content): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => $content]],
                ],
            ]),
        ]);
    }

    public function test_clean_title_is_used_as_is(): void
    {
        $this->fakeTitleResponse('Sčítání zlomků');

        $this->assertSame(
            'Sčítání zlomků',
            app(ChatAiService::class)->generateTitle('kolik je 3/4 + 1/2')
        );
    }

    public function test_meta_title_falls_back_to_the_literal_question(): void
    {
        $this->fakeTitleResponse('Nejasný dotaz bez kontextu');

        $this->assertSame(
            'kdy to bude',
            app(ChatAiService::class)->generateTitle('kdy to bude')
        );
    }

    public function test_overlong_model_answer_falls_back_to_the_literal_question(): void
    {
        $this->fakeTitleResponse('Zdá se, že tvá zpráva je velmi krátká a nedokážu z ní určit téma konverzace');

        $this->assertSame(
            'kdy to bude',
            app(ChatAiService::class)->generateTitle('kdy to bude')
        );
    }

    public function test_fallback_title_is_truncated_at_a_word_boundary(): void
    {
        $this->fakeTitleResponse('Nejasný dotaz bez kontextu');

        $long = 'kdy to bude a jak se na to mám vlastně připravit když už je skoro pozdě';

        $title = app(ChatAiService::class)->generateTitle($long);

        $this->assertSame('kdy to bude a jak se na to mám vlastně…', $title);
        $this->assertLessThanOrEqual(41, mb_strlen($title));
    }

    public function test_fallback_title_hard_cuts_a_single_long_word(): void
    {
        $this->fakeTitleResponse('Nejasný dotaz bez kontextu');

        $title = app(ChatAiService::class)->generateTitle(str_repeat('á', 60));

        $this->assertSame(str_repeat('á', 40) . '…', $title);
    }

    public function test_fallback_title_collapses_whitespace(): void
    {
        $this->fakeTitleResponse('Nejasný dotaz bez kontextu');

        $this->assertSame(
            'kdy to bude',
            app(ChatAiService::class)->generateTitle("  kdy   to\tbude \nzítra?")
        );
    }

    public function test_greeting_stays_untitled(): void
    {
        $this->fakeTitleResponse('');

        $this->assertNull(app(ChatAiService::class)->generateTitle('ahoj'));
    }

    public function test_failed_request_returns_null(): void
    {
        Http::fake(['*/chat/completions' => Http::response([], 500)]);

        $this->assertNull(app(ChatAiService::class)->generateTitle('kolik je 3/4 + 1/2'));
    }
}
