<?php

namespace App\Enums;

/**
 * A feature a plan gate can refuse, as a stable identifier with a localized name.
 *
 * It exists because the refusal SENTENCE reaches a human. The gate used to take
 * the feature as an English label from each call site and splice it into a
 * hardcoded English sprintf, so a Turkish operator read one English sentence in
 * an otherwise fully translated page, on the upgrade prompt of all places. The
 * enum makes the set closed (no call site can invent a label that has no
 * translation) and moves the words into `lang/{locale}/plans.php`, which the
 * `SetApiLocale` middleware already resolves per caller.
 */
enum GatedFeature: string
{
    case AiIncidentAnalysis = 'ai_incident_analysis';
    case AiIncidentDrafting = 'ai_incident_drafting';
    case AiMetricDiscovery = 'ai_metric_discovery';
    case AiMonitorAnalysis = 'ai_monitor_analysis';
    case AiAssistant = 'ai_assistant';
    case AiWeeklyDigest = 'ai_weekly_digest';

    /**
     * The feature's name in the CALLER's language, for the refusal sentence.
     *
     * Resolved on read rather than stored, because a queued job and an HTTP
     * request resolve their locale at different moments and only the request
     * knows the recipient.
     */
    public function label(): string
    {
        return (string) __('plans.features.'.$this->value);
    }
}
