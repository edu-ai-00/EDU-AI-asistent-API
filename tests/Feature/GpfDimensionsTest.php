<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\GpfVectorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for BR-N2E9MF: the app must read Czech GPF vector labels from the
 * backend instead of hardcoding English ones. GET /api/gpf/dimensions exposes
 * the canonical 35-dimension label set to any logged-in or guest user.
 */
class GpfDimensionsTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): self
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }

    public function test_returns_all_35_czech_dimensions_in_index_order(): void
    {
        $this->seed(GpfVectorSeeder::class);

        $response = $this->actingAsUser()->getJson('/api/gpf/dimensions');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(35, $data);

        // Ordered by dimension_index 0..34.
        $indices = array_column($data, 'dimension_index');
        $this->assertSame(range(0, 34), $indices);

        // Labels are Czech, sourced from the seeder.
        $this->assertSame('Číslo a operace', $data[0]['domain_name']);
        $this->assertSame('Algebra', $data[34]['domain_name']);

        // Shape.
        $this->assertEqualsCanonicalizing(
            ['dimension_index', 'code', 'domain_code', 'domain_name', 'construct_name', 'name'],
            array_keys($data[0]),
        );
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/gpf/dimensions')->assertUnauthorized();
    }
}
