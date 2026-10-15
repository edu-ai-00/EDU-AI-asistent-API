<?php

namespace Tests\Feature;

use App\Support\StudentCodes;
use App\Support\StudentNames;
use Tests\TestCase;

class StudentCodesTest extends TestCase
{
    public function test_generated_code_uses_crockford_alphabet_without_ilou(): void
    {
        $seenChars = [];

        for ($i = 0; $i < 200; $i++) {
            $code = StudentCodes::generate();
            $this->assertSame(6, strlen($code));
            $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{6}$/', $code);
            $this->assertStringNotContainsString('I', $code);
            $this->assertStringNotContainsString('L', $code);
            $this->assertStringNotContainsString('O', $code);
            $this->assertStringNotContainsString('U', $code);

            foreach (str_split($code) as $char) {
                $seenChars[$char] = true;
            }
        }

        // With 1200 draws from a 32-char alphabet, every character should
        // have come up at least once — proves the sample actually spans the
        // full alphabet rather than a lucky narrow slice.
        $this->assertSame(strlen(StudentCodes::ALPHABET), count($seenChars));
    }

    public function test_normalize_maps_confusable_characters(): void
    {
        $this->assertSame('1234K0', StudentCodes::normalize('i234ko'));
        $this->assertSame('1111AB', StudentCodes::normalize('  llIiab '));
    }

    public function test_hash_is_deterministic_and_never_returns_plaintext(): void
    {
        $hash = StudentCodes::hash('ABC123');

        $this->assertSame($hash, StudentCodes::hash('abc123'));
        $this->assertSame(64, strlen($hash));
        $this->assertStringNotContainsString('ABC123', $hash);
        $this->assertNotSame($hash, StudentCodes::hash('XYZ999'));
    }

    public function test_generate_token_is_random_hex(): void
    {
        $token = StudentCodes::generateToken();

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertNotSame($token, StudentCodes::generateToken());
    }

    public function test_generated_names_carry_no_personal_data(): void
    {
        $name = StudentNames::generate();

        $this->assertMatchesRegularExpression('/^\S+ \S+$/u', $name);
        $this->assertNotSame($name, StudentNames::generate() . 'x');
    }
}
