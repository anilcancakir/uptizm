<?php

namespace App\Services\Monitoring;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\MetricBand;
use App\Enums\MonitorStatus;
use App\Enums\SignalSource;
use App\Enums\ThresholdDirection;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorMetric;

/**
 * Opens {@see Incident} rows tagged {@see SignalSource::UserThreshold} when a
 * metric sample breaches its configured bounds or when a monitor's
 * `consecutive_fails` counter crosses `incident_threshold`.
 *
 * Pure-function banding ({@see self::band()}) keeps threshold math out of
 * Eloquent so callers can freeze the band at insert time without spinning up
 * a full domain graph.
 */
class ThresholdEvaluator
{
    /**
     * Evaluate a completed check against the monitor's thresholds and metric
     * bounds; open an incident if a new breach is detected.
     *
     * @param  array<string, float|int|null>  $metricSamples
     */
    public function evaluate(Monitor $monitor, MonitorCheck $check, array $metricSamples): void
    {
        // 1. Metric bound breaches fire first so incidents carry metric context.
        $metricBreach = $this->firstMetricBreach($monitor, $metricSamples);
        if ($metricBreach !== null && ! $this->hasActiveIncidentForMetric($monitor, $metricBreach['metric']->key)) {
            $this->openIncident(
                monitor: $monitor,
                check: $check,
                severity: $metricBreach['severity'],
                title: "{$metricBreach['metric']->label} breached {$metricBreach['severity']->value} bound",
                metricKey: $metricBreach['metric']->key,
            );

            return;
        }

        // 2. Fall back to consecutive-fail threshold for bare up/down signals.
        if ($this->shouldOpenForConsecutiveFails($monitor)
            && ! $this->hasActiveIncidentForMonitor($monitor)) {
            $this->openIncident(
                monitor: $monitor,
                check: $check,
                severity: IncidentSeverity::Critical,
                title: "{$monitor->name} is down",
                metricKey: null,
            );
        }
    }

    /**
     * Find the first metric whose sample lands in warn or critical.
     *
     * @param  array<string, float|int|null>  $samples
     * @return array{metric: MonitorMetric, severity: IncidentSeverity}|null
     */
    protected function firstMetricBreach(Monitor $monitor, array $samples): ?array
    {
        foreach ($monitor->metrics as $metric) {
            if ($metric->threshold_direction === null) {
                continue;
            }
            $sample = $samples[$metric->key] ?? null;
            if ($sample === null) {
                continue;
            }

            $band = self::band(
                direction: $metric->threshold_direction,
                value: (float) $sample,
                warnBound: $metric->warn_bound !== null ? (float) $metric->warn_bound : null,
                criticalBound: $metric->critical_bound !== null ? (float) $metric->critical_bound : null,
            );
            $severity = match ($band) {
                MetricBand::Critical => IncidentSeverity::Critical,
                MetricBand::Warn => IncidentSeverity::Warn,
                MetricBand::Ok => null,
            };

            if ($severity !== null) {
                return [
                    'metric' => $metric,
                    'severity' => $severity,
                ];
            }
        }

        return null;
    }

    /**
     * Pure-function banding: compute a {@see MetricBand} from a numeric
     * sample and its bounds, respecting direction.
     */
    public static function band(
        ThresholdDirection $direction,
        float $value,
        ?float $warnBound,
        ?float $criticalBound,
    ): MetricBand {
        if ($direction === ThresholdDirection::HighBad) {
            if ($criticalBound !== null && $value >= $criticalBound) {
                return MetricBand::Critical;
            }
            if ($warnBound !== null && $value >= $warnBound) {
                return MetricBand::Warn;
            }

            return MetricBand::Ok;
        }

        if ($criticalBound !== null && $value <= $criticalBound) {
            return MetricBand::Critical;
        }
        if ($warnBound !== null && $value <= $warnBound) {
            return MetricBand::Warn;
        }

        return MetricBand::Ok;
    }

    /**
     * True when the monitor already has an unresolved incident tagged with
     * the given trigger metric, guarding against duplicate opens on repeated
     * breaches of the same metric.
     */
    protected function hasActiveIncidentForMetric(Monitor $monitor, string $metricKey): bool
    {
        return Incident::query()
            ->where('primary_monitor_id', $monitor->id)
            ->where('trigger_metric_key', $metricKey)
            ->get()
            ->contains(fn (Incident $incident): bool => $incident->lifecycle->isActive());
    }

    /**
     * True when the monitor already has any unresolved incident, guarding
     * the consecutive-fail fallback against opening a duplicate.
     */
    protected function hasActiveIncidentForMonitor(Monitor $monitor): bool
    {
        return Incident::query()
            ->where('primary_monitor_id', $monitor->id)
            ->get()
            ->contains(fn (Incident $incident): bool => $incident->lifecycle->isActive());
    }

    /**
     * Falls back to {@see Monitor::DEFAULT_INCIDENT_THRESHOLD} when the
     * monitor has no explicit threshold so a freshly-created monitor still
     * opens incidents on sustained failure.
     */
    protected function shouldOpenForConsecutiveFails(Monitor $monitor): bool
    {
        $threshold = $monitor->incident_threshold ?? Monitor::DEFAULT_INCIDENT_THRESHOLD;

        return ($monitor->consecutive_fails ?? 0) >= $threshold;
    }

    /**
     * Persist the new incident, tagged {@see SignalSource::UserThreshold} and
     * never AI-owned (AI signal detection is gated off in this port).
     */
    protected function openIncident(
        Monitor $monitor,
        MonitorCheck $check,
        IncidentSeverity $severity,
        string $title,
        ?string $metricKey,
    ): Incident {
        // 1. Persist the incident with the denormalized primary-monitor hint.
        $incident = Incident::query()->create([
            'team_id' => $monitor->team_id,
            'primary_monitor_id' => $monitor->id,
            'title' => $title,
            'impact' => $severity->toImpact(),
            'severity' => $severity,
            'signal_source' => SignalSource::UserThreshold,
            'lifecycle' => IncidentStatus::Detected,
            'ai_owned' => false,
            'trigger_metric_key' => $metricKey,
            'started_at' => $check->checked_at,
        ]);

        // 2. Attach the primary monitor to the affected-component pivot so the
        //    incident serializes its affected set (name + component status) to
        //    the client. Without this the pivot is empty and the Flutter view
        //    reads affectedCount=0 with a blank monitor name. The component
        //    status freezes the monitor's current health at open time and
        //    mirrors it as the live status.
        $componentStatus = $monitor->last_status?->value ?? MonitorStatus::Down->value;
        $incident->monitors()->attach($monitor->id, [
            'component_status_at_start' => $componentStatus,
            'component_status_current' => $componentStatus,
        ]);

        return $incident;
    }
}
