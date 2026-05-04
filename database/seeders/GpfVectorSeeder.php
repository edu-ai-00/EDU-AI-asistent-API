<?php

namespace Database\Seeders;

use App\Models\SkillVector;
use App\Models\VectorDimension;
use Illuminate\Database\Seeder;

/**
 * Seeds the GPF-35 math vector with all 35 subconstruct dimensions.
 *
 * Source: gpf.json structure — 5 domains, 35 subconstructs (N1.1–A3.4).
 * This is the default vector for Czech primary school math courses.
 */
class GpfVectorSeeder extends Seeder
{
    public function run(): void
    {
        $vector = SkillVector::updateOrCreate(
            ['name' => 'GPF Matematika – ZŠ'],
            [
                'description' => 'Graduovaný pedagogický framework pro matematiku na základní škole. 35 subkonstruktů v 5 doménách.',
                'dimension_count' => 35,
                'version' => 1,
                'is_public' => true,
            ]
        );

        // Delete existing dimensions and re-create (idempotent)
        $vector->dimensions()->delete();

        $dimensions = self::gpfDimensions();

        foreach ($dimensions as $dim) {
            VectorDimension::create([
                'vector_id' => $vector->id,
                'dimension_index' => $dim['index'],
                'code' => $dim['code'],
                'name' => $dim['name'],
                'domain_code' => $dim['domain_code'],
                'domain_name' => $dim['domain_name'],
                'construct_name' => $dim['construct_name'],
            ]);
        }
    }

