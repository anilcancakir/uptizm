<?php

namespace App\Services\StatusPages;

use App\Enums\IncidentStatus;
use App\Enums\MonitorStatus;
use App\Http\ViewModels\StatusPageViewModel;
use App\Jobs\BustStatusPageCacheForMaintenanceBoundaries;
use App\Jobs\TranslateStatusPageText;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\ScheduledMaintenance;
use App\Models\StatusPage;
use App\Models\StatusPageTranslation;
use App\Services\Monitoring\IncidentTitle;
use App\Support\StatusPages\StatusPageLocale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Builds the public status page's read model from a {@see StatusPage}.
 *
 * Every visibility and secrecy decision is made HERE, at the query and the
 * mapping, not in Blade:
 *   - Components are scoped in the query (shown, not paused, and not hidden
 *     by `only_show_if_degraded` while healthy), so a paused or hidden monitor
 *     never reaches the template.
 *   - The 90-day strips are batch-loaded in one query (no N+1 per component).
 *   - The overall banner rolls the visible components up the severity ladder.
 *   - Only PUBLIC incident updates are loaded, grouped by day (Cachet pattern).
 *   - Maintenance windows are scoped to the page they were announced on AND to
 *     its visible monitors, so planned work can never publish a component the
 *     page hides.
 *
 * The result is a {@see StatusPageViewModel}: a field-allowlisted object that
 * carries no monitor url / auth_config / internal id / team_id.
 *
 * The payload is LANGUAGE-DEPENDENT throughout and reads the ambient locale.
 * Three kinds of string answer to it: the banner label
 * ({@see self::overallLabel()}), an auto-generated incident title
 * ({@see IncidentTitle::render()}), and the six operator-authored free-text
 * fields, each resolved through the translation store by
 * {@see self::translatedField()} and carrying its original and its provenance in
 * two SIBLING keys beside it. `ShowStatusPageController` applies the render
 * locale before calling this and caches the result under
 * `status-page:{slug}:{locale}`, one entry per language: a page now serves every
 * supported language rather than the single one its owner picked. Anything else
 * that assembles a page outside a request must set the locale itself, or it will
 * bake the deployment default into a Turkish page's cache entry.
 */
class StatusPageAssembler
{
    /**
     * Trailing window (in days) of incidents surfaced on the public page. An
     * incident is shown when it is either still active or carries a public
     * update within this window.
     */
    protected const int RECENT_INCIDENT_DAYS = 14;

    /**
     * Forward horizon (in days) of maintenance windows surfaced on the public
     * page. A window is shown while it is open, and from this far ahead of its
     * start.
     *
     * "Open now or starting soon" needs a definite bound, and a week is the one
     * this surface earns. It is the span an operator schedules inside and the
     * span a visitor can still act on: rescheduling a deployment, holding off an
     * import, warning their own users. A window a month out changes nobody's
     * afternoon, and this section sits ABOVE the components, so anything that
     * cannot be acted on yet is pushing the actual health of the service further
     * down the page. Nothing is lost by waiting: a window beyond the horizon
     * simply appears once it comes within a week of starting.
     */
    public const int UPCOMING_MAINTENANCE_DAYS = 7;

    /**
     * Overall status for a page with no published components.
     *
     * Deliberately NOT on the severity ladder: it is the absence of a verdict,
     * not a rung on it. Kept as a constant because the Blade banner matches on
     * it to pick a neutral dot rather than a green one.
     */
    public const string STATUS_UNKNOWN = 'unknown';

    /**
     * Where a rendered free-text value came from, carried beside it as
     * `<field>_provenance` so the page can label a machine translation and offer
     * the original.
     *
     * Four states rather than a translated/untranslated boolean, because the
     * three ways a field can lack a translation are different promises to the
     * reader: this IS the operator's own language, the translation is still
     * queued, or the output contract refused it and nothing will re-queue it.
     * Constants rather than a backed enum: the values travel inside the cached
     * plain-array payload and are read as strings by the Blade, and an enum
     * would have to be unwrapped on both sides of the cache boundary.
     */
    public const string PROVENANCE_AUTHORED = 'authored';

    public const string PROVENANCE_TRANSLATED = 'translated';

    public const string PROVENANCE_PENDING = 'pending';

