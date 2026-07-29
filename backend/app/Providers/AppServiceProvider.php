<?php

namespace App\Providers;

use App\Actions\PlanGatedInviteTeamMember;
use App\Models\Team;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use App\Services\Ai\AnalysisGateway;
use App\Services\Ai\AnomalyTriageGateway;
use App\Services\Ai\AssistantGateway;
use App\Services\Ai\DigestGateway;
use App\Services\Ai\IncidentAnalysisGateway;
use App\Services\Ai\LaravelAiAnalysisGateway;
use App\Services\Ai\LaravelAiAssistantGateway;
use App\Services\Ai\LaravelAiDigestGateway;
use App\Services\Ai\LaravelAiIncidentAnalysisGateway;
use App\Services\Ai\LaravelAiTriageGateway;
use App\Services\StatusPages\StatusPagePreviewRenderer;
use FlutterSdk\MagicStarter\Contracts\InvitesTeamMembers;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use Illuminate\Support\Facades\Event;
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

        // Bind the weekly-digest boundary the same way. Tests rebind the
        // FakeDigestGateway, so no real Anthropic call happens in CI.
        $this->app->bind(DigestGateway::class, LaravelAiDigestGateway::class);

        // Bind the floating-assistant boundary the same way. Tests rebind
        // the FakeAssistantGateway, so no real Anthropic call happens in CI.
        $this->app->bind(AssistantGateway::class, LaravelAiAssistantGateway::class);

        // Wrap the starter's team-invite action with the plan responder cap
        // (contract-action override), so a team cannot invite past its tier.
        $this->app->bind(InvitesTeamMembers::class, PlanGatedInviteTeamMember::class);

        // Register the headless preview renderer as the container's single
        // resolution point, so the whole class can be swapped for a browserless
        // double. Tests\TestCase does exactly that for every test: the suite runs
        // with QUEUE_CONNECTION=sync and several feature tests reach a render
        // through an endpoint, so without a swappable boundary `php artisan test`
        // would launch real Chromium. The class's own protected `capture()` seam
        // is not enough on its own, since nothing can reach it from a container
        // resolution.
        $this->app->singleton(StatusPagePreviewRenderer::class);
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

        // Register the third-party Socialite drivers. The streamlined skeleton
        // has no EventServiceProvider, so SocialiteProviders' listener must be
        // wired here instead of a $listen array. Google needs no extension: it
        // ships with laravel/socialite core.
        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('microsoft', MicrosoftProvider::class);
            $event->extendSocialite('github', GitHubProvider::class);
        });

        // Register the incident notification types so preference-gating
        // (GateNotificationChannels) and the client preference matrix know
        // about them. 'push' is the logical channel name (aliased to the
        // 'onesignal' driver channel by MagicStarterServiceProvider when the
        // onesignal feature is enabled); the registry must use the logical
        // name so GateNotificationChannels resolves the driver channel back
        // to it and gates on the notifiable's push preference.
        NotificationPreferenceRegistry::register([
            IncidentOpened::class => [
                'label' => 'Incident opened',
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
            IncidentResolved::class => [
                'label' => 'Incident resolved',
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
