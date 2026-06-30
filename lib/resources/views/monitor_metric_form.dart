import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'monitor_metrics_support.dart';
import '../../app/mocks/status.dart';
import '../../ui/components/ai_insight/index.dart';
import '../../ui/components/status_dot/index.dart';

/// The simulated "fetch & test" lifecycle for the extraction preview.
///
/// Mirrors the React `testStatus` union (`"idle" | "fetching" | "done"`). The
/// transition `idle -> fetching -> done` is driven by a simulated 800ms delay,
/// never a real network request.
enum MetricTestStatus {
  /// No test has run; the panel shows a prompt to fetch a sample.
  idle,

  /// A simulated fetch is in flight; the panel shows a spinner row.
  fetching,

  /// The fetch resolved; the panel shows the resolved value or a not-found
  /// state plus the sample JSON body.
  done,
}

/// **The metric create/edit form — a magic_starter BottomSheet body.**
///
/// A faithful Flutter port of the React `MonitorMetricsTab` create/edit form
/// (lines 388-495). It renders the full metric definition surface inside a
/// [BottomSheet]: Name, Key (auto-slugified from Name until manually edited),
/// Type, Source + Unit, the extraction path, numeric thresholds with an
/// [AiInsight] suggestion, and a simulated extraction-test panel.
///
/// The widget is the *body* of the sheet and owns the footer (Cancel / Save)
/// so the footer can read the form's live validation state directly. Open it
/// with the [show] convenience entry point, which wraps it in a
/// `magic_starter` [BottomSheet]:
///
/// ```dart
/// MonitorMetricForm.show(
///   context,
///   initial: kEmptyMetricForm,
///   isEdit: false,
///   onSave: (form) => setState(() => metrics.add(form)),
/// );
/// ```
///
/// All thresholds round-trip through [MetricForm] as raw strings, so partially
/// typed numeric input is preserved without lossy conversion. No color is
/// hardcoded: every tone flows through semantic alias keys (the monitoring
/// status families from `uptizm_status_tokens.dart` and the standard
/// `DESIGN.md` roles).
class MonitorMetricForm extends StatefulWidget {
  /// The initial form values (an empty form for create, a seeded form for
  /// edit).
  final MetricForm initial;

  /// Whether the form is editing an existing metric (drives titles and the
  /// Save label).
  final bool isEdit;

  /// Called with the validated form when the user taps Save.
  final void Function(MetricForm form) onSave;

  /// Called when the user taps Cancel.
  final VoidCallback onCancel;

  /// Creates a [MonitorMetricForm].
  const MonitorMetricForm({
    super.key,
    required this.initial,
    required this.isEdit,
    required this.onSave,
    required this.onCancel,
  });

  /// Opens the form inside a `magic_starter` [BottomSheet] and resolves when it
  /// is dismissed.
  ///
  /// The sheet title reflects [isEdit]. [onSave] and [onCancel] both close the
  /// sheet via [Navigator.pop] after invoking the caller's callback, mirroring
  /// the React sheet's `onOpenChange(false)` close path.
  static Future<void> show(
    BuildContext context, {
    required MetricForm initial,
    required bool isEdit,
    required void Function(MetricForm form) onSave,
  }) {
    return BottomSheet.show<void>(
      context,
      title: isEdit
          ? trans('uptizm.monitors.metrics_form_edit_title')
          : trans('uptizm.monitors.metrics_form_new_title'),
      body: Builder(
        builder: (sheetContext) => MonitorMetricForm(
          initial: initial,
          isEdit: isEdit,
          onSave: (form) {
            onSave(form);
            Navigator.of(sheetContext).pop();
          },
          onCancel: () => Navigator.of(sheetContext).pop(),
        ),
      ),
    );
  }

  @override
  State<MonitorMetricForm> createState() => _MonitorMetricFormState();
}

class _MonitorMetricFormState extends State<MonitorMetricForm> {
  /// The live, string-backed form model. Mutated through [_set] / [_onLabel] /
  /// [_onKey] so every edit also resets [_testStatus] to idle (matching the
  /// React `set` patch behavior).
  late MetricForm _form;