    public const string PROVENANCE_UNAVAILABLE = 'unavailable';

    public function __construct(
        protected ComponentDailyUptimeService $uptime = new ComponentDailyUptimeService,
    ) {}

    /**
     * Assemble the public read model for the given status page.
     */
    public function build(StatusPage $page): StatusPageViewModel
    {
        // 1. Scope visible components at the DB level (fail-closed presentation).
        //    This id set is the ONLY set anything else on this page may draw
        //    from: the strips, the incidents and the maintenance windows are all
        //    keyed on it, so a monitor the page hides cannot surface through a
        //    side channel.
        $monitors = $this->visibleMonitors($page);
        $monitorIds = $monitors->pluck('id')->all();

        // 2. Batch-load every 90-day strip in a single rollup query.
        $strips = $this->uptime->last90DaysForMonitors($monitorIds);

        // 3. Shape components and roll their statuses up the severity ladder.
        //
        // A page with NOTHING published does not get a health verdict. `worstOf`
        // returns the bottom of the ladder for an empty set, which would put
        // "All Systems Operational" above a components card reading "No
        // components are currently published on this page" -- a claim about
        // systems the page is not tracking, next to the admission that it is
        // tracking none. On a status page that is not a fail-safe default, it is
        // the one lie that costs the most trust.
        $components = $this->buildComponents($monitors, $strips);
        $overallStatus = $components === []
            ? self::STATUS_UNKNOWN
            : $this->uptime->worstOf(array_column($components, 'status'));

        // 4. Load the rows carrying operator-authored free text BEFORE shaping
        //    any of them, because their translations are fetched in one query
        //    keyed by the ids those rows hand over. Resolving a translation at
        //    each of the six call sites instead would cost a query per field per
        //    row (dozens on a page with three incidents and their updates),
        //    inside a cache fill that runs on a visitor's request.
        $now = CarbonImmutable::now();
        $windows = $this->maintenanceWindows($page, $monitorIds, $now);
        // Scope incidents to the VISIBLE monitors only: an incident title
        // embeds the monitor name ("{name} is down"), so pulling incidents for a
        // hidden/paused component would leak its name even though its row is
        // hidden from the page.
        $incidents = $this->recentIncidents($monitorIds);
        $translations = $this->loadTranslations($page, $windows, $incidents);

        return new StatusPageViewModel(
            page: $this->buildPage($page, $translations),
            overallStatus: $overallStatus,
            overallLabel: $this->overallLabel($overallStatus),
            // Planned work first in the read model as well as on the page: a
            // visitor arriving during a window should read "this was announced"
            // before reading the red it explains.
            maintenances: $this->buildMaintenances($windows, $now, $translations),
            components: $components,
            incidents: $this->groupIncidentsByDay($incidents, $translations),
            // Assembly time travels in the cached array, so "updated Xm ago"
            // reflects the age of the cached snapshot, not the render time.
            generatedAt: $now->toIso8601String(),
        );
    }

    /**
     * Monitors shown on the page, scoped in the query: shown on the status
     * page, not paused, and not hidden by `only_show_if_degraded` while up.
     *
     * @return Collection<int, Monitor>
     */
    protected function visibleMonitors(StatusPage $page): Collection
    {
        return $page->monitors()
            ->where('show_on_status_page', true)
            // "Not paused" reads the administrative column too. Filtering on
            // `last_status` alone excluded nothing, because pausing never writes
            // that column: a paused monitor kept publishing its final reading,
            // and a `down` one rolled the whole page up to a major outage.
            ->notPaused()
            ->whereNot(function (Builder $query): void {
                // Degraded-only components disappear from the page while healthy.
                $query->where('only_show_if_degraded', true)
                    ->where('last_status', MonitorStatus::Up->value);
            })
            ->get();
    }

