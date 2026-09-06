import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'monitor_metrics_support.dart';
import '../../../app/controllers/monitor_metrics_controller.dart'
    show
        MetricCandidate,
        MetricCandidateSet,
        MetricPreviewResult,
        MonitorMetricsController;
import '../../../app/enums/status_key.dart';
import '../../../app/support/submits_once.dart';
import '../../../ui/components/ai_insight/index.dart';
import '../../../ui/components/status_dot/index.dart';
import '../../../ui/components/string_value_list/index.dart';

/// The "fetch & test" lifecycle for the extraction preview.
///
/// The transition `idle -> fetching -> done` is driven by a real round trip to
/// `POST /monitors/:id/metrics/preview`. It used to be an 800ms
/// `Future.delayed` with no request at all, which let the panel confirm a rule
/// the extraction pipeline could never resolve.
enum MetricTestStatus {
  /// No test has run; the panel shows a prompt to fetch a sample.
  idle,

  /// The preview request is in flight; the panel shows a loading row.
  fetching,

  /// The preview resolved; the panel reports what the backend extracted.
  done,
}

/// The tone of a verdict box in the test / candidates panels.
///
/// An enum rather than the two className strings the builder used to take,
/// because a caller-supplied token escapes every check the parser can make: a
/// value that is not an alias key renders a box with no fill and nothing
/// reports it.
enum _VerdictTone {
  /// The rule or the round trip failed: the `down` family.
  error,

  /// Nothing to say yet (no sample, no candidates): the neutral surface.
  neutral,
}

/// The fetch lifecycle for the candidate browser.
///
/// Deliberately separate from [MetricTestStatus] rather than folded into it: the
/// candidates describe the monitor's last archived RESPONSE, not the draft rule,
/// so editing a field invalidates a test verdict (see `_set`) while leaving a
/// fetched candidate list perfectly valid.
enum MetricCandidateStatus {
  /// Nothing fetched yet; the panel states where the values would come from.
  idle,

  /// The candidates request is in flight.
  fetching,

  /// The request resolved, into rows, an empty list, or a failure.
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
///   onSave: (form) => controller.create(monitorId, form),
/// );
/// ```
///
/// All thresholds round-trip through [MetricForm] as raw strings, so partially
/// typed numeric input is preserved without lossy conversion. No color is
/// hardcoded: every tone flows through semantic alias keys (the monitoring
/// status families from `uptizm_status_tokens.dart` and the standard
/// `DESIGN.md` roles).
///
/// Field validation is the framework's, not this widget's. The rules live on
/// `MonitorMetricsController` (`_createRules` / `_updateRules`, mirrored off
/// the backend's `StoreMonitorMetricRequest`), a refusal is published in
/// `validationErrors`, and every `error:` slot below reads it back through
/// `getError`. Two checks stay here because magic ships no rule that can state
/// them: the source-dependent required extraction path (a `required_if`) and
/// the string-band block's two cross-field rules (no value in two lists; an
/// unmatched band needs a non-empty list).
class MonitorMetricForm extends StatefulWidget {
  /// The initial form values (an empty form for create, a seeded form for
  /// edit).
  final MetricForm initial;

  /// Whether the form is editing an existing metric (drives titles and the
  /// Save label).
  final bool isEdit;

  /// Called with the form when the user taps Save (once the client-side shape
  /// checks this form still owns pass).
  ///
  /// Answers whether the metric was WRITTEN, and nothing more: the per-field
  /// detail of a refusal lives on [MonitorMetricsController.validationErrors],
  /// which every field slot below reads through `getError`. So `false` means
  /// "stay open", and whether the operator has already been told why is
  /// answered by whether those errors are populated (see
  /// [MonitorMetricsController.create]).
  final Future<bool> Function(MetricForm form) onSave;

  /// Called when the user taps "Fetch & test": applies the draft rule to the
  /// monitor's most recent check and returns what the backend actually
  /// extracted, or `null` when the round trip itself failed.
  ///
  /// A callback rather than a controller reference, matching [onSave], so the
  /// form stays unaware of the monitor id and the controller.
  final Future<MetricPreviewResult?> Function(MetricForm form) onPreview;

  /// Called when the user taps "Fetch values": returns the extraction
  /// candidates the backend proved against the monitor's newest archived
  /// response, or `null` when the round trip itself failed.
  ///
  /// A callback rather than a controller reference for the same reason as
  /// [onPreview]: it closes over the monitor id, which the form does not know.
  ///
  /// NULLABLE, and the candidate panel renders only when it is supplied: the
  /// sheet's opener owns the monitor id, so a caller that does not wire this has
  /// no candidates to offer and gets no panel rather than an empty one.
  final Future<MetricCandidateSet?> Function()? onCandidates;

  /// Called when the user taps Cancel.
  final VoidCallback onCancel;

