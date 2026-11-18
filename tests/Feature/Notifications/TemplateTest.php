<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationType;
use App\Models\UserNotification;
use App\Notifications\TemplateRenderer;
use App\Notifications\TemplateValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Templates (NOTIF-5, spec §4): declared variables only, fallbacks, length
 * checks with samples, truncation that respects diacritics, and no raw
 * `{{…}}` ever reaching a user.
 */
class TemplateTest extends TestCase
{
    use NotificationTestHelpers;
    use RefreshDatabase;

    private function variables(): array
    {
        return [
            ['key' => 'title', 'label' => 'Nadpis', 'sample' => 'Výlet', 'fallback' => 'Zpráva', 'required' => true],
            ['key' => 'name', 'label' => 'Jméno', 'sample' => 'Tereza', 'fallback' => 'Ahoj', 'required' => false],
        ];
    }

    private function templates(array $overrides = []): array
    {
        return array_merge([
            'push_title' => '{{title}}',
            'push_body' => '{{ name }}, máme novinku.',
            'feed_title' => '{{title}}',
            'feed_body' => 'Ahoj {{name}}!',
            'deep_link' => '/news',
        ], $overrides);
    }

    private function validator(): TemplateValidator
    {
        return app(TemplateValidator::class);
    }

    private function codes(array $errors): array
    {
        return array_map(fn (array $e) => $e['template'] . ':' . $e['code'], $errors);
    }

    public function test_valid_templates_pass_and_whitespace_inside_braces_is_allowed(): void
    {
        $this->assertSame([], $this->validator()->validate($this->templates(), $this->variables()));
    }

    public function test_every_seeded_type_passes_validation(): void
    {
        $this->seedCatalog();

        foreach (NotificationType::all() as $type) {
            $this->assertSame([], $this->validator()->validateType($type), "type {$type->key}");
        }

        $this->assertSame(15, NotificationType::count());
    }

    public function test_unknown_variable_is_rejected(): void
    {
        $errors = $this->validator()->validate($this->templates(['feed_body' => 'Ahoj {{nickname}}']), $this->variables());

        $this->assertContains('feed_body:unknown_variable', $this->codes($errors));
        $this->assertStringContainsString('{{nickname}}', $errors[0]['message']);
    }

    public function test_required_variable_must_appear_in_a_text(): void
    {
        $errors = $this->validator()->validate(
            $this->templates(['push_title' => 'Novinka', 'feed_title' => 'Novinka']),
            $this->variables(),
        );

        $this->assertContains('variables:required_variable_unused', $this->codes($errors));
    }

    public function test_variable_without_a_fallback_key_is_rejected(): void
    {
        $variables = $this->variables();
        unset($variables[1]['fallback']);

        $this->assertContains('variables:missing_fallback', $this->codes($this->validator()->validate($this->templates(), $variables)));
    }

    public function test_lengths_are_checked_with_the_sample_values(): void
    {
        $variables = $this->variables();
        $variables[0]['sample'] = str_repeat('á', 70);

        $errors = $this->validator()->validate($this->templates(), $variables);
        $this->assertContains('push_title:too_long', $this->codes($errors));
        $this->assertNotContains('feed_title:too_long', $this->codes($errors));

        // Feed-only type: push lengths do not matter.
        $this->assertSame([], $this->validator()->validate($this->templates(), $variables, ['feed']));

        $preview = $this->validator()->preview($this->templates(), $this->variables(), ['push', 'feed'], ['name' => 'Kuba']);
        $this->assertSame('Kuba, máme novinku.', $preview['rendered']['push_body']);
        $this->assertSame(mb_strlen('Kuba, máme novinku.'), $preview['lengths']['push_body']);
        $this->assertSame(65, $preview['limits']['push_title']);
    }

    public function test_malformed_placeholders_and_empty_texts_are_rejected(): void
    {
        $errors = $this->validator()->validate($this->templates([
            'feed_title' => '',
            'push_body' => 'Ahoj {{name',
            'deep_link' => 'news',
        ]), $this->variables());

        $codes = $this->codes($errors);
        $this->assertContains('feed_title:empty', $codes);
        $this->assertContains('push_body:invalid_placeholder', $codes);
        $this->assertContains('deep_link:invalid_deep_link', $codes);
    }

    public function test_fallback_replaces_missing_empty_and_non_scalar_values(): void
    {
        $renderer = app(TemplateRenderer::class);
        $map = ['name' => ['key' => 'name', 'fallback' => 'Ahoj']];

        $this->assertSame('Ahoj!', $renderer->render('{{name}}!', $map, []));
        $this->assertSame('Ahoj!', $renderer->render('{{name}}!', $map, ['name' => '  ']));
        $this->assertSame('Ahoj!', $renderer->render('{{name}}!', $map, ['name' => ['x']]));
        $this->assertSame('Tereza!', $renderer->render('{{name}}!', $map, ['name' => 'Tereza']));
        $this->assertSame('7 dní', $renderer->render('{{name}} dní', $map, ['name' => 7]));
    }

    public function test_raw_placeholders_never_survive_rendering(): void
    {
        $renderer = app(TemplateRenderer::class);
        $map = ['name' => ['key' => 'name', 'fallback' => 'Ahoj']];
        $issues = [];

        // Undeclared variable, malformed placeholder, braces carried in by a value.
        $out = $renderer->render('{{unknown}} {{name}} {{ bad name }} konec', $map, ['name' => 'a {{x}} b'], $issues);

        $this->assertStringNotContainsString('{{', $out);
        $this->assertSame('a b konec', $out);
        $this->assertContains('unknown_variable:unknown', $issues);
        $this->assertContains('leftover_placeholder', $issues);
    }

    public function test_truncation_is_multibyte_safe_and_diacritics_survive_storage(): void
    {
        $this->assertSame('Žluťoučký…', TemplateRenderer::truncate('Žluťoučký kůň úpěl ďábelské ódy', 10));
        $this->assertSame('Krátký', TemplateRenderer::truncate('Krátký', 10));
        $this->assertSame(10, mb_strlen(TemplateRenderer::truncate('Žluťoučký kůň úpěl ďábelské ódy', 10)));

        // A long broadcast is shortened for push at send, the feed keeps more.
        $this->seedCatalog();
        $this->fakePush();
        $student = $this->student();
        $this->device($student);
        $long = str_repeat('Příliš žluťoučký kůň úpěl ďábelské ódy. ', 10);

        $result = $this->sendTo('school_important_message', [$student], ['title' => 'Řeřicha', 'body' => $long], ['source' => 'broadcast']);
        $notification = UserNotification::find($result->delivered[0]->id);

        $this->assertSame(180, mb_strlen($notification->push_body));
        $this->assertStringEndsWith('…', $notification->push_body);
        $this->assertSame(trim($long), $notification->body);
        $this->assertSame('Řeřicha', $notification->title);

        // Stored as UTF-8, not as \u escapes or mangled bytes.
        $raw = DB::table('user_notifications')->where('id', $notification->id)->value('push_title');
        $this->assertSame('Řeřicha', $raw);
    }

    public function test_deep_link_values_are_url_encoded(): void
    {
        $renderer = app(TemplateRenderer::class);

        $rendered = $renderer->renderTemplateSet(
            ['push_title' => '', 'push_body' => '', 'feed_title' => 'x', 'feed_body' => 'y', 'deep_link' => '/course/{{course_code}}'],
            ['course_code' => ['key' => 'course_code', 'fallback' => '-']],
            ['course_code' => 'A B/1'],
        );

        $this->assertSame('/course/A%20B%2F1', $rendered['deep_link']);
    }
}
