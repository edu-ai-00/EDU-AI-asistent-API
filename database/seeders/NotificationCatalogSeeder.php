<?php

namespace Database\Seeders;

use App\Models\NotificationRule;
use App\Models\NotificationType;
use Illuminate\Database\Seeder;

/**
 * The 15 notification types and their 15 system rules (spec §3.4).
 *
 * Runs on every deploy (see start.sh) and is create-if-missing on purpose:
 * once a row exists it belongs to the admin, who edits texts, limits and
 * channels in the panel — a deploy must never put the seeded values back.
 * A type or rule that is missing (a fresh database, a new entry added here)
 * is created; nothing that exists is touched.
 *
 * Texts are Czech and gender-neutral. Every variable carries a non-empty
 * fallback, so a send with missing data still reads as a sentence.
 */
class NotificationCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $createdTypes = 0;
        $createdRules = 0;

        foreach (self::types() as $definition) {
            $rule = $definition['rule'];
            unset($definition['rule']);

            $type = NotificationType::firstOrCreate(['key' => $definition['key']], $definition + ['is_system' => true]);
            if ($type->wasRecentlyCreated) {
                $createdTypes++;
            }

            $ruleKey = NotificationRule::SYSTEM_KEY_PREFIX . $type->key;
            $existing = NotificationRule::where('key', $ruleKey)->exists();

            if (!$existing) {
                NotificationRule::create([
                    'key' => $ruleKey,
                    'name' => $type->name,
                    'description' => sprintf('Systémové pravidlo: událost %s posílá typ %s.', $rule['event'], $type->key),
                    'is_system' => true,
                    'is_active' => true,
                    'event_key' => $rule['event'],
                    'notification_type_id' => $type->id,
                    'definition' => [
                        'when' => ['event' => $rule['event']],
                        'if' => $rule['if'],
                        'then' => [
                            'type' => $type->key,
                            'target_role' => $definition['target_role'],
                            'channels' => $definition['channels'],
                            'delay_minutes' => 0,
                        ],
                    ],
                ]);
                $createdRules++;
            }
        }

        $this->command?->info("Notification catalog: {$createdTypes} types and {$createdRules} rules created.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function types(): array
    {
        $name = self::variable('name', 'Křestní jméno', 'Tereza', 'Ahoj', false);

        return [
            [
                'key' => 'school_important_message',
                'name' => 'Důležitá zpráva školy nebo učitele',
                'description' => 'Ruční rozesílka od školy nebo učitele s důležitým sdělením.',
                'category' => 'provoz',
                'target_role' => 'all',
                'is_mandatory' => false,
                'manual_allowed' => true,
                'channels' => ['push', 'feed'],
                'frequency_limit' => null,
                'frequency_period_hours' => null,
                'dedup_window_hours' => 24,
                'push_title' => '{{title}}',
                'push_body' => '{{body}}',
                'feed_title' => '{{title}}',
                'feed_body' => '{{body}}',
                'variables' => [
                    self::variable('title', 'Nadpis', 'Ředitelské volno v pátek', 'Důležitá zpráva', true),
                    self::variable('body', 'Text', 'V pátek je ředitelské volno, výuka odpadá.', 'Otevři aplikaci a přečti si novou zprávu.', true),
                ],
                'deep_link' => '/news',
                'target_action' => null,
                'rule' => self::broadcastRule('school_important_message'),
            ],
            [
                'key' => 'org_deadline_reminder',
                'name' => 'Blížící se organizační termín',
                'description' => 'Připomenutí 24 hodin před termínem uvedeným v rozesílce.',
                'category' => 'provoz',
                'target_role' => 'all',
                'is_mandatory' => false,
                'manual_allowed' => false,
                'channels' => ['push', 'feed'],
                'frequency_limit' => null,
                'frequency_period_hours' => null,
                'dedup_window_hours' => 48,
                'push_title' => 'Blíží se termín',
                'push_body' => '{{title}} – termín {{deadline}}.',
                'feed_title' => 'Blíží se termín: {{title}}',
                'feed_body' => 'Nezapomeň: {{title}}. Termín je {{deadline}}.',
                'variables' => [
                    self::variable('title', 'Nadpis rozesílky', 'Odevzdání přihlášek na výlet', 'důležitá událost', true),
                    self::variable('deadline', 'Termín', '15. 10. 2026 12:00', 'brzy', true),
                ],
                'deep_link' => '/news',
                'target_action' => null,
                'rule' => ['event' => 'broadcast.deadline_approaching', 'if' => []],
            ],
            [
                'key' => 'maintenance_notice',
                'name' => 'Plánovaná údržba nebo výpadek',
                'description' => 'Oznámení o plánované údržbě nebo výpadku aplikace. Přijde vždy.',
                'category' => 'provoz',
                'target_role' => 'all',
                'is_mandatory' => true,
                'manual_allowed' => true,
                'channels' => ['push', 'feed'],
                'frequency_limit' => null,
                'frequency_period_hours' => null,
                'dedup_window_hours' => 24,
                'push_title' => '{{title}}',
                'push_body' => '{{body}}',
                'feed_title' => '{{title}}',
                'feed_body' => '{{body}}',
                'variables' => [
                    self::variable('title', 'Nadpis', 'Údržba v neděli večer', 'Plánovaná údržba', true),
                    self::variable('body', 'Text', 'V neděli od 20:00 do 22:00 může být aplikace krátce nedostupná.', 'Aplikace může být krátce nedostupná.', true),
                ],
                'deep_link' => '/news',
                'target_action' => null,
                'rule' => self::broadcastRule('maintenance_notice'),
            ],
            [
                'key' => 'app_update_notice',
                'name' => 'Důležitá aktualizace aplikace nebo podmínek',
                'description' => 'Oznámení o důležité aktualizaci aplikace nebo podmínek používání. Přijde vždy.',
                'category' => 'provoz',
                'target_role' => 'all',
                'is_mandatory' => true,
                'manual_allowed' => true,
                'channels' => ['push', 'feed'],
                'frequency_limit' => null,
                'frequency_period_hours' => null,
                'dedup_window_hours' => 24,
                'push_title' => '{{title}}',
                'push_body' => '{{body}}',
                'feed_title' => '{{title}}',
                'feed_body' => '{{body}}',
                'variables' => [
                    self::variable('title', 'Nadpis', 'Nová verze aplikace', 'Důležitá aktualizace', true),
                    self::variable('body', 'Text', 'Přinášíme novou verzi aplikace s vylepšeným procvičováním.', 'Otevři aplikaci a podívej se, co je nového.', true),
                ],
                'deep_link' => '/news',
                'target_action' => null,
                'rule' => self::broadcastRule('app_update_notice'),
            ],
            [
                'key' => 'learning_reminder',
                'name' => 'Připomenutí ve zvolený čas učení',
                'description' => 'Připomenutí v čase učení, který si žák sám nastavil, pokud se ten den ještě neučil.',
                'category' => 'navyk',
                'target_role' => 'student',
                'is_mandatory' => false,
                'manual_allowed' => false,
                'channels' => ['push', 'feed'],
                'frequency_limit' => 1,
                'frequency_period_hours' => 24,
                'dedup_window_hours' => 20,
                'push_title' => 'Je čas na učení',
                'push_body' => '{{name}}, stačí pár minut a jsi zase o krok dál.',
                'feed_title' => 'Připomenutí učení',
                'feed_body' => 'Je tvůj čas na učení. Stačí pár minut a jsi zase o krok dál.',
                'variables' => [$name],
                'deep_link' => '/dash',
                'target_action' => 'learning.activity',
                'rule' => ['event' => 'habit.reminder_due', 'if' => []],
            ],
            [
                'key' => 'streak_milestone',
                'name' => 'Milník konzistence',
                'description' => 'Ocenění za sérii dní učení v řadě.',
                'category' => 'navyk',
                'target_role' => 'student',
                'is_mandatory' => false,
                'manual_allowed' => false,
                'channels' => ['feed'],
                'frequency_limit' => null,
                'frequency_period_hours' => null,
                'dedup_window_hours' => 24,
                'push_title' => '{{streak_days}} dní v řadě!',
                'push_body' => 'Učíš se už {{streak_days}} dní v řadě. Skvělá práce, jen tak dál!',
                'feed_title' => '{{streak_days}} dní v řadě!',
                'feed_body' => 'Učíš se už {{streak_days}} dní v řadě. Skvělá práce, jen tak dál!',
                'variables' => [
                    self::variable('streak_days', 'Dní v řadě', '7', 'několik', true),
                ],
                'deep_link' => '/dash',
                'target_action' => null,
                'rule' => ['event' => 'streak.milestone', 'if' => [
                    ['field' => 'streak_days', 'op' => 'in', 'value' => [7, 30]],
                ]],
            ],
            [
                'key' => 'inactivity_comeback',
                'name' => 'Návrat po neaktivitě',
                'description' => 'Pozvánka zpět k učení po několika dnech bez aktivity.',
                'category' => 'navyk',
                'target_role' => 'student',
                'is_mandatory' => false,
                'manual_allowed' => false,
                'channels' => ['push', 'feed'],
                'frequency_limit' => 1,
                'frequency_period_hours' => 168,
                'dedup_window_hours' => 168,
                'push_title' => 'Tvoje kurzy na tebe čekají',
                'push_body' => 'Stačí pár minut a můžeš navázat na svůj postup.',
                'feed_title' => 'Vrať se k učení',
                'feed_body' => 'Tvoje kurzy na tebe čekají. Stačí pár minut a můžeš navázat na svůj postup.',
                'variables' => [],
                'deep_link' => '/dash',
                'target_action' => 'learning.activity',
                'rule' => ['event' => 'user.inactive', 'if' => [
                    ['field' => 'days_inactive', 'op' => 'gte', 'value' => 3],
                    ['field' => 'days_inactive', 'op' => 'lte', 'value' => 30],
                ]],
            ],
            [
                'key' => 'break_suggestion',
                'name' => 'Nabídka přestávky po delší práci',
                'description' => 'Doporučení krátké přestávky po delším souvislém učení.',
                'category' => 'navyk',
                'target_role' => 'student',
                'is_mandatory' => false,
                'manual_allowed' => false,
                'channels' => ['push', 'feed'],
                'frequency_limit' => 1,
                'frequency_period_hours' => 4,
                'dedup_window_hours' => 4,
                'push_title' => 'Dej si pauzu',
                'push_body' => 'Učíš se už {{minutes}} minut. Krátká přestávka pomůže, aby se učivo lépe uleželo.',
                'feed_title' => 'Dej si pauzu',
                'feed_body' => 'Učíš se už {{minutes}} minut. Krátká přestávka pomůže, aby se učivo lépe uleželo.',
                'variables' => [
                    self::variable('minutes', 'Délka učení (min)', '45', '45', true),
                ],
                'deep_link' => '/dash',
                'target_action' => null,
                'rule' => ['event' => 'study.long_session', 'if' => [
                    ['field' => 'minutes', 'op' => 'gte', 'value' => 45],
                ]],
            ],
            [
                'key' => 'goal_completed',
                'name' => 'Splnění denního nebo týdenního cíle',
                'description' => 'Ocenění za splněný denní nebo týdenní cíl XP.',
                'category' => 'pokrok',
                'target_role' => 'student',
                'is_mandatory' => false,
                'manual_allowed' => false,
                'channels' => ['feed'],
                'frequency_limit' => null,
                'frequency_period_hours' => null,
                'dedup_window_hours' => 24,
                'push_title' => 'Cíl splněn!',
                'push_body' => 'Máš splněný {{period_label}} cíl: {{xp}} XP. Výborně!',
                'feed_title' => 'Cíl splněn!',
                'feed_body' => 'Máš splněný {{period_label}} cíl: {{xp}} XP. Výborně!',
                'variables' => [
                    self::variable('period_label', 'Období (denní / týdenní)', 'denní', 'denní', true),
                    self::variable('xp', 'Získané XP', '50', '50', true),
                ],
                'deep_link' => '/dash',
                'target_action' => null,
                'rule' => ['event' => 'goal.completed', 'if' => []],
            ],
            [
                'key' => 'course_completed',
                'name' => 'Dokončení kurzu',
                'description' => 'Gratulace k dokončení celého kurzu.',
                'category' => 'pokrok',
                'target_role' => 'student',
                'is_mandatory' => false,
                'manual_allowed' => false,
                'channels' => ['feed'],
                'frequency_limit' => null,
                'frequency_period_hours' => null,
                'dedup_window_hours' => 8760,
                'push_title' => 'Kurz dokončen',
                'push_body' => 'Gratulujeme! Máš za sebou celý kurz {{course_name}}.',
                'feed_title' => 'Kurz dokončen',
                'feed_body' => 'Gratulujeme! Máš za sebou celý kurz {{course_name}}.',
                'variables' => [
                    self::variable('course_name', 'Název kurzu', 'Zlomky v praxi', 'kurz', true),
                    self::courseCode(),
                ],
                'deep_link' => '/course/{{course_code}}',
                'target_action' => null,
                'rule' => ['event' => 'course.completed', 'if' => []],
            ],
            [
                'key' => 'course_halfway',
                'name' => 'Polovina kurzu',
                'description' => 'Povzbuzení v polovině kurzu.',
                'category' => 'pokrok',
                'target_role' => 'student',
                'is_mandatory' => false,
                'manual_allowed' => false,
                'channels' => ['feed'],
                'frequency_limit' => null,
                'frequency_period_hours' => null,
                'dedup_window_hours' => 8760,
                'push_title' => 'Polovina kurzu za tebou',
                'push_body' => 'V kurzu {{course_name}} máš hotovou polovinu. Jen tak dál!',
                'feed_title' => 'Polovina kurzu za tebou',
                'feed_body' => 'V kurzu {{course_name}} máš hotovou polovinu. Jen tak dál!',
                'variables' => [
                    self::variable('course_name', 'Název kurzu', 'Zlomky v praxi', 'kurz', true),
                    self::courseCode(),
                ],
                'deep_link' => '/course/{{course_code}}',
                'target_action' => 'learning.activity',
                'rule' => ['event' => 'course.progress_milestone', 'if' => [
                    ['field' => 'percent', 'op' => 'eq', 'value' => 50],
                ]],
            ],
            [
                'key' => 'topic_mastered',
                'name' => 'Zvládnutí tématu',
                'description' => 'Ocenění za zvládnuté téma.',
                'category' => 'pokrok',
                'target_role' => 'student',
                'is_mandatory' => false,
                'manual_allowed' => false,
                'channels' => ['feed'],
                'frequency_limit' => null,
                'frequency_period_hours' => null,
                'dedup_window_hours' => 8760,
                'push_title' => 'Téma zvládnuto',
                'push_body' => 'Téma {{topic_name}} už máš v malíčku. Skvělá práce!',
                'feed_title' => 'Téma zvládnuto',
                'feed_body' => 'Téma {{topic_name}} už máš v malíčku. Skvělá práce!',
                'variables' => [
                    self::variable('topic_name', 'Název tématu', 'Sčítání zlomků', 'nové téma', true),
                ],
                'deep_link' => '/dash',
                'target_action' => null,
                'rule' => ['event' => 'topic.mastered', 'if' => []],
            ],
            [
                'key' => 'activation_daily_quiz',
                'name' => 'Výzva k dennímu kvízu',
                'description' => 'Denní výzva ke krátkému kvízu pro žáka, který se dnes ještě neučil.',
                'category' => 'aktivace',
                'target_role' => 'student',
                'is_mandatory' => false,
                'manual_allowed' => false,
                'channels' => ['push', 'feed'],
                'frequency_limit' => 1,
                'frequency_period_hours' => 24,
                'dedup_window_hours' => 20,
                'push_title' => 'Dej si kvíz',
                'push_body' => 'Krátký kvíz ti pomůže ověřit, co už umíš. Zvládneš ho za pár minut.',
                'feed_title' => 'Dej si kvíz',
                'feed_body' => 'Krátký kvíz ti pomůže ověřit, co už umíš. Zvládneš ho za pár minut.',
                'variables' => [],
                'deep_link' => '/quiz',
                'target_action' => 'quiz.completed',
                'rule' => self::activationRule('quiz'),
            ],
            [
                'key' => 'activation_daily_practice',
                'name' => 'Výzva k dennímu procvičování',
                'description' => 'Denní výzva k opakování připravených úloh pro žáka, který se dnes ještě neučil.',
                'category' => 'aktivace',
                'target_role' => 'student',
                'is_mandatory' => false,
                'manual_allowed' => false,
                'channels' => ['push', 'feed'],
                'frequency_limit' => 1,
                'frequency_period_hours' => 24,
                'dedup_window_hours' => 20,
                'push_title' => 'Čas na procvičování',
                'push_body' => 'Máš připravené úlohy k opakování ({{due_count}}). Pár minut a máš hotovo.',
                'feed_title' => 'Čas na procvičování',
                'feed_body' => 'Máš připravené úlohy k opakování ({{due_count}}). Pár minut a máš hotovo.',
                'variables' => [
                    self::variable('due_count', 'Úloh k opakování', '5', 'několik', true),
                ],
                'deep_link' => '/practice',
                'target_action' => 'practice.reviewed',
                'rule' => self::activationRule('practice'),
            ],
            [
                'key' => 'activation_game',
                'name' => 'Výzva ke hře',
                'description' => 'Denní pozvánka k mini hře pro žáka, který se dnes ještě neučil.',
                'category' => 'aktivace',
                'target_role' => 'student',
                'is_mandatory' => false,
                'manual_allowed' => false,
                'channels' => ['push', 'feed'],
                'frequency_limit' => 1,
                'frequency_period_hours' => 24,
                'dedup_window_hours' => 20,
                'push_title' => 'Zahraj si',
                'push_body' => 'Hra {{game_name}} na tebe čeká. Dej si chvilku odpočinku.',
                'feed_title' => 'Zahraj si',
                'feed_body' => 'Hra {{game_name}} na tebe čeká. Dej si chvilku odpočinku.',
                'variables' => [
                    self::variable('game_name', 'Název hry', 'Sky Jumper', 'mini hra', true),
                ],
                'deep_link' => '/games',
                'target_action' => 'game.played',
                'rule' => self::activationRule('game'),
            ],
        ];
    }

    /** @return array{key: string, label: string, sample: string, fallback: string, required: bool} */
    private static function variable(string $key, string $label, string $sample, string $fallback, bool $required): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'sample' => $sample,
            'fallback' => $fallback,
            'required' => $required,
        ];
    }

    /**
     * Only in the deep link, so not required. A missing code yields
     * /course/- which the app does not know and safely sends to /dash.
     */
    private static function courseCode(): array
    {
        return self::variable('course_code', 'Kód kurzu', 'ABC123', '-', false);
    }

    /** broadcast.sent fires every broadcast rule; each picks its own type. */
    private static function broadcastRule(string $typeKey): array
    {
        return ['event' => 'broadcast.sent', 'if' => [
            ['field' => 'type_key', 'op' => 'eq', 'value' => $typeKey],
        ]];
    }

    private static function activationRule(string $suggestion): array
    {
        return ['event' => 'activation.daily', 'if' => [
            ['field' => 'suggestion', 'op' => 'eq', 'value' => $suggestion],
        ]];
    }
}