  /// Creates a [MonitorMetricForm].
  const MonitorMetricForm({
    super.key,
    required this.initial,
    required this.isEdit,
    required this.onSave,
    required this.onPreview,
    required this.onCancel,
    this.onCandidates,
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
    required Future<bool> Function(MetricForm form) onSave,
    required Future<MetricPreviewResult?> Function(MetricForm form) onPreview,
    Future<MetricCandidateSet?> Function()? onCandidates,
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
          // The sheet closes only on a successful write, mirroring the monitor
          // form's "navigate only on success"; a server 422 leaves the sheet
          // open with its errors published on the controller for the field
          // slots below to paint.
          onSave: (form) async {
            final bool written = await onSave(form);
            if (written && sheetContext.mounted) {
              Navigator.of(sheetContext).pop();
            }
            return written;
          },
          onPreview: onPreview,
          onCandidates: onCandidates,
          onCancel: () => Navigator.of(sheetContext).pop(),
        ),
      ),
    );
  }

  @override
  State<MonitorMetricForm> createState() => _MonitorMetricFormState();
}

class _MonitorMetricFormState extends State<MonitorMetricForm>
    with SubmitsOnce<MonitorMetricForm> {
  /// The controller that owns this form's validation errors.
  ///
  /// Resolved from the container rather than passed in, the same way
  /// `monitor_form.dart`'s `_monitor` is: it is the one
  /// [MonitorMetricsController] the create and edit call sites already back
  /// onto, so the rules it checked the payload against and the messages this
  /// form paints are the same object's state. Every error slot below reads it
  /// through `getError`, and the field stack is wrapped in a
  /// [ListenableBuilder] on it so a refusal repaints.
  final MonitorMetricsController _controller = MonitorMetricsController.instance;

  /// The live, string-backed form model. Mutated through [_set] / [_onLabel] /
  /// [_onKey] so every edit also resets [_testStatus] to idle (matching the
  /// React `set` patch behavior).
  late MetricForm _form;

  /// Tracks whether the user has manually edited the Key field. Once true, the
  /// Key stops auto-following the Name slug (React `keyEdited`).
  late bool _keyEdited;

  /// Inline validation error for the extraction-path field, or null when it is
  /// valid.
  ///
  /// The one field-level check that did not move to [_controller], because
  /// whether a path is required at all depends on the sibling `source` field
  /// (magic ships no `required_if`), and the backend's own rule
  /// (`extraction_path`: `nullable`) does not enforce it either: it is purely
  /// this form's own business rule. Set on submit when the source needs a path
  /// but it is blank, cleared when the user edits the field.
  String? _pathError;

  /// Inline validation errors for the three string-band value lists, set by
  /// the client-side overlap check (`_stringBandErrors`). Neither cross-field
  /// rule (no value in two lists; an unmatched band needs a non-empty list) is
  /// a single-field rule magic can state, so both stay here, on the same
  /// footing as `monitor_form.dart`'s credential-block check. Merged at
  /// display time with [_controller]'s own message for the field (e.g. a
  /// server-side duplicate-value rejection this form's check does not catch).
  /// Cleared whenever ANY of the four string-band fields changes, because both
  /// cross-field rules read all four.
  String? _okValuesError;
  String? _warnValuesError;
  String? _criticalValuesError;
  String? _unmatchedBandError;

  /// The extraction-test lifecycle.
  MetricTestStatus _testStatus = MetricTestStatus.idle;

  /// The candidate-browser lifecycle.
  MetricCandidateStatus _candidateStatus = MetricCandidateStatus.idle;

  /// What the last candidate fetch returned, or null before any fetch AND when
  /// the fetch itself failed. Read together with [_candidateStatus]: `done` plus
  /// null is the transport failure, which is a distinct rendering from an
  /// archive holding nothing.
  MetricCandidateSet? _candidates;

  /// What the backend extracted on the last "Fetch & test", or null before any
  /// test has run.
  ///
  /// This is the ONLY source for the panel's verdict and for the threshold
  /// suggestion. Both used to be computed locally from a hardcoded sample map
  /// and a constant-per-unit fallback, so the form could report "RESOLVED
  /// 73.4 %" for a path that existed nowhere in the monitor's response.
  MetricPreviewResult? _preview;

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
    _controller.clearFieldError('label');
    // A server key error no longer applies once the auto-slug follows a fresh
    // Name.
    if (!_keyEdited) _controller.clearFieldError('key');
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
    _controller.clearFieldError('key');
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
    _controller.clearFieldError('warn_bound');
    _controller.clearFieldError('critical_bound');
    _set(_form.copyWith(warn: warn, critical: critical));
  }

  /// Runs the extraction test for real against the monitor's last check.
  ///
  /// On a transport failure the callback returns null (having already toasted),
  /// so the panel falls back to idle rather than presenting a verdict it does
  /// not have.
  Future<void> _runTest() async {
    setState(() {
      _testStatus = MetricTestStatus.fetching;
      _preview = null;
    });

    final MetricPreviewResult? result = await widget.onPreview(_form);
    if (!mounted) return;

    setState(() {
      _preview = result;
      _testStatus = result == null
          ? MetricTestStatus.idle
          : MetricTestStatus.done;
    });
  }

  /// Clears every string-band error slot, both the client-side ones held
  /// locally and whatever [_controller] is holding for the same four fields.
  ///
  /// One helper rather than eight separate clears, because both cross-field
  /// rules read all four fields: an edit to any one of them invalidates a
  /// verdict painted on any other, so keeping a stale one visible would point
  /// the operator at the wrong field.
  void _clearStringBandErrors() {
    _okValuesError = null;
    _warnValuesError = null;
    _criticalValuesError = null;
    _unmatchedBandError = null;
    _controller.clearFieldError('ok_values');
    _controller.clearFieldError('warn_values');
    _controller.clearFieldError('critical_values');
    _controller.clearFieldError('unmatched_band');
  }

  /// Applies an edit that invalidates the string-band verdict (one of the four
  /// fields, or the type itself): clears the four error slots, then routes
  /// through [_set] so the edit also invalidates a fetched test verdict exactly
  /// as every other field change does.
  void _setStringBand(MetricForm next) {
    _clearStringBandErrors();
    _set(next);
  }

  /// Fetches the candidate list for the monitor behind [MonitorMetricForm.onCandidates].
  ///
  /// A null answer is kept as null with the status at `done`, which is the
  /// panel's transport-failure rendering; it deliberately does NOT fall back to
  /// idle the way [_runTest] does, because the candidate panel reports the
  /// failure itself rather than leaning on a toast the controller raises.
  Future<void> _fetchCandidates() async {
    final Future<MetricCandidateSet?> Function()? fetch = widget.onCandidates;
    if (fetch == null) return;

    setState(() {
      _candidateStatus = MetricCandidateStatus.fetching;
      _candidates = null;
    });

    final MetricCandidateSet? result = await fetch();
    if (!mounted) return;

    setState(() {
      _candidates = result;
      _candidateStatus = MetricCandidateStatus.done;
    });
  }

  /// Fills the source, the extraction path and the type from [candidate].
  ///
  /// Exactly those three, and nothing else: no threshold, no key, and no label
  /// the operator did not choose. It is safe because the backend GENERATED the
  /// path and proved it resolves against the archived body before offering it,
  /// so this is the same gesture as [_applySuggestion] rather than free-text
  /// input.
  ///
  /// The type only moves when the candidate names one the form can actually
  /// render; an unknown token would leave [MetricForm.type] holding a value the
  /// segmented control cannot show and the write would 422 on arrival.
  void _applyCandidate(MetricCandidate candidate) {
    final String? type = _eligibleType(candidate);
    final String nextType = type ?? _form.type;

    // Assigned before [_set]'s setState, matching how the path field's own
    // handler clears it.
    _pathError = null;

    final MetricForm next = _form.copyWith(
      source: candidate.source,
      path: candidate.path,
      type: nextType,
    );

    // A candidate can switch the draft's type, which changes WHICH band
    // fields are rendered. Route through [_setStringBand] then, so a 422 on
    // the value lists does not outlive the type it was reported against.
    if (nextType == _form.type) {
      _set(next);
    } else {
      _setStringBand(next);
    }
  }

  /// The first type [candidate] is eligible for that this form can render, or
  /// null when the backend offered none of them.
  String? _eligibleType(MetricCandidate candidate) {
    final Set<String> known = kMetricTypes
        .map((MetricOption option) => option.value)
        .toSet();

    for (final String type in candidate.types) {
      if (known.contains(type)) return type;
    }
    return null;
  }

  // ---------------------------------------------------------------------------
  // Computed state (React lines 251-264).
  // ---------------------------------------------------------------------------

  bool get _isNumeric => _form.type == 'numeric';

  /// Whether the draft is a `string` metric, and therefore banded by value
  /// lists rather than by numeric bounds.
  bool get _isString => _form.type == 'string';

  bool get _needsPath => _form.source != 'http_status';

  bool get _keyValid => _form.key.isEmpty || kKeyRe.hasMatch(_form.key);

  /// The error rendered under the Key field: [_controller]'s message (required
  /// or a server rejection, e.g. the per-monitor uniqueness rule) takes
  /// precedence, falling back to the live slug-format check so a malformed key
  /// still surfaces as the user types even before a submit.
  String? get _keyFieldError {
    final String? controllerError = _controller.getError('key');
    if (controllerError != null) return controllerError;
    return _keyValid ? null : trans('uptizm.monitors.metrics_form_key_error');
  }

  bool get _ruleReady => !_needsPath || _form.path.trim().isNotEmpty;

  /// The value the last test extracted, parsed as a number, or null when no
  /// test has run, the rule resolved nothing, or the value is not numeric.
  ///
  /// Every one of those is a real "we do not know", which is why this is
  /// nullable rather than falling back to a plausible constant.
  num? get _measured {
    final MetricPreviewResult? preview = _preview;
    if (preview == null || !preview.resolved) return null;
    return num.tryParse(preview.value ?? '');
  }

  /// Whether the last test resolved a usable value.
  bool get _found => _preview?.resolved ?? false;

  /// The extracted value formatted for display, or null when there is none.
  String? get _valueText {
    final MetricPreviewResult? preview = _preview;
    if (preview == null || !preview.resolved) return null;

    final num? measured = _measured;

    return measured == null
        // A status/string metric extracts a word, not a number, so it is shown
        // verbatim instead of being pushed through the numeric formatter.
        ? preview.value
        : fmt(measured, _form.unit);
  }

  /// The band the backend said the extracted value lands in, or null when the
  /// draft carries no thresholds to band against.
  StatusKey? get _band => switch (_preview?.band) {
    'critical' => StatusKey.down,
    'warn' => StatusKey.degraded,
    'ok' => StatusKey.up,
    _ => null,
  };

  /// Suggested warn bound, derived from the value the rule ACTUALLY read.
  ///
  /// Null until a test has measured something: the suggestion used to be
  /// computed from a constant-per-unit fallback, so a brand-new metric was told
  /// "this metric typically reads near 73.4 %" about a value nothing had
  /// measured.
  int? get _suggWarn {
    final num? measured = _measured;
    if (measured == null) return null;

    return _form.direction == 'low'
        ? (measured * 0.75).round()
        : (measured * 1.15).round();
  }

  /// Suggested critical bound, from the same measured value as [_suggWarn].
  int? get _suggCrit {
    final num? measured = _measured;
    if (measured == null) return null;

    return _form.direction == 'low'
        ? (measured * 0.5).round()
        : (measured * 1.3).round();
  }

  // ---------------------------------------------------------------------------
  // Build.
  // ---------------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-4',
      children: [
        // Wrapped in a ListenableBuilder on [_controller] because that is
        // where the field errors live now: `validate()` and a server 422 both
        // publish into `validationErrors` and notify, and without a listener
        // the refusal would be recorded and never painted.
        ListenableBuilder(
          listenable: _controller,
          builder: (BuildContext context, Widget? _) => WDiv(
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
                // Null until a test has measured a real value, so no baseline
                // is claimed before one exists.
                ?_buildAiInsight(),
              ],

              // 6. String-only block: the three value lists plus the
              //    unmatched band. A sibling of the numeric block above,
              //    never a companion to it: the two band the same reading by
              //    different means, and a metric is one type at a time.
              if (_isString) _buildStringBandBlock(),

              // 7. Candidate browser, only when the sheet's opener wired a
              //    source for it (it owns the monitor id; see
              //    [MonitorMetricForm.onCandidates]).
              if (widget.onCandidates != null) _buildCandidatePanel(),

              // 8. Test extraction panel (when the rule is ready).
              if (_ruleReady) _buildTestPanel(),
            ],
          ),
        ),

        // 9. Footer: Cancel + Save.
        _buildFooter(),
      ],
    );
  }

  /// Builds the Name field.
  Widget _buildNameField() {
    return MSFormField(
      label: trans('uptizm.monitors.metrics_form_name_label'),
      error: _controller.getError('label'),
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
      selectedIndex: indexOfValue(kMetricTypes, _form.type),
      size: SegmentedControlSize.sm,
      // Through [_setStringBand]: a type change hides or shows the whole
      // string-band block, so any error painted on it stops applying and must
      // not be waiting behind the switch when the operator comes back.
      onChanged: (index) =>
          _setStringBand(_form.copyWith(type: kMetricTypes[index].value)),
    );
  }

  /// Builds the Source + Unit row (Unit only when the type is numeric).
  Widget _buildSourceUnitRow() {
    return WDiv(
      // The base column count is explicit: wind's grid default is TWO, not
      // one, so without it the `sm:` prefix changed nothing and these two
      // selects sat side by side at ~160pt on a phone.
      className: 'grid grid-cols-1 gap-4 sm:grid-cols-2',
      children: [
        MSFormField(
          label: trans('uptizm.monitors.metrics_form_source_label'),
          child: MSSelect<String>(
            value: _form.source,
            options: _selectOptions(kMetricSources),
            onChange: (value) {
              // `withSource`, not `copyWith`: the blank form's `%` default
              // cannot describe an HTTP status code, and the unit is rendered
              // without ever being validated. See MetricForm.withSource.
              if (value != null) _set(_form.withSource(value));
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
      error: _pathError ?? _controller.getError('extraction_path'),
      child: MSInput(
        value: _form.path,
        onChanged: (value) {
          _pathError = null;
          _controller.clearFieldError('extraction_path');
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
      selectedIndex: indexOfValue(kMetricDirections, _form.direction),
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
          error: _controller.getError('warn_bound'),
          child: MSInput(
            controller: _warnController,
            onChanged: (value) {
              _controller.clearFieldError('warn_bound');
              _set(_form.copyWith(warn: value));
            },
            placeholder: '80',
            className: _monoInputClass(InputState.normal),
          ),
        ),
        MSFormField(
          label: trans('uptizm.monitors.metrics_form_critical_label'),
          error: _controller.getError('critical_bound'),
          child: MSInput(
            controller: _criticalController,
            onChanged: (value) {
              _controller.clearFieldError('critical_bound');
              _set(_form.copyWith(critical: value));
            },
            placeholder: '95',
            className: _monoInputClass(InputState.normal),
          ),
        ),
      ],
    );
  }

  /// Builds the threshold-suggestion insight, or nothing at all when no value
  /// has been measured yet.
  ///
  /// The suggestion is only offered once "Fetch & test" has read a real number
  /// off the monitor's own response, because the copy states a baseline
  /// ("typically reads near X") and deriving that from anything other than a
  /// measurement makes it a fabricated claim. It previously ran on a
  /// constant-per-unit fallback, so a brand-new metric with no data was told it
  /// "typically reads near 73.4 %".
  Widget? _buildAiInsight() {
    final num? measured = _measured;
    final int? suggWarn = _suggWarn;
    final int? suggCrit = _suggCrit;
    if (measured == null || suggWarn == null || suggCrit == null) {
      return null;
    }

    return AiInsight(
      action: MSButton(
        intent: ButtonIntent.secondary,
        size: ButtonSize.sm,
        onPressed: () => _applySuggestion(suggWarn, suggCrit),
        child: WText(trans('uptizm.monitors.metrics_form_ai_use')),
      ),
      child: WText(
        trans('uptizm.monitors.metrics_form_ai_suggestion', {
          'value': fmt(measured, _form.unit),
          'warn': suggWarn.toString(),
          'crit': suggCrit.toString(),
        }),
      ),
    );
  }

  /// Builds the string-band block: three value lists (healthy, warning,
  /// critical), the unmatched-band select, and the note about how matching
  /// compares.
  ///
  /// This is the whole authoring surface for a `string` metric's alerting. The
  /// band it produces is never computed here: the server owns the one function
  /// that turns a value plus these lists into a band, and the verdict panel
  /// shows what that function answered.
  Widget _buildStringBandBlock() {
    return WDiv(
      className: 'flex flex-col gap-3 border-t border-color-border pt-4',
      children: [
        WText(
          trans('uptizm.monitors.metrics_form_string_band_label'),
          className: 'text-sm font-medium text-fg',
        ),
        WText(
          trans('uptizm.monitors.metrics_form_string_band_help'),
          className: 'text-xs text-fg-muted',
        ),
        MSFormField(
          label: trans('uptizm.monitors.metrics_form_ok_values_label'),
          error: _okValuesError ?? _controller.getError('ok_values'),
          child: StringValueList(
            value: _form.okValues,
            onChanged: (List<String> next) =>
                _setStringBand(_form.copyWith(okValues: next)),
            placeholder: trans(
              'uptizm.monitors.metrics_form_ok_values_placeholder',
            ),
          ),
        ),
        MSFormField(
          label: trans('uptizm.monitors.metrics_form_warn_values_label'),
          error: _warnValuesError ?? _controller.getError('warn_values'),
          child: StringValueList(
            value: _form.warnValues,
            onChanged: (List<String> next) =>
                _setStringBand(_form.copyWith(warnValues: next)),
            tone: StringValueListTone.warn,
            placeholder: trans(
              'uptizm.monitors.metrics_form_warn_values_placeholder',
            ),
          ),
        ),
        MSFormField(
          label: trans('uptizm.monitors.metrics_form_critical_values_label'),
          error: _criticalValuesError ?? _controller.getError('critical_values'),
          child: StringValueList(
            value: _form.criticalValues,
            onChanged: (List<String> next) =>
                _setStringBand(_form.copyWith(criticalValues: next)),
            tone: StringValueListTone.critical,
            placeholder: trans(
              'uptizm.monitors.metrics_form_critical_values_placeholder',
            ),
          ),
        ),
        MSFormField(
          label: trans('uptizm.monitors.metrics_form_unmatched_band_label'),
          hint: trans('uptizm.monitors.metrics_form_unmatched_band_help'),
          error: _unmatchedBandError ?? _controller.getError('unmatched_band'),
          child: MSSelect<String>(
            value: _form.unmatchedBand,
            options: _unmatchedBandOptions(),
            onChange: (String? value) {
              if (value == null) return;
              _setStringBand(_form.copyWith(unmatchedBand: value));
            },
          ),
        ),
        // Only once a list exists: with all three empty the write path refuses
        // an unmatched band outright, and the field's own error says so. The
        // gap this closes is the saveable-but-silent draft, where the operator
        // has configured healthy values, left the band unset, and believes an
        // unlisted value pages.
        if (_hasAnyStringValue && _form.unmatchedBand.isEmpty)
          WText(
            trans('uptizm.monitors.metrics_form_unmatched_band_silent'),
            className: 'text-xs text-fg-muted',
          ),
        WText(
          trans('uptizm.monitors.metrics_form_string_match_note'),
          className: 'text-xs text-fg-muted',
        ),
      ],
    );
  }

  /// Whether the draft configures at least one string value, in any band.
  ///
  /// Mirrors the server's `MonitorMetric::alertsOnString()`, which is what
  /// decides whether a string metric alerts at all.
  bool get _hasAnyStringValue =>
      _form.okValues.isNotEmpty ||
      _form.warnValues.isNotEmpty ||
      _form.criticalValues.isNotEmpty;

  /// The unmatched-band options: the three bands, preceded by an em-dash entry
  /// standing for "leave it unset".
  ///
  /// The empty-string entry is what makes the field reversible. `WSelect` has no
  /// clear affordance, so without it an operator who picked a band could never
  /// go back to the inert default, and the rule that a band needs a list would
  /// then be a trap rather than a correction. The glyph carries no words on
  /// purpose: there is no key for "none", and inventing one belongs in the
  /// localization step, not here.
  List<SelectOption<String>> _unmatchedBandOptions() {
    return [
      const SelectOption<String>(value: '', label: '—'),
      SelectOption<String>(
        value: 'ok',
        label: trans('uptizm.monitors.metrics_band_ok'),
      ),
      SelectOption<String>(
        value: 'warn',
        label: trans('uptizm.monitors.metrics_band_warn'),
      ),
      SelectOption<String>(
        value: 'critical',
        label: trans('uptizm.monitors.metrics_band_critical'),
      ),
    ];
  }

  /// Builds the candidate browser: a header with the fetch button, then one of
  /// the idle / fetching / done states.
  ///
  /// It sits beside the test panel and shares its chrome deliberately. The test
  /// panel answers "does my rule work"; this one answers "what is there to write
  /// a rule about", and they read the monitor's last response from the two ends.
  Widget _buildCandidatePanel() {
    return WDiv(
      className: 'flex flex-col gap-2 border-t border-color-border pt-4',
      children: [
        WDiv(
          className: 'flex items-center justify-between gap-3',
          children: [
            WText(
              trans('uptizm.monitors.metrics_form_candidates_title'),
              className: 'text-sm font-medium text-fg',
            ),
            MSButton(
              intent: ButtonIntent.secondary,
              size: ButtonSize.sm,
              disabled: _candidateStatus == MetricCandidateStatus.fetching,
              isLoading: _candidateStatus == MetricCandidateStatus.fetching,
              onPressed: _fetchCandidates,
              child: WText(
                trans('uptizm.monitors.metrics_form_candidates_fetch'),
              ),
            ),
          ],
        ),
        ..._buildCandidateBody(),
      ],
    );
  }

  /// Builds the state-dependent body of the candidate panel.
  ///
  /// Five outcomes, each with its own rendering, because to an operator a blank
  /// box is indistinguishable from a pending one: not fetched yet, in flight,
  /// the fetch failed, nothing archived to read, the archive held nothing
  /// extractable, and the rows themselves.
  List<Widget> _buildCandidateBody() {
    return switch (_candidateStatus) {
      MetricCandidateStatus.idle => [
        WText(
          trans('uptizm.monitors.metrics_form_candidates_hint'),
          className: 'text-xs text-fg-muted',
        ),
      ],
      MetricCandidateStatus.fetching => [
        WText(
          trans('uptizm.monitors.metrics_form_candidates_fetching'),
          className: 'text-sm text-fg-muted',
        ),
      ],
      MetricCandidateStatus.done => [_buildCandidateResult()],
    };
  }

  /// Builds the resolved candidate panel: the rows, or the reason there are
  /// none.
  ///
  /// The three empty renderings reuse [_buildVerdictBox] rather than growing a
  /// second box. None of them carries a provenance line: unlike the preview, the
  /// candidates endpoint reports no check timestamp and no status code, and
  /// naming a sample it did not name would be exactly the invented evidence the
  /// provenance line exists to replace.
  Widget _buildCandidateResult() {
    final MetricCandidateSet? result = _candidates;

    // The round trip itself failed. The controller stays silent for this one
    // (no toast), so this box is the only report the operator gets.
    if (result == null) {
      return _buildVerdictBox(
        tone: _VerdictTone.error,
        message: trans('uptizm.monitors.metrics_form_candidates_error'),
      );
    }

    // Nothing archived yet is the same answer the test panel gives, in the same
    // words: the operator needs a check to have run, not a different path.
    if (!result.hasSample) {
      return _buildVerdictBox(
        tone: _VerdictTone.neutral,
        message: trans('uptizm.monitors.metrics_form_no_sample'),
      );
    }

    // A body WAS read and held nothing extractable, which is a statement about
    // the endpoint rather than about the archive.
    if (result.candidates.isEmpty) {
      return _buildVerdictBox(
        tone: _VerdictTone.neutral,
        message: trans('uptizm.monitors.metrics_form_candidates_empty'),
      );
    }

    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        for (final MetricCandidate candidate in result.candidates)
          _buildCandidateRow(candidate),
      ],
    );
  }

  /// Builds one tappable candidate row: its label hint (or its path) over the
  /// sample value, with the "use this" affordance on the right.
  ///
  /// Both texts are plain [WText] and stay that way. They are attacker-controlled
  /// substrings of a monitored response, so rendering either through markup,
  /// markdown or a link would turn a third party's response body into this app's
  /// UI. `truncate` bounds them visually; the backend already bounds their
  /// length.
  Widget _buildCandidateRow(MetricCandidate candidate) {
    return WAnchor(
      onTap: () => _applyCandidate(candidate),
      child: WDiv(
        className:
            'flex flex-row items-center justify-between gap-3 rounded-lg '
            'border border-color-border bg-surface p-3 '
            'hover:bg-surface-container transition-colors',
        children: [
          WDiv(
            className: 'flex-1 flex flex-col min-w-0',
            children: [
              WText(
                candidate.label ?? candidate.path,
                className: 'font-mono text-xs text-fg truncate',
              ),
              WText(
                candidate.value,
                className: 'font-mono text-xs text-fg-muted truncate',
              ),
            ],
          ),
          WText(
            trans('uptizm.monitors.metrics_form_candidate_use'),
            className: 'text-xs font-medium text-fg',
          ),
        ],
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
  ///
  /// Every branch reports the backend's own answer. There are four outcomes, and
  /// they are deliberately distinct: the rule resolved, the rule resolved
  /// something of the wrong type, the rule resolved nothing, or there was no
  /// sample to test against at all.
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
      MetricTestStatus.done => [_buildVerdictPanel()],
    };
  }

  /// Builds the verdict panel for the completed test.
  Widget _buildVerdictPanel() {
    final MetricPreviewResult? preview = _preview;
    if (preview == null) {
      return const SizedBox.shrink();
    }

    // Nothing to test against is its own state: the operator needs to run a
    // check, not to fix their path.
    if (!preview.hasSample) {
      return _buildVerdictBox(
        tone: _VerdictTone.neutral,
        message: trans('uptizm.monitors.metrics_form_no_sample'),
      );
    }

    if (_found) {
      return _buildResolvedPanel(preview);
    }

    return _buildVerdictBox(
      tone: _VerdictTone.error,
      // The backend's own explanation when it has one (a bad regex, a
      // non-JSON body, a type mismatch), so the operator is told what actually
      // went wrong rather than a generic "not found".
      message: preview.error ??
          trans('uptizm.monitors.metrics_test_not_found_body', {
            'path': _form.path,
          }),
      provenance: _sampleProvenance(preview),
    );
  }

  /// Builds the resolved panel: the "Resolved" tag, the extracted value (with a
  /// [StatusDot] when the backend banded it), and the sample provenance.
  Widget _buildResolvedPanel(MetricPreviewResult preview) {
    final StatusKey? band = _band;

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
                // Only when the draft actually carries thresholds; an unbanded
                // value gets no dot rather than a green one.
                ?(band == null ? null : StatusDot(band)),
                WText(
                  _valueText ?? '',
                  className: 'font-mono text-lg tabular-nums text-fg',
                ),
              ],
            ),
          ],
        ),
        WText(
          _sampleProvenance(preview),
          className: 'font-mono text-xs text-fg-muted',
        ),
      ],
    );
  }

  /// Builds a single-message verdict box with an optional provenance line.
  ///
  /// Takes a [_VerdictTone], not two className strings. The caller used to hand
  /// in `tone` and `textClass` verbatim, which the parser cannot check: a token
  /// that is not an alias key produces a box with no fill and nothing anywhere
  /// reports it. `uptizm_status_tokens.dart` exists because `border-ai-soft`
  /// was once dropped in exactly that silence.
  Widget _buildVerdictBox({
    required _VerdictTone tone,
    required String message,
    String? provenance,
  }) {
    final bool isError = tone == _VerdictTone.error;

    return WDiv(
      states: {if (isError) 'error'},
      className:
          'flex flex-col gap-2 rounded-lg border border-color-border p-3 '
          'bg-surface-container error:bg-down-soft',
      children: [
        WText(
          message,
          // Two whole literals rather than an interpolated tone: the state
          // prefix above styles the box, and a child WText does not inherit it.
          className: isError
              ? 'text-sm text-down-soft-foreground'
              : 'text-sm text-fg-muted',
        ),
        ?(provenance == null
            ? null
            : WText(
                provenance,
                className: 'font-mono text-xs text-fg-muted',
              )),
      ],
    );
  }

  /// Names the sample the verdict came from.
  ///
  /// The panel used to print a hardcoded sample JSON body, which implied it had
  /// fetched the endpoint. It is verified against the monitor's last recorded
  /// check, so it says exactly that.
  String _sampleProvenance(MetricPreviewResult preview) {
    final DateTime? at = preview.sampleCheckedAt;
    final int? code = preview.sampleStatusCode;

    return trans('uptizm.monitors.metrics_form_sample_from', {
      'when': at == null ? '-' : Carbon.parse(at.toIso8601String()).diffForHumans(),
      'code': code?.toString() ?? '-',
    });
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
          // `isLoading` is the guard, not just the spinner: the button drops
          // its tap while a write is in flight. This form was the fourth one,
          // and it is the one `SubmitsOnce` predicted would forget: a double
          // tap sent two POSTs, the first closed the sheet, and the second took
          // a 422 on the per-monitor unique key that reached nobody, because
          // `!mounted` swallowed it into a log line.
          isLoading: isSubmitting,
          onPressed: () => submitOnce(_submitIfValid),
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

  /// The wire fields this form renders a dedicated error slot for. Used by
  /// [_revealRefusedFields] to tell a rejection this form already painted
  /// apart from one it owns no slot for.
  static const Set<String> _ownedFields = <String>{
    'label',
    'key',
    'extraction_path',
    'warn_bound',
    'critical_bound',
    'ok_values',
    'warn_values',
    'critical_values',
    'unmatched_band',
  };

  /// Runs the two shape checks this form still owns, then hands the form to
  /// [MonitorMetricForm.onSave].
  ///
  /// Everything a magic [Rule] can state (the required Name, the required Key,
  /// their lengths, the enum fields) now lives on [MonitorMetricsController]
  /// and is read back through `getError`, so a rejection there needs no
  /// routing here. What is left is what magic has no rule for: the
  /// SOURCE-dependent required extraction path (a `required_if` magic does not
  /// ship) and the string-band block's two cross-field rules (no value in two
  /// lists; an unmatched band needs a non-empty list). Both are answers the
  /// client already knows, so they still run first and still stop the request.
  ///
  /// After a refused write there is exactly one thing left to do that the error
  /// slots cannot do themselves: toast whatever this form owns no slot for
  /// (see [_revealRefusedFields]).
  Future<void> _submitIfValid() async {
    if (!_checkClientSideShape()) return;

    final bool written = await widget.onSave(_form);
    if (!mounted || written) return;

    _revealRefusedFields();
  }

  /// Runs the two shape checks this form still owns, painting their slots, and
  /// returns whether the form may be submitted.
  ///
  /// Both slots are always written (a passing check clears its slot) so a
  /// previously shown error never lingers after a corrected resubmit.
  bool _checkClientSideShape() {
    final String? pathError = _needsPath && _form.path.trim().isEmpty
        ? trans('uptizm.monitors.metrics_form_path_error_required')
        : null;
    final Map<String, String> bandErrors = _stringBandErrors();

    setState(() {
      _pathError = pathError;
      _okValuesError = bandErrors['ok_values'];
      _warnValuesError = bandErrors['warn_values'];
      _criticalValuesError = bandErrors['critical_values'];
      _unmatchedBandError = bandErrors['unmatched_band'];
    });

    return pathError == null && bandErrors.isEmpty;
  }

  /// Toasts a refused write's detail that no field slot on this form covers.
  ///
  /// Reads [_controller] rather than a returned map, because that is where the
  /// detail lives now. An EMPTY error map here means the failure was not a
  /// per-field one (a transport error or a 500), and the controller has
  /// already surfaced its own toast for it, so this deliberately says nothing.
  void _revealRefusedFields() {
    final Iterable<MapEntry<String, String>> unmapped = _controller
        .validationErrors.entries
        .where((MapEntry<String, String> entry) =>
            !_ownedFields.contains(entry.key));
    if (unmapped.isEmpty) return;

    Magic.error(
      trans('uptizm.monitors.toast_save_failed_title'),
      unmapped.first.value,
    );
  }

  /// The two cross-field string-band rules, keyed by the wire field each one
  /// belongs under. Empty when the configuration is valid, and always empty for
  /// a metric that is not a `string`.
  ///
  /// Both rules exist on the server too. They are repeated here so the operator
  /// is corrected before the round trip, NOT to replace the server's copy: the
  /// server validates merged state against the stored row, which this cannot
  /// see.
  Map<String, String> _stringBandErrors() {
    if (!_isString) return const {};

    final Map<String, List<String>> lists = {
      'ok_values': _form.okValues,
      'warn_values': _form.warnValues,
      'critical_values': _form.criticalValues,
    };
    final Map<String, String> errors = {};

    // 1. Which lists carry each value, compared through normalizeMatchValue()
    //    because that is how the SERVER compares them. Comparing raw values
    //    would let `['OK']` against `['ok']` pass the client and then collide at
    //    evaluation, which is the whole reason the normalizer is shared.
    final Map<String, Set<String>> owners = {};
    lists.forEach((String field, List<String> values) {
      for (final String value in values) {
        owners
            .putIfAbsent(normalizeMatchValue(value), () => <String>{})
            .add(field);
      }
    });

    // 2. A value in two lists is flagged on BOTH of them, so the operator can
    //    see the collision rather than half of it.
    for (final Set<String> fields in owners.values) {
      if (fields.length < 2) continue;
      for (final String field in fields) {
        errors[field] = trans(
          'uptizm.monitors.metrics_form_string_values_error_overlap',
        );
      }
    }

    // 3. An unmatched band with nothing to match against would band EVERY
    //    reading, which is never what it means; the server refuses it too.
    final bool hasList = lists.values.any(
      (List<String> values) => values.isNotEmpty,
    );
    if (_form.unmatchedBand.isNotEmpty && !hasList) {
      errors['unmatched_band'] = trans(
        'uptizm.monitors.'
        'metrics_form_string_values_error_unmatched_needs_list',
      );
    }

    return errors;
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
