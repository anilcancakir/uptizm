<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\MonitorRegion;
use App\Enums\NotificationChannelType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;

/**
 * The marketing landing page on the apex host.
 *
 * Every list this hands the view is derived from the enum or the config that
 * actually GOVERNS the behaviour, never written out in the template: probe
 * regions come from {@see MonitorRegion}, alert destinations from
 * {@see NotificationChannelType}, and the free-tier numbers from the same
 * `config/plans.php` entry PlanGate enforces. A page that reads its claims out
 * of the source of truth cannot drift into advertising a region we do not probe
 * from, a channel we cannot deliver to, or a limit we do not honour.
 *
 * The one thing it deliberately does NOT render is a price table. Paid tiers
 * are not self-serve yet (no Stripe price ids are mapped, so checkout 422s), and
 * a price with a dead button is worse than no price.
 */
class ShowLandingController
{
    public function __invoke(): View
    {
        return view('marketing.landing', [
            'regions' => $this->regions(),
            'channels' => $this->channels(),
            'freeTier' => $this->freeTier(),
            'signInUrl' => $this->clientUrl('/login'),
            'signUpUrl' => $this->clientUrl('/register'),
            'aiEnabled' => $this->aiEnabled(),
            'mailDeliverable' => $this->mailDeliverable(),
            // Our own public status page, when we run one. Null keeps the link
            // out of the footer rather than shipping a dead promise: every
            // credible tool in this category dogfoods publicly, and a broken
            // link would be worse than the gap it papers over.
            'ownStatusPageUrl' => $this->ownStatusPageUrl(),
        ]);
    }

    /**
     * The probe regions a monitor can actually be pinned to.
     *
     * Sourced from the enum the write requests validate against, NOT from
     * `config/relay.php`'s `regions` key, which the dispatch path does not read.
     *
     * @return list<array{value: string, label: string}>
     */
    protected function regions(): array
    {
        return array_map(
            fn (MonitorRegion $region): array => [
                'value' => $region->value,
                'label' => $region->label(),
            ],
            MonitorRegion::cases(),
        );
    }

    /**
     * The team-scoped alert destinations that exist.
     *
     * An exhaustive match with no default arm, so adding a channel type is a
     * compile-time-ish failure here rather than a page that quietly omits it.
     *
     * @return list<string>
     */
    protected function channels(): array
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
     * The free tier's enforced limits, read from the plan catalog.
     *
     * Only the four numbers the page states. Returns null when the catalog has
     * no free tier at all, which drops the sentence rather than inventing one.
     *
     * @return array{monitors: int|null, interval_sec: int|null, interval_label: string, status_pages: int|null, subscribers: int|null}|null
     */
    protected function freeTier(): ?array
    {
        $tier = Arr::first(
            config('plans.tiers', []),
            fn (array $tier): bool => ($tier['id'] ?? null) === 'free',
        );

        if ($tier === null) {
            return null;
        }

        $interval = Arr::get($tier, 'limits.check_interval_sec');

        return [
            'monitors' => Arr::get($tier, 'limits.monitors'),
            'interval_sec' => $interval,
            // "3-minute" reads better than "180-second" on a whole number of
            // minutes, and the copy should not have to know which it is.
            'interval_label' => is_int($interval) && $interval >= 60 && $interval % 60 === 0
                ? __(':count-minute', ['count' => intdiv($interval, 60)])
                : __(':count-second', ['count' => $interval]),
            'status_pages' => Arr::get($tier, 'limits.status_pages'),
            'subscribers' => Arr::get($tier, 'limits.subscribers'),
        ];
    }

    /**
     * Whether this deployment can actually run an AI feature.
     *
     * Without a provider key every AI path in the product degrades to its
     * deterministic baseline. That degrade is a feature, but a page advertising
     * "AI-assisted triage" on top of it would be selling the fallback, so the
     * whole section is withheld instead.
     */
    protected function aiEnabled(): bool
    {
        $provider = config('ai.default');

        if (! is_string($provider) || $provider === '') {
            return false;
        }

        $key = config("ai.providers.{$provider}.key");

        return is_string($key) && $key !== '';
    }

    /**
     * Whether the configured mailer can actually deliver to a human.
     *
     * `log` and `array` both accept a message and drop it, which would make the
     * status-page subscriber promise false: the confirmation mail a subscriber
     * waits for would be written to a file. The claim is withheld until a real
     * transport is configured.
     */
    protected function mailDeliverable(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array', null], true);
    }

    /**
     * Absolute URL into the Flutter client.
     *
     * The client mounts its auth screens under a prefix, so these are NOT
     * `/login` and `/register` on the app host; getting it wrong sends every
     * visitor who clicks the primary CTA to a route the client does not serve.
     */
    protected function clientUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .rtrim((string) config('app.frontend_auth_prefix'), '/')
            .$path;
    }

    protected function ownStatusPageUrl(): ?string
    {
        $url = config('app.own_status_page_url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
