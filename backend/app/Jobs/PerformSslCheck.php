<?php

namespace App\Jobs;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\SignalSource;
use App\Models\Incident;
use App\Models\Monitor;
use App\Services\Monitoring\ThresholdEvaluator;
use App\Support\Monitoring\HostGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\SslCertificate\Exceptions\CouldNotDownloadCertificate;
use Spatie\SslCertificate\SslCertificate;

/**
 * Inspects one monitor's TLS certificate and opens an SSL-expiry incident when
 * the certificate is inside the monitor's alert window.
 *
 * SSL checks run Laravel-side (not on the Cloudflare relay, which cannot read
 * TLS certs). Each monitor gets its own job so a single unreachable or slow
 * host cannot stall the rest of the fleet.
 *
 * The certificate host is re-validated through {@see HostGuard} immediately
 * before the socket is opened: a target that passed validation at create time
 * may since have been re-pointed at an internal address (DNS rebinding), so the
 * denylist is enforced at connect time, not just at write time.
 */
class PerformSslCheck implements ShouldQueue
{
    use FoundationQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Default TLS port assumed when the monitor URL omits an explicit port.
     */
    protected const int DEFAULT_HTTPS_PORT = 443;

    /**
     * Socket connect timeout for the certificate download, in seconds.
     */
    protected const int CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * Marker written to `trigger_metric_key` so SSL-expiry incidents are
     * distinguishable from down / metric incidents and the idempotency guard
     * can scope itself to this monitor's SSL incidents only.
     */
    protected const string SSL_TRIGGER_KEY = 'ssl';

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * @var int
     */
    public $backoff = 30;

    /**
     * @param  string  $monitorId  Primary key of the monitor to inspect.
     */
    public function __construct(
        public string $monitorId,
    ) {
        $this->onQueue('ssl');
    }

    /**
     * Resolve the monitor, re-validate its host, and evaluate its certificate.
     *
     * @param  HostGuard  $hostGuard  SSRF denylist, re-checked before connecting.
     */
    public function handle(HostGuard $hostGuard): void
    {
        // 1. Resolve the monitor; deletion between schedule and run is a no-op.
        $monitor = Monitor::query()->find($this->monitorId);
        if ($monitor === null) {
            return;
        }

        // 2. Parse the target host + port from the monitor URL.
        $host = parse_url($monitor->url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            $this->recordError($monitor, 'The monitor url has no host to inspect.');

            return;
        }
        $port = parse_url($monitor->url, PHP_URL_PORT) ?: self::DEFAULT_HTTPS_PORT;

        // 3. Re-validate immediately before connecting (DNS-rebind guard): never
        //    open a socket to a host that now resolves to an internal address.
        if ($hostGuard->isBlockedHost($host)) {
            $this->recordError($monitor, "The host {$host} is not allowed for SSL inspection.");

            return;
        }

        // 4. Download + evaluate the certificate. A download failure is a
        //    distinct class from an expiry breach: it records the error and
        //    finishes, it never opens an incident or aborts the run.
        try {
            $certificate = $this->fetchCertificate("{$host}:{$port}");
        } catch (CouldNotDownloadCertificate $exception) {
            Log::warning('SSL certificate download failed.', [
                'monitor_id' => $monitor->id,
                'host' => $host,
                'error' => $exception->getMessage(),
            ]);
            $this->recordError($monitor, $exception->getMessage());

            return;
        }

        $this->recordCertificate($monitor, $certificate);
    }

