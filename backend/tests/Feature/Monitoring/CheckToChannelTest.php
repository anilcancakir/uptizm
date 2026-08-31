<?php

namespace Tests\Feature\Monitoring;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Enums\NotificationChannelSeverity;
use App\Events\IncidentBroadcast;
use App\Events\MonitorStatusChanged;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use App\Notifications\IncidentOpened;
use App\Services\Monitoring\CheckPersistenceService;
use App\Services\StatusPages\StatusPageCache;
use App\Support\Monitoring\CheckResult;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Drives a failing {@see CheckResult} through {@see CheckPersistenceService::persist()}
 * and proves the whole chain up to {@see IncidentDispatcher}'s channel fan-out:
 * the persist, the consecutive-fail streak, the threshold-evaluator open, and
 * the `alert_on_down` gate at `IncidentDispatcher.php:102`. Every prior test on
 * this fan-out ({@see IncidentChannelDispatchTest}) starts from an
 * already-created incident, so none of them proves this chain holds together.
 */
class CheckToChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failing_check_notifies_an_enabled_team_channel(): void
    {
        Notification::fake();
        $this->fakeSideEffects();
        [$monitor, $team] = $this->makeMonitor(alertOnDown: true);
        $channel = $this->channel($team);

        $this->persistFailingCheck($monitor);

        Notification::assertSentTo($channel, IncidentOpened::class);
    }

    public function test_a_failing_check_notifies_nobody_when_alert_on_down_is_off(): void
    {
        Notification::fake();
        $this->fakeSideEffects();
        [$monitor, $team] = $this->makeMonitor(alertOnDown: false);
        $channel = $this->channel($team);

        $this->persistFailingCheck($monitor);

        Notification::assertNotSentTo($channel, IncidentOpened::class);
    }

    /**
     * Persist one down {@see CheckResult} for a fresh monitor whose
     * `incident_threshold` is 1 and whose `consecutive_fails` starts at 0, so
     * the very first failing check crosses the threshold and opens an
     * incident, the way {@see CheckPersistenceService::downStreak()} computes
     * it for a monitor with no configured regions.
     */
    protected function persistFailingCheck(Monitor $monitor): void
    {
        $result = new CheckResult(
            monitorId: (string) $monitor->id,
            region: 'fra',
            checkedAt: new DateTimeImmutable,
            status: MonitorStatus::Down,
            statusCode: null,
            responseMs: null,
            errorMessage: 'Connection refused',
            timingDnsMs: 0,
            timingConnectMs: 0,
            timingTlsMs: 0,
            timingTtfbMs: 0,
            timingDownloadMs: 0,
            responseHeaders: [],
            responseBodyPreview: null,
            probeRunId: (string) Str::uuid(),
        );

        $this->app->make(CheckPersistenceService::class)->persist($monitor, $result);
    }

    /**
     * Silence the broadcast + status-page-cache side effects so the test only
     * exercises the notification fan-out, mirroring
     * {@see IncidentChannelDispatchTest::fakeSideEffects()}.
     */
    protected function fakeSideEffects(): void
    {
        Event::fake([IncidentBroadcast::class, MonitorStatusChanged::class]);

        $spy = Mockery::spy(StatusPageCache::class);
        $this->app->instance(StatusPageCache::class, $spy);
    }

    /**
     * Persist a team-scoped, severity-`all`, enabled notification channel.
     */
    protected function channel(Team $team): NotificationChannel
    {
        return NotificationChannel::factory()->slack()->create([
            'team_id' => $team->id,
            'severity' => NotificationChannelSeverity::All,
            'is_enabled' => true,
        ]);
    }

    /**
     * Create a monitor owned by a team with a single notifiable member, primed
     * to open an incident on its first failing check.
     *
     * @return array{0: Monitor, 1: Team}
     */
    protected function makeMonitor(bool $alertOnDown): array
    {
        $user = User::query()->create([
            'name' => 'Check To Channel Tester',
            'email' => Str::uuid().'@example.com',
            'password' => 'irrelevant',
        ]);

        $team = Team::query()->create([
            'user_id' => $user->id,
            'name' => 'Check To Channel Team',
        ]);
        $team->users()->attach($user->id, ['role' => 'admin']);

        $monitor = Monitor::query()->create([
            'team_id' => $team->id,
            'name' => 'API Uptime',
            'type' => MonitorType::Http,
            'url' => 'https://example.com/health',
            'check_interval_sec' => 60,
            'incident_threshold' => 1,
            'consecutive_fails' => 0,
            'alert_on_down' => $alertOnDown,
            'alert_on_recover' => true,
        ]);

        return [$monitor, $team];
    }
}
