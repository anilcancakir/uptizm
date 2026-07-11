import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'monitor_form_support.dart';
import 'monitor_metrics_support.dart';
import '../../../app/mocks/billing.dart';
import '../../../app/mocks/monitors.dart';
import '../../../app/mocks/oncall.dart';
import '../../../ui/components/key_value_editor/key_value_editor.dart';
import '../../../ui/components/region_picker/region_picker.dart';

/// **The monitor configuration form (fields + submit row).**
///
/// A faithful Flutter port of the React `MonitorForm.tsx`. It renders the full
/// monitor definition surface inside a surface [Card], with an optional
/// [banner] slot above it (used by the AI-assisted flow to show its summary).
/// Simple by default; the "Advanced configuration" switch reveals HTTP method,
/// request headers, request body, and timeout.
///
/// The form is self-contained state: every field round-trips through this
/// widget's [State] and the [onSubmit] / [onCancel] callbacks report the user's
/// intent. The check-interval [Select] gates options below the current billing
/// tier's fastest allowed interval, labelling each locked option with the
/// cheapest plan that unlocks it (`currentLimits.checkIntervalSec` +
/// [smallestPlanWhere]).
///
/// No color is hardcoded: every tone flows through semantic alias keys, and no
/// footer button carries `w-full` (a full-width button inside a `flex-row`
/// forces unbounded width and aborts the row's layout).
///
/// ```dart
/// MonitorForm(
///   submitLabel: trans('uptizm.monitors.form_submit_create'),
///   onSubmit: (fields) => _create(fields),
///   onCancel: () => Navigator.of(context).pop(),
/// )
/// ```
class MonitorForm extends StatefulWidget {
  /// Initial monitor name. Defaults to empty (React `initialName = ""`).
  final String initialName;

  /// Initial monitor type token (`http` / `ping` / `tcp` / `dns`). Defaults to
  /// `http` (React `initialType = "http"`).
  final String initialType;

  /// Initial monitored URL or host. Defaults to empty (React `initialUrl = ""`).
  final String initialUrl;

  /// Initial check-interval token (`10s` / `30s` / `1m` / `5m`). Defaults to
  /// `30s` (React `initialInterval = "30s"`).
  final String initialInterval;

  /// Initial selected probe-region values. Defaults to `['us-east', 'eu-west']`
  /// (React `initialRegions`).
  final List<String> initialRegions;

  /// Initial request headers. Defaults to a single `Authorization: Bearer …`
  /// row (React `initialHeaders`).
  final List<KeyValueRow> initialHeaders;

  /// Initial escalation-policy id. Defaults to [defaultEscalationPolicy.id]
  /// (React `initialPolicy`).
  final String initialPolicy;

  /// Initial uptime SLO target as a percentage string, or `''` for none.
  /// Defaults to `99.9` (React `initialSlo = "99.9"`).
  final String initialSlo;

  /// Open the advanced section on mount (the AI flow pre-fills advanced
  /// fields). Defaults to `false`.
  final bool startAdvanced;

  /// Optional content rendered above the form card (e.g. the AI summary
  /// banner). Maps to the React `banner` slot.
  final Widget? banner;

  /// Label for the primary submit button (e.g. "Create monitor").
  final String submitLabel;

  /// Called when the user taps the primary submit button, with the field map
  /// assembled by [_MonitorFormState.buildFields] (the backend request
  /// shape). The caller decides whether that fires a create or a save.
  final void Function(Map<String, dynamic> fields) onSubmit;

  /// Called when the user taps Cancel.
  final VoidCallback onCancel;

  /// Creates a [MonitorForm].
  MonitorForm({
    super.key,
    this.initialName = '',
    this.initialType = 'http',
    this.initialUrl = '',
    this.initialInterval = '30s',
    this.initialRegions = const ['us-east', 'eu-west'],
    this.initialHeaders = const [
      KeyValueRow(key: 'Authorization', value: 'Bearer …'),
    ],
    String? initialPolicy,
    this.initialSlo = '99.9',
    this.startAdvanced = false,
    this.banner,
    required this.submitLabel,
    required this.onSubmit,
    required this.onCancel,
  }) : initialPolicy = initialPolicy ?? _defaultPolicyId;

  /// The default escalation-policy id, resolved once. A non-const default
  /// cannot live in the parameter list, so it is funnelled through the
  /// constructor body.
  static final String _defaultPolicyId = defaultEscalationPolicy.id;

  @override
  State<MonitorForm> createState() => _MonitorFormState();
}

class _MonitorFormState extends State<MonitorForm> {
  /// Monitor name (React `name`).
  late String _name;

  /// Monitor type token (React `type`).
  late String _type;

  /// Monitored URL or host (React `url`).
  late String _url;

