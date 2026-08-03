import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'incident_form_support.dart';
import '../monitors/monitor_metrics_support.dart';
import '../../../app/controllers/incident_controller.dart';
import '../../../app/controllers/maintenance_controller.dart';
import '../../../app/controllers/monitor_controller.dart';
import '../../../app/controllers/status_page_controller.dart';
import '../../../app/enums/ai_confidence.dart' show AiConfidence;
import '../../../app/models/incident.dart';
import '../../../app/models/monitor.dart';
import '../../../app/models/status_page.dart';
import '../../../ui/components/ai_confidence_badge/index.dart';
import '../../../ui/components/region_picker/region_picker.dart';
import '../../../ui/layouts/page_container.dart';

/// The incident kind: a real incident, or a scheduled maintenance window.
///
/// Mirrors the React `kind` union (`"incident" | "maintenance"`); the
/// [kIncidentKinds] segmented control switches between them. Maintenance swaps
/// the severity control for a start/end window, pins the status-page impact to
/// `info`, and drops the AI banner.
enum _IncidentKind {
  /// A real, operator-filed (or AI-promoted) incident.
  incident,

  /// A planned maintenance window with a start/end range.
  maintenance,
}

/// **The Incident Create screen (`/incidents/new`).**
///
/// A faithful Flutter port of the React `IncidentCreatePage.tsx`. Operators
/// file an incident by hand: pick the affected monitors, set the operator-side
/// severity, draft the first public update, and decide whether to notify
/// subscribers. The "Scheduled maintenance" kind swaps severity for a
/// start/end window, pins the status-page impact to `info`, and hides the AI
/// banner.
///
/// ### The `?from` AI-promotion path
/// The dashboard AI-inbox opens this view with a `?from=<incidentId>` query
/// param ([MagicRoute.to] with `query:`). Because a [MagicRoute.page] builder
/// receives only PATH params, the view reads the query ITSELF from
/// [MagicRouter.instance.queryParameters] in [initState] (the route registers a
/// zero-arg `const IncidentCreateView()`, exactly like `/monitors/new`). When
/// `from` resolves through [findIncident] the view treats it as a promoted AI
/// anomaly: it prefills the title, the affected monitor (resolved from the
/// suggestion's `monitorName`), and the severity (from the anomaly's AI
/// confidence via [severityFromConfidence]), and shows the "Promoted from an AI
/// anomaly" banner. With no suggestion and the incident kind, the generic
/// "Uptizm AI analyzes this incident..." banner shows instead.
///
/// The prefilled title is a computed SEED value, not view chrome: it mirrors
/// the React literal `Investigating anomaly on ${monitorName}` the way
/// `aiNameFromUrl` seeds the monitor-create name, so it lives here rather than
/// in the i18n table.
///
/// ### Layout + token discipline
/// A plain Flutter [Column] scaffolds the page body inside the shared
/// [PageContainer]; Wind utilities appear only on the leaf containers. The AI
/// banner uses the dedicated `bg-ai-wash` / `border-ai-soft` / `bg-ai-soft` /
/// `text-ai` tokens (no opacity-on-alias like the React `from-ai-soft/50`,
/// which does not expand in Wind) and no raw color anywhere. The footer buttons
/// are auto-width inside a `flex flex-row justify-end gap-3` row (never `w-full`
/// in a Row, which forces unbounded width and aborts the layout).
///
/// ### Both kinds write
/// Submitting the incident kind fires `POST /incidents` via
/// [IncidentController.create] with the form's real `monitor_id`/`severity`/
/// `title`/`message`. Submitting the maintenance kind fires
/// `POST /scheduled-maintenances` via [MaintenanceController.create] with the
/// window's `status_page_id`/`title`/`description`/`starts_at`/`ends_at`/
/// `monitor_ids` (see [_buildMaintenanceFields]); the backend announces it to
/// the page's confirmed subscribers and holds paging for the attached monitors
/// while it is open. Both navigate to the incidents list on success and paint a
/// server 422 into the matching inline error slot. Cancel always just navigates
/// back.
///
/// ### Example
/// ```dart
/// // Registered as the routed `/incidents/new` content (Step 6):
/// MagicRoute.page('/incidents/new', () => const IncidentCreateView())
/// ```
@immutable
class IncidentCreateView extends MagicStatefulView<IncidentController> {
  /// Creates the [IncidentCreateView]. Zero-arg: the `?from` suggestion id is
  /// read from the router singleton in [State.initState], not passed in.
  const IncidentCreateView({super.key});

  @override
  State<IncidentCreateView> createState() => _IncidentCreateViewState();
}