    /**
     * The 35 GPF math subconstructs organized by domain.
     */
    private static function gpfDimensions(): array
    {
        return [
            // ── N: Číslo a operace (indices 0–16) ──
            ['index' => 0,  'code' => 'N1.1', 'name' => 'Přirozená čísla – určí a počítá', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Přirozená čísla'],
            ['index' => 1,  'code' => 'N1.2', 'name' => 'Přirozená čísla – porovná', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Přirozená čísla'],
            ['index' => 2,  'code' => 'N1.3', 'name' => 'Přirozená čísla – zaokrouhlí', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Přirozená čísla'],
            ['index' => 3,  'code' => 'N2.1', 'name' => 'Celá čísla – určí a uspořádá', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Celá čísla'],
            ['index' => 4,  'code' => 'N3.1', 'name' => 'Zlomky – určí a znázorní', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Zlomky'],
            ['index' => 5,  'code' => 'N3.2', 'name' => 'Zlomky – porovná', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Zlomky'],
            ['index' => 6,  'code' => 'N3.3', 'name' => 'Zlomky – převádí', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Zlomky'],
            ['index' => 7,  'code' => 'N4.1', 'name' => 'Desetinná čísla – určí a zapíše', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Desetinná čísla'],
            ['index' => 8,  'code' => 'N4.2', 'name' => 'Desetinná čísla – porovná a zaokrouhlí', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Desetinná čísla'],
            ['index' => 9,  'code' => 'N5.1', 'name' => 'Sčítání a odčítání – pamětné', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Sčítání a odčítání'],
            ['index' => 10, 'code' => 'N5.2', 'name' => 'Sčítání a odčítání – písemné', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Sčítání a odčítání'],
            ['index' => 11, 'code' => 'N6.1', 'name' => 'Násobení a dělení – pamětné', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Násobení a dělení'],
            ['index' => 12, 'code' => 'N6.2', 'name' => 'Násobení a dělení – písemné', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Násobení a dělení'],
            ['index' => 13, 'code' => 'N7.1', 'name' => 'Operace se zlomky – sčítání a odčítání', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Operace se zlomky'],
            ['index' => 14, 'code' => 'N7.2', 'name' => 'Operace se zlomky – násobení a dělení', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Operace se zlomky'],
            ['index' => 15, 'code' => 'N8.1', 'name' => 'Operace s desetinnými čísly – sčítání a odčítání', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Operace s desetinnými čísly'],
            ['index' => 16, 'code' => 'N8.2', 'name' => 'Operace s desetinnými čísly – násobení a dělení', 'domain_code' => 'N', 'domain_name' => 'Číslo a operace', 'construct_name' => 'Operace s desetinnými čísly'],

            // ── M: Míry (indices 17–21) ──
            ['index' => 17, 'code' => 'M1.1', 'name' => 'Délka – odhadne a změří', 'domain_code' => 'M', 'domain_name' => 'Míry', 'construct_name' => 'Délka'],
            ['index' => 18, 'code' => 'M1.2', 'name' => 'Délka – převádí jednotky', 'domain_code' => 'M', 'domain_name' => 'Míry', 'construct_name' => 'Délka'],
            ['index' => 19, 'code' => 'M2.1', 'name' => 'Hmotnost – odhadne a převádí', 'domain_code' => 'M', 'domain_name' => 'Míry', 'construct_name' => 'Hmotnost'],
            ['index' => 20, 'code' => 'M3.1', 'name' => 'Čas – určí a převádí', 'domain_code' => 'M', 'domain_name' => 'Míry', 'construct_name' => 'Čas'],
            ['index' => 21, 'code' => 'M4.1', 'name' => 'Objem a obsah – vypočítá', 'domain_code' => 'M', 'domain_name' => 'Míry', 'construct_name' => 'Objem a obsah'],

            // ── G: Geometrie (indices 22–24) ──
            ['index' => 22, 'code' => 'G1.1', 'name' => 'Rovinné útvary – rozpozná a popíše', 'domain_code' => 'G', 'domain_name' => 'Geometrie', 'construct_name' => 'Rovinné útvary'],
            ['index' => 23, 'code' => 'G2.1', 'name' => 'Prostorové útvary – rozpozná a popíše', 'domain_code' => 'G', 'domain_name' => 'Geometrie', 'construct_name' => 'Prostorové útvary'],
            ['index' => 24, 'code' => 'G3.1', 'name' => 'Souměrnost a transformace', 'domain_code' => 'G', 'domain_name' => 'Geometrie', 'construct_name' => 'Souměrnost'],

            // ── S: Statistika a pravděpodobnost (indices 25–28) ──
            ['index' => 25, 'code' => 'S1.1', 'name' => 'Sběr a uspořádání dat', 'domain_code' => 'S', 'domain_name' => 'Statistika a pravděpodobnost', 'construct_name' => 'Sběr dat'],
            ['index' => 26, 'code' => 'S1.2', 'name' => 'Čtení grafů a tabulek', 'domain_code' => 'S', 'domain_name' => 'Statistika a pravděpodobnost', 'construct_name' => 'Grafy a tabulky'],
            ['index' => 27, 'code' => 'S2.1', 'name' => 'Průměr a medián', 'domain_code' => 'S', 'domain_name' => 'Statistika a pravděpodobnost', 'construct_name' => 'Střední hodnoty'],
            ['index' => 28, 'code' => 'S3.1', 'name' => 'Pravděpodobnost – základní odhady', 'domain_code' => 'S', 'domain_name' => 'Statistika a pravděpodobnost', 'construct_name' => 'Pravděpodobnost'],

            // ── A: Algebra (indices 29–34) ──
            ['index' => 29, 'code' => 'A1.1', 'name' => 'Číselné výrazy – vyhodnotí', 'domain_code' => 'A', 'domain_name' => 'Algebra', 'construct_name' => 'Číselné výrazy'],
            ['index' => 30, 'code' => 'A1.2', 'name' => 'Číselné vzory a posloupnosti', 'domain_code' => 'A', 'domain_name' => 'Algebra', 'construct_name' => 'Vzory a posloupnosti'],
            ['index' => 31, 'code' => 'A2.1', 'name' => 'Rovnice – řeší jednoduché', 'domain_code' => 'A', 'domain_name' => 'Algebra', 'construct_name' => 'Rovnice'],
            ['index' => 32, 'code' => 'A2.2', 'name' => 'Nerovnice – řeší jednoduché', 'domain_code' => 'A', 'domain_name' => 'Algebra', 'construct_name' => 'Nerovnice'],
            ['index' => 33, 'code' => 'A3.1', 'name' => 'Závislosti a funkce – rozpozná', 'domain_code' => 'A', 'domain_name' => 'Algebra', 'construct_name' => 'Závislosti a funkce'],
            ['index' => 34, 'code' => 'A3.2', 'name' => 'Poměr a úměra', 'domain_code' => 'A', 'domain_name' => 'Algebra', 'construct_name' => 'Poměr a úměra'],
        ];
    }
}
