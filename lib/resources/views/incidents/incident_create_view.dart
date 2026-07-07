import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'incident_form_support.dart';
import '../monitors/monitor_metrics_support.dart';
import '../../../app/controllers/incident_controller.dart';
import '../../../app/mocks/incidents.dart';
import '../../../app/mocks/monitors.dart';
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
/// This is a mock screen: nothing persists. Both submit and cancel navigate to
/// the incidents list.
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
  /// The route both submit and cancel return to (mock: nothing persists).
  static const String _doneRoute = '/incidents';

  /// The active incident kind (React `kind`, default `incident`).
  _IncidentKind _kind = _IncidentKind.incident;

  /// The incident title (React `title`).
  late String _title;

  /// The selected affected-monitor ids (React `affected`).
  late List<String> _affected;

  /// The operator-side severity token (React `severity`, default `critical`).
  late String _severity;

  /// The customer-facing status-page impact token (React `impact`, default
  /// `down`; maintenance pins it to `info`).
  String _impact = 'down';

  /// The maintenance-window start, as a raw string (React `startsAt`).
  String _startsAt = '';

  /// The maintenance-window end, as a raw string (React `endsAt`).
  String _endsAt = '';

  /// The first public update (React `message`).
  String _message = '';

  /// Whether to notify subscribers on open (React `notify`, default `true`).
  bool _notify = true;

  /// The promoted AI suggestion, resolved from `?from=<id>` on mount, or `null`
  /// when the view was opened blank (React `suggestion` from `location.state`).
  IncidentSummary? _suggestion;

  /// The affected-monitor options, projected once from the monitors fixture.
  late final List<Region> _monitorOptions = monitorsToRegions();

  @override
  void initState() {
    // Register the controller before the base resolves it via `Magic.find<T>()`
    // (which throws if unregistered); see Conventions -> Controller binding.
    Magic.findOrPut(IncidentController.new);
    super.initState();

    // 1. Read the AI-promotion suggestion id from the router query itself; the
    //    page builder gets no query params, so the view resolves them here.
    final String? fromId = MagicRouter.instance.queryParameters['from'];
    final IncidentSummary? suggestion = controller.incidentById(fromId);
    _suggestion = suggestion;

    // 2. Seed the form. With a resolved suggestion, prefill title/affected/
    //    severity from the promoted anomaly; otherwise start blank at the React
    //    defaults.
    if (suggestion != null) {
      _title = 'Investigating anomaly on ${suggestion.monitorName}';
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
  }

  /// Whether the current kind is scheduled maintenance (React `isMaintenance`).
  bool get _isMaintenance => _kind == _IncidentKind.maintenance;

  /// Whether the form can be submitted (React `canSubmit`): a non-empty title
  /// and at least one affected monitor.
  bool get _canSubmit => _title.trim().isNotEmpty && _affected.isNotEmpty;

  /// Resolves a monitor display name to its stable id, or `null` when the
  /// suggestion's monitor is not in the fixture (the affected list then stays
  /// empty). Mirrors the React `monitors.find((m) => m.name === monitorName)`.
  String? _resolveMonitorId(String monitorName) {
    for (final MonitorSummary monitor in monitors) {
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
    final IncidentSummary? suggestion = _suggestion;
    if (suggestion != null) return _buildPromotedBanner(suggestion);
    return _buildGenericBanner();
  }

  /// Builds the promoted-from-anomaly banner (React lines 114-132): the glyph
  /// tile, the "Promoted from an AI anomaly" title with an [AiConfidenceBadge]
  /// and the start time, the anomaly summary, and the prefill explainer.
  ///
  /// [IncidentSummary] carries no `summary`/`time` field (those lived on the
  /// React router-state `Suggestion`), so the summary maps to
  /// `ai?.tldr ?? ''` and the time to `startedAt`.
  Widget _buildPromotedBanner(IncidentSummary suggestion) {
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
      child: MSInput(
        value: _title,
        onChanged: (value) => setState(() => _title = value),
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
      child: RegionPicker(
        regions: _monitorOptions,
        value: _affected,
        onChanged: (next) => setState(() => _affected = next),
      ),
    );
  }

  /// Builds the maintenance-only Starts + Ends inputs, two columns from `sm`.
  Widget _buildScheduleFields() {
    return WDiv(
      className: 'grid grid-cols-1 sm:grid-cols-2 gap-5',
      children: [
        MSFormField(
          label: trans('uptizm.incidents.form_starts_label'),
          child: MSInput(
            value: _startsAt,
            onChanged: (value) => setState(() => _startsAt = value),
          ),
        ),
        MSFormField(
          label: trans('uptizm.incidents.form_ends_label'),
          child: MSInput(
            value: _endsAt,
            onChanged: (value) => setState(() => _endsAt = value),
          ),
        ),
      ],
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
  /// on kind and is disabled until [_canSubmit]. Both actions navigate to the
  /// incidents list (mock: nothing persists).
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
          disabled: !_canSubmit,
          onPressed: _canSubmit ? controller.create : null,
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
}
