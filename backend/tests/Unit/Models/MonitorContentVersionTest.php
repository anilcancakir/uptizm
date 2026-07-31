<?php

namespace Tests\Unit\Models;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorContentVersion;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the {@see MonitorContentVersion} shape the content-archive dedupe
 * pipeline relies on: the monitor/team relations resolve, the numeric,
 * boolean and datetime casts return typed values, and `updated_at` stays
 * enabled, the deliberate inverse of {@see MonitorCheck}'s
 * immutable-log behaviour, because a version row is revised in place every
 * time its content is seen again.
 */
class MonitorContentVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_persists_a_row_with_its_monitor_and_team(): void
    {
        $version = MonitorContentVersion::factory()->create();

        $this->assertDatabaseHas('monitor_content_versions', [
            'id' => $version->id,
        ]);
        $this->assertInstanceOf(Monitor::class, $version->monitor);
        $this->assertSame($version->monitor_id, $version->monitor->id);
        $this->assertInstanceOf(Team::class, $version->team);
        $this->assertSame($version->team_id, $version->team->id);
    }

    public function test_casts_return_typed_values(): void
    {
        $version = MonitorContentVersion::factory()->create();

        $this->assertIsInt($version->byte_size);
        $this->assertIsInt($version->normalizer_version);
        $this->assertIsBool($version->truncated);
        $this->assertInstanceOf(CarbonImmutable::class, $version->last_seen_at);
        $this->assertInstanceOf(CarbonImmutable::class, $version->first_seen_at);
    }

    public function test_updated_at_is_maintained_on_save(): void
    {
        $version = MonitorContentVersion::factory()->create();
        $this->assertNotNull($version->updated_at);
        $originalUpdatedAt = $version->updated_at;

        // Move the clock forward so a maintained `updated_at` is guaranteed to
        // differ, rather than risking two writes landing in the same second.
        $this->travel(1)->hour();
        $version->update(['last_seen_at' => now()]);

        $this->assertNotEquals(
            $originalUpdatedAt->toIso8601String(),
            $version->updated_at->toIso8601String(),
        );
    }
}
