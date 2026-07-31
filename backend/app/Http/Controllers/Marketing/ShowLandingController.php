<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\MonitorRegion;
use App\Enums\NotificationChannelType;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Locale;

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
            'stageLabels' => $this->stageLabels(),
            'localeLinks' => $this->localeLinks(),
            'homePath' => $this->pathFor(app()->getLocale()),
            'canonicalUrl' => $this->urlFor(app()->getLocale()),
            'sections' => $this->sections(),
        ]);
    }

    /**
     * The in-page sections the header and footer may link to.
     *
     * Empty while the hero is the only section, and that emptiness is the feature:
     * both render their nav from this list, so a nav entry cannot outrun the section
     * it points at. Add an entry in the same change that adds the section.
     *
     * @return list<array{id: string, label: string}>
     */
    protected function sections(): array
    {
        return [];
    }

    /**
     * The stage's act names, handed to the Alpine sequence to label itself with.
     *
     * They belong here rather than in heroSequence.js because a string literal in a
     * JS module never reaches Laravel's translator: the server HTML would come out
     * translated and then the running animation would relabel itself in English a
     * second later.
     *
     * Keys are strings because the transition act is `1.5`, and PHP truncates a
     * float array key to an integer.
     *
     * @return array<string, string>
     */
    protected function stageLabels(): array
    {
        return [
            '1' => __('New monitor'),
            '1.5' => __('Dispatching'),
            '2' => __('Checks'),
            '3' => __('Triage'),
            '4' => __('Escalation'),
        ];
    }

    /**
     * Every language the product speaks, with this page's address in each.
     *
     * The list is `magic-starter.supported_locales`, the same array the API
     * negotiates Accept-Language against and the same pair the Flutter client
     * registers, so the three surfaces cannot drift apart.
     *
     * @return list<array{code: string, label: string, path: string, url: string, current: bool}>
     */
    protected function localeLinks(): array
    {
        $current = app()->getLocale();

        return array_map(
            fn (string $code): array => [
                'code' => $code,
                'label' => $this->languageName($code),
                'path' => $this->pathFor($code),
                'url' => $this->urlFor($code),
                'current' => $code === $current,
            ],
            array_values((array) config('magic-starter.supported_locales', [])),
        );
    }

    /**
     * A language's name in its own language.
     *
     * Read from ICU rather than a typed label map, so a locale added to config names
     * itself instead of showing a two-letter code. This matches the app's own
     * switcher (`English`, `Türkçe`): somebody hunting for Turkish scans for
     * "Türkçe", not for "Turkish".
     */
    protected function languageName(string $code): string
    {
        return Str::ucfirst(Locale::getDisplayLanguage($code, $code));
    }

    /**
     * The path a language is served on. The default language lives on the apex.
     */
    protected function pathFor(string $locale): string
    {
        return $locale === config('app.default_locale') ? '/' : '/'.$locale;
    }

    /**
     * The absolute URL for a language, for canonical and hreflang.
     *
     * From `route()`, which is configuration-derived, rather than from the request:
     * the URL a crawler indexes must be the canonical host even when the request
     * arrived on some other name.
     */
    protected function urlFor(string $locale): string
    {
        return $locale === config('app.default_locale')
            ? route('landing')
            : route('landing.localized', ['locale' => $locale]);
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
     *
     * The conjunction is translated. It used to be a literal `' and '` inside the
     * implode, which is the kind of leak that survives every "is the page translated"
     * check: the sentence around it came out in Turkish and read "sırada iOS and
     * Android".
     */
    protected function platformClaim(): string
    {
        $platforms = (array) config('app.client_platforms', []);
        $pending = array_keys(array_filter($platforms, fn (string $state): bool => $state !== 'live'));

        return $pending === []
            ? __('Web, iOS and Android')
            : __('On the web today, :pending next', ['pending' => Arr::join(
                array_map(
                    fn (string $key): string => $key === 'ios' ? 'iOS' : ucfirst($key),
                    $pending,
                ),
                ', ',
                ' '.__('and').' ',
            )]);
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
