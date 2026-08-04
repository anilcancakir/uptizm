<?php

namespace Tests\Unit\Models;

use App\Models\Proxy;
use App\Models\ProxySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Locks the {@see Proxy} shape: `credentials` encrypted at rest (ciphertext
 * in the column, decrypted array through the model) and
 * {@see Proxy::scopeHealthy()}'s selection predicate (enabled, not swept as
 * removed, and either never penalised or past its backoff window).
 */
class ProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_round_trip_through_the_encrypted_cast(): void
    {
        $proxy = $this->makeProxy([
            'credentials' => [
                'username' => 'exit-user',
                'password' => 'p@ss:word/x#1',
            ],
        ]);

        $this->assertSame('exit-user', $proxy->fresh()->credentials['username']);
        $this->assertSame('p@ss:word/x#1', $proxy->fresh()->credentials['password']);
    }

    public function test_credentials_are_ciphertext_in_the_raw_column(): void
    {
        $proxy = $this->makeProxy([
            'credentials' => [
                'username' => 'exit-user',
                'password' => 'p@ss:word/x#1',
            ],
        ]);

        // The raw column must never contain the plaintext password: a leak here
        // means anyone with read access to the database (a backup, a replica, an
        // ops query) can see a live provider credential in the clear.
        $rawColumn = (string) DB::table('proxies')
            ->where('id', $proxy->id)
            ->value('credentials');

        $this->assertStringNotContainsString('p@ss', $rawColumn);
        $this->assertStringNotContainsString('exit-user', $rawColumn);
        // Ciphertext is not valid JSON; a plaintext leak would decode cleanly.
        $this->assertNull(json_decode($rawColumn, true));
    }

    public function test_healthy_scope_sql_expresses_the_region_enabled_and_available_at_predicate(): void
    {
        $sql = Proxy::query()->healthy()->toSql();

        $this->assertStringContainsString('enabled', $sql);
        $this->assertStringContainsString('available_at', $sql);
        // The column is denormalised precisely so a region-scoped caller can add
        // ->region($region) on top of this scope without a join.
        $sql = Proxy::query()->healthy()->region('eu-west')->toSql();
        $this->assertStringContainsString('region', $sql);
    }

    public function test_healthy_scope_excludes_a_proxy_still_inside_its_backoff_window(): void
    {
        $this->makeProxy([
            'available_at' => now()->addMinutes(5),
        ]);

        $this->assertSame(0, Proxy::query()->healthy()->count());
    }

    public function test_healthy_scope_includes_a_proxy_whose_backoff_window_has_elapsed(): void
    {
        $this->makeProxy([
            'available_at' => now()->subMinute(),
        ]);

        $this->assertSame(1, Proxy::query()->healthy()->count());
    }

    public function test_healthy_scope_excludes_a_disabled_proxy(): void
    {
        $this->makeProxy([
            'enabled' => false,
        ]);

        $this->assertSame(0, Proxy::query()->healthy()->count());
    }

    public function test_healthy_scope_excludes_a_proxy_swept_as_removed(): void
    {
        $this->makeProxy([
            'removed_at' => now(),
        ]);

        $this->assertSame(0, Proxy::query()->healthy()->count());
    }

    public function test_belongs_to_its_proxy_source(): void
    {
        $source = $this->makeSource();
        $proxy = $this->makeProxy([
            'proxy_source_id' => $source->id,
        ]);

        $this->assertTrue($proxy->proxySource->is($source));
    }

    /**
     * Creates a persisted proxy source for the `eu-west` region.
     */
    protected function makeSource(): ProxySource
    {
        return ProxySource::query()->create([
            'region' => 'eu-west',
            'kind' => 'url',
            'location' => 'https://example.com/proxies.txt',
        ]);
    }

    /**
     * Creates a persisted proxy with sane defaults, overridable per test.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makeProxy(array $overrides = []): Proxy
    {
        return Proxy::query()->create([
            'proxy_source_id' => $overrides['proxy_source_id'] ?? $this->makeSource()->id,
            'region' => 'eu-west',
            'host' => '203.0.113.10',
            'port' => 8080,
            'credentials' => [
                'username' => 'exit-user',
                'password' => 'secret',
            ],
            'enabled' => true,
            'last_refreshed_at' => now(),
            ...$overrides,
        ]);
    }
}
