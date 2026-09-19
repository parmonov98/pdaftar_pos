<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The health endpoint answers, and answers about the POS.
 *
 * This test used to assert the opposite of what it asserts now: that the
 * `App\` autoload reached a pdaftar.backend checkout and that three of
 * pDaftar's observers were attached. Both were load-bearing while a POS sale
 * was written by pDaftar's code. The POS owns its data now, so the couplings
 * those checks defended no longer exist to be defended.
 */
class PosHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_answers_in_json(): void
    {
        $response = $this->getJson('/api/pos/v1/health');

        $response->assertJsonStructure([
            'ok',
            'service',
            'checks' => [['name', 'ok', 'detail']],
            'server_time',
        ]);
        $response->assertJsonPath('service', 'pdaftar-pos-backend');
    }

    /**
     * The schema check is the one that earns its place: a missing table does
     * not announce itself, it shows up as "the till keeps logging me out".
     */
    public function test_schema_check_passes_on_a_migrated_database(): void
    {
        $checks = collect($this->getJson('/api/pos/v1/health')->json('checks'))
            ->keyBy('name');

        $this->assertArrayHasKey('schema', $checks->all(), "'schema' tekshiruvi yo'q");
        $this->assertTrue(
            $checks['schema']['ok'],
            'schema: '.$checks['schema']['detail'],
        );
    }

    /** Nothing here should ask about pDaftar any more. */
    public function test_no_check_depends_on_pdaftar(): void
    {
        $names = collect($this->getJson('/api/pos/v1/health')->json('checks'))
            ->pluck('name')
            ->all();

        foreach (['shared_domain', 'sale_contract', 'observers'] as $gone) {
            $this->assertNotContains($gone, $names);
        }
    }
}
