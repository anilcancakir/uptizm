import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'monitor_metrics_support.dart';
import '../../../app/enums/status_key.dart';
import '../../../ui/components/ai_insight/index.dart';
import '../../../ui/components/status_dot/index.dart';

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

  /// Called with the form when the user taps Save (once the client-side
  /// required checks pass).
  ///
  /// Returns the backend field errors keyed by the wire field name the write
  /// posts (`label`, `key`, `extraction_path`, `warn_bound`,
  /// `critical_bound`, ...), single message per field, so a server 422 renders
  /// inline under the matching field. An empty map means success (the sheet has
  /// already been closed by [show]). Any returned key the form does not own is
  /// surfaced as a generic failure toast.
  final Future<Map<String, String>> Function(MetricForm form) onSave;

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
    required Future<Map<String, String>> Function(MetricForm form) onSave,
  }) {
    return MSBottomSheet.show<void>(
      context,
      title: isEdit
          ? trans('uptizm.monitors.metrics_form_edit_title')
          : trans('uptizm.monitors.metrics_form_new_title'),
      body: Builder(
        builder: (sheetContext) => MonitorMetricForm(
          initial: initial,
          isEdit: isEdit,
          // The sheet closes only on a successful write (an empty error map),
          // mirroring the monitor form's "navigate only on success"; a server
          // 422 hands its field errors back so the form keeps the sheet open
          // and paints them inline.
          onSave: (form) async {
            final Map<String, String> errors = await onSave(form);
            if (errors.isEmpty && sheetContext.mounted) {
              Navigator.of(sheetContext).pop();
            }
            return errors;
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

  /// Inline validation error for the Name field, or null when it is valid.
  ///
  /// Set on submit when the required Name is blank (a check the client can make
  /// before any round trip), and by a server 422 that rejects `label`. Cleared
  /// when the user edits the field.
  String? _labelError;

  /// Inline validation error for the Key field, or null when it is valid.
  ///
  /// Set on submit when the required Key is blank or malformed, and by a server
  /// 422 that rejects `key` (e.g. the per-monitor uniqueness rule). Cleared when
  /// the user edits the field; while null, the live slug-format check
  /// ([_keyValid]) still surfaces a malformed key as the user types.
  String? _keyError;

  /// Inline validation error for the extraction-path field, or null when it is
  /// valid. Set on submit when a path is required (the source needs one) but
  /// blank, and by a server 422 that rejects `extraction_path`. Cleared when the
  /// user edits the field.
  String? _pathError;

  /// Inline validation error for the Warn threshold field, set only by a server
  /// 422 that rejects `warn_bound`. Cleared when the user edits the field.
  String? _warnError;

  /// Inline validation error for the Critical threshold field, set only by a
  /// server 422 that rejects `critical_bound`. Cleared when the user edits the
  /// field.
  String? _criticalError;

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
      _labelError = null;
      // A blank/server key error no longer applies once the auto-slug follows a
      // fresh Name.
      if (!_keyEdited) _keyError = null;
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
      _keyError = null;
    });
  }

  /// Fills Warn and Critical from the AI suggestion and syncs their
  /// controllers (React `set({ warn, critical })` on the "Use" action).
  void _applySuggestion(int suggWarn, int suggCrit) {
    final String warn = suggWarn.toString();
    final String critical = suggCrit.toString();
    _warnController.text = warn;
    _criticalController.text = critical;
    setState(() {
      _warnError = null;
      _criticalError = null;
    });
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

  /// The error rendered under the Key field: the submit/server error slot
  /// ([_keyError]) takes precedence, falling back to the live slug-format check
  /// so a malformed key still surfaces as the user types.
  String? get _keyFieldError {
    if (_keyError != null) return _keyError;
    return _keyValid ? null : trans('uptizm.monitors.metrics_form_key_error');
  }

  bool get _ruleReady => !_needsPath || _form.path.trim().isNotEmpty;

  num? get _resolved => _form.source == 'json' ? resolveJson(_form.path) : null;

  bool get _found => _form.source == 'json' ? _resolved != null : true;

  num get _numValue => _form.source == 'http_status'
      ? 200
      : _resolved ?? fallbackValue(_form.unit);

  String get _valueText => switch (_form.type) {
    'status' => 'operational',
    'string' => 'ok',
    _ => fmt(_numValue, _form.unit),
  };

  StatusKey get _band => _isNumeric
      ? bandOf(_numValue, _form.warn, _form.critical, _form.direction)
      : StatusKey.up;

  int get _suggWarn => _form.direction == 'low'
      ? (_numValue * 0.75).round()
      : (_numValue * 1.15).round();

  int get _suggCrit => _form.direction == 'low'
      ? (_numValue * 0.5).round()
      : (_numValue * 1.3).round();

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
        MSFormField(
          label: trans('uptizm.monitors.metrics_form_type_label'),
          child: _buildTypeControl(),
        ),

        // 3. Source + Unit selects (Unit numeric-only, 2-col responsive).
        _buildSourceUnitRow(),

        // 4. Extraction path (hidden for http_status).
        if (_needsPath) _buildPathField(),

        // 5. Numeric-only block: direction, thresholds, AI suggestion.
        if (_isNumeric) ...[
          MSFormField(
            label: trans('uptizm.monitors.metrics_form_direction_label'),
            child: _buildDirectionControl(),
          ),
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
    return MSFormField(
      label: trans('uptizm.monitors.metrics_form_name_label'),
      error: _labelError,
      child: MSInput(
        value: _form.label,
        onChanged: _onLabel,
        placeholder: trans('uptizm.monitors.metrics_form_name_placeholder'),
      ),
    );
  }

  /// Builds the Key field: monospace, with the slugify validation error shown
  /// inline when the key is malformed.
  Widget _buildKeyField() {
    final String? keyError = _keyFieldError;
    final InputState keyState = keyError == null
        ? InputState.normal
        : InputState.error;
    return MSFormField(
      label: trans('uptizm.monitors.metrics_form_key_label'),
      hint: trans('uptizm.monitors.metrics_form_key_hint'),
      error: keyError,
      child: MSInput(
        controller: _keyController,
        onChanged: _onKey,
        state: keyState,
        placeholder: trans('uptizm.monitors.metrics_form_key_placeholder'),
        className: _monoInputClass(keyState),
      ),
    );
  }

  /// Builds the Type segmented control.
  ///
  /// [SegmentedControl] takes `options: List<String>` + `selectedIndex`, so the
  /// labels are projected from [kMetricTypes] and the change handler maps the
  /// tapped index back to the option's machine value.
  Widget _buildTypeControl() {
    return MSSegmentedControl<String>(
      options: kMetricTypes.map((o) => o.label).toList(),
      selectedIndex: _indexOfValue(kMetricTypes, _form.type),
      size: SegmentedControlSize.sm,
      onChanged: (index) =>
          _set(_form.copyWith(type: kMetricTypes[index].value)),
    );
  }

  /// Builds the Source + Unit row (Unit only when the type is numeric).
  Widget _buildSourceUnitRow() {
    return WDiv(
      className: 'grid gap-4 sm:grid-cols-2',
      children: [
        MSFormField(
          label: trans('uptizm.monitors.metrics_form_source_label'),
          child: MSSelect<String>(
            value: _form.source,
            options: _selectOptions(kMetricSources),
            onChange: (value) {
              if (value != null) _set(_form.copyWith(source: value));
            },
          ),
        ),
        if (_isNumeric)
          MSFormField(
            label: trans('uptizm.monitors.metrics_form_unit_label'),
            child: MSSelect<String>(
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
    return MSFormField(
      label: trans('uptizm.monitors.metrics_form_extraction_label'),
      hint: kPathHint[_form.source],
      error: _pathError,
      child: MSInput(
        value: _form.path,
        onChanged: (value) {
          _pathError = null;
          _set(_form.copyWith(path: value));
        },
        placeholder: kPathPlaceholder[_form.source] ?? '',
        className: _monoInputClass(InputState.normal),
      ),
    );
  }

  /// Builds the Threshold direction segmented control.
  Widget _buildDirectionControl() {
    return MSSegmentedControl<String>(
      options: kMetricDirections.map((o) => o.label).toList(),
      selectedIndex: _indexOfValue(kMetricDirections, _form.direction),
      size: SegmentedControlSize.sm,
      onChanged: (index) =>
          _set(_form.copyWith(direction: kMetricDirections[index].value)),
    );
  }

  /// Builds the Warn + Critical threshold row (2-col, monospace inputs).
  Widget _buildThresholdRow() {
    return WDiv(
      className: 'grid grid-cols-2 gap-4',
      children: [
        MSFormField(
          label: trans('uptizm.monitors.metrics_form_warn_label'),
          error: _warnError,
          child: MSInput(
            controller: _warnController,
            onChanged: (value) {
              _warnError = null;
              _set(_form.copyWith(warn: value));
            },
            placeholder: '80',
            className: _monoInputClass(InputState.normal),
          ),
        ),
        MSFormField(
          label: trans('uptizm.monitors.metrics_form_critical_label'),
          error: _criticalError,
          child: MSInput(
            controller: _criticalController,
            onChanged: (value) {
              _criticalError = null;
              _set(_form.copyWith(critical: value));
            },
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
      action: MSButton(
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
            WText(
              trans('uptizm.monitors.metrics_form_test_title'),
              className: 'text-sm font-medium text-fg',
            ),
            MSButton(
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
        WText(
          trans('uptizm.monitors.metrics_form_test_hint'),
          className: 'text-xs text-fg-muted',
        ),
      ],
      MetricTestStatus.fetching => [
        WText(
          trans('uptizm.monitors.metrics_form_fetching_sample'),
          className: 'text-sm text-fg-muted',
        ),
      ],
      MetricTestStatus.done =>
        _found ? [_buildResolvedPanel()] : [_buildNotFoundPanel()],
    };
  }

  /// Builds the resolved panel: the "Resolved" tag, the value (with a
  /// [StatusDot] for numeric metrics), and the sample JSON block.
  Widget _buildResolvedPanel() {
    return WDiv(
      className:
          'flex flex-col gap-2 rounded-lg border border-color-border bg-up-soft p-3',
      children: [
        WDiv(
          className: 'flex items-center justify-between gap-3',
          children: [
            WText(
              trans('uptizm.monitors.metrics_form_resolved'),
              className:
                  'text-xs font-medium uppercase tracking-wide text-up-soft-foreground',
            ),
            WDiv(
              className: 'flex items-center gap-2',
              children: [
                if (_isNumeric) StatusDot(_band),
                WText(
                  _valueText,
                  className: 'font-mono text-lg tabular-nums text-fg',
                ),
              ],
            ),
          ],
        ),
        _buildSampleBlock(
          _form.source == 'http_status' ? 'HTTP/1.1 200 OK' : kMetricSampleJson,
        ),
      ],
    );
  }

  /// Builds the not-found panel: the unresolved-path message and the sample
  /// JSON block.
  Widget _buildNotFoundPanel() {
    return WDiv(
      className:
          'flex flex-col gap-2 rounded-lg border border-color-border bg-down-soft p-3',
      children: [
        WText(
          trans('uptizm.monitors.metrics_test_not_found_body', {
            'path': _form.path,
          }),
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
      child: WText(
        content,
        className: 'font-mono text-xs leading-relaxed text-fg-muted',
      ),
    );
  }

  /// Builds the footer: Cancel + Save, right-aligned.
  ///
  /// A Wind `flex flex-row justify-end gap-3` row of AUTO-width buttons. The
  /// buttons carry no `w-full` (Wind `width: double.infinity`): a full-width
  /// button inside this sheet's [ListView] body forces an unbounded width and
  /// aborts the whole sheet's layout ("RenderBox was not laid out"). Auto-width
  /// buttons fit the sheet at any width without that hazard.
  Widget _buildFooter() {
    return WDiv(
      className: 'mt-1 flex flex-row justify-end gap-3',
      children: [
        MSButton(
          intent: ButtonIntent.secondary,
          onPressed: widget.onCancel,
          child: WText(trans('uptizm.common.cancel')),
        ),
        MSButton(
          onPressed: _submitIfValid,
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
  // Submit + validation.
  // ---------------------------------------------------------------------------

  /// Validates every client-side required field, then hands the form to
  /// [MonitorMetricForm.onSave] and routes any server 422 back into the inline
  /// error slots.
  ///
  /// The client checks the required Name, the required + well-formed Key, and
  /// (when the source needs one) the required extraction path up front so those
  /// rejections surface inline WITHOUT a round trip. Only when they pass does it
  /// await [MonitorMetricForm.onSave]; an empty result means success ([show] has
  /// already closed the sheet), a non-empty result (a server 422) is a
  /// field-error map keyed by the posted wire field names, which
  /// [_applyServerErrors] paints under the matching fields. A returned key the
  /// form owns no slot for is surfaced as the generic save-failed toast.
  Future<void> _submitIfValid() async {
    if (!_validateClientSide()) return;

    final Map<String, String> serverErrors = await widget.onSave(_form);
    if (!mounted || serverErrors.isEmpty) return;

    final Map<String, String> unmapped = _applyServerErrors(serverErrors);
    if (unmapped.isNotEmpty) {
      Magic.error(
        trans('uptizm.monitors.toast_save_failed_title'),
        unmapped.values.first,
      );
    }
  }

  /// Runs every client-side required check, painting each field's inline error
  /// slot, and returns whether the form may be submitted.
  ///
  /// Checks the required Name, the required + well-formed Key, and the required
  /// extraction path (only when the source needs one, mirroring [_ruleReady]);
  /// all three are checks the client can make before any round trip. Every slot
  /// is always written (a passing check clears its slot) so a previously shown
  /// error never lingers after a corrected resubmit.
  bool _validateClientSide() {
    final String? labelError = _form.label.trim().isEmpty
        ? trans('uptizm.monitors.form_name_error_required')
        : null;
    final String? keyError = _keyRequiredError();
    final String? pathError = _needsPath && _form.path.trim().isEmpty
        ? trans('uptizm.monitors.metrics_form_path_error_required')
        : null;

    setState(() {
      _labelError = labelError;
      _keyError = keyError;
      _pathError = pathError;
    });

    return labelError == null && keyError == null && pathError == null;
  }

  /// Resolves the client-side Key error: the required message when blank, the
  /// slug-format message when malformed, or null when valid.
  String? _keyRequiredError() {
    if (_form.key.trim().isEmpty) {
      return trans('uptizm.monitors.metrics_form_key_error_required');
    }
    if (!kKeyRe.hasMatch(_form.key)) {
      return trans('uptizm.monitors.metrics_form_key_error');
    }
    return null;
  }

  /// Routes a backend 422 field-error map (keyed by the wire field names the
  /// write posts) into the inline error slots, returning the entries that map
  /// to no known field so the caller can surface them another way.
  Map<String, String> _applyServerErrors(Map<String, String> errors) {
    final Map<String, String> unmapped = {};
    setState(() {
      for (final MapEntry<String, String> entry in errors.entries) {
        switch (entry.key) {
          case 'label':
            _labelError = entry.value;
          case 'key':
            _keyError = entry.value;
          case 'extraction_path':
            _pathError = entry.value;
          case 'warn_bound':
            _warnError = entry.value;
          case 'critical_bound':
            _criticalError = entry.value;
          default:
            unmapped[entry.key] = entry.value;
        }
      }
    });
    return unmapped;
  }

  // ---------------------------------------------------------------------------
  // Small helpers.
  // ---------------------------------------------------------------------------

  /// Resolves the fetch button label for the current [_testStatus].
  String _fetchButtonLabel() {
    return switch (_testStatus) {
      MetricTestStatus.fetching => trans(
        'uptizm.monitors.metrics_form_fetching',
      ),
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
    return options
        .map((o) => SelectOption<String>(value: o.value, label: o.label))
        .toList();
  }

  /// Resolves the monospace Input className by appending `font-mono` to the
  /// recipe output for [state], since [Input] has no `font-mono` flag and a
  /// caller `className` bypasses the recipe entirely.
  String _monoInputClass(InputState state) {
    return '${inputRecipe(variants: {kInputStateAxis: state.name})} font-mono';
  }
}
