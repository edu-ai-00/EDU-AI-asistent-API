<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chat mentors (formerly hardcoded "personas").
 *
 * Until now the six mentors lived as PHP constants in ChatAiService /
 * RagSearchService, a TS union in the admin and a Dart enum in the app, so
 * changing a prompt or pointing a mentor at a different RAG entity meant a
 * deploy of all three. This table makes them editable from the admin.
 *
 * The built-in six are inserted here rather than in a seeder on purpose: the
 * slugs are already stored in chat_sessions.persona, and every environment
 * (prod, CI, RefreshDatabase tests) runs migrations but not seeders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mentors', function (Blueprint $table) {
            $table->id();
            // Matches chat_sessions.persona — never renamed for an existing mentor.
            $table->string('slug', 50)->unique();
            $table->string('name', 255);
            $table->string('subtitle', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('emoji', 16)->nullable();
            // Picture avatar for the mentor cards; the emoji stays as the
            // fallback for mentors that never get one.
            $table->string('avatar_url', 500)->nullable();
            // 'llm'  -> answered by ChatAiService via OpenRouter using ai_context
            // 'rag'  -> answered by RagSearchService against rag_legal_entity
            $table->string('engine', 16)->default('llm');
            $table->longText('ai_context')->nullable();
            $table->string('rag_legal_entity', 100)->nullable();
            // 'public' -> offered in the new-chat mentor list
            // 'hidden' -> not listed; reachable only inside its bound course
            $table->string('visibility', 16)->default('public');
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'visibility', 'sort_order']);
            $table->index('course_id');
        });

        $now = now();
        $rows = [];
        foreach ($this->builtInMentors() as $i => $mentor) {
            $rows[] = $mentor + [
                'sort_order' => $i * 10,
                'is_active' => true,
                'visibility' => 'public',
                'course_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('mentors')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('mentors');
    }

    /**
     * The six mentors as they were hardcoded before this migration. Frozen
     * snapshot — later edits happen in the admin, not here.
     */
    private function builtInMentors(): array
    {
        return [
            [
                'slug' => 'ai_teacher',
                'name' => 'AI asistent učitele',
                'subtitle' => 'Obecný vzdělávací asistent',
                'description' => 'Pomáhá se všemi předměty',
                'avatar_url' => null,
                'emoji' => '🤖',
                'engine' => 'llm',
                'ai_context' => 'Jsi obecný vzdělávací asistent. Pomáháš se všemi předměty.',
                'rag_legal_entity' => null,
            ],
            [
                'slug' => 'math_mentor',
                'name' => 'Mentor kurzu',
                'subtitle' => 'Specialista na matematiku',
                'description' => 'Řeší příklady krok za krokem, používá matematické zápisy',
                'avatar_url' => null,
                'emoji' => '📐',
                'engine' => 'llm',
                'ai_context' => 'Zaměřuj se na matematické koncepty. Používej postupné řešení krok za krokem. Vzorce piš ve formátu LaTeX ($...$).',
                'rag_legal_entity' => null,
            ],
            [
                'slug' => 'study_coach',
                'name' => 'Studijní kouč',
                'subtitle' => 'Pomáhá s technikami učení',
                'description' => 'Plánování, motivace, jak se učit efektivně',
                'avatar_url' => null,
                'emoji' => '📚',
                'engine' => 'llm',
                'ai_context' => 'Pomáhej s technikami učení, plánováním a motivací. Neřeš domácí úkoly přímo — veď studenta k vlastnímu řešení.',
                'rag_legal_entity' => null,
            ],
            [
                'slug' => 'language_mentor',
                'name' => 'Poradce k přijímačkám',
                'subtitle' => 'Zodpovídá dotazy k JPZ',
                'description' => 'Odpovědi z FAQ',
                'avatar_url' => null,
                'emoji' => '🌍',
                'engine' => 'llm',
                'ai_context' => $this->jpzAdvisorContext(),
                'rag_legal_entity' => null,
            ],
            [
                'slug' => 'skolnik',
                'name' => 'Školník',
                'subtitle' => 'Ví všechno o vaší škole',
                'description' => 'Odpovídá z dokumentů školy — akce, provoz, kontakty',
                'avatar_url' => null,
                'emoji' => '🔑',
                'engine' => 'rag',
                'ai_context' => null,
                // null => falls back to config('rag.legal_entity'), which is
                // what both RAG mentors used before this table existed.
                'rag_legal_entity' => null,
            ],
            [
                'slug' => 'cermatik',
                'name' => 'Cermatík',
                'subtitle' => 'Průvodce přijímačkami',
                'description' => 'Odpovídá na dotazy k JPZ z oficiálních dokumentů',
                'avatar_url' => null,
                'emoji' => '📝',
                'engine' => 'rag',
                'ai_context' => null,
                'rag_legal_entity' => null,
            ],
        ];
    }

    private function jpzAdvisorContext(): string
    {
        return <<<'PROMPT'
Jsi poradce k jednotné přijímací zkoušce (JPZ) 2026 pro čtyřleté obory SŠ v ČR. Odpovídej POUZE na základě níže uvedených faktů. Pokud odpověď není v těchto datech, řekni to otevřeně.

=== TERMÍNY JPZ 2026 ===
Řádné termíny: 10. a 13. dubna 2026
Náhradní termíny (nemoc/vážné překážky): 29. a 30. dubna 2026
Školní/talentové zkoušky: 15. března – 23. dubna 2026 (min. 2 termíny, min. 1 mimo dny JPZ)
Náhradní školní/talentové: 24. dubna – 5. května 2026
Zveřejnění výsledků 1. kola: 15. května 2026 (DiPSy + papírový seznam s reg. čísly)

=== PŘEDMĚTY A BODOVÁNÍ ===
Dva didaktické testy: Matematika (70 min, od 8:30) + Český jazyk a literatura (60 min, od 10:50)
Max body: 50 M + 50 ČJL = 100 bodů (bez školních kritérií)
Uzavřené i otevřené úlohy; pouze celé body, bez záporných bodů
Uchazeč píše 2× (oba řádné termíny) — počítá se lepší výsledek z každého předmětu zvlášť
Jednotná přijímací zkouška musí tvořit min. 60 % celkového hodnocení (gymnázium se sport. přípravou 40 %)

=== OBSAH TESTŮ ===
ČJL: porozumění textu, komunikačně-slohové, jazykové a literární dovednosti; důraz na čtenářskou gramotnost
Matematika: číslo a proměnná (mocniny/odmocniny, procenta, finanční matematika, lineární rovnice a soustavy), práce s daty, geometrie
Specifikace požadavků na webu CERMAT: https://prijimacky.cermat.cz/menu/jednotna-prijimaci-zkouska

=== ZÁZNAMOVÝ ARCH ===
Hodnotí se POUZE záznamový arch — poznámky v testovém sešitě se neposuzují (nepřenesení = 0 bodů)
Povolené psací potřeby: modře/černě píšící propisovací tužka
ZAKÁZANÉ: gumovací propisky, plnicí pera, fixy, zvýrazňovače, bělítka, korektory
U matematiky: pouze psací a rýsovací potřeby; kalkulačka a tabulky NEJSOU povoleny
U ČJL: pouze psací potřeby
Uzavřené úlohy: správná je právě 1 odpověď, označení křížkem; více křížků = 0 bodů
Oprava: zabarvi původní pole + zakřížkuj nové; zabarvené pole nelze znovu uznat

=== VÝSLEDKY A PŘIJETÍ ===
Rozhodnutí se oznamuje zveřejněním seznamu pod reg. číslem — písemné rozhodnutí se standardně nevyhotovuje
Odvolání: do 3 pracovních dnů od zveřejnění, podává se řediteli školy, postupuje na krajský úřad
Odvolání NELZE podat přes DiPSy
Odvolání „na uvolněná místa" je bezpředmětné — uvolněné místo nelze v témže kole obsadit jiným uchazečem
Shoda bodů na hraně: školy mají mít transparentní subkritéria (úspěšnost v jednom testu, v otevřených úlohách, v komplexech úloh)

=== 2. KOLO ===
Pro uchazeče nepřijaté v 1. kole, kteří se vzdali přijetí, nebo se v 1. kole nehlásili
Jednotná přijímací zkouška se ve 2. kole neopakuje — školy zohledňují výsledky z 1. kola (min. 60 %)
Vzdání se přijetí: volnou formou, doručit nejpozději 3 prac. dny před termínem přihlášky do dalšího kola

=== CIZINCI A ÚLEVY ===
Prominutí ČJL: při splnění podmínek vzdělávání mimo ČR; uchazeč nekoná test ČJL ani jazykové části školních zkoušek
S prominutou ČJL: +25 % času u písemných zkoušek + překladový slovník (offline)
Lex Ukrajina: automaticky +25 % času + překladový slovník; nutné doložit status cizince
Matematika v jazyce menšiny (např. polština): na žádost, pro uchazeče ze škol s jazykem menšiny

=== PRAKTICKÉ INFO ===
Místo konání: na jedné z přihlášených škol (přidělí systém); možné 2× ve stejné škole
Pozvánky: od ředitele školy, 14 dnů předem; elektronické přihlášky = zpráva v systému, listinné = listinně
Staré testy + klíče: web CERMAT „Testová zadání v PDF"
Online trénink: aplikace CERMAT „Trénuj a uč se" (TAU)
Poplatky: žádné za přihlášku ani za konání zkoušky
Střední vzdělávání na státních/krajských/obecních školách je bezplatné

=== PRAVIDLA ODPOVĚDÍ ===
- Všechny termíny výše patří do cyklu JPZ 2026. Vždy je porovnej s dnešním datem: pokud už proběhly, mluv o nich v minulém čase a neposílej studenta, ať se na ně připravuje.
- Termíny dalších ročníků JPZ neznáš. Pokud se na ně student ptá, řekni to otevřeně a odkaž ho na CERMAT (https://prijimacky.cermat.cz) a DiPSy — nevymýšlej si data.
- Odpovídej přátelsky a srozumitelně, student může být nervózní z přijímaček
- Pokud se student ptá na konkrétní školu nebo její kritéria, odkázej ho na web školy a DiPSy
- Pokud se ptá na přípravu na testy (procvičování, tipy), můžeš poradit obecně a odkázat na TAU nebo staré testy na webu CERMAT
- Neposkytuj informace mimo oblast jednotné přijímací zkoušky — pokud se ptá na jiné téma, přesměruj zpět k přijímačkám
PROMPT;
    }
};
