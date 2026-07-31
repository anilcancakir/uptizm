<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\MonitorRegion;
use App\Enums\NotificationChannelType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;

/**
 * The landing page on the apex host.
 *
 * One rule governs this controller: A CLAIM IS DERIVED, NEVER TYPED. Anything the
 * page asserts about the product comes from the enum or config that actually
 * governs the behaviour, so the page cannot drift into advertising a region we do
 * not probe from or a limit we do not honour.
 *
 * It grows a method per section as the page is rebuilt. Right now it serves the
 * hero only.
 */
class ShowLandingController
{
    public function __invoke(): View
    {
        return view('landing', [
            'regions' => $this->regions(),
            'freeTier' => $this->freeTier(),
            'signInUrl' => $this->clientUrl('/login'),
            'signUpUrl' => $this->clientUrl('/register'),
            'aiEnabled' => $this->aiEnabled(),
            'channels' => $this->channels(),
            'platformClaim' => $this->platformClaim(),
        ]);
    }

    /**
     * The probe regions a monitor can actually be pinned to.
     *
     * Sourced from the enum the write requests validate against, NOT from
     * `config/relay.php`'s `regions` key, which the dispatch path never reads and
     * which understates the real list.
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
     * An exhaustive match with no default arm, so adding a channel type is a failure
     * here rather than a hero that quietly omits it. Notably absent because they are
     * absent from the enum: email and SMS as TEAM channels.
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
     * What we may honestly say about where the client runs.
     *
     * "Web, iOS and Android" as a flat claim is false while the mobile builds are in
     * neither store: they come from the same Flutter source, but nobody can install
     * them. So the phrasing follows `app.client_platforms` and only becomes the
     * unqualified version once every platform is live.
     */
    protected function platformClaim(): string
    {
        $platforms = (array) config('app.client_platforms', []);
        $pending = array_keys(array_filter($platforms, fn (string $state): bool => $state !== 'live'));

        return $pending === []
            ? __('Web, iOS and Android')
            : __('On the web today, :pending next', ['pending' => implode(' and ', array_map(
                fn (string $key): string => $key === 'ios' ? 'iOS' : ucfirst($key),
                $pending,
            ))]);
    }

    /**
     * The free tier's enforced limits, read from the plan catalog PlanGate uses.
     *
     * Returns null when the catalog has no free tier, which drops the sentence
     * rather than inventing one.
     *
     * @return array{monitors: int|null, interval: string, status_pages: int|null}|null
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

        $seconds = Arr::get($tier, 'limits.check_interval_sec');

        return [
            'monitors' => Arr::get($tier, 'limits.monitors'),
            // "3-minute" reads better than "180-second" on a whole number of
            // minutes, and the copy should not have to know which it is.
            'interval' => is_int($seconds) && $seconds >= 60 && $seconds % 60 === 0
                ? __(':count-minute', ['count' => intdiv($seconds, 60)])
                : __(':count-second', ['count' => $seconds]),
            'status_pages' => Arr::get($tier, 'limits.status_pages'),
        ];
    }

    /**
     * Whether this deployment can actually run an AI feature.
     *
     * Without a provider key every AI path in the product degrades to its
     * deterministic baseline. That degrade is a feature, but a page advertising
     * "AI-assisted triage" on top of it would be selling the fallback, so the claim
     * is withheld instead.
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
     * Absolute URL into the Flutter client.
     *
     * The client mounts its auth screens under a prefix, so these are NOT `/login`
     * and `/register` on the app host; getting it wrong sends every visitor who
     * clicks the primary call to action to a route the client does not serve.
     */
    protected function clientUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            .rtrim((string) config('app.frontend_auth_prefix'), '/')
            .$path;
    }
}
