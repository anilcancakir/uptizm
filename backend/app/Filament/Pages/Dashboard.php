<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The staff panel's dashboard, moved off the panel root onto `/dashboard`.
 *
 * WHY THIS CLASS EXISTS AT ALL
 *
 * The panel is mounted with `->domain(config('uptizm.admin_host'))` and
 * `->path('')`, so the panel's route group has an EMPTY prefix and Filament's
 * stock dashboard (`$routePath = '/'`) would sit on the group root. Filament
 * then decides whether to register its own `home` route by asking whether the
 * group root is already taken (`vendor/filament/filament/routes/web.php`, the
 * Laravel 13 branch that compares `$rootDomain.$rootUri` against the GET route
 * table). With the dashboard occupying the root, `filament.admin.home` is never
 * registered, and `Panel::getUrl()` falls back to `url($panel->getPath())`,
 * which resolves against APP_URL and points every "back to the panel" link at
 * the marketing apex instead of the panel host. Filament issue #19549 is the
 * same collision seen from the login-redirect side.
 *
 * Giving the dashboard its own path frees the group root, so Filament registers
 * `filament.admin.home` there, the panel owns `admin.<host>/` outright, and the
 * root redirects to the dashboard rather than answering it.
 *
 * `tests/Feature/Admin/PanelIsolationTest.php` pins both halves: that the panel
 * owns that host's root even when the wildcard status-page route is configured,
 * and that the dashboard answers on `/dashboard`.
 *
 * Nothing is overridden beyond the path. The widgets, navigation and layout are
 * the framework's, registered on the panel in `AdminPanelProvider`.
 */
class Dashboard extends BaseDashboard
{
    protected static string $routePath = '/dashboard';
}