  /// Tracks whether the user has manually edited the Key field. Once true, the
  /// Key stops auto-following the Name slug (React `keyEdited`).
  late bool _keyEdited;

  /// The simulated extraction-test lifecycle.
  MetricTestStatus _testStatus = MetricTestStatus.idle;

  /// The Key field is filled programmatically (auto-slugify) while remaining
  /// user-editable, so it needs a controller to reflect the computed value.
  late final TextEditingController _keyController;

  /// Warn and Critical are filled programmatically by the AI "Use" action, so
  /// they need controllers to reflect the suggested thresholds.
  late final TextEditingController _warnController;
  late final TextEditingController _criticalController;

  @override
  void initState() {
    super.initState();
    _form = widget.initial;
    // In edit mode the key already exists, so it is treated as user-authored
    // and never auto-overwritten by the Name slug (React `openEdit`).
    _keyEdited = widget.isEdit;
    _keyController = TextEditingController(text: _form.key);
    _warnController = TextEditingController(text: _form.warn);
    _criticalController = TextEditingController(text: _form.critical);
  }

  @override
  void dispose() {
    _keyController.dispose();
    _warnController.dispose();
    _criticalController.dispose();
    super.dispose();
  }

  /// Applies a partial update to [_form] and resets the test lifecycle.
  ///
  /// Mirrors the React `set(patch)` helper: any field change invalidates a
  /// previously fetched test result.
  void _set(MetricForm next) {
    setState(() {
      _form = next;
      _testStatus = MetricTestStatus.idle;
    });
  }

  /// Updates the Name and, while the Key has not been manually edited, mirrors
  /// the slugified Name into the Key field (React `onLabel`).
  void _onLabel(String value) {
    setState(() {
      final String nextKey = _keyEdited ? _form.key : slugify(value);
      _form = _form.copyWith(label: value, key: nextKey);
      _testStatus = MetricTestStatus.idle;
      if (!_keyEdited && _keyController.text != nextKey) {
        _keyController.text = nextKey;
      }
    });
  }

  /// Marks the Key as manually edited and stores the raw value (React `onKey`).
  void _onKey(String value) {
    setState(() {
      _keyEdited = true;
      _form = _form.copyWith(key: value);
      _testStatus = MetricTestStatus.idle;
    });
  }

  /// Fills Warn and Critical from the AI suggestion and syncs their
  /// controllers (React `set({ warn, critical })` on the "Use" action).
  void _applySuggestion(int suggWarn, int suggCrit) {
    final String warn = suggWarn.toString();
    final String critical = suggCrit.toString();
    _warnController.text = warn;
    _criticalController.text = critical;
    _set(_form.copyWith(warn: warn, critical: critical));
  }

  /// Runs the simulated extraction test: `idle -> fetching -> done` over an
  /// 800ms delay. No network request is performed (React `runTest`).
  void _runTest() {
    setState(() => _testStatus = MetricTestStatus.fetching);
    Future<void>.delayed(const Duration(milliseconds: 800), () {
      if (!mounted) return;
      setState(() => _testStatus = MetricTestStatus.done);
    });
  }

  // ---------------------------------------------------------------------------
  // Computed state (React lines 251-264).
  // ---------------------------------------------------------------------------

  bool get _isNumeric => _form.type == 'numeric';

  bool get _needsPath => _form.source != 'http_status';

  bool get _keyValid => _form.key.isEmpty || kKeyRe.hasMatch(_form.key);

  bool get _ruleReady => !_needsPath || _form.path.trim().isNotEmpty;

  bool get _canSave => _form.label.trim().isNotEmpty && _form.key.trim().isNotEmpty && _keyValid && _ruleReady;

  num? get _resolved => _form.source == 'json' ? resolveJson(_form.path) : null;

  bool get _found => _form.source == 'json' ? _resolved != null : true;

  num get _numValue => _form.source == 'http_status' ? 200 : _resolved ?? fallbackValue(_form.unit);

  String get _valueText => switch (_form.type) {
    'status' => 'operational',
    'string' => 'ok',
    _ => fmt(_numValue, _form.unit),
  };