    /**
     * Map each visible monitor to its allowlisted component shape.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @param  array<int|string, array<int, array<string, mixed>>>  $strips
     * @return array<int, array{
     *     label: string,
     *     status: string,
     *     uptimePercent: float|null,
     *     strip: array<int, array{date: string, status: string}>,
     * }>
     */
    /**
     * The labels `$page` actually PUBLISHES for the given monitors: the visible
     * set intersected with `$monitorIds`, each rendered as the page's own
     * `custom_label` when it has one.
     *
     * Public, because the subscriber announcement mail needs the same answer the
     * page renders and got it wrong by reading the window's raw pivot instead:
     * that named a component the operator had hidden, and used the internal
     * monitor name where the page publishes a friendly one. Visibility is a
     * presentation-time decision over one authoritative id set, so every
     * surface that shows a component name to the public resolves it here.
     *
     * @param  list<string>  $monitorIds  Candidates to intersect with the visible set.
     * @return list<string>
     */
    public function publicComponentLabels(StatusPage $page, array $monitorIds): array
    {
        if ($monitorIds === []) {
            return [];
        }

        return $this->visibleMonitors($page)
            ->whereIn('id', $monitorIds)
            ->map(static fn (Monitor $monitor): string => $monitor->pivot?->custom_label ?? $monitor->name)
            ->values()
            ->all();
    }

    protected function buildComponents(Collection $monitors, array $strips): array
    {
        $components = [];

        foreach ($monitors as $monitor) {
            $strip = $strips[$monitor->id] ?? [];

            $components[] = [
                'label' => $monitor->pivot?->custom_label ?? $monitor->name,
                'status' => $this->componentStatus($monitor->effectiveStatus()),
                'uptimePercent' => $this->uptimePercent($strip),
                'strip' => array_map(
                    static fn (array $day): array => [
                        'date' => $day['date'],
                        'status' => $day['worst_status'],
                    ],
                    $strip,
                ),
            ];
        }

        return $components;
    }

