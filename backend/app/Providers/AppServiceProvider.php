<?php

namespace App\Providers;

use App\Actions\PlanGatedInviteTeamMember;
use App\Actions\StoreSubscriptionGuardedDeleteTeam;
use App\Listeners\RecordNotificationDelivery;
use App\Models\Team;
use App\Notifications\IncidentEscalated;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use App\Policies\BillingPolicy;
use App\Services\Ai\AiDeadline;
use App\Services\Ai\AnalysisGateway;
use App\Services\Ai\AnomalyTriageGateway;
use App\Services\Ai\AssistantGateway;
use App\Services\Ai\DigestGateway;
use App\Services\Ai\IncidentAnalysisGateway;
use App\Services\Ai\IncidentDraftGateway;
use App\Services\Ai\LaravelAiAnalysisGateway;
use App\Services\Ai\LaravelAiAssistantGateway;
use App\Services\Ai\LaravelAiDigestGateway;
use App\Services\Ai\LaravelAiIncidentAnalysisGateway;
use App\Services\Ai\LaravelAiIncidentDraftGateway;
use App\Services\Ai\LaravelAiTriageGateway;
use App\Services\Ai\OpenRouterUpstreamRecorder;
use App\Services\Monitoring\ProbeTransport;
use App\Services\Monitoring\RelayClient;
use App\Services\StatusPages\StatusPagePreviewRenderer;
use FlutterSdk\MagicStarter\Contracts\DeletesTeams;
use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use SocialiteProviders\GitHub\Provider as GitHubProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the triage boundary to the real laravel/ai wrapper. Tests rebind
        // the FakeAnomalyTriageGateway, so no real Anthropic call happens in CI.
        $this->app->bind(AnomalyTriageGateway::class, LaravelAiTriageGateway::class);

        // Bind the monitor-setup analysis boundary the same way. Tests rebind
        // the FakeAnalysisGateway, so no real Anthropic call happens in CI.
        $this->app->bind(AnalysisGateway::class, LaravelAiAnalysisGateway::class);

        // Bind the post-incident RCA boundary the same way. Tests rebind the
        // FakeIncidentAnalysisGateway, so no real Anthropic call happens in CI.
        $this->app->bind(IncidentAnalysisGateway::class, LaravelAiIncidentAnalysisGateway::class);
        $this->app->bind(IncidentDraftGateway::class, LaravelAiIncidentDraftGateway::class);

        // Bind the weekly-digest boundary the same way. Tests rebind the
        // FakeDigestGateway, so no real Anthropic call happens in CI.
        $this->app->bind(DigestGateway::class, LaravelAiDigestGateway::class);

        // Bind the floating-assistant boundary the same way. Tests rebind
        // the FakeAssistantGateway, so no real Anthropic call happens in CI.
        $this->app->bind(AssistantGateway::class, LaravelAiAssistantGateway::class);

        // Bind the probe transport to the Cloudflare relay, which is the network
        // every customer monitor must stay on; PerformMonitorCheck overrides it
        // per monitor for the system team's catalog probes.
        //
        // The binding is not optional plumbing. The queue resolves `handle()`'s
        // parameters through Container::call(), so an unbound interface throws
        // BindingResolutionException on EVERY check in production while the test
        // suite stays green: the positional handle() call sites in CheckJobTest
        // pass both arguments by hand and never ask the container for anything.
        $this->app->bind(ProbeTransport::class, RelayClient::class);

        // Wrap the starter's team-invite action with the plan responder cap
        // (contract-action override), so a team cannot invite past its tier.
        $this->app->bind(InvitesTeamMembers::class, PlanGatedInviteTeamMember::class);

        // Same pattern, same reason: team deletion is the starter's endpoint and
        // uptizm owns no team route to guard. A store subscription outlives the
        // team row (the store keeps charging and only its own account surface can
        // cancel), so deleting a store-billed team is refused until the owner has
        // been there. See StoreSubscriptionGuardedDeleteTeam.
        $this->app->bind(DeletesTeams::class, StoreSubscriptionGuardedDeleteTeam::class);

        // Register the headless preview renderer as the container's single
        // resolution point, so the whole class can be swapped for a browserless
        // double. Tests\TestCase does exactly that for every test: the suite runs
        // with QUEUE_CONNECTION=sync and several feature tests reach a render
        // through an endpoint, so without a swappable boundary `php artisan test`
        // would launch real Chromium. The class's own protected `capture()` seam
        // is not enough on its own, since nothing can reach it from a container
        // resolution.
        $this->app->singleton(StatusPagePreviewRenderer::class);

        // SCOPED, not singleton, and the difference is load-bearing under
        // Octane: the container persists across requests there, so a singleton
        // would carry the first request's start time forever and every later
        // analyze would believe its budget was already spent. `scoped` is reset
        // per request and per queued job, which is exactly the unit the budget
        // is meant to cover.
        $this->app->scoped(AiDeadline::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The team, not the user, is the Stripe customer: every subscription
        // and payment method belongs to a team so billing stays scoped to
        // the workspace, matching the SaaS-team-billable pattern (research/01).
        Cashier::useCustomerModel(Team::class);

        // The billing WRITE gate: `checkout`, `swap`, `cancel` and `portal` are
        // the team owner's, every read stays open to any member. See
        // {@see BillingPolicy} for why the split falls there.
        //
        // A NAMED ABILITY rather than `Gate::policy(Team::class, ...)`, and the
        // difference is load-bearing. `MagicStarterServiceProvider` already
        // registers `Gate::policy(Team::class, TeamPolicy::class)`; the policy
        // map is keyed by model class, this provider boots after the package's,
        // and a second registration would REPLACE that entry rather than add to
        // it, silently unguarding team member management, invitations, and team
        // deletion. Auto-discovery is no help either: it looks for a policy
        // named after the model, and this one is named after the surface it
        // guards. So the ability is defined explicitly and named for what it
        // authorizes.
        Gate::define('manageBilling', [BillingPolicy::class, 'manage']);

        // Record which OpenRouter upstream served each AI call. Global on
        // purpose: six gateways prompt a model and `laravel/ai` exposes no
        // per-request header or raw-response seam above the HTTP client, so this
        // is the one place that cannot be half applied. It gates on the
        // OpenRouter host itself, so every other outbound request in the
        // application passes through untouched and unread.
        Http::globalMiddleware(new OpenRouterUpstreamRecorder);

        // Register the third-party Socialite drivers. The streamlined skeleton
        // has no EventServiceProvider, so SocialiteProviders' listener must be
        // wired here instead of a $listen array. Google needs no extension: it
        // ships with laravel/socialite core.
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('microsoft', MicrosoftProvider::class);
            $event->extendSocialite('github', GitHubProvider::class);
        });

        // Record every attempted delivery through a team-scoped
        // NotificationChannel. BOTH events are registered because either alone
        // under-records: NotificationSent fires only once the channel's send()
        // has RETURNED, so a transport failure (which the channels rethrow
        // rather than report, precisely so it lands here) reaches only
        // NotificationFailed. The listener
        // filters on the notifiable, so the user lanes never write a row.
        Event::listen([
            NotificationSent::class,
            NotificationFailed::class,
        ], RecordNotificationDelivery::class);

        // Register the incident notification types so preference-gating
        // (GateNotificationChannels) and the client preference matrix know
        // about them. 'push' is the logical channel name (aliased to the
        // 'onesignal' driver channel by MagicStarterServiceProvider when the
        // onesignal feature is enabled); the registry must use the logical
        // name so GateNotificationChannels resolves the driver channel back
        // to it and gates on the notifiable's push preference.
        NotificationPreferenceRegistry::register([
            IncidentOpened::class => [
                // A translation KEY, not a sentence. magic-starter-laravel
                // 0.0.6 resolves it inside the request through the
                // notifiable's own preferredLocale(), so it follows the
                // account's language. Registering the finished English here
                // froze it at boot, and a Turkish operator read "Incident
                // opened" on a screen whose every other string was Turkish.
                'label' => 'notifications.incident_opened_preference_label',
                'channels' => [
                    'mail',
                    'database',
                    'push',
                    'sms',
                ],
                // 'sms' is advertised (a toggle shows on /settings/notifications)
                // but deliberately omitted from 'default': SMS is opt-in, so a
                // member is only texted after explicitly enabling it. A default-on
                // sms would text every member on every incident (10DLC cost + spam).
                'default' => [
                    'mail',
                    'database',
                    'push',
                ],
                'locked' => [],
            ],
            // Its own row, not IncidentOpened's: an operator who muted
            // incident-opened noise should still be told when something they are
            // already watching gets worse. Registering it also matters for a
            // reason that is easy to miss: GateNotificationChannels returns true
            // for an UNREGISTERED class, so without this the escalation shipped
            // ungated and a member who had turned push off would still be pushed.
            // The registry auto-derives the slug from the class name, which lands
            // on exactly the `incident_escalated` token the notification uses.
            IncidentEscalated::class => [
                'label' => 'notifications.incident_escalated_preference_label',
                'channels' => [
                    'mail',
                    'database',
                    'push',
                    'sms',
                ],
                'default' => [
                    'mail',
                    'database',
                    'push',
                ],
                'locked' => [],
            ],
            IncidentResolved::class => [
                'label' => 'notifications.incident_resolved_preference_label',
                'channels' => [
                    'mail',
                    'database',
                    'push',
                    'sms',
                ],
                // See IncidentOpened: 'sms' is advertised but never default-on.
                'default' => [
                    'mail',
                    'database',
                    'push',
                ],
                'locked' => [],
            ],
        ]);
    }
}