    /**
     * Download the certificate for the given `host:port`.
     *
     * Isolated as a seam so tests can inject a fabricated certificate (or throw
     * a download failure) without a live TLS handshake. `verify=false` so an
     * expired or self-signed certificate still reads back its expiry date,
     * which is exactly the state SSL monitoring exists to surface.
     *
     * @throws CouldNotDownloadCertificate When the host is unreachable, has no
     *                                     certificate, or the download times out.
     */
    protected function fetchCertificate(string $hostPort): SslCertificate
    {
        $certificate = SslCertificate::createForHostName(
            $hostPort,
            self::CONNECT_TIMEOUT_SECONDS,
            false,
        );

        // A connection that yields no certificate is the same failure class as
        // a refused connection: route it through the CouldNotDownload path.
        if (! $certificate instanceof SslCertificate) {
            throw CouldNotDownloadCertificate::noCertificateInstalled($hostPort);
        }

        return $certificate;
    }

    /**
     * Persist the freshly-read expiry and open an incident when the certificate
     * is inside the monitor's alert window.
     */
    protected function recordCertificate(Monitor $monitor, SslCertificate $certificate): void
    {
        // 1. Store the expiry + checked timestamp and clear any stale error;
        //    a successful read means the prior failure no longer holds.
        $monitor->forceFill([
            'ssl_expires_at' => $certificate->expirationDate(),
            'ssl_last_checked_at' => now(),
            'ssl_last_error' => null,
        ])->save();

        // 2. Outside the alert window: nothing to open.
        $daysRemaining = $certificate->daysUntilExpirationDate();
        if ($daysRemaining > $monitor->ssl_alert_threshold_days) {
            return;
        }

        // 3. Idempotency: skip when an active SSL incident already exists so a
        //    daily re-run does not stack duplicates on the same monitor.
        if ($this->hasActiveSslIncident($monitor)) {
            return;
        }

        $this->openSslIncident($monitor, $daysRemaining);
    }

    /**
     * True when the monitor already has an unresolved SSL-expiry incident.
     *
     * Mirrors the active-incident guard in
     * {@see ThresholdEvaluator}, scoped to the SSL
     * trigger marker so a down incident does not suppress an SSL one.
     */
    protected function hasActiveSslIncident(Monitor $monitor): bool
    {
        return Incident::query()
            ->where('primary_monitor_id', $monitor->id)
            ->where('trigger_metric_key', self::SSL_TRIGGER_KEY)
            ->get()
            ->contains(fn (Incident $incident): bool => $incident->lifecycle->isActive());
    }

    /**
     * Open an SSL-expiry incident via the public model path.
     *
     * Uses `Incident::create` + `monitors()->attach` rather than
     * {@see ThresholdEvaluator::openIncident()}, which
     * is protected and requires a `MonitorCheck` an SSL check never produces.
     * The create shape and pivot attach mirror that method.
     */
    protected function openSslIncident(Monitor $monitor, int $daysRemaining): void
    {
        // 1. Persist the incident with the denormalized primary-monitor hint and
        //    the SSL trigger marker. A near-expiry cert is a warning, not a live
        //    outage, so it opens at warn severity.
        $incident = Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => "{$monitor->name} SSL cert expires in {$daysRemaining} days",
            'impact' => IncidentSeverity::Warn->toImpact(),
            'severity' => IncidentSeverity::Warn,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'trigger_metric_key' => self::SSL_TRIGGER_KEY,
            'started_at' => now(),
        ]);

        // 2. Attach the monitor to the affected-component pivot so the incident
        //    serializes its affected set (name + component status), matching
        //    ThresholdEvaluator::openIncident. The status freezes the monitor's
        //    current health at open time and mirrors it as the live status.
        $componentStatus = $monitor->last_status?->value ?? MonitorStatus::Down->value;
        $incident->monitors()->attach($monitor->id, [
            'component_status_at_start' => $componentStatus,
            'component_status_current' => $componentStatus,
        ]);
    }

    /**
     * Record a check failure (blocked host or download error) on the monitor
     * without opening an incident. The message is truncated to the column width.
     */
    protected function recordError(Monitor $monitor, string $message): void
    {
        $monitor->forceFill([
            'ssl_last_error' => Str::limit($message, 250, ''),
            'ssl_last_checked_at' => now(),
        ])->save();
    }
}
