<?php

namespace App\Notifications;

use App\Models\NotificationType;
use Illuminate\Support\Facades\Log;

/**
 * Renders `{{variable}}` templates (NOTIF-5, spec §4).
 *
 * Only variables declared on the type are substituted. A missing, empty or
 * non-scalar value is replaced by the variable's fallback, and whatever
 * `{{…}}` is left afterwards — an undeclared variable, a malformed
 * placeholder, braces carried in by a value — is stripped and reported, so a
 * raw placeholder never reaches a user. Diacritics pass through untouched;
 * truncation counts characters, not bytes.
 */
class TemplateRenderer
{
    /** A well-formed placeholder; whitespace inside the braces is allowed. */
    public const PLACEHOLDER = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/u';

    /** Anything that still looks like a placeholder after substitution. */
    private const LEFTOVER = '/\{\{.*?\}\}/su';

    public const ELLIPSIS = '…';

    public const TEMPLATE_KEYS = ['push_title', 'push_body', 'feed_title', 'feed_body', 'deep_link'];

    /**
     * @param  array<string, array<string, mixed>>  $variables  declared variables keyed by key
     * @param  array<string, mixed>  $values
     * @param  list<string>  $issues  appended to: unknown_variable:<key>, leftover_placeholder
     */
    public function render(?string $template, array $variables, array $values, array &$issues = [], bool $urlEncode = false): string
    {
        if ($template === null || $template === '') {
            return '';
        }

        $out = preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($variables, $values, &$issues, $urlEncode) {
            $key = $match[1];

            if (!array_key_exists($key, $variables)) {
                $issues[] = 'unknown_variable:' . $key;

                return '';
            }

            $value = self::valueOrFallback($values[$key] ?? null, $variables[$key]);

            return $urlEncode ? rawurlencode($value) : $value;
        }, $template);

        if (preg_match(self::LEFTOVER, (string) $out)) {
            $issues[] = 'leftover_placeholder';
            $out = preg_replace(self::LEFTOVER, '', (string) $out);
        }

        // A stripped placeholder in the middle of a sentence leaves a double space.
        return trim((string) preg_replace('/ {2,}/u', ' ', (string) $out));
    }

    /**
     * All four texts plus the deep link, unshortened.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, ?string>|null  $templates  overrides the type's own (admin preview)
     * @param  list<string>  $issues
     * @return array{push_title: string, push_body: string, feed_title: string, feed_body: string, deep_link: ?string}
     */
    public function renderAll(NotificationType $type, array $values, ?array $templates = null, array &$issues = []): array
    {
        $templates = array_merge($type->templates(), $templates ?? []);
        $variables = $type->variableMap();

        return $this->renderTemplateSet($templates, $variables, $values, $issues);
    }

    /**
     * @param  array<string, ?string>  $templates
     * @param  array<string, array<string, mixed>>  $variables
     * @param  array<string, mixed>  $values
     * @param  list<string>  $issues
     * @return array{push_title: string, push_body: string, feed_title: string, feed_body: string, deep_link: ?string}
     */
    public function renderTemplateSet(array $templates, array $variables, array $values, array &$issues = []): array
    {
        $rendered = [];

        foreach (['push_title', 'push_body', 'feed_title', 'feed_body'] as $key) {
            $rendered[$key] = $this->render($templates[$key] ?? '', $variables, $values, $issues);
        }

        // Values going into a path are URL-encoded; the literal parts are not.
        $deepLink = $templates['deep_link'] ?? null;
        $rendered['deep_link'] = $deepLink === null || $deepLink === ''
            ? null
            : $this->render($deepLink, $variables, $values, $issues, urlEncode: true);

        return $rendered;
    }

    /**
     * The texts as they are stored and sent: push and feed shortened to the
     * configured limits, with a trailing "…".
     *
     * @param  array<string, mixed>  $values
     * @param  array{type_key?: string, user_id?: int}  $logContext
     * @return array{push_title: string, push_body: string, feed_title: string, feed_body: string, deep_link: ?string}
     */
    public function renderForSend(NotificationType $type, array $values, array $logContext = []): array
    {
        $issues = [];
        $rendered = $this->renderAll($type, $values, null, $issues);

        if ($issues) {
            Log::channel('notifications')->warning('notifications.template_placeholders_stripped', $logContext + [
                'type_key' => $type->key,
                'issues' => array_values(array_unique($issues)),
            ]);
        }

        $limits = self::limits();
        $rendered['push_title'] = self::truncate($rendered['push_title'], $limits['push_title']);
        $rendered['push_body'] = self::truncate($rendered['push_body'], $limits['push_body']);
        $rendered['feed_title'] = self::truncate($rendered['feed_title'], $limits['feed_title']);
        $rendered['feed_body'] = self::truncate($rendered['feed_body'], $limits['feed_body']);

        if ($rendered['deep_link'] !== null && mb_strlen($rendered['deep_link']) > 255) {
            $rendered['deep_link'] = null;
        }

        return $rendered;
    }

    /** Shorten to $max characters including the trailing "…". Multibyte-safe. */
    public static function truncate(string $text, int $max): string
    {
        if ($max <= 0 || mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(0, $max - 1))) . self::ELLIPSIS;
    }

    /** @return array{push_title: int, push_body: int, feed_title: int, feed_body: int, deep_link: int} */
    public static function limits(): array
    {
        return [
            'push_title' => (int) config('notifications.push.title_max', 65),
            'push_body' => (int) config('notifications.push.body_max', 180),
            'feed_title' => (int) config('notifications.feed.title_max', 120),
            'feed_body' => (int) config('notifications.feed.body_max', 1000),
            'deep_link' => 255,
        ];
    }

    /**
     * The text a variable contributes: its value when that is a non-empty
     * scalar, otherwise its fallback.
     *
     * @param  array<string, mixed>  $definition
     */
    public static function valueOrFallback(mixed $value, array $definition): string
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        if (is_scalar($value) && trim((string) $value) !== '') {
            return (string) $value;
        }

        $fallback = $definition['fallback'] ?? '';

        return is_scalar($fallback) ? (string) $fallback : '';
    }

    /**
     * Variable keys a template refers to, in order of appearance.
     *
     * @return list<string>
     */
    public static function placeholders(?string $template): array
    {
        if ($template === null || $template === '') {
            return [];
        }

        preg_match_all(self::PLACEHOLDER, $template, $matches);

        return array_values(array_unique($matches[1]));
    }
}
