<?php

namespace App\Providers;

use App\Models\Team;
use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use App\Services\Ai\AnomalyTriageGateway;
use App\Services\Ai\LaravelAiTriageGateway;
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
        // about them.
        NotificationPreferenceRegistry::register([
            IncidentOpened::class => [
                'label' => 'Incident opened',
                'channels' => [
                    'mail',
                    'database',
                ],
                'default' => [
                    'mail',
                    'database',
                ],
                'locked' => [],
            ],
            IncidentResolved::class => [
                'label' => 'Incident resolved',
                'channels' => [
                    'mail',
                    'database',
                ],
                'default' => [
                    'mail',
                    'database',
                ],
                'locked' => [],
            ],
        ]);
    }
}
