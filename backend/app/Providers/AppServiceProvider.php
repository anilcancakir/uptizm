<?php

namespace App\Providers;

use App\Notifications\IncidentOpened;
use App\Notifications\IncidentResolved;
use FlutterSdk\MagicStarter\NotificationPreferenceRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
