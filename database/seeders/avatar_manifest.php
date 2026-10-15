<?php

/**
 * The shipped avatar cosmetics.
 *
 * One entry per SVG under `database/seeders/avatar_assets/<slot>/<code>.svg`.
 * `AvatarItemSeeder` uploads the file to R2 and upserts the catalog row.
 *
 * `layer` follows the numbering the designer baked into the original filenames
 * (2_ body, 3_ face, 4_ head, 5_ neck/hands), so painting the equipped items in
 * ascending layer order reproduces the intended stacking.
 *
 * `is_default` marks the free starting outfit — those items are owned by every
 * account implicitly and are never sold.
 *
 * @return list<array{code: string, name: string, slot: string, layer: int, rarity: string, is_default: bool, sort_order: int}>
 */

$item = fn (
    string $code,
    string $name,
    string $slot,
    int $layer,
    string $rarity = 'common',
    bool $isDefault = false,
    int $sortOrder = 0,
) => compact('code', 'name', 'slot', 'layer', 'rarity') + [
    'is_default' => $isDefault,
    'sort_order' => $sortOrder,
];

return [
    // ── Body (layer 20-21) ───────────────────────────────────────────────
    $item('torso_telo_fialove', 'Tělo', 'torso', 20, 'common', true, 10),
    $item('legs_nohy_staticke', 'Nohy', 'legs', 21, 'common', true, 10),
    $item('legs_nohy_v_pohybu', 'Nohy v pohybu', 'legs', 21, 'common', false, 20),

    // ── Face (layer 30-32) ───────────────────────────────────────────────
    $item('eyes_vesele', 'Veselé oči', 'eyes', 30, 'common', true, 10),
    $item('mouth_vesela', 'Veselá pusa', 'mouth', 31, 'common', true, 10),

    $item('face_bryle', 'Brýle', 'face', 32, 'common', false, 10),
    $item('face_cerne_bryle', 'Černé brýle', 'face', 32, 'common', false, 20),
    $item('face_hnede_bryle', 'Hnědé brýle', 'face', 32, 'common', false, 30),
    $item('face_modre_bryle', 'Modré brýle', 'face', 32, 'common', false, 40),
    $item('face_knir', 'Knír', 'face', 32, 'rare', false, 50),
    $item('face_klaunsky_nos', 'Klaunský nos', 'face', 32, 'rare', false, 60),
    $item('face_paska_pirat', 'Pirátská páska', 'face', 32, 'rare', false, 70),
    $item('face_maska_skraboska', 'Škraboška', 'face', 32, 'epic', false, 80),

    // ── Head (layer 40) ──────────────────────────────────────────────────
    $item('head_ruzova_stuha', 'Růžová stuha', 'head', 40, 'common', false, 10),
    $item('head_hippie_celenka', 'Hippie čelenka', 'head', 40, 'common', false, 20),
    $item('head_zimni_cepice', 'Zimní čepice', 'head', 40, 'common', false, 30),
    $item('head_student', 'Studentský klobouk', 'head', 40, 'common', false, 40),
    $item('head_kvetinovy_venec', 'Květinový věnec', 'head', 40, 'rare', false, 50),
    $item('head_kocici_usi', 'Kočičí uši', 'head', 40, 'rare', false, 60),
    $item('head_kralici_usi', 'Králičí uši', 'head', 40, 'rare', false, 70),
    $item('head_beruska', 'Beruška', 'head', 40, 'rare', false, 80),
    $item('head_sluchatka', 'Sluchátka', 'head', 40, 'rare', false, 90),
    $item('head_kovboj_klobouk', 'Kovbojský klobouk', 'head', 40, 'rare', false, 100),
    $item('head_pirat_satek', 'Pirátský šátek', 'head', 40, 'rare', false, 110),
    $item('head_carodejnicky_klobouk', 'Čarodějnický klobouk', 'head', 40, 'epic', false, 120),
    $item('head_kosmonaut_helma', 'Kosmonautská helma', 'head', 40, 'epic', false, 130),
    $item('head_zlata_korunka', 'Zlatá korunka', 'head', 40, 'epic', false, 140),

    // ── Neck (layer 50) ──────────────────────────────────────────────────
    $item('neck_satek_na_krk', 'Šátek na krk', 'neck', 50, 'common', false, 10),
    $item('neck_cerny_choker', 'Černý choker', 'neck', 50, 'common', false, 20),
    $item('neck_zimni_sala', 'Zimní šála', 'neck', 50, 'common', false, 30),
    $item('neck_motylek_na_krk', 'Motýlek', 'neck', 50, 'rare', false, 40),
    $item('neck_korale_na_krk', 'Korále', 'neck', 50, 'rare', false, 50),
    $item('neck_zlata_medaile', 'Zlatá medaile', 'neck', 50, 'epic', false, 60),

    // ── Hands (layer 51) ─────────────────────────────────────────────────
    $item('hands_u_tela', 'Ruce u těla', 'hands', 51, 'common', true, 10),
    $item('hands_leva_ruka', 'Zvednutá levá ruka', 'hands', 51, 'common', false, 20),
    $item('hands_prava_ruka', 'Zvednutá pravá ruka', 'hands', 51, 'common', false, 30),

    // ── Held items (layer 52) — one at a time ────────────────────────────
    $item('held_kniha', 'Kniha', 'held', 52, 'common', false, 10),
    $item('held_voskovka', 'Voskovka', 'held', 52, 'common', false, 20),
    $item('held_lizatko', 'Lízátko', 'held', 52, 'rare', false, 30),
    $item('held_zmrzlina', 'Zmrzlina', 'held', 52, 'rare', false, 40),
];