class _IncidentCreateViewState
    extends MagicStatefulViewState<IncidentController, IncidentCreateView> {
  /// The route cancel returns to, and the back-affordance fallback.
  ///
  /// Both write paths navigate there from their own controller on success, so
  /// this constant is the cancel/back leg only.
  static const String _doneRoute = '/incidents';

  /// How far ahead the default maintenance window opens, and how long it runs.
  ///
  /// The window bounds are never null (the picker is seeded in [initState]), so
  /// the form needs no required-window copy and the operator can submit the
  /// default slot as-is. An hour out on the next quarter hour is a plausible
  /// planned slot; "right now, mid-minute" is not.
  static const Duration _defaultWindowLeadTime = Duration(hours: 1);

  /// The default window's duration.
  static const Duration _defaultWindowDuration = Duration(hours: 1);

  /// Minutes per step of the window pickers' time row. Matches the quarter-hour
  /// rounding of the seeded default so stepping stays on clean boundaries.
  static const int _windowMinuteStep = 15;

  /// The active incident kind (React `kind`, default `incident`).
  _IncidentKind _kind = _IncidentKind.incident;

  /// The incident title (React `title`).
  late String _title;

  /// Inline validation error for the Title field, or null when it is valid.
  ///
  /// Set on submit when the required title is blank (a check the client can
  /// make before any round trip), and by a server 422 that rejects `title`.
  /// Cleared when the user edits the field.
  String? _titleError;

  /// The selected affected-monitor ids (React `affected`).
  late List<String> _affected;

  /// Inline validation error for the Affected-monitors field, or null when it
  /// is valid.
  ///
  /// Set on submit when no monitor is selected (the backend requires a
  /// `monitor_id`, and [_buildCreateFields] reads `_affected.first`), and by a
  /// server 422 that rejects `monitor_id`. Cleared when the selection changes.
  String? _affectedError;

  /// The operator-side severity token (React `severity`, default `critical`).
  late String _severity;

  /// The customer-facing status-page impact token (React `impact`, default
  /// `down`; maintenance pins it to `info`).
  String _impact = 'down';

  /// The maintenance-window start, as a LOCAL [DateTime] (React `startsAt`).
  ///
  /// Local because that is what the operator picks and reads back; the
  /// conversion to UTC happens once, on the way out, in
  /// [_buildMaintenanceFields]. Dart's [DateTime] cannot carry an arbitrary
  /// offset (dart-lang/sdk#54993, closed as by-design), so a naive local string
  /// crossing the wire silently loses the operator's offset against a database
  /// session pinned to UTC.
  late DateTime _startsAt;

  /// Inline validation error for the Starts field, or null when it is valid.
  ///
  /// Server-side only: the field cannot be blank (see [_startsAt]), so there is
  /// no client-side check to run, and the backend is the only thing that can
  /// reject a window's start.
  String? _startsAtError;

  /// The maintenance-window end, as a LOCAL [DateTime] (React `endsAt`).
  late DateTime _endsAt;

  /// Inline validation error for the Ends field, or null when it is valid.
  ///
  /// Painted by the backend's `after:starts_at` rejection, which is the one
  /// window rule the client does not duplicate: the server owns it and its
  /// message already names the constraint.
  String? _endsAtError;

  /// The status page a maintenance window is announced on, or null when the
  /// roster has not landed yet or the team owns no page at all.
  ///
  /// Collected rather than resolved. This field used to be derived silently:
  /// the submit picked the first page publishing an affected monitor, else an
  /// arbitrary one, and posted nothing when the team had no page. A team with
  /// no status page therefore filled in the whole form and got
  /// "The status page id field is required" under a generic error toast,
  /// naming a field the form never showed. The choice is load-bearing (it
  /// decides which public page the window renders on and which subscribers are
  /// mailed), so the operator makes it.
  String? _statusPageId;

  /// Inline validation error for the Status-page field, or null when valid.
  String? _statusPageError;

  /// The team's status pages, projected live from the roster the same way
  /// [_monitorOptions] projects monitors.
  List<StatusPage> get _statusPageOptions =>
      StatusPageController.instance.statusPages;

  /// The first public update (React `message`).
  String _message = '';

  /// Whether to notify subscribers on open (React `notify`, default `true`).
  bool _notify = true;

  /// The promoted AI suggestion, resolved from `?from=<id>` on mount, or `null`
  /// when the view was opened blank (React `suggestion` from `location.state`).
  Incident? _suggestion;

  /// The affected-monitor options, projected LIVE from the backend monitor
  /// inventory ([MonitorController.monitors]) so a selected id is a real
  /// `monitor_id` the backend accepts (not a design-lab fixture id, which would
  /// 422 on `POST /incidents`).
  List<Region> get _monitorOptions => [
    for (final Monitor m in MonitorController.instance.monitors)
      Region(label: m.name ?? '', value: m.id),
  ];

  @override
  void initState() {
    // Register the controller before the base resolves it via `Magic.find<T>()`
    // (which throws if unregistered); see Conventions -> Controller binding.
    Magic.findOrPut(IncidentController.new);
    super.initState();

    // The affected-monitors picker projects the live monitor inventory; ensure
    // it is loaded (warm from the dashboard/list in normal flow, empty on a
    // direct deep-link) and rebuild when it lands.
    Magic.findOrPut(MonitorController.new);
    if (MonitorController.instance.monitors.isEmpty) {
      MonitorController.instance.reload().then((_) {
        if (mounted) setState(() {});
      });
    }

    // 1. Seed the maintenance window on the next clean quarter hour. Both
    //    bounds are non-null for the rest of the view's life, which is what
    //    lets the datetime pickers stay uncontrolled-by-null and the wire map
    //    convert unconditionally.
    _startsAt = _nextQuarterHour().add(_defaultWindowLeadTime);
    _endsAt = _startsAt.add(_defaultWindowDuration);

    // 2. Read the AI-promotion suggestion id from the router query itself; the
    //    page builder gets no query params, so the view resolves them here.
    final String? fromId = MagicRouter.instance.queryParameters['from'];
    final Incident? suggestion = controller.incidentById(fromId);
    _suggestion = suggestion;

    // 3. Seed the form. With a resolved suggestion, prefill title/affected/
    //    severity from the promoted anomaly; otherwise start blank at the React
    //    defaults.
    if (suggestion != null) {
      _title = trans('uptizm.incidents.form_prefill_title', {
        'monitor': suggestion.monitorName,
      });
      final String? monitorId = _resolveMonitorId(suggestion.monitorName);
      _affected = monitorId == null ? <String>[] : <String>[monitorId];
      _severity =
          severityFromConfidence[suggestion.ai?.confidence ??
              AiConfidence.high] ??
          'critical';
    } else {
      _title = '';
      _affected = <String>[];
      _severity = 'critical';
    }

    // 4. The status-page roster feeds the maintenance-only page field. Loaded
    //    the same way as the monitor inventory, and for the same reason: read
    //    as a secondary controller, `onInit` never fires for one, so nothing
    //    else would have fetched it. Seeded rather than left null so the common
    //    single-page team never has to touch the field, and seeded LAST because
    //    the preference reads `_affected`, which step 3 above assigns.
    Magic.findOrPut(StatusPageController.new);
    if (StatusPageController.instance.statusPages.isEmpty) {
      StatusPageController.instance.reload().then((_) {
        if (mounted) setState(_seedStatusPage);
      });
    } else {
      _seedStatusPage();
    }
  }

  /// Preselects the status page a window would most likely be announced on:
  /// the first page that publishes one of the affected monitors, else the first
  /// page in the roster. Leaves an operator's own pick alone, and leaves the
  /// field empty when the team owns no page.
  ///
  /// This is the same preference the old silent resolution used, kept as a
  /// DEFAULT rather than a decision: the field shows which page it landed on,
  /// and the operator can change it.
  void _seedStatusPage() {
    if (_statusPageId != null) return;

    final List<StatusPage> roster = _statusPageOptions;
    if (roster.isEmpty) return;

    final Set<String> affected = _affected.toSet();
    for (final StatusPage page in roster) {
      if (page.monitorIds.any(affected.contains)) {
        _statusPageId = page.id;
        return;
      }
    }

    _statusPageId = roster.first.id;
  }

  /// Whether the current kind is scheduled maintenance (React `isMaintenance`).
  bool get _isMaintenance => _kind == _IncidentKind.maintenance;

  /// The wall clock rounded UP to the next quarter hour, seconds dropped.
  ///
  /// The seed for the default window bounds: a planned slot reads as a clean
  /// time, and a value already on a [_windowMinuteStep] boundary keeps the time
  /// row's stepping on clean boundaries too.
  DateTime _nextQuarterHour() {
    final DateTime now = DateTime.now();
    final DateTime floor = DateTime(
      now.year,
      now.month,
      now.day,
      now.hour,
      now.minute - (now.minute % _windowMinuteStep),
    );
    return floor.add(const Duration(minutes: _windowMinuteStep));
  }

  /// Resolves a monitor display name to its stable id, or `null` when the
  /// suggestion's monitor is not in the fixture (the affected list then stays
  /// empty). Mirrors the React `monitors.find((m) => m.name === monitorName)`.
  String? _resolveMonitorId(String monitorName) {
    for (final Monitor monitor in MonitorController.instance.monitors) {
      if (monitor.name == monitorName) return monitor.id;
    }
    return null;
  }

  /// Switches the incident kind and pins the status-page impact: maintenance
  /// reads as `info`, a real incident as `down` (React `handleKind`).
  void _handleKind(_IncidentKind next) {
    setState(() {
      _kind = next;
      _impact = next == _IncidentKind.maintenance ? 'info' : 'down';
    });
  }

  /// Leaves the create flow for the incidents list (React `navigate`).
  void _done() {
    MagicRoute.to(_doneRoute);
  }

  @override
  Widget build(BuildContext context) {
    // The page body is a Wind flex column: the outer `gap-6` (24px) separates
    // the header from the body block, and the body block nests its own `gap-6`
    // between the optional AI banner, the form card, and the footer.
    return PageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          // 1. Header: title switches on kind, plus the back affordance.
          MSPageHeader(
            title: _isMaintenance
                ? trans('uptizm.incidents.form_title_maintenance')
                : trans('uptizm.incidents.form_title_new'),
            subtitle: trans('uptizm.incidents.form_description'),
            backLabel: trans('uptizm.incidents.back'),
            backFallback: _doneRoute,
          ),

          // 2. Body: optional AI banner (null-aware element, dropped when no
          //    banner applies), the form card, the footer.
          WDiv(
            className: 'flex flex-col gap-6',
            children: [?_buildBanner(), _buildFormCard(), _buildFooter()],
          ),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // AI banner.
  // ---------------------------------------------------------------------------

  /// Builds the AI banner shown above the form card, or `null` when none
  /// applies. Maintenance never shows a banner; a resolved suggestion shows the
  /// promoted variant; a blank incident shows the generic variant.
  Widget? _buildBanner() {
    if (_isMaintenance) return null;
    final Incident? suggestion = _suggestion;
    if (suggestion != null) return _buildPromotedBanner(suggestion);
    return _buildGenericBanner();
  }

  /// Builds the promoted-from-anomaly banner (React lines 114-132): the glyph
  /// tile, the "Promoted from an AI anomaly" title with an [AiConfidenceBadge]
  /// and the start time, the anomaly summary, and the prefill explainer.
  ///
  /// [Incident] carries no `summary`/`time` field (those lived on the
  /// React router-state `Suggestion`), so the summary maps to
  /// `ai?.tldr ?? ''` and the time to `startedAt`.
  Widget _buildPromotedBanner(Incident suggestion) {
    final String summary = suggestion.ai?.tldr ?? '';
    return WDiv(
      className:
          'flex flex-row items-start gap-3 rounded-xl border border-ai-soft bg-ai-wash p-4',
      children: [
        _buildGlyphTile(),
        WDiv(
          className: 'min-w-0 flex-1 flex flex-col gap-1.5',
          children: [
            // 1. Title row: heading + confidence badge + start time (wraps).
            WDiv(
              className: 'wrap items-center gap-2',
              children: [
                WText(
                  trans('uptizm.incidents.ai_promoted_title'),
                  className: 'text-sm font-semibold text-fg',
                ),
                AiConfidenceBadge(
                  suggestion.ai?.confidence ?? AiConfidence.high,
                ),
                WText(
                  suggestion.startedAt,
                  className: 'font-mono text-xs tabular-nums text-fg-muted',
                ),
              ],
            ),

            // 2. The anomaly summary (mapped from the AI tldr).
            if (summary.isNotEmpty)
              WText(summary, className: 'text-sm leading-relaxed text-fg'),

            // 3. The prefill explainer.
            WText(
              trans('uptizm.incidents.ai_promoted_explainer'),
              className: 'text-xs leading-relaxed text-fg-muted',
            ),
          ],
        ),
      ],
    );
  }

  /// Builds the generic "Uptizm AI analyzes this incident..." banner (React
  /// lines 134-145): the glyph tile beside the explanatory copy.
  Widget _buildGenericBanner() {
    return WDiv(
      className:
          'flex flex-row items-start gap-3 rounded-xl border border-ai-soft bg-ai-wash p-4',
      children: [
        _buildGlyphTile(),
        WText(
          trans('uptizm.incidents.ai_generic_banner'),
          className: 'min-w-0 flex-1 text-sm leading-relaxed text-fg',
        ),
      ],
    );
  }

  /// Builds the rounded ai-soft glyph tile carrying the sparkle mark, matching
  /// the codebase's AI-surface glyph idiom (monitor create + AiInsight banner).
  Widget _buildGlyphTile() {
    return WDiv(
      className:
          'size-8 shrink-0 flex items-center justify-center rounded-lg bg-ai-soft',
      child: WText('✦', className: 'text-ai text-lg'),
    );
  }

  // ---------------------------------------------------------------------------
  // Form card.
  // ---------------------------------------------------------------------------

  /// Builds the surface [Card] holding the field stack, in React order: Type,
  /// Title, Affected monitors, then (maintenance) Starts+Ends or (incident)
  /// Severity, Status page impact, First update, and the Notify section.
  Widget _buildFormCard() {
    return MSCard(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-5',
        children: [
          _buildTypeField(),
          _buildTitleField(),
          _buildAffectedField(),
          if (_isMaintenance) _buildStatusPageField(),
          if (_isMaintenance) _buildScheduleFields() else _buildSeverityField(),
          _buildImpactField(),
          _buildFirstUpdateField(),
          _buildNotifySection(),
        ],
      ),
    );
  }

  /// Builds the Type segmented control (incident / maintenance). Maps the tapped
  /// index back to the [_IncidentKind] via [kIncidentKinds].
  Widget _buildTypeField() {
    return MSFormField(
      label: trans('uptizm.incidents.form_type_label'),
      child: MSSegmentedControl<String>(
        options: kIncidentKinds.map((o) => o.label).toList(),
        selectedIndex: _kind.index,
        onChanged: (index) => _handleKind(_IncidentKind.values[index]),
      ),
    );
  }

  /// Builds the required Title field, with the placeholder switching on kind.
  Widget _buildTitleField() {
    return MSFormField(
      label: trans('uptizm.incidents.form_title_label'),
      error: _titleError,
      child: MSInput(
        value: _title,
        onChanged: (value) => setState(() {
          _title = value;
          _titleError = null;
        }),
        placeholder: _isMaintenance
            ? trans('uptizm.incidents.form_title_placeholder_maintenance')
            : trans('uptizm.incidents.form_title_placeholder_incident'),
      ),
    );
  }

  /// Builds the required Affected-monitors multi-select (React `RegionPicker`
  /// fed the monitor options).
  Widget _buildAffectedField() {
    return MSFormField(
      label: trans('uptizm.incidents.form_affected_label'),
      hint: trans('uptizm.incidents.form_affected_hint'),
      error: _affectedError,
      child: RegionPicker(
        regions: _monitorOptions,
        value: _affected,
        onChanged: (next) => setState(() {
          _affected = next;
          _affectedError = null;
        }),
      ),
    );
  }

  /// Builds the maintenance-only Status-page field.
  ///
  /// A window is announced ON a page: `status_page_id` is `required` in
  /// `StoreScheduledMaintenanceRequest` and NOT NULL behind a cascading foreign
  /// key, and the page decides which public page renders the window and which
  /// confirmed subscribers are mailed. So the field is shown even when the team
  /// owns exactly one page, because "which page" is never a detail.
  ///
  /// With no page at all, the select is replaced by the reason and the remedy.
  /// The submit is blocked by [_validateClientSide] in that state rather than
  /// posting a request the backend is guaranteed to reject.
  Widget _buildStatusPageField() {
    final List<StatusPage> roster = _statusPageOptions;

    return MSFormField(
      label: trans('uptizm.incidents.form_status_page_label'),
      hint: roster.isEmpty
          ? null
          : trans('uptizm.incidents.form_status_page_hint'),
      error: _statusPageError,
      child: roster.isEmpty
          ? WText(
              trans('uptizm.incidents.form_status_page_empty'),
              className: 'text-sm text-fg-muted',
            )
          : MSSelect<String>(
              value: _statusPageId,
              options: roster
                  .map(
                    (StatusPage page) => SelectOption<String>(
                      value: page.id,
                      label: page.name ?? page.slug ?? '',
                    ),
                  )
                  .toList(),
              onChange: (String? value) {
                if (value == null) return;
                setState(() {
                  _statusPageId = value;
                  _statusPageError = null;
                });
              },
            ),
    );
  }

  /// Builds the maintenance-only Starts + Ends window pickers, two columns from
  /// `sm`.
  ///
  /// Wind's [WDatePickerMode.dateTime] is the control: it captures a date AND a
  /// time of day and hands back a full local [DateTime], which is what a
  /// maintenance window needs and what the two free-text inputs that used to sit
  /// here could never guarantee.
  Widget _buildScheduleFields() {
    return WDiv(
      className: 'grid grid-cols-1 sm:grid-cols-2 gap-5',
      children: [
        MSFormField(
          label: trans('uptizm.incidents.form_starts_label'),
          error: _startsAtError,
          child: _buildWindowPicker(
            slot: 'starts',
            label: trans('uptizm.incidents.form_starts_label'),
            value: _startsAt,
            hasError: _startsAtError != null,
            onChanged: (value) => setState(() {
              _startsAt = value;
              _startsAtError = null;
            }),
          ),
        ),
        MSFormField(
          label: trans('uptizm.incidents.form_ends_label'),
          error: _endsAtError,
          child: _buildWindowPicker(
            slot: 'ends',
            label: trans('uptizm.incidents.form_ends_label'),
            value: _endsAt,
            hasError: _endsAtError != null,
            // The end bound cannot precede the start bound, so the picker
            // refuses those days outright rather than letting the operator
            // submit into the backend's `after:starts_at` rejection.
            minDate: _startsAt,
            onChanged: (value) => setState(() {
              _endsAt = value;
              _endsAtError = null;
            }),
          ),
        ),
      ],
    );
  }

  /// Builds one window bound's datetime picker.
  ///
  /// The label and the error message live in the surrounding [MSFormField] (the
  /// form's own slots), so the picker renders neither: its built-in label and
  /// error styling carries raw palette classes, which this app's token-only rule
  /// forbids. The trigger mirrors [MSInput]'s recipe, error variant included,
  /// through the `error:` state Wind resolves from [WDatePicker.states].
  ///
  /// [value] seeds the field ONCE, as any [FormField] does: the picked value
  /// afterwards lives in the field's own state and reaches this view through
  /// [onChanged]. That is why nothing here moves a bound programmatically; a
  /// write to [_startsAt] / [_endsAt] the field never heard about would leave
  /// the trigger showing a time the form no longer holds.
  Widget _buildWindowPicker({
    required String slot,
    required String label,
    required DateTime value,
    required bool hasError,
    required ValueChanged<DateTime> onChanged,
    DateTime? minDate,
  }) {
    return WFormDatePicker(
      // Stable per bound: the pair is unmounted whole when the kind switches
      // (which re-seeds both from this state) and must never swap identity
      // while it is mounted, since that would close an open popover mid-edit.
      key: ValueKey<String>('maintenance-window-$slot'),
      mode: WDatePickerMode.dateTime,
      initialValue: value,
      minDate: minDate,
      minuteStep: _windowMinuteStep,
      // The trigger's Semantics label, not visible copy: a bound always has a
      // value, so the placeholder never renders. Passing the field name keeps
      // the control announced as "Starts" / "Ends" instead of wind's untranslated
      // default.
      placeholder: label,
      // The popover's two labels have no dedicated key in the incidents
      // namespace and this step does not own the lang assets; both of these
      // exist and are translated, so the popover stays localized.
      timeLabel: trans('uptizm.monitors.check_col_time'),
      doneLabel: trans('common.done'),
      states: hasError ? const {'error'} : const {},
      className:
          'w-full rounded-lg border px-3 py-2.5 text-sm text-fg '
          'bg-surface-container-high border-color-border '
          'error:border-bg-destructive',
      onChanged: onChanged,
    );
  }

  /// Builds the incident-only Severity segmented control. Maps the tapped index
  /// back to the severity token via [kIncidentSeverities].
  Widget _buildSeverityField() {
    return MSFormField(
      label: trans('uptizm.incidents.form_severity_label'),
      hint: trans('uptizm.incidents.form_severity_hint'),
      child: MSSegmentedControl<String>(
        options: kIncidentSeverities.map((o) => o.label).toList(),
        selectedIndex: _indexOfValue(kIncidentSeverities, _severity),
        onChanged: (index) =>
            setState(() => _severity = kIncidentSeverities[index].value),
      ),
    );
  }

  /// Builds the Status-page impact select ([kIncidentImpacts]).
  Widget _buildImpactField() {
    return MSFormField(
      label: trans('uptizm.incidents.form_impact_label'),
      hint: trans('uptizm.incidents.form_impact_hint'),
      child: MSSelect<String>(
        value: _impact,
        options: kIncidentImpacts
            .map((o) => SelectOption<String>(value: o.value, label: o.label))
            .toList(),
        onChange: (value) {
          if (value != null) setState(() => _impact = value);
        },
      ),
    );
  }

  /// Builds the First-update textarea, with the placeholder switching on kind.
  Widget _buildFirstUpdateField() {
    return MSFormField(
      label: trans('uptizm.incidents.form_first_update_label'),
      hint: trans('uptizm.incidents.form_first_update_hint'),
      child: MSTextarea(
        value: _message,
        onChanged: (value) => setState(() => _message = value),
        placeholder: _isMaintenance
            ? trans(
                'uptizm.incidents.form_first_update_placeholder_maintenance',
              )
            : trans('uptizm.incidents.form_first_update_placeholder_incident'),
      ),
    );
  }

  /// Builds the Notify-subscribers section: a top-bordered block with the
  /// switch row and its hint (React lines 222-232).
  Widget _buildNotifySection() {
    return WDiv(
      className: 'flex flex-col gap-1.5 border-t border-color-border pt-5',
      children: [
        _buildSwitchRow(
          label: trans('uptizm.incidents.form_notify_label'),
          value: _notify,
          onChanged: (value) => setState(() => _notify = value),
        ),
        WText(
          trans('uptizm.incidents.form_notify_hint'),
          className: 'text-xs text-fg-muted',
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Footer + small helpers.
  // ---------------------------------------------------------------------------

  /// Builds the footer: Cancel + submit, right-aligned, auto-width.
  ///
  /// A Wind `flex flex-row justify-end gap-3` row of AUTO-width buttons. The
  /// buttons carry no `w-full` (a full-width button inside a `flex-row` forces
  /// an unbounded width and aborts the row's layout). The submit label switches
  /// on kind. The button is always enabled so a blank required field surfaces
  /// its inline error on submit (via [_onSubmit]) rather than silently locking
  /// the button. Both actions navigate to the incidents list on success.
  Widget _buildFooter() {
    return WDiv(
      className: 'flex flex-row justify-end gap-3',
      children: [
        MSButton(
          intent: ButtonIntent.secondary,
          onPressed: _done,
          child: WText(trans('uptizm.incidents.cancel')),
        ),
        MSButton(
          onPressed: _onSubmit,
          child: WText(
            _isMaintenance
                ? trans('uptizm.incidents.submit_schedule')
                : trans('uptizm.incidents.submit_open'),
          ),
        ),
      ],
    );
  }

  /// Builds a labelled switch row: the [Switch] toggle followed by its text
  /// label. Mirrors the `monitor_form.dart` switch-row helper (the Dart [Switch]
  /// is toggle-only, so the label renders beside it).
  Widget _buildSwitchRow({
    required String label,
    required bool value,
    required ValueChanged<bool> onChanged,
  }) {
    return WDiv(
      className: 'flex flex-row items-center gap-3',
      children: [
        MSSwitch(value: value, onChanged: onChanged, semanticLabel: label),
        WText(label, className: 'min-w-0 text-sm text-fg'),
      ],
    );
  }

  /// Returns the zero-based index of [value] in [options], or 0 when absent.
  int _indexOfValue(List<MetricOption> options, String value) {
    final int index = options.indexWhere((o) => o.value == value);
    return index < 0 ? 0 : index;
  }

  /// Submits the form: runs the client-side required checks first, then threads
  /// the real field values into the write that matches the kind, so either
  /// `POST /incidents` ([IncidentController.create]) or
  /// `POST /scheduled-maintenances` ([MaintenanceController.create]) fires.
  ///
  /// A blank required field surfaces inline via [_validateClientSide] without a
  /// round trip. Only when the client checks pass does it await the write; a
  /// non-empty result (a server 422) is a field-error map keyed by the posted
  /// wire field names, which [_applyServerErrors] paints under the matching
  /// fields. A returned key the form owns no slot for is surfaced as the generic
  /// error toast. `status_page_id` is no longer one of those: the form collects
  /// it in a labelled field and refuses to submit without it, because relying on
  /// the server here produced "The status page id field is required" under an
  /// unexpected-error toast, about a field the operator had never seen.
  Future<void> _onSubmit() async {
    if (!_validateClientSide()) return;

    final Map<String, String> serverErrors = _isMaintenance
        ? await _submitMaintenance()
        : await controller.create(_buildCreateFields());
    if (!mounted || serverErrors.isEmpty) return;

    final Map<String, String> unmapped = _applyServerErrors(serverErrors);
    if (unmapped.isNotEmpty) {
      Magic.error(
        trans('common.error_occurred'),
        unmapped.values.first,
      );
    }
  }

  /// Runs every client-side required check, painting each field's inline error
  /// slot, and returns whether the form may be submitted.
  ///
  /// Checks the required title and at least one affected monitor; both are
  /// checks the client can make before any round trip (and the affected check
  /// guards [_buildCreateFields]'s `_affected.first` read). Both slots are
  /// always written (a passing check clears its slot) so a previously shown
  /// error never lingers after a corrected resubmit.
  ///
  /// Both kinds run the same two checks. The maintenance endpoint would in fact
  /// accept a window with no `monitor_ids`, but such a window suppresses no
  /// alert and renders no component on the status page, so the form keeps
  /// requiring one. The window bounds need no check at all: they are seeded and
  /// picked, never blank ([_startsAt]).
  bool _validateClientSide() {
    final String? titleError = _title.trim().isEmpty
        ? trans('uptizm.incidents.form_title_error_required')
        : null;
    final String? affectedError = _affected.isEmpty
        ? trans('uptizm.incidents.form_affected_error_required')
        : null;

    // Maintenance only: the window needs a page to be announced on. Checked
    // here so a team with no status page is told what to do BEFORE filling the
    // form in, instead of hearing "The status page id field is required" from
    // the server about a field that used to be invisible.
    final String? statusPageError = _isMaintenance && _statusPageId == null
        ? trans('uptizm.incidents.form_status_page_error_required')
        : null;

    setState(() {
      _titleError = titleError;
      _affectedError = affectedError;
      _statusPageError = statusPageError;
    });

    return titleError == null &&
        affectedError == null &&
        statusPageError == null;
  }

  /// Routes a backend 422 field-error map (keyed by the wire field names the
  /// form posts) into the inline error slots, returning the entries that map to
  /// no known field so the caller can surface them another way.
  ///
  /// Both kinds' wire names are handled: `monitor_id` (incident) and
  /// `monitor_ids`, including its per-entry `monitor_ids.0` form (maintenance),
  /// share the affected-monitors slot, and the two window bounds have their own.
  Map<String, String> _applyServerErrors(Map<String, String> errors) {
    final Map<String, String> unmapped = {};
    setState(() {
      for (final MapEntry<String, String> entry in errors.entries) {
        switch (entry.key) {
          case 'title':
            _titleError = entry.value;
          case 'monitor_id':
            _affectedError = entry.value;
          case 'starts_at':
            _startsAtError = entry.value;
          case 'ends_at':
            _endsAtError = entry.value;
          case 'status_page_id':
            _statusPageError = entry.value;
          case final String key when key.startsWith('monitor_ids'):
            _affectedError = entry.value;
          default:
            unmapped[entry.key] = entry.value;
        }
      }
    });
    return unmapped;
  }

  /// Persists the maintenance window through [MaintenanceController.create],
  /// resolving the status page it is announced on first.
  ///
  /// Returns the same field-error map contract [_onSubmit] expects. With no
  /// status page resolvable at all, the `status_page_id` key is simply left out
  /// and the backend's own required-field 422 is what the operator sees: the one
  /// thing this path must never do is what it did before, which is report
  /// success for a window nothing wrote.
  /// Posts the window on the page the operator picked in
  /// [_buildStatusPageField], which [_validateClientSide] has already proved
  /// non-null.
  Future<Map<String, String>> _submitMaintenance() async {
    return MaintenanceController.instance.create(
      _buildMaintenanceFields(_statusPageId),
    );
  }

  /// Builds the `POST /scheduled-maintenances` field map
  /// (`StoreScheduledMaintenanceRequest`): the resolved [statusPageId], the
  /// trimmed `title`, the first update as the public `description` (omitted when
  /// blank, matching the rule's `nullable`), the two window bounds, and the
  /// affected monitors as the `monitor_ids` pivot list.
  ///
  /// **Every datetime crosses the wire as UTC.** `.toUtc().toIso8601String()` is
  /// the conversion, and the trailing `Z` is the point: Dart's [DateTime] cannot
  /// carry an arbitrary offset, so a naive local string would arrive at a
  /// database session pinned to UTC and shift the window by the operator's
  /// offset, opening and closing planned work at the wrong hour.
  ///
  /// Two fields the endpoint accepts are deliberately absent. `suppress_alerts`
  /// is `sometimes` and defaults to true in both the schema and the model, and
  /// the form exposes no control for it, so posting a client-side default would
  /// be this view asserting a choice the operator never made. `announced_at` is
  /// the backend's announce-once guard and no request class accepts it.
  Map<String, dynamic> _buildMaintenanceFields(String? statusPageId) {
    final Map<String, dynamic> fields = <String, dynamic>{
      'status_page_id': ?statusPageId,
      'title': _title.trim(),
      'starts_at': _startsAt.toUtc().toIso8601String(),
      'ends_at': _endsAt.toUtc().toIso8601String(),
      'monitor_ids': List<String>.from(_affected),
    };
    final String description = _message.trim();
    if (description.isNotEmpty) {
      fields['description'] = description;
    }
    return fields;
  }

  /// Builds the `POST /incidents` field map (`StoreIncidentRequest`):
  /// `monitor_id` from the first selected affected monitor (the backend
  /// accepts a single monitor per incident; [_canSubmit] guarantees
  /// [_affected] is non-empty here), `severity` mapped to the backend enum
  /// value via [_severityForBackend], the trimmed `title`, and an optional
  /// trimmed `message`.
  Map<String, dynamic> _buildCreateFields() {
    final Map<String, dynamic> fields = <String, dynamic>{
      'monitor_id': _affected.first,
      'severity': _severityForBackend(_severity),
      'title': _title.trim(),
    };
    final String message = _message.trim();
    if (message.isNotEmpty) {
      fields['message'] = message;
    }
    return fields;
  }

  /// Maps the form's severity token to the backend `IncidentSeverity` enum
  /// value: the form offers `critical`/`warning`/`info` ([kIncidentSeverities])
  /// while the backend enum is `critical`/`warn`/`info`.
  String _severityForBackend(String severity) {
    return severity == 'warning' ? 'warn' : severity;
  }
}