  StatusKey get _band => _isNumeric ? bandOf(_numValue, _form.warn, _form.critical, _form.direction) : StatusKey.up;

  int get _suggWarn => _form.direction == 'low' ? (_numValue * 0.75).round() : (_numValue * 1.15).round();

  int get _suggCrit => _form.direction == 'low' ? (_numValue * 0.5).round() : (_numValue * 1.3).round();

  // ---------------------------------------------------------------------------
  // Build.
  // ---------------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-4',
      children: [
        // 1. Name + Key text fields (Key auto-slugifies from Name).
        _buildNameField(),
        _buildKeyField(),

        // 2. Type segmented control.
        MagicFormField(label: trans('uptizm.monitors.metrics_form_type_label'), child: _buildTypeControl()),

        // 3. Source + Unit selects (Unit numeric-only, 2-col responsive).
        _buildSourceUnitRow(),

        // 4. Extraction path (hidden for http_status).
        if (_needsPath) _buildPathField(),

        // 5. Numeric-only block: direction, thresholds, AI suggestion.
        if (_isNumeric) ...[
          MagicFormField(label: trans('uptizm.monitors.metrics_form_direction_label'), child: _buildDirectionControl()),
          _buildThresholdRow(),
          _buildAiInsight(),
        ],

        // 6. Test extraction panel (when the rule is ready).
        if (_ruleReady) _buildTestPanel(),

        // 7. Footer: Cancel + Save.
        _buildFooter(),
      ],
    );
  }

  /// Builds the Name field.
  Widget _buildNameField() {
    return MagicFormField(
      label: trans('uptizm.monitors.metrics_form_name_label'),
      child: Input(
        value: _form.label,
        onChanged: _onLabel,
        placeholder: trans('uptizm.monitors.metrics_form_name_placeholder'),
      ),
    );
  }

  /// Builds the Key field: monospace, with the slugify validation error shown
  /// inline when the key is malformed.
  Widget _buildKeyField() {
    return MagicFormField(
      label: trans('uptizm.monitors.metrics_form_key_label'),
      hint: trans('uptizm.monitors.metrics_form_key_hint'),
      error: _keyValid ? null : trans('uptizm.monitors.metrics_form_key_error'),
      child: Input(
        controller: _keyController,
        onChanged: _onKey,
        state: _keyValid ? InputState.normal : InputState.error,
        placeholder: trans('uptizm.monitors.metrics_form_key_placeholder'),
        className: _monoInputClass(_keyValid ? InputState.normal : InputState.error),
      ),
    );
  }

  /// Builds the Type segmented control.
  ///
  /// [SegmentedControl] takes `options: List<String>` + `selectedIndex`, so the
  /// labels are projected from [kMetricTypes] and the change handler maps the
  /// tapped index back to the option's machine value.
  Widget _buildTypeControl() {
    return SegmentedControl<String>(
      options: kMetricTypes.map((o) => o.label).toList(),
      selectedIndex: _indexOfValue(kMetricTypes, _form.type),
      size: SegmentedControlSize.sm,
      onChanged: (index) => _set(_form.copyWith(type: kMetricTypes[index].value)),
    );
  }

  /// Builds the Source + Unit row (Unit only when the type is numeric).
  Widget _buildSourceUnitRow() {
    return WDiv(
      className: 'grid gap-4 sm:grid-cols-2',
      children: [
        MagicFormField(
          label: trans('uptizm.monitors.metrics_form_source_label'),
          child: Select<String>(
            value: _form.source,
            options: _selectOptions(kMetricSources),
            onChange: (value) {
              if (value != null) _set(_form.copyWith(source: value));
            },
          ),
        ),
        if (_isNumeric)
          MagicFormField(
            label: trans('uptizm.monitors.metrics_form_unit_label'),
            child: Select<String>(
              value: _form.unit,
              options: _selectOptions(kMetricUnits),
              onChange: (value) {
                if (value != null) _set(_form.copyWith(unit: value));
              },
            ),
          ),
      ],
    );
  }

  /// Builds the extraction-path field, with the per-source placeholder and
  /// hint.
  Widget _buildPathField() {
    return MagicFormField(
      label: trans('uptizm.monitors.metrics_form_extraction_label'),
      hint: kPathHint[_form.source],
      child: Input(
        value: _form.path,
        onChanged: (value) => _set(_form.copyWith(path: value)),
        placeholder: kPathPlaceholder[_form.source] ?? '',
        className: _monoInputClass(InputState.normal),
      ),
    );
  }

  /// Builds the Threshold direction segmented control.
  Widget _buildDirectionControl() {
    return SegmentedControl<String>(
      options: kMetricDirections.map((o) => o.label).toList(),
      selectedIndex: _indexOfValue(kMetricDirections, _form.direction),
      size: SegmentedControlSize.sm,
      onChanged: (index) => _set(_form.copyWith(direction: kMetricDirections[index].value)),
    );
  }

  /// Builds the Warn + Critical threshold row (2-col, monospace inputs).
  Widget _buildThresholdRow() {
    return WDiv(
      className: 'grid grid-cols-2 gap-4',
      children: [
        MagicFormField(
          label: trans('uptizm.monitors.metrics_form_warn_label'),
          child: Input(
            controller: _warnController,
            onChanged: (value) => _set(_form.copyWith(warn: value)),
            placeholder: '80',
            className: _monoInputClass(InputState.normal),
          ),
        ),
        MagicFormField(
          label: trans('uptizm.monitors.metrics_form_critical_label'),
          child: Input(
            controller: _criticalController,
            onChanged: (value) => _set(_form.copyWith(critical: value)),
            placeholder: '95',
            className: _monoInputClass(InputState.normal),
          ),
        ),
      ],
    );
  }

  /// Builds the AI threshold-suggestion insight with a "Use" action that fills
  /// Warn and Critical from the computed suggestions.
  Widget _buildAiInsight() {
    final int suggWarn = _suggWarn;
    final int suggCrit = _suggCrit;
    return AiInsight(
      action: Button(
        intent: ButtonIntent.secondary,
        size: ButtonSize.sm,
        onPressed: () => _applySuggestion(suggWarn, suggCrit),
        child: WText(trans('uptizm.monitors.metrics_form_ai_use')),
      ),
      child: WText(
        trans('uptizm.monitors.metrics_form_ai_suggestion', {
          'value': fmt(_numValue, _form.unit),
          'warn': suggWarn.toString(),
          'crit': suggCrit.toString(),
        }),
      ),
    );
  }

  /// Builds the "Test extraction" panel: a header with the fetch button, then
  /// one of the idle / fetching / done states.
  Widget _buildTestPanel() {
    return WDiv(
      className: 'flex flex-col gap-2 border-t border-color-border pt-4',
      children: [
        WDiv(
          className: 'flex items-center justify-between gap-3',
          children: [
            WText(trans('uptizm.monitors.metrics_form_test_title'), className: 'text-sm font-medium text-fg'),
            Button(
              intent: ButtonIntent.secondary,
              size: ButtonSize.sm,
              disabled: _testStatus == MetricTestStatus.fetching,
              isLoading: _testStatus == MetricTestStatus.fetching,
              onPressed: _runTest,
              child: WText(_fetchButtonLabel()),
            ),
          ],
        ),
        ..._buildTestBody(),
      ],
    );
  }

  /// Builds the state-dependent body of the test panel.
  List<Widget> _buildTestBody() {
    return switch (_testStatus) {
      MetricTestStatus.idle => [
        WText(trans('uptizm.monitors.metrics_form_test_hint'), className: 'text-xs text-fg-muted'),
      ],
      MetricTestStatus.fetching => [
        WText(trans('uptizm.monitors.metrics_form_fetching_sample'), className: 'text-sm text-fg-muted'),
      ],
      MetricTestStatus.done => _found ? [_buildResolvedPanel()] : [_buildNotFoundPanel()],
    };
  }

  /// Builds the resolved panel: the "Resolved" tag, the value (with a
  /// [StatusDot] for numeric metrics), and the sample JSON block.
  Widget _buildResolvedPanel() {
    return WDiv(
      className: 'flex flex-col gap-2 rounded-lg border border-color-border bg-up-soft p-3',
      children: [
        WDiv(
          className: 'flex items-center justify-between gap-3',
          children: [
            WText(
              trans('uptizm.monitors.metrics_form_resolved'),
              className: 'text-xs font-medium uppercase tracking-wide text-up-soft-foreground',
            ),
            WDiv(
              className: 'flex items-center gap-2',
              children: [
                if (_isNumeric) StatusDot(_band),
                WText(_valueText, className: 'font-mono text-lg tabular-nums text-fg'),
              ],
            ),
          ],
        ),
        _buildSampleBlock(_form.source == 'http_status' ? 'HTTP/1.1 200 OK' : kMetricSampleJson),
      ],
    );
  }

  /// Builds the not-found panel: the unresolved-path message and the sample
  /// JSON block.
  Widget _buildNotFoundPanel() {
    return WDiv(
      className: 'flex flex-col gap-2 rounded-lg border border-color-border bg-down-soft p-3',
      children: [
        WText(
          trans('uptizm.monitors.metrics_test_not_found_body', {'path': _form.path}),
          className: 'text-sm text-down-soft-foreground',
        ),
        _buildSampleBlock(kMetricSampleJson),
      ],
    );
  }

  /// Builds the `<pre>`-style monospace sample block.
  Widget _buildSampleBlock(String content) {
    return WDiv(
      className: 'max-h-28 overflow-auto rounded-md bg-surface p-2',
      child: WText(content, className: 'font-mono text-xs leading-relaxed text-fg-muted'),
    );
  }

  /// Builds the footer: Cancel (left) + Save (right, disabled unless savable).
  Widget _buildFooter() {
    return WDiv(
      className: 'mt-1 flex flex-col gap-3 sm:flex-row sm:justify-end',
      children: [
        Button(
          intent: ButtonIntent.secondary,
          className: _footerButtonClass(ButtonIntent.secondary),
          onPressed: widget.onCancel,
          child: WText(trans('uptizm.common.cancel')),
        ),
        Button(
          className: _footerButtonClass(ButtonIntent.primary),
          disabled: !_canSave,
          onPressed: _canSave ? () => widget.onSave(_form) : null,
          child: WText(
            widget.isEdit
                ? trans('uptizm.monitors.metrics_form_save_edit')
                : trans('uptizm.monitors.metrics_form_save_create'),
          ),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Small helpers.
  // ---------------------------------------------------------------------------

  /// Resolves the fetch button label for the current [_testStatus].
  String _fetchButtonLabel() {
    return switch (_testStatus) {
      MetricTestStatus.fetching => trans('uptizm.monitors.metrics_form_fetching'),
      MetricTestStatus.done => trans('uptizm.monitors.metrics_form_test_again'),
      MetricTestStatus.idle => trans('uptizm.monitors.metrics_form_fetch_test'),
    };
  }

  /// Returns the zero-based index of [value] in [options], or 0 when absent.
  int _indexOfValue(List<MetricOption> options, String value) {
    final int index = options.indexWhere((o) => o.value == value);
    return index < 0 ? 0 : index;
  }

  /// Projects a [MetricOption] list into [SelectOption]s (label -> value).
  List<SelectOption<String>> _selectOptions(List<MetricOption> options) {
    return options.map((o) => SelectOption<String>(value: o.value, label: o.label)).toList();
  }

  /// Resolves the monospace Input className by appending `font-mono` to the
  /// recipe output for [state], since [Input] has no `font-mono` flag and a
  /// caller `className` bypasses the recipe entirely.
  String _monoInputClass(InputState state) {
    return '${inputRecipe(variants: {kInputStateAxis: state.name})} font-mono';
  }

  /// Resolves the full-width-on-mobile footer button className by appending the
  /// responsive width tokens to the recipe output for [intent].
  String _footerButtonClass(ButtonIntent intent) {
    final String base = buttonRecipe(variants: {kButtonIntentAxis: intent.name, kButtonSizeAxis: ButtonSize.md.name});
    return '$base w-full sm:w-auto';
  }
}
