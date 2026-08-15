<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The staff back-office panel, mounted on its own host.
 *
 * THE URLS THIS PANEL ANSWERS ON
 *
 *   login      https://<uptizm.admin_host>/login       (production: https://admin.uptizm.com/login)
 *   dashboard  https://<uptizm.admin_host>/dashboard   (production: https://admin.uptizm.com/dashboard)
 *   root       https://<uptizm.admin_host>/            redirects to one of the two above
 *
 * They are written down because they are targets, not trivia: the panel's
 * access-gate QA and the Octane memory measurement both drive the login URL, and
 * a measurement that hit the marketing landing page instead of a Filament page
 * would be worthless with no stated URL to check it against.
 *
 * WHY ITS OWN HOST, AND WHY NOT A PATH ON THE APEX
 *
 * The middleware list below starts a session and encrypts cookies, which is
 * unavoidable for a Livewire panel. The apex serves the marketing pages and the
 * public status pages, both registered OUTSIDE the `web` group precisely so they
 * set no cookie, and `resources/legal/privacy.en.md:208-212` publishes that as an
 * unqualified claim. A panel at `uptizm.com/admin` would not itself falsify the
 * claim, but it puts session middleware one route file away from the surface that
 * must never carry it. Its own host keeps the two apart, and the host is read from
 * `config('uptizm.admin_host')` so local and production differ by environment
 * alone. `tests/Feature/Admin/PanelIsolationTest.php` asserts the marketing and
 * status routes still resolve no session middleware after this install, which is
 * the property the separation exists to protect.
 *
 * WHY `->path('')` AND NOT THE GENERATOR'S `->path('admin')`
 *
 * On a dedicated host, `->path('admin')` answers at `admin.<host>/admin` and
 * leaves `admin.<host>/` to whatever else matches. Two routes compete for that
 * root and the second one is the trap:
 *
 *   - `routes/marketing.php:87` registers `GET /` with NO host constraint.
 *   - `routes/status.php:54` registers `GET /` under `{slug}.<subdomain_host>`
 *     when `status_pages.subdomain_host` is set, and a host-constrained route is
 *     matched before an unconstrained one.
 *
 * `subdomain_host` is EMPTY in `.env.example` and set in production, so an
 * unowned `admin.<host>/` would match `{slug}` in production only, 404 through
 * `RejectReservedStatusPageSlug` (`admin` is a reserved slug), and look perfectly
 * healthy on every developer's machine. `->path('')` makes the panel the owner of
 * that root instead of leaving the answer to registration order, and
 * `PanelIsolationTest` compiles the route collection with `subdomain_host`
 * configured and asserts the panel wins, because reasoning about Laravel's
 * matching order from memory is exactly how this class of bug reaches production.
 *
 * The dashboard is `App\Filament\Pages\Dashboard`, whose `$routePath` moves it to
 * `/dashboard` so the panel root stays free for Filament's own `home` redirect.
 * That class carries the reasoning; do not point this back at
 * `Filament\Pages\Dashboard`.
 *
 * NO TENANCY, DELIBERATELY. This is a cross-team staff console, and Filament's
 * `Panel::tenant()` builder scopes every query to a single tenant, which is the
 * exact opposite. It must never be called anywhere in this directory. The name is
 * written above without its call parentheses on purpose, so that grepping this
 * directory for the call shape keeps returning nothing;
 * `PanelIsolationTest::test_the_panel_has_no_tenancy()` is the executable form.
 *
 * WHERE THE ACCESS CONTROL ACTUALLY LIVES, WHICH IS NOT HERE
 *
 * `->login()` below admits any user Filament can authenticate, so this file on its
 * own gates nothing. The control is `User::canAccessPanel()`, which requires both
 * of: membership of `config('uptizm.staff_emails')` and a verified address. It
 * required a confirmed second factor until 2026-08-15; that method's docblock
 * carries what removing it costs and what restoring it takes. Filament calls it
 * from
 * `Filament\Http\Middleware\Authenticate`, and that middleware falls back to
 * `config('app.env') !== 'local'` when the user model does not implement
 * `FilamentUser`, which would admit every authenticated user on a dev box and
 * consult no allowlist at all. So the gate is load-bearing in a way that is invisible
 * from this file: removing the `implements FilamentUser` on the model does not break
 * anything here, it silently opens the console.
 * `tests/Feature/Admin/StaffGateTest.php` pins it, including an HTTP case proving the
 * panel really does consult the gate rather than merely that the method returns
 * false.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            // The panel owns the ROOT of its own host: a host constraint read
            // from config plus an EMPTY path. See the class docblock for why
            // neither half is optional.
            ->domain(config('uptizm.admin_host'))
            ->path('')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