  /// Check-interval token (React `intervalValue`).
  late String _intervalValue;

  /// Selected probe-region values (React `regions`).
  late List<String> _regions;

  /// Whether the advanced section is expanded (React `advanced`).
  late bool _advanced;

  /// HTTP method token for the advanced section (React `method`).
  String _method = 'GET';

  /// Request headers for the advanced section (React `headers`).
  late List<KeyValueRow> _headers;

  /// Request body for the advanced section (React `body`).
  String _body = '';

  /// Timeout in seconds, kept as a raw string (React `timeoutMs`).
  String _timeoutMs = '30';

  /// Alert when the monitor goes down (React `notifyDown`).
  bool _notifyDown = true;

  /// Alert when the monitor recovers (React `notifyRecover`).
  bool _notifyRecover = true;

  /// Selected escalation-policy id (React `policy`).
  late String _policy;

  /// Selected SLO target string (React `slo`).
  late String _slo;

  /// AI-assist mode token (`off` / `suggest`). Defaults to `off`; there is no
  /// React source counterpart, this is a new uptizm-only control.
  String _aiMode = 'off';

  /// The probe regions projected into the [RegionPicker]'s [Region] shape,
  /// computed once from the static [allRegions] fixture.
  late final List<Region> _regionOptions = probeRegionsToRegions(allRegions);

  @override
  void initState() {
    super.initState();
    _name = widget.initialName;
    _type = widget.initialType;
    _url = widget.initialUrl;
    _intervalValue = widget.initialInterval;
    _regions = List<String>.from(widget.initialRegions);
    _advanced = widget.startAdvanced;
    _headers = List<KeyValueRow>.from(widget.initialHeaders);
    _policy = widget.initialPolicy;
    _slo = widget.initialSlo;
  }

  /// Whether the monitor is an HTTP check (React `isHttp`). Gates the advanced
  /// method/headers/body fields.
  bool get _isHttp => _type == 'http';

