<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Enums\NotificationChannelType;
use App\Services\Ai\IncidentAnalysisPayload;
use App\Support\Marketing\ChromeData;
use App\Support\Marketing\LegalDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;

/**
 * The FAQ, in whichever language its URL asked for.
 *
 * Questions and answers live in the Markdown under `resources/legal/faq.<locale>.md` and
 * nowhere else. There is no separate FAQ view on purpose: every marketing document renders
 * through the shared content page, so a second surface would be a second source of truth
 * for the same answers and the two would drift.
 *
 * Every number an answer quotes is interpolated here from the enum or config that actually
 * governs it (regions, monitor types, the plan catalog's check-interval floor and free-tier
 * limits, Timescale retention, the alert-channel list, and the AI evidence character cap),
 * the same "a claim is derived, never typed" rule {@see ShowLandingController} follows. Only
 * digits and proper nouns are interpolated; the surrounding sentence is authored per locale
 * in the Markdown itself, so no translatable word ever has to route through a placeholder.
 *
 * `$sections` stays at ChromeData's empty default, as on every document page.
 */
class ShowFaqController
{
    /**
     * The route path, the Markdown filename and the path `ChromeData` builds this page's
     * canonical and hreflang set from, held as one constant so they cannot drift apart.
     */
    private const PAGE = 'faq';

    public function __construct(
        protected LegalDocument $document,
    ) {}

    public function __invoke(): View
    {
        return view('marketing.content-page', [
            ...(new ChromeData(
                path: self::PAGE,
                summary: $this->summary(),
            ))->toArray(),
            'title' => __('Frequently Asked Questions'),
            // The locale `SetMarketingLocale` set from the path, not the route parameter:
            // the apex form carries no `{locale}` parameter to read.
            'document' => $this->document->render(self::PAGE, app()->getLocale(), $this->replacements()),
        ]);
    }

    /**
     * Every derived value an answer quotes, mapped from its bracketed placeholder.
     *
     * @return array<string, string>
     */
    protected function replacements(): array
    {
        $tiers = (array) config('plans.tiers', []);

        return [
            '[[faq.region_count]]' => (string) count(MonitorRegion::cases()),
            '[[faq.region_names]]' => Arr::join(
                array_map(fn (MonitorRegion $region): string => $region->label(), MonitorRegion::cases()),
                ', ',
            ),
            '[[faq.monitor_types]]' => Arr::join(
                array_map(fn (MonitorType $type): string => strtoupper($type->value), MonitorType::cases()),
                ', ',
            ),
            '[[faq.free_interval_seconds]]' => (string) $this->tierLimit($tiers, 'free', 'check_interval_sec'),
            '[[faq.pro_interval_seconds]]' => (string) $this->tierLimit($tiers, 'pro', 'check_interval_sec'),
            '[[faq.business_interval_seconds]]' => (string) $this->tierLimit($tiers, 'business', 'check_interval_sec'),
            '[[faq.enterprise_interval_seconds]]' => (string) $this->tierLimit($tiers, 'enterprise', 'check_interval_sec'),
            '[[faq.free_monitors]]' => (string) $this->tierLimit($tiers, 'free', 'monitors'),
            '[[faq.free_status_pages]]' => (string) $this->tierLimit($tiers, 'free', 'status_pages'),
            '[[faq.free_subscribers]]' => (string) $this->tierLimit($tiers, 'free', 'subscribers'),
            '[[faq.free_responders]]' => (string) $this->tierLimit($tiers, 'free', 'responders'),
            '[[faq.free_ai_trials]]' => (string) $this->tierLimit($tiers, 'free', 'ai_analysis_trials'),
            '[[faq.retention_days]]' => (string) config('timescale.retention.raw_days'),
            '[[faq.alert_channels]]' => Arr::join($this->alertChannels(), ', '),
            '[[faq.ai_char_limit]]' => (string) IncidentAnalysisPayload::UNTRUSTED_FIELD_MAX_LENGTH,
            '[[faq.rights_email]]' => (string) config('legal.rights_email'),
        ];
    }

    /**
     * One tier's limit value from the plan catalog, by tier id and limit key.
     *
     * @param  array<int, array<string, mixed>>  $tiers
     */
    protected function tierLimit(array $tiers, string $tierId, string $key): int|string|null
    {
        $tier = Arr::first($tiers, fn (array $candidate): bool => ($candidate['id'] ?? null) === $tierId);

        return Arr::get($tier ?? [], "limits.{$key}");
    }

    /**
     * The team-scoped alert destinations that exist, matching
     * {@see ShowLandingController::channels()}: an exhaustive match with no default arm, so
     * adding a channel type is a failure here rather than an FAQ answer that quietly omits
     * it.
     *
     * @return list<string>
     */
    protected function alertChannels(): array
    {
        return array_map(
            fn (NotificationChannelType $type): string => match ($type) {
                NotificationChannelType::Slack => 'Slack',
                NotificationChannelType::Webhook => 'Webhook',
                NotificationChannelType::PagerDuty => 'PagerDuty',
                NotificationChannelType::Teams => 'Microsoft Teams',
            },
            NotificationChannelType::cases(),
        );
    }

    /**
     * This page's own meta description, never the landing page's: a crawler and a link
     * preview both show it, and two pages sharing one sentence claim to be one document.
     */
    protected function summary(): string
    {
        return __('Straight answers about what Uptizm checks, what it costs, and what it cannot do.');
    }
}
