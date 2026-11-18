<?php

namespace App\Notifications;

use App\Models\NotificationType;

/**
 * Checks a type's templates and variable catalog before they are saved
 * (NOTIF-5, spec §4), and renders the admin preview.
 *
 * Errors are `{template, code, message}` with Czech messages — the shape the
 * admin endpoints return as `errors.templates`. Codes:
 *   invalid_variable, duplicate_variable, missing_fallback,
 *   unknown_variable, invalid_placeholder, required_variable_unused,
 *   empty, too_long, invalid_deep_link
 */
class TemplateValidator
{
    private const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const LABELS = [
        'push_title' => 'Nadpis pushe',
        'push_body' => 'Text pushe',
        'feed_title' => 'Nadpis v přehledu',
        'feed_body' => 'Text v přehledu',
        'deep_link' => 'Odkaz',
    ];

    public function __construct(private readonly TemplateRenderer $renderer) {}

    /**
     * @param  array<string, ?string>  $templates  push_title, push_body, feed_title, feed_body, deep_link
     * @param  list<array<string, mixed>>  $variables  [{key, label, sample, fallback, required}]
     * @param  list<string>  $channels  the type's channels — push texts only matter with push
     * @return list<array{template: string, code: string, message: string}>
     */
    public function validate(array $templates, array $variables, array $channels = NotificationType::CHANNELS): array
    {
        return $this->check($templates, $variables, $channels, [])['errors'];
    }

    /**
     * Render with sample values (overridable) and report errors, lengths and
     * limits — the admin preview, which saves nothing.
     *
     * @param  array<string, ?string>  $templates
     * @param  list<array<string, mixed>>  $variables
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $sample  overrides the variables' own samples
     * @return array{rendered: array<string, ?string>, errors: list<array{template: string, code: string, message: string}>,
     *               lengths: array<string, int>, limits: array<string, int>}
     */
    public function preview(array $templates, array $variables, array $channels = NotificationType::CHANNELS, array $sample = []): array
    {
        return $this->check($templates, $variables, $channels, $sample);
    }

    /** Shortcut for an existing type, optionally with edited templates. */
    public function validateType(NotificationType $type, ?array $templates = null, ?array $variables = null, ?array $channels = null): array
    {
        return $this->validate(
            array_merge($type->templates(), $templates ?? []),
            $variables ?? (array) $type->variables,
            $channels ?? $type->channelList(),
        );
    }