    /**
     * Maintenance windows this page announces that are open right now or start
     * within {@see self::UPCOMING_MAINTENANCE_DAYS}, earliest start first (so an
     * in-progress window always heads the list).
     *
     * Two scopes, and on a public surface both are load-bearing:
     *
     *   - `status_page_id`. An operator picks the page a window is announced on.
     *     A monitor can be published on several pages, so without this scope one
     *     team's internal-facing page would drag its wording onto the
     *     customer-facing one.
     *   - An intersection with the page's VISIBLE monitors. A window's title and
     *     description describe work on a named component ("Upgrading the
     *     payments database"), so announcing one announces that the component
     *     exists. A window whose monitors are all hidden or paused therefore
     *     publishes nothing at all.
     *
     * A window from ANOTHER TEAM can satisfy neither: the page id belongs to one
     * tenant, and so does every id in the visible set.
     *
     * The state is derived here rather than in Blade, because the payload is
     * cached for 60 seconds and its boundaries are busted by
     * {@see BustStatusPageCacheForMaintenanceBoundaries}; a template asking the
     * clock would disagree with the snapshot it was rendered from.
     *
     * @param  array<int, int|string>  $monitorIds  The page's visible monitor ids.
     * @return Collection<int, ScheduledMaintenance>
     */
    protected function maintenanceWindows(StatusPage $page, array $monitorIds, CarbonImmutable $now): Collection
    {
        if ($monitorIds === []) {
            return new Collection;
        }

        return ScheduledMaintenance::query()
            ->where('status_page_id', $page->getKey())
            // Open or upcoming: it has not finished, and it starts inside the
            // horizon. A finished window is history; the incidents section is
            // where any consequence of it lives.
            ->where('ends_at', '>=', $now)
            ->where('starts_at', '<=', $now->addDays(self::UPCOMING_MAINTENANCE_DAYS))
            ->whereHas('monitors', fn (Builder $monitors) => $monitors->whereIn('monitors.id', $monitorIds))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Shape the windows {@see self::maintenanceWindows()} scoped, each free-text
     * field resolved through the translation store.
     *
     * @param  Collection<int, ScheduledMaintenance>  $windows
     * @param  array<string, array<string, array<string, StatusPageTranslation>>>  $translations
     * @return array<int, array{
     *     title: string,
     *     title_original: string,
     *     title_provenance: string,
     *     description: string|null,
     *     description_original: string|null,
     *     description_provenance: string,
     *     startsAt: string,
     *     endsAt: string,
     *     state: 'in_progress'|'scheduled',
     * }>
     */
    protected function buildMaintenances(Collection $windows, CarbonImmutable $now, array $translations): array
    {
        $source = $this->teamSourceLocale();

        return $windows
            ->map(fn (ScheduledMaintenance $window): array => [
                ...$this->translatedField($translations, $window, 'title', $window->title, $source),
                ...$this->translatedField($translations, $window, 'description', $window->description, $source),
                'startsAt' => $window->starts_at->toIso8601String(),
                'endsAt' => $window->ends_at->toIso8601String(),
                'state' => $window->starts_at->lessThanOrEqualTo($now) ? 'in_progress' : 'scheduled',
            ])
            ->all();
    }

    /**
     * Recent public incidents affecting the page's monitors, each carrying only
     * its `is_public` updates.
     *
     * @return Collection<int, Incident>
     */
    protected function recentIncidents(array $monitorIds): Collection
    {
        if ($monitorIds === []) {
            return new Collection;
        }

        $since = CarbonImmutable::now()->subDays(self::RECENT_INCIDENT_DAYS);

        return Incident::query()
            ->whereHas('monitors', fn (Builder $query) => $query->whereIn('monitors.id', $monitorIds))
            ->where('started_at', '>=', $since)
            ->where(function (Builder $query): void {
                // Public when it carries a public update, is still active, or has
                // a published postmortem (publishing IS the act of making the
                // incident's write-up customer-visible, so it must list the
                // incident even when no public update was ever posted).
                $query->whereHas('updates', fn (Builder $updates) => $updates->where('is_public', true))
                    ->orWhere('lifecycle', '!=', IncidentStatus::Resolved->value)
                    ->orWhereNotNull('postmortem_published_at');
            })
            ->with(['updates' => fn (Relation $updates) => $updates->where('is_public', true)])
            ->orderByDesc('started_at')
            ->get();
    }

    /**
     * Group incidents by their `started_at` calendar day into the public shape.
     *
     * @param  Collection<int, Incident>  $incidents
     * @param  array<string, array<string, array<string, StatusPageTranslation>>>  $translations
     * @return array<int, array{day: string, entries: array<int, array<string, mixed>>}>
     */
    protected function groupIncidentsByDay(Collection $incidents, array $translations): array
    {
        $groups = [];

        foreach ($incidents as $incident) {
            $day = $incident->started_at->format('Y-m-d');
            $groups[$day][] = $this->buildIncidentEntry($incident, $translations);
        }

        return array_map(
            static fn (string $day, array $entries): array => [
                'day' => $day,
                'entries' => $entries,
            ],
            array_keys($groups),
            $groups,
        );
    }

    /**
     * Allowlisted shape for a single incident and its public updates.
     *
     * The postmortem is gated on its PUBLICATION STAMP, not on the body: an
     * operator's internal draft (body set, `postmortem_published_at` null) is
     * omitted entirely, so "publish" is the single switch that makes it
     * customer-visible and an unpublished draft can never reach the page.
     *
     * The title goes through {@see IncidentTitle::render()} rather than off the
     * column, with NO explicit locale: the controller has already applied the
     * render language, so a composed title resolves into it and an
     * operator-authored one (null `title_key`) comes back as the text a human
     * wrote. The output is byte-identical English on an English page, and reading
     * the raw column here is how the next person concludes the column is the
     * contract.
     *
     * @param  array<string, array<string, array<string, StatusPageTranslation>>>  $translations
     * @return array<string, mixed>
     */
    protected function buildIncidentEntry(Incident $incident, array $translations): array
    {
        $source = $this->teamSourceLocale();

        // An auto-generated title is composed from a catalogue key and rendered
        // into the ambient locale by IncidentTitle above, so it is already in the
        // language this page is answering in: it is AUTHORED in every language,
        // and TranslateStatusPageText deliberately queues it nothing (its stored
        // English also carries the monitored response value interpolated into
        // it). Saying its source locale is the one being rendered is what makes
        // the resolver answer `authored` instead of waiting forever for a row
        // nothing writes.
        $titleSource = $incident->title_key === null ? $source : app()->getLocale();

        return [
            ...$this->translatedField(
                $translations,
                $incident,
                'title',
                IncidentTitle::render($incident),
                $titleSource,
            ),
            'lifecycle' => $incident->lifecycle->value,
            'impact' => $incident->impact->value,
            'startedAt' => $incident->started_at->toIso8601String(),
            'postmortem' => $incident->postmortemIsPublished()
                ? [
                    ...$this->translatedField(
                        $translations,
                        $incident,
                        'postmortem_body',
                        $incident->postmortem_body,
                        $source,
                        key: 'body',
                    ),
                    'publishedAt' => $incident->postmortem_published_at->toIso8601String(),
                ]
                : null,
            // NEWEST FIRST. The relation orders `display_at` ascending, which is
            // what the operator-facing timeline wants (read the incident forwards).
            // A public history reads the other way: a visitor arriving mid-incident
            // wants the latest word at the top, which is how every status page they
            // have already seen behaves. Reversed here rather than on the relation,
            // so the in-app timeline keeps its chronological order.
            'updates' => $incident->updates
                ->reverse()
                ->map(fn (IncidentUpdate $update): array => [
                    ...$this->translatedField($translations, $update, 'message', $update->message, $source),
                    'actor' => $update->actor,
                    'displayAt' => $update->display_at->toIso8601String(),
                    'status' => $update->status->value,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Allowlisted page branding + addressing fields.
     *
     * `name` and `logo_text` are IDENTITY and stay as authored: a translated
     * brand is a wrong brand, which is why {@see TranslateStatusPageText}
     * does not name them either. `description` is the one free-text field here.
     *
     * @param  array<string, array<string, array<string, StatusPageTranslation>>>  $translations
     * @return array{
     *     name: string,
     *     brand_color: string|null,
     *     logo_text: string|null,
     *     description: string|null,
     *     description_original: string|null,
     *     description_provenance: string,
     *     subscriptions_enabled: bool,
     *     slug: string,
     * }
     */
    protected function buildPage(StatusPage $page, array $translations): array
    {
        return [
            'name' => $page->name,
            'brand_color' => $this->safeBrandColor($page->brand_color),
            'logo_text' => $page->logo_text,
            ...$this->translatedField(
                $translations,
                $page,
                'description',
                $page->description,
                $this->pageSourceLocale($page),
            ),
            'subscriptions_enabled' => (bool) $page->subscriptions_enabled,
            'slug' => $page->slug,
        ];
    }

    /**
     * Every stored translation this page needs for the language being rendered,
     * in ONE query, indexed by (morph type, key, field).
     *
     * Loaded up front rather than resolved at each call site: the six sites sit
     * inside three nested loops (windows, incidents, each incident's updates), so
     * a lookup per field per row is dozens of queries on a busy page, spent
     * inside a 60-second cache fill that runs on a visitor's request.
     *
     * `team_id` is on the query because it is on the row for exactly this: the
     * store carries the tenant denormalised so a public read can scope by it
     * without joining back through four morph targets.
     *
     * @param  Collection<int, ScheduledMaintenance>  $windows
     * @param  Collection<int, Incident>  $incidents
     * @return array<string, array<string, array<string, StatusPageTranslation>>>
     */
    protected function loadTranslations(StatusPage $page, Collection $windows, Collection $incidents): array
    {
        $locale = app()->getLocale();

        // The render IS the source language of every field on the page, so all
        // of them are `authored` and no stored row could apply. That is the
        // default-language render, which is most of this surface's traffic, and
        // it therefore costs no translation query at all.
        if ($locale === $this->teamSourceLocale() && $locale === $this->pageSourceLocale($page)) {
            return [];
        }

        // Types come off the models rather than from `::class` so a morph map,
        // if this application ever registers one, is honoured on the read side
        // exactly as `TranslateStatusPageText` honours it on the write side.
        $keys = [];

        foreach ($this->translatableRows($page, $windows, $incidents) as $row) {
            $keys[$row->getMorphClass()][] = $row->getKey();
        }

        $rows = StatusPageTranslation::query()
            ->where('team_id', $page->team_id)
            ->where('locale', $locale)
            ->where(function (Builder $query) use ($keys): void {
                foreach ($keys as $type => $ids) {
                    $query->orWhere(fn (Builder $group) => $group
                        ->where('translatable_type', $type)
                        ->whereIn('translatable_id', $ids));
                }
            })
            ->get();

        $indexed = [];

        foreach ($rows as $row) {
            $indexed[(string) $row->translatable_type][(string) $row->translatable_id][(string) $row->field] = $row;
        }

        return $indexed;
    }

    /**
     * Every row on this page that carries one of the six translated fields.
     *
     * @param  Collection<int, ScheduledMaintenance>  $windows
     * @param  Collection<int, Incident>  $incidents
     * @return list<Model>
     */
    protected function translatableRows(StatusPage $page, Collection $windows, Collection $incidents): array
    {
        $updates = $incidents
            ->flatMap(static fn (Incident $incident): array => $incident->updates->all())
            ->all();

        return [
            $page,
            ...$windows->all(),
            ...$incidents->all(),
            ...$updates,
        ];
    }

    /**
     * One free-text field as the three keys the page reads: the value to SHOW
     * under the field's existing key, the operator's original beside it, and
     * where the shown value came from.
     *
     * Siblings rather than a nested triple under the existing key, because those
     * keys have readers outside this file: the layout puts
     * `page['description']` straight into `og:description` and
     * `<meta name="description">`, and an array arriving there is a fatal on
     * every render of a page whose whole job is to be up when nothing else is.
     * With siblings every existing reader keeps working untouched.
     *
     * @param  array<string, array<string, array<string, StatusPageTranslation>>>  $translations
     * @param  string  $field  The COLUMN, which is what the store is keyed on.
     * @param  string|null  $original  The value as its operator authored it.
     * @param  string  $sourceLocale  The language that original is in.
     * @param  string|null  $key  The read model's key, when it differs from the
     *                            column (the postmortem's `postmortem_body` renders as `body`).
     * @return array<string, string|null>
     */
    protected function translatedField(
        array $translations,
        Model $row,
        string $field,
        ?string $original,
        string $sourceLocale,
        ?string $key = null,
    ): array {
        $key ??= $field;

        // Authored, on two counts. The render is already in the language this
        // text was written in, and `TranslateStatusPageText` writes no row for a
        // field's own source locale, so the absence of one here is correct rather
        // than pending. An empty source is authored too: the job's
        // `shouldTranslate()` refuses a blank field, so anything else would leave
        // it reading "translation in progress" forever.
        if ($sourceLocale === app()->getLocale() || ! is_string($original) || trim($original) === '') {
            return [
                $key => $original,
                $key.'_original' => $original,
                $key.'_provenance' => self::PROVENANCE_AUTHORED,
            ];
        }

        $translation = $translations[$row->getMorphClass()][(string) $row->getKey()][$field] ?? null;
        $value = $original;
        $provenance = self::PROVENANCE_PENDING;

        if ($translation !== null && $translation->rejected_at !== null) {
            // A rejection is a recorded verdict, not work still queued: the
            // output contract refused the model's answer and nothing re-queues
            // it, so `pending` would promise an arrival that never comes.
            $provenance = self::PROVENANCE_UNAVAILABLE;
        } elseif ($translation !== null && is_string($translation->value) && trim($translation->value) !== '') {
            $provenance = self::PROVENANCE_TRANSLATED;
            $value = $translation->value;
        }

        return [
            $key => $value,
            $key.'_original' => $original,
            $key.'_provenance' => $provenance,
        ];
    }

    /**
     * The language a TEAM-SCOPED row's free text was authored in.
     *
     * THE SOURCE-LOCALE RULE, and both ends of this surface have to apply the
     * same one. No incident, update or maintenance row carries a language column
     * (the only one on this surface is `status_pages.locale`), so every write
     * path hands {@see TranslateStatusPageText} `app.default_locale` for all
     * three, and it writes no row for a field's own source language. Reading
     * them under any other rule would render a field `pending` against a row
     * nothing ever queues.
     *
     * The method name is spelled out in prose rather than as a `{@see}` with
     * parentheses on purpose: `TranslateStatusPageText`'s own suite enumerates
     * the files that mention its dispatch call, so that a fifth caller in a
     * public controller or a view fails a test, and this file is a READER.
     */
    protected function teamSourceLocale(): string
    {
        return (string) config('app.default_locale');
    }

    /**
     * The language the PAGE's own description was authored in.
     *
     * The one field with a language column of its own, and the same expression
     * {@see StatusPageLocale::render()} falls back to and the write path fans
     * out from: a null `locale` means the deployment default.
     */
    protected function pageSourceLocale(StatusPage $page): string
    {
        return $page->locale ?? $this->teamSourceLocale();
    }

    /**
     * A brand colour safe to interpolate into a CSS declaration, or null.
     *
     * Both status partials render this value inside an inline
     * `style="background-color: {{ ... }}"`. Blade escapes HTML entities, which
     * stops attribute break-out, but a value like
     * `red; background-image: url(https://host/x.png)` contains nothing
     * HTML-special and would survive intact as a SECOND CSS declaration. That
     * would make a tenant-controlled string issue a remote fetch from inside the
     * headless browser that renders the preview PNG, which is the exact SSRF
     * shape the render-safety test exists to forbid.
     *
     * The write paths do validate this pattern (Store and
     * UpdateStatusPageRequest both anchor a hex regex), so a hostile value is
     * not reachable through the API today. This guard is deliberately here
     * anyway: the column carries no constraint, a seeder, an import or a console
     * command bypasses the request rules entirely, and the render-safety
     * control must not silently depend on validation staying correct forever.
     *
     * Anything that is not a 6 or 8 digit hex literal becomes null, which both
     * partials already render as their neutral default.
     */
    protected function safeBrandColor(?string $brandColor): ?string
    {
        if ($brandColor === null) {
            return null;
        }

        return preg_match('/^#[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?$/', $brandColor) === 1
            ? $brandColor
            : null;
    }

    /**
     * Map a monitor's health to a component status on the severity ladder.
     * Paused monitors never reach here (they are scoped out of the query).
     */
    protected function componentStatus(?MonitorStatus $status): string
    {
        return match ($status) {
            MonitorStatus::Down => 'major_outage',
            MonitorStatus::Degraded => 'degraded',
            // A monitor nobody has probed yet has no verdict, and `default` used to
            // hand it `operational`: a component published as healthy on the strength
            // of nothing. It resolves to the neutral family instead, the same one the
            // unmeasured days in its strip use, so the row reads "not measured"
            // rather than "passed".
            null => self::STATUS_UNKNOWN,
            default => 'operational',
        };
    }

    /**
     * Mean daily uptime across the 90-day strip, rounded to two decimals.
     *
     * @param  array<int, array<string, mixed>>  $strip
     */
    protected function uptimePercent(array $strip): ?float
    {
        // Averaged over the days that were actually MEASURED, and null when none
        // were. Returning 100.0 for an empty strip published a figure nothing stood
        // behind, and averaging a gap-filled 100 alongside real days quietly pulled
        // a partial history up toward perfect. Null reaches the view as an em space
        // rather than a number, because "we do not know yet" and "no downtime" are
        // different claims and only one of them is ours to make.
        $percents = array_values(array_filter(
            array_column($strip, 'uptime_percent'),
            static fn (?float $percent): bool => $percent !== null,
        ));

        if ($percents === []) {
            return null;
        }

        return round(array_sum($percents) / count($percents), 2);
    }

    /**
     * Human banner label for an overall status on the severity ladder, in the
     * language the page renders in.
     *
     * This is the largest copy on the page and the only piece composed in PHP
     * rather than in a template, which is why a Blade grep for untranslated
     * strings never found it: a `tr` page can otherwise read "All Systems
     * Operational" above Turkish incident titles.
     *
     * Resolved HERE rather than in the banner partial, and the reason is the
     * file boundary rather than the design: keying the Blade off `overallStatus`
     * (the shape a view-model builder would prefer) would leave
     * {@see StatusPageViewModel::$overallLabel} populated and cached with nothing
     * reading it, and that field cannot be removed from this step. The locale is
     * ambient: the controller applies the render language before the assembler
     * runs, so the label lands in the 60-second `status-page:{slug}:{locale}`
     * payload already in the language that entry answers in, and each of the
     * page's languages caches its own.
     *
     * `default` keeps its old reach on purpose: a status this ladder does not
     * know still gets a label rather than a raw key.
     */
    protected function overallLabel(string $status): string
    {
        return (string) __(match ($status) {
            'major_outage' => 'status.banner.major_outage',
            'partial_outage' => 'status.banner.partial_outage',
            'degraded' => 'status.banner.degraded',
            self::STATUS_UNKNOWN => 'status.banner.unknown',
            default => 'status.banner.operational',
        });
    }
}