  // ---------------------------------------------------------------------------
  // Build.
  // ---------------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6',
      children: [
        // 1. Optional banner above the card (AI summary slot).
        if (widget.banner != null) widget.banner!,

        // 2. The form card (surface variant) with the field stack.
        MSCard(
          variant: CardVariant.surface,
          child: WDiv(
            className: 'flex flex-col gap-5',
            children: [
              _buildNameField(),
              _buildTypeField(),
              _buildUrlField(),
              _buildIntervalField(),
              _buildRegionsField(),
              _buildSloField(),
              _buildAiModeField(),
              _buildNotificationsSection(),
              _buildAdvancedToggle(),
              if (_advanced) ..._buildAdvancedSection(),
            ],
          ),
        ),

        // 3. Footer: Cancel + Submit, right-aligned, auto-width.
        _buildFooter(),
      ],
    );
  }

  /// Builds the Name field.
  Widget _buildNameField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_field_name_label'),
      child: MSInput(
        value: _name,
        onChanged: (value) => setState(() => _name = value),
        placeholder: trans('uptizm.monitors.form_field_name_placeholder'),
      ),
    );
  }

  /// Builds the Monitor-type segmented control.
  ///
  /// [SegmentedControl] takes `options: List<String>` + `selectedIndex`, so the
  /// labels are projected from [kMonitorTypes] and the change handler maps the
  /// tapped index back to the option's machine value.
  Widget _buildTypeField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_type_label'),
      child: MSSegmentedControl<String>(
        options: kMonitorTypes.map((o) => o.label).toList(),
        selectedIndex: _indexOfValue(kMonitorTypes, _type),
        onChanged: (index) =>
            setState(() => _type = kMonitorTypes[index].value),
      ),
    );
  }

  /// Builds the URL field, with the hint switching on the HTTP/non-HTTP type.
  Widget _buildUrlField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_url_label'),
      hint: _isHttp
          ? trans('uptizm.monitors.form_url_hint_http')
          : trans('uptizm.monitors.form_url_hint_other'),
      child: MSInput(
        value: _url,
        onChanged: (value) => setState(() => _url = value),
        placeholder: trans('uptizm.monitors.form_url_placeholder'),
      ),
    );
  }

  /// Builds the Check-interval select.
  ///
  /// Each option whose interval (in seconds) is faster than the current plan's
  /// fastest allowed interval is [SelectOption.disabled] and its label is
  /// suffixed with the cheapest plan that unlocks it (React lines 148-157).
  Widget _buildIntervalField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_interval_label'),
      child: MSSelect<String>(
        value: _intervalValue,
        options: kCheckIntervals.map(_intervalOption).toList(),
        onChange: (value) {
          if (value != null) setState(() => _intervalValue = value);
        },
      ),
    );
  }

  /// Projects a check-interval [MetricOption] into a [SelectOption], locking and
  /// relabelling it when its interval is faster than the plan allows.
  SelectOption<String> _intervalOption(MetricOption option) {
    final int seconds = kIntervalSeconds[option.value] ?? 0;
    final bool locked = seconds < currentLimits.checkIntervalSec;
    if (!locked) {
      return SelectOption<String>(value: option.value, label: option.label);
    }

    // The cheapest plan whose fastest interval reaches this option unlocks it.
    final String requiredPlan = smallestPlanWhere(
      (limits) => limits.checkIntervalSec <= seconds,
    ).name;
    return SelectOption<String>(
      value: option.value,
      label: '${option.label} · $requiredPlan',
      disabled: true,
    );
  }

  /// Builds the Regions multi-select grid.
  Widget _buildRegionsField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_regions_label'),
      hint: trans('uptizm.monitors.form_regions_hint'),
      child: RegionPicker(
        regions: _regionOptions,
        value: _regions,
        onChanged: (next) => setState(() => _regions = next),
      ),
    );
  }

  /// Builds the Uptime SLO target select.
  Widget _buildSloField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_slo_label'),
      hint: trans('uptizm.monitors.form_slo_hint'),
      child: MSSelect<String>(
        value: _slo,
        options: kSloTargets
            .map((o) => SelectOption<String>(value: o.value, label: o.label))
            .toList(),
        onChange: (value) {
          if (value != null) setState(() => _slo = value);
        },
      ),
    );
  }

  /// Builds the AI-assist mode segmented control.
  ///
  /// `Off` keeps the monitor fully manual; `Suggest` lets Uptizm AI post
  /// suggested incidents to the dashboard inbox for an operator to approve or
  /// dismiss (graduated trust: nothing is ever auto-created). Fully
  /// autonomous `Auto` mode is deliberately not offered here.
  Widget _buildAiModeField() {
    return MSFormField(
      label: trans('uptizm.monitors.form_ai_mode_label'),
      hint: trans('uptizm.monitors.form_ai_mode_hint'),
      child: MSSegmentedControl<String>(
        options: kAiModes.map((o) => o.label).toList(),
        selectedIndex: _indexOfValue(kAiModes, _aiMode),
        onChanged: (index) => setState(() => _aiMode = kAiModes[index].value),
      ),
    );
  }

  /// Builds the Notifications block: header, the two alert switches, and the
  /// escalation-policy select.
  Widget _buildNotificationsSection() {
    return WDiv(
      className: 'flex flex-col gap-3 border-t border-color-border pt-5',
      children: [
        WDiv(
          className: 'flex flex-col gap-0.5',
          children: [
            WText(
              trans('uptizm.monitors.form_notifications_title'),
              className: 'text-sm font-medium text-fg',
            ),
            WText(
              trans('uptizm.monitors.form_notifications_hint'),
              className: 'text-xs text-fg-muted',
            ),
          ],
        ),
        _buildSwitchRow(
          label: trans('uptizm.monitors.form_alert_down'),
          value: _notifyDown,
          onChanged: (value) => setState(() => _notifyDown = value),
        ),
        _buildSwitchRow(
          label: trans('uptizm.monitors.form_alert_recover'),
          value: _notifyRecover,
          onChanged: (value) => setState(() => _notifyRecover = value),
        ),
        MSFormField(
          label: trans('uptizm.monitors.form_escalation_label'),
          hint: trans('uptizm.monitors.form_escalation_hint'),
          child: MSSelect<String>(
            value: _policy,
            options: escalationPolicies
                .map((p) => SelectOption<String>(value: p.id, label: p.name))
                .toList(),
            onChange: (value) {
              if (value != null) setState(() => _policy = value);
            },
          ),
        ),
      ],
    );
  }

  /// Builds the Advanced-configuration toggle: the switch row plus its hint.
  Widget _buildAdvancedToggle() {
    return WDiv(
      className: 'flex flex-col gap-1.5 border-t border-color-border pt-5',
      children: [
        _buildSwitchRow(
          label: trans('uptizm.monitors.form_advanced_label'),
          value: _advanced,
          onChanged: (value) => setState(() => _advanced = value),
        ),
        WText(
          trans('uptizm.monitors.form_advanced_hint'),
          className: 'text-xs text-fg-muted',
        ),
      ],
    );
  }

  /// Builds the advanced section: HTTP method, request headers, request body,
  /// and timeout.
  ///
  /// Method and headers render for HTTP monitors only; the body renders only
  /// for HTTP POST/PUT (React lines 257-273). Timeout always renders.
  List<Widget> _buildAdvancedSection() {
    final bool showBody = _isHttp && (_method == 'POST' || _method == 'PUT');
    return [
      if (_isHttp)
        MSFormField(
          label: trans('uptizm.monitors.form_method_label'),
          child: MSSegmentedControl<String>(
            options: kHttpMethods.map((o) => o.label).toList(),
            selectedIndex: _indexOfValue(kHttpMethods, _method),
            onChanged: (index) =>
                setState(() => _method = kHttpMethods[index].value),
          ),
        ),
      if (_isHttp)
        MSFormField(
          label: trans('uptizm.monitors.form_headers_label'),
          hint: trans('uptizm.monitors.form_headers_hint'),
          child: KeyValueEditor(
            value: _headers,
            onChanged: (next) => setState(() => _headers = next),
          ),
        ),
      if (showBody)
        MSFormField(
          label: trans('uptizm.monitors.form_body_label'),
          child: MSTextarea(
            value: _body,
            onChanged: (value) => setState(() => _body = value),
            placeholder: trans('uptizm.monitors.form_body_placeholder'),
          ),
        ),
      MSFormField(
        label: trans('uptizm.monitors.form_timeout_label'),
        hint: trans('uptizm.monitors.form_timeout_hint'),
        child: MSInput(
          value: _timeoutMs,
          onChanged: (value) => setState(() => _timeoutMs = value),
          type: InputType.number,
          className: 'max-w-32',
        ),
      ),
    ];
  }

  /// Builds the footer: Cancel + Submit, right-aligned.
  ///
  /// A Wind `flex flex-row justify-end gap-3` row of AUTO-width buttons. The
  /// buttons carry no `w-full` (Wind `width: double.infinity`): a full-width
  /// button inside a `flex-row` forces an unbounded width and aborts the row's
  /// layout ("RenderBox was not laid out"). Auto-width buttons fit at any width
  /// without that hazard.
  Widget _buildFooter() {
    return WDiv(
      className: 'flex flex-row justify-end gap-3',
      children: [
        MSButton(
          intent: ButtonIntent.secondary,
          onPressed: widget.onCancel,
          child: WText(trans('uptizm.monitors.form_cancel')),
        ),
        MSButton(
          onPressed: () => widget.onSubmit(buildFields()),
          child: WText(widget.submitLabel),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Field collector.
  // ---------------------------------------------------------------------------

  /// Assembles the backend request field map from this state's fields.
  ///
  /// The keys match the snake_case wire shape `StoreMonitorRequest` /
  /// `UpdateMonitorRequest` validate on the backend: `check_interval_sec` is
  /// converted from the interval token via [kIntervalSeconds] and
  /// `timeout_sec` is parsed straight from [_timeoutMs] (the field is
  /// labelled "Timeout (seconds)"; despite its variable name it already holds
  /// seconds, not milliseconds). Fields with no dedicated UI control yet
  /// (`auth_config`, `expected_status_code`, `tags`, the status-page and SSL
  /// toggles) fall back to sensible request-shape defaults rather than being
  /// omitted, so a create/save always posts a complete, valid payload.
  Map<String, dynamic> buildFields() {
    return {
      'name': _name,
      'url': _url,
      'type': _type,
      'method': _method,
      'request_headers': _headersToMap(_headers),
      'request_body': _body,
      'auth_config': null,
      'expected_status_code': null,
      'check_interval_sec': kIntervalSeconds[_intervalValue] ?? 30,
      'timeout_sec': int.tryParse(_timeoutMs) ?? 30,
      'regions': _regions,
      'tags': const <String>[],
      'slo_target': _slo.isEmpty ? null : double.tryParse(_slo),
      'ai_mode': _aiMode,
      'show_on_status_page': true,
      'only_show_if_degraded': false,
      'alert_on_down': _notifyDown,
      'alert_on_recover': _notifyRecover,
      'ssl_tracking': _url.startsWith('https://'),
      'ssl_alert_threshold_days': 14,
    };
  }

  /// Converts the ordered [KeyValueRow] list into a plain map, matching the
  /// `request_headers` wire shape. A row with a blank key (a trailing empty
  /// row left by the editor) is skipped rather than sent as `"": "value"`.
  Map<String, String> _headersToMap(List<KeyValueRow> rows) {
    final Map<String, String> map = {};
    for (final KeyValueRow row in rows) {
      if (row.key.isEmpty) continue;
      map[row.key] = row.value;
    }
    return map;
  }

  // ---------------------------------------------------------------------------
  // Small helpers.
  // ---------------------------------------------------------------------------

  /// Builds a labelled switch row: the [Switch] toggle followed by its text
  /// label. The Dart [Switch] is toggle-only, so the label is rendered beside
  /// it (the React `Switch` carried an inline `label` prop).
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