    /**
     * @return array{rendered: array<string, ?string>, errors: list<array{template: string, code: string, message: string}>,
     *               lengths: array<string, int>, limits: array<string, int>}
     */
    private function check(array $templates, array $variables, array $channels, array $sample): array
    {
        $errors = [];
        $declared = $this->checkVariables($variables, $errors);
        $withPush = in_array(NotificationType::CHANNEL_PUSH, $channels, true);

        $used = [];
        foreach (TemplateRenderer::TEMPLATE_KEYS as $key) {
            $template = (string) ($templates[$key] ?? '');

            foreach (TemplateRenderer::placeholders($template) as $name) {
                $used[$name] = true;

                if (!array_key_exists($name, $declared)) {
                    $errors[] = $this->error($key, 'unknown_variable', sprintf(
                        '%s: proměnná {{%s}} není u typu deklarovaná.',
                        self::LABELS[$key],
                        $name,
                    ));
                }
            }

            // `{{` that is not a well-formed placeholder would be stripped at send.
            $withoutValid = preg_replace(TemplateRenderer::PLACEHOLDER, '', $template);
            if (str_contains((string) $withoutValid, '{{') || str_contains((string) $withoutValid, '}}')) {
                $errors[] = $this->error($key, 'invalid_placeholder', sprintf(
                    '%s: zápis proměnné je neplatný. Použijte tvar {{nazev_promenne}}.',
                    self::LABELS[$key],
                ));
            }
        }

        foreach (['feed_title', 'feed_body'] as $key) {
            if (trim((string) ($templates[$key] ?? '')) === '') {
                $errors[] = $this->error($key, 'empty', self::LABELS[$key] . ' nesmí být prázdný.');
            }
        }

        if ($withPush) {
            foreach (['push_title', 'push_body'] as $key) {
                if (trim((string) ($templates[$key] ?? '')) === '') {
                    $errors[] = $this->error($key, 'empty', self::LABELS[$key] . ' nesmí být prázdný, když typ posílá push.');
                }
            }
        }

        $deepLink = (string) ($templates['deep_link'] ?? '');
        if ($deepLink !== '' && !str_starts_with($deepLink, '/')) {
            $errors[] = $this->error('deep_link', 'invalid_deep_link', 'Odkaz musí začínat lomítkem, např. /dash.');
        }

        // A spec rule: each required variable appears in at least one of the
        // four texts — otherwise the value the producer supplies goes nowhere.
        foreach ($declared as $name => $definition) {
            if (!empty($definition['required']) && !$this->usedInTexts($name, $templates)) {
                $errors[] = $this->error('variables', 'required_variable_unused', sprintf(
                    'Povinná proměnná {{%s}} se nepoužívá v žádném z textů.',
                    $name,
                ));
            }
        }

        // Render with the samples and measure.
        $values = [];
        foreach ($declared as $name => $definition) {
            $values[$name] = array_key_exists($name, $sample) ? $sample[$name] : ($definition['sample'] ?? null);
        }

        $issues = [];
        $rendered = $this->renderer->renderTemplateSet($templates, $declared, $values, $issues);
        $limits = TemplateRenderer::limits();
        $lengths = [];

        foreach (TemplateRenderer::TEMPLATE_KEYS as $key) {
            $lengths[$key] = mb_strlen((string) ($rendered[$key] ?? ''));

            $isPush = str_starts_with($key, 'push_');
            if ($isPush && !$withPush) {
                continue;
            }

            if ($lengths[$key] > $limits[$key]) {
                $errors[] = $this->error($key, 'too_long', sprintf(
                    '%s má po dosazení vzorových hodnot %d znaků, povoleno je nejvýše %d.',
                    self::LABELS[$key],
                    $lengths[$key],
                    $limits[$key],
                ));
            }
        }

        return [
            'rendered' => $rendered,
            'errors' => $errors,
            'lengths' => $lengths,
            'limits' => $limits,
        ];
    }

    /**
     * Validate the variable catalog and return the well-formed entries keyed
     * by variable key.
     *
     * @param  list<array<string, mixed>>  $variables
     * @param  list<array{template: string, code: string, message: string}>  $errors
     * @return array<string, array<string, mixed>>
     */
    private function checkVariables(array $variables, array &$errors): array
    {
        $declared = [];

        foreach (array_values($variables) as $i => $variable) {
            $position = $i + 1;

            if (!is_array($variable) || !isset($variable['key']) || !is_string($variable['key'])
                || !preg_match(self::KEY_PATTERN, $variable['key'])) {
                $errors[] = $this->error('variables', 'invalid_variable', sprintf(
                    'Proměnná č. %d nemá platný klíč (písmena, číslice a podtržítko).',
                    $position,
                ));

                continue;
            }

            $key = $variable['key'];

            if (array_key_exists($key, $declared)) {
                $errors[] = $this->error('variables', 'duplicate_variable', sprintf(
                    'Proměnná {{%s}} je deklarovaná vícekrát.',
                    $key,
                ));

                continue;
            }

            if (!array_key_exists('fallback', $variable) || !(is_string($variable['fallback']) || $variable['fallback'] === null)) {
                $errors[] = $this->error('variables', 'missing_fallback', sprintf(
                    'Proměnná {{%s}} nemá náhradní hodnotu (fallback).',
                    $key,
                ));
            }

            foreach (['label', 'sample'] as $field) {
                if (array_key_exists($field, $variable) && $variable[$field] !== null && !is_scalar($variable[$field])) {
                    $errors[] = $this->error('variables', 'invalid_variable', sprintf(
                        'Proměnná {{%s}}: pole „%s“ musí být text.',
                        $key,
                        $field,
                    ));
                }
            }

            $declared[$key] = $variable;
        }

        return $declared;
    }

    /** @param  array<string, ?string>  $templates */
    private function usedInTexts(string $name, array $templates): bool
    {
        foreach (['push_title', 'push_body', 'feed_title', 'feed_body'] as $key) {
            if (in_array($name, TemplateRenderer::placeholders($templates[$key] ?? null), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{template: string, code: string, message: string} */
    private function error(string $template, string $code, string $message): array
    {
        return ['template' => $template, 'code' => $code, 'message' => $message];
    }
}
