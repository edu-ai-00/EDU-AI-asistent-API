<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The end-of-quiz AI comment prompt ("Quiz Feedback v1"), stored as a hidden
 * mentors row.
 *
 * The brief asks for this prompt to be updatable without a deploy. The
 * mentors table is the admin's one existing prompt surface (see
 * create_mentors_table — built exactly to stop prompts living in code), so
 * the comment prompt lives there under the reserved slug 'quiz-feedback'.
 * 'hidden' keeps it out of the app's chat-mentor list; the admin's Mentors
 * screen shows it and its ai_context textarea IS the prompt.
 *
 * QuizFeedbackService::DEFAULT_PROMPT carries the same text as a fallback for
 * a deleted/blank row — if the wording here ever changes, change it there too.
 */
return new class extends Migration
{
    private const PROMPT = 'Vytvoř stručnou zpětnou vazbu pro žáka 15 let (cca 3-5 vět), '
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

    public function up(): void
    {
        if (DB::table('mentors')->where('slug', 'quiz-feedback')->exists()) {
            return;
        }

        $now = now();
        DB::table('mentors')->insert([
            'slug' => 'quiz-feedback',
            'name' => 'Zpětná vazba po kvízu',
            'subtitle' => 'Prompt AI komentáře v rekapitulaci po bloku úloh',
            'description' => 'Toto není chatový mentor: pole „AI kontext" je prompt, '
                .'kterým se generuje komentář ve vyskakovacím okně po dokončení kvízu. '
                .'Úpravy se projeví okamžitě, bez nasazení.',
            'emoji' => '📝',
            'engine' => 'llm',
            'ai_context' => self::PROMPT,
            'visibility' => 'hidden',
            'course_id' => null,
            'is_active' => true,
            'sort_order' => 900,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('mentors')->where('slug', 'quiz-feedback')->delete();
    }
};
