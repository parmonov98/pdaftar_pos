<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The two couplings CI can actually answer for.
 *
 * PosIntegrityService checks six things; four of them need a live pDaftar —
 * its database, its Redis namespace, its migrated tables — which a CI runner
 * has no way to provide. The remaining two need nothing but a correct
 * checkout, and they are the ones that fail silently in production:
 *
 *   shared_domain  the `App\` autoload really does reach pdaftar.backend, so
 *                  a sale is written by pDaftar's own use case and not by a
 *                  copy that has drifted from it.
 *   observers      the observers are attached, so a sale's cash reaches Kassa.
 *                  Nothing throws when they are missing; the money just never
 *                  arrives, and the books are wrong weeks later.
 *
 * Asserting them here is what makes a deploy that shipped only this directory
 * a red check instead of a hole in the accounts.
 */
class PosHealthTest extends TestCase {
    public function test_health_endpoint_answers_in_json(): void {
        $response = $this->getJson('/api/pos/v1/health');

        // 503 is a legitimate answer: the checks that need pDaftar's database
        // cannot pass on a CI runner. What matters is that the endpoint
        // reports rather than blows up.
        $this->assertContains($response->status(), [200, 503]);

        $response->assertJsonStructure([
            'ok',
            'service',
            'checks' => [['name', 'ok', 'detail']],
            'server_time',
        ]);
        $response->assertJsonPath('service', 'pdaftar-pos-backend');
    }

    public function test_shared_domain_and_observers_are_wired(): void {
        $checks = collect($this->getJson('/api/pos/v1/health')->json('checks'))
            ->keyBy('name');

        foreach (['shared_domain', 'observers'] as $name) {
            $this->assertArrayHasKey($name, $checks->all(), "'{$name}' tekshiruvi yo'q");
            $this->assertTrue(
                $checks[$name]['ok'],
                "'{$name}': ".$checks[$name]['detail'],
            );
        }
    }
}
