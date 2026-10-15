<?php

namespace Tests\Unit;

use App\Services\ChatAiService;
use PHPUnit\Framework\TestCase;

/**
 * BR-KFHPAR — the "Zeptat se AI" context carried raw media URLs
 * (`![náměstí](https://pub-….r2.dev/…)`) into the prompt. The model cannot open
 * links and the URL crowded out the task statement in the context budget.
 */
class ChatUserContentSanitizeTest extends TestCase
{
    private function sanitize(string $content): string
    {
        return (new ChatAiService())->sanitizeUserContent($content);
    }

    public function test_markdown_image_collapses_to_its_alt_text(): void
    {
        $this->assertSame(
            'Obsah úlohy: [Obrázek: náměstí]',
            $this->sanitize('Obsah úlohy: ![náměstí](https://pub-7a82.r2.dev/img/x.png)')
        );
    }

    public function test_image_without_alt_keeps_a_bare_label(): void
    {
        $this->assertSame('[Obrázek]', $this->sanitize('![](https://pub-7a82.r2.dev/x.png)'));
    }

    public function test_link_keeps_its_label_and_loses_the_target(): void
    {
        $this->assertSame(
            'Podívej se na CERMAT a pokračuj.',
            $this->sanitize('Podívej se na [CERMAT](https://prijimacky.cermat.cz) a pokračuj.')
        );
    }

    public function test_bare_url_is_dropped(): void
    {
        $this->assertSame(
            'Zdroj: a dál',
            $this->sanitize('Zdroj: https://example.com/a/b?c=d a dál')
        );
    }

    public function test_html_media_collapses_to_labels(): void
    {
        $this->assertSame(
            '[Obrázek: graf] [Video] [Audio]',
            $this->sanitize(
                '<img src="https://x.dev/g.png" alt="graf"> '
                .'<video src="https://x.dev/v.mp4"></video> '
                .'<audio src="https://x.dev/a.mp3"></audio>'
            )
        );
    }

    public function test_latex_and_prose_are_left_alone(): void
    {
        $input = "Vyřeš \$x^2 + 1 = 0\$ a pak \$\$\\frac{a}{b}\$\$.\n- první\n- druhý";

        $this->assertSame($input, $this->sanitize($input));
    }

    public function test_line_left_empty_by_a_removal_does_not_leave_a_hole(): void
    {
        $this->assertSame(
            "Zadání:\n\nHotovo",
            $this->sanitize("Zadání:\n\nhttps://pub-7a82.r2.dev/x.png\n\nHotovo")
        );
    }

    public function test_nested_list_indentation_survives(): void
    {
        $this->assertSame(
            "- první\n  - [Obrázek: mapa]",
            $this->sanitize("- první\n  - ![mapa](https://pub-7a82.r2.dev/m.png)")
        );
    }
}
