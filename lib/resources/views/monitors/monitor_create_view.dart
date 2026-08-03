import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'monitor_form.dart';
import 'monitor_form_support.dart';
import '../../../app/controllers/monitor_controller.dart';
import '../../../app/enums/ai_confidence.dart';
import '../../../ui/components/ai_confidence_badge/index.dart';
import '../../../ui/components/key_value_editor/key_value_editor.dart';
import '../../../app/controllers/entitlement_controller.dart';

/// The setup mode: AI-assisted or manual hand configuration.
///
/// Mirrors the React `mode` union (`"ai" | "manual"`); the `MODES` segmented
/// control switches between them and resets the [_AiStep] to its initial state.
enum _CreateMode {
  /// AI probes a URL and proposes settings the user reviews before creating.
  ai,

  /// Drop straight into the bare [MonitorForm].
  manual,
}

/// The AI flow step: paste a URL, watch it analyze, then review the proposal.
///
/// Mirrors the React `step` union (`"input" | "analyzing" | "review"`).
enum _AiStep {
  /// The URL prompt + "Analyze with AI" call to action.
  input,

  /// The simulated probe is in flight (the analyze-step list with spinners).
  analyzing,

  /// The AI-prefilled [MonitorForm] behind the AI summary banner.
  review,
}

/// **The Monitor Create screen (`/monitors/new`).**
///
/// A faithful Flutter port of the React `MonitorCreatePage.tsx`. AI-first by
/// default: the user pastes a URL, Uptizm probes it via the live `POST
/// /monitors/analyze` ([MonitorController.analyze]), then proposes optimal
/// settings the user reviews and edits in the shared [MonitorForm] before
/// creating. A "Manual" [SegmentedControl] toggle drops straight into the
/// bare form.
///
/// The AI flow is a small state machine over two axes:
/// - [_CreateMode] (`ai` / `manual`, default `ai`): switching mode always
///   resets the step to [_AiStep.input] and drops any in-flight analysis.
/// - [_AiStep] (`input` -> `analyzing` -> `review`, default `input`): only
///   meaningful in AI mode. A failed analyze falls back to [_AiStep.input]
///   with an error toast (surfaced by [MonitorController.analyze] itself).
///
/// On the review step the [MonitorForm] is pre-filled with the AI's choices and
/// carries an AI summary banner (the `banner` slot) so the human stays in
/// control. The AI surfaces (the input/analyzing card and the review banner)
/// use the dedicated `bg-ai-wash` / `border-ai-soft` tokens, mirroring the
/// `AiInsight` banner recipe; no opacity-on-alias (`from-ai-soft/40` does not
/// expand in Wind) and no raw color anywhere.
///
/// Layout discipline mirrors [MonitorDetailView]: a plain Flutter [Column]
/// scaffolds the page body inside the shared [MSPageContainer], and Wind
/// utilities appear only on leaf containers. The footer buttons inside the
/// embedded form are auto-width (never `w-full` inside a `flex-row`).
///
/// Submit fires the real `POST /monitors` with the form's full field map
/// (via [MonitorController.create]) and then returns to the monitors list;
/// Cancel returns to the monitors list WITHOUT creating anything (the React
/// `done()` lands on a mock detail, but this app's create flow returns to the
/// list; both modes route to `/monitors`).
///
/// ### Example
/// ```dart
/// // Registered as the routed `/monitors/new` content (wrapped by the shell):
/// MagicStarter.view.makeLayout('layout.app', child: const MonitorCreateView())
/// ```
@immutable
class MonitorCreateView extends MagicStatefulView<MonitorController> {
  /// Creates the [MonitorCreateView].
  const MonitorCreateView({super.key});

  @override
  State<MonitorCreateView> createState() => _MonitorCreateViewState();
}

class _MonitorCreateViewState
    extends MagicStatefulViewState<MonitorController, MonitorCreateView> {
  /// The route the create flow returns to on submit or cancel (mock: nothing
  /// persists, so both just leave for the monitors list).
  static const String _doneRoute = '/monitors';

  /// The active setup mode (AI vs manual). Defaults to [_CreateMode.ai]
  /// (React `useState("ai")`).
  _CreateMode _mode = _CreateMode.ai;

  /// The active AI flow step. Defaults to [_AiStep.input]
  /// (React `useState("input")`).
  _AiStep _step = _AiStep.input;

  /// The URL the user pasted for the AI to analyze (React `url`).
  String _url = '';

  /// The live analyze result, populated once [_analyze] resolves
  /// successfully. Backs the [_AiStep.review] prefill; `null` until then.
  MonitorAnalysis? _analysis;

  /// A human-facing error shown on the input card when the last [_analyze]
  /// failed (an unreachable URL, a down relay, a non-2xx). `null` when the
  /// input step has not just bounced back from a failed probe. The controller
  /// also logs the technical cause; this is the visible affordance so the flow
  /// never bounces to the input step silently.
  String? _analyzeError;

  @override
  void initState() {
    // Register the controller before the base state resolves it via
    // Magic.find<T>() (which throws when unregistered). Idempotent.
    Magic.findOrPut(MonitorController.new);
    super.initState();
  }

  /// The AI-derived display name for the current [_url] (React `aiName`),
  /// used as a fallback until [_analysis] resolves.
  String get _aiName => aiNameFromUrl(_url);

  /// Metered AI monitor setups the team has left, or `null` when its tier
  /// entitles AI analysis (nothing to count down) or the entitlement has not
  /// loaded yet, in which case the card shows no allowance line at all.
  int? get _trialsRemaining =>
      EntitlementController.instance.aiAnalysisTrialsRemaining;

  /// Runs the live AI probe: flips to [_AiStep.analyzing], awaits
  /// [MonitorController.analyze], then flips to [_AiStep.review] pre-filled
  /// from the response. A failed analyze (the controller already surfaced the
  /// error toast) falls back to [_AiStep.input] instead of stalling on the
  /// analyzing step.
  Future<void> _analyze() async {
    setState(() {
      _step = _AiStep.analyzing;
      _analyzeError = null;
    });
    final MonitorAnalysis? result = await controller.analyze(_url);
    if (!mounted) return;

    if (result == null) {
      setState(() {
        _step = _AiStep.input;
        // A plan wall already showed its own upgrade dialog, and the URL is
        // fine: telling the user to check that it is reachable would be a wrong
        // diagnosis, so only a real failure gets the input-card error.
        _analyzeError = controller.lastAnalyzeWasGated
            ? null
            : trans('uptizm.monitors.create_ai_analyze_failed');
      });
      return;
    }
    setState(() {
      _analysis = result;
      _analyzeError = null;
      _step = _AiStep.review;
    });
  }

  /// Switches the setup mode and resets the AI step to [_AiStep.input],
  /// dropping any previously resolved analysis (React `switchMode`).
  void _switchMode(_CreateMode next) {
    setState(() {
      _mode = next;
      _step = _AiStep.input;
      _analysis = null;
      _analyzeError = null;
    });
  }

  /// Leaves the create flow for the monitors list WITHOUT creating anything
  /// (Cancel). Delegates to [MonitorController.create] with no fields, which
  /// stays navigation-only (see the controller's class docblock).
  void _done() {
    controller.create();
  }

  /// Creates the monitor with the form's [fields] map (React `done` on
  /// submit, but wired to the real `POST /monitors` instead of a mock).
  /// [MonitorController.create] navigates to the monitors list once the
  /// request settles, and returns any backend 422 field errors so the form
  /// renders them inline instead of a generic toast.
  Future<Map<String, String>> _submit(Map<String, dynamic> fields) {
    return controller.create(fields);
  }

  @override
  Widget build(BuildContext context) {
    // The page body is a Wind flex column with a uniform 24px (gap-6) rhythm
    // between the header, the mode picker, and the mode/step body; each leaf
    // still receives a bounded width from MSPageContainer.
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          // 1. Header: title + description, with the back affordance.
          MSPageHeader(
            title: trans('uptizm.monitors.create_header_title'),
            subtitle: trans('uptizm.monitors.create_header_description'),
            backLabel: trans('uptizm.monitors.back_to_monitors'),
            backFallback: _doneRoute,
          ),

          // 2. Mode picker: AI setup vs Manual.
          _buildModePicker(),

          // 3. The mode/step body.
          _buildBody(),
        ],
      ),
    );
  }

  /// Builds the AI/Manual segmented control. Maps the tapped index back to the
  /// [_CreateMode] (React `MODES` + `onValueChange`).
  Widget _buildModePicker() {
    return MSSegmentedControl<String>(
      options: [
        trans('uptizm.monitors.create_mode_ai'),
        trans('uptizm.monitors.create_mode_manual'),
      ],
      selectedIndex: _mode.index,
      onChanged: (index) => _switchMode(_CreateMode.values[index]),
    );
  }

  /// Builds the active body for the current mode and step.
  ///
  /// Manual mode renders the bare [MonitorForm]; AI mode dispatches on the
  /// current [_AiStep] (input prompt, analyzing list, or the prefilled review
  /// form + banner).
  Widget _buildBody() {
    if (_mode == _CreateMode.manual) {
      return _buildManualForm();
    }
    return switch (_step) {
      _AiStep.input => _buildAiInput(),
      _AiStep.analyzing => _buildAiAnalyzing(),
      _AiStep.review => _buildAiReview(),
    };
  }

  /// Builds the manual-mode bare form (React `mode === "manual"`).
  ///
  /// The distinct [ValueKey] forces Flutter to mount a FRESH form state when
  /// switching here from the AI review form (which is a different `MonitorForm`
  /// instance at the same tree position). Without it, Flutter reuses the review
  /// form's [State] and the AI-prefilled values bleed into the bare manual form;
  /// React unmounts/remounts across its separate `mode === "manual"` /
  /// `step === "review"` branches, so the manual form is always blank.
  Widget _buildManualForm() {
    return MonitorForm(
      key: const ValueKey('monitor-form-manual'),
      submitLabel: trans('uptizm.monitors.form_submit_create'),
      onSubmit: _submit,
      onCancel: _done,
    );
  }

  // ---------------------------------------------------------------------------
  // AI flow
  // ---------------------------------------------------------------------------

  /// Builds the AI input step: the ai-wash card with the glyph header, the
  /// explanatory copy, the URL field, and the "Analyze with AI" button
  /// (disabled while the URL is empty). React lines 100-128.
  Widget _buildAiInput() {
    return _buildAiCard(
      children: [
        _buildAiCardHeader(trans('uptizm.monitors.create_ai_card_title')),
        WText(
          trans('uptizm.monitors.create_ai_card_description'),
          className: 'mt-3 text-sm text-fg-muted',
        ),
        // Metered allowance: a tier that entitles AI analysis reports null and
        // shows nothing, so only a Free team sees a countdown, and it says so
        // BEFORE the button rather than after the wall. Listens to the
        // entitlement controller because the count arrives with the `GET
        // /billing` read (after this card first paints) and drops by one after
        // every setup.
        ListenableBuilder(
          listenable: EntitlementController.instance,
          builder: (context, _) {
            final int? remaining = _trialsRemaining;
            if (remaining == null) return const SizedBox.shrink();

            return WText(
              trans(
                remaining == 0
                    ? 'uptizm.monitors.create_ai_trials_spent'
                    : 'uptizm.monitors.create_ai_trials_left',
                {'count': '$remaining'},
              ),
              className: 'mt-2 text-xs text-ai',
            );
          },
        ),
        // Visible failure affordance: when the last analyze bounced back to the
        // input step (unreachable URL, down relay, non-2xx), show why instead
        // of silently returning to the form. Destructive-family alias tokens
        // carry their own dark: pairs.
        if (_analyzeError != null)
          WDiv(
            className:
                'mt-4 flex flex-row items-start gap-2 rounded-lg bg-destructive-container px-4 py-3',
            children: [
              WIcon(
                Icons.error_outline,
                className: 'size-4 shrink-0 text-destructive',
              ),
              WText(_analyzeError!, className: 'text-sm text-destructive'),
            ],
          ),
        WDiv(
          className: 'mt-5',
          child: MSFormField(
            label: trans('uptizm.monitors.create_ai_url_label'),
            child: MSInput(
              value: _url,
              onChanged: (value) => setState(() => _url = value),
              placeholder: trans('uptizm.monitors.create_ai_url_placeholder'),
            ),
          ),
        ),
        // Auto-width button (React `w-full sm:w-auto`): a `w-full` button inside
        // a Wind flex context aborts layout, so the analyze CTA stays auto-width
        // and left-aligned within the card column.
        WDiv(
          className: 'mt-5 flex flex-row',
          child: MSButton(
            disabled: _url.isEmpty,
            onPressed: _url.isEmpty ? null : _analyze,
            child: WDiv(
              className: 'flex flex-row items-center gap-2',
              children: [
                WText('✦'),
                WText(trans('uptizm.monitors.create_ai_analyze_button')),
              ],
            ),
          ),
        ),
      ],
    );
  }

  /// Builds the AI analyzing step: the same ai-wash card with the "Analyzing
  /// endpoint…" title, the URL echoed in monospace, and the [kAnalyzeSteps]
  /// list rendered as spinner rows. React lines 130-150.
  Widget _buildAiAnalyzing() {
    return _buildAiCard(
      children: [
        WDiv(
          className: 'flex flex-row items-center gap-2.5',
          children: [
            _buildAiGlyphTile(),
            WDiv(
              className: 'min-w-0',
              children: [
                WText(
                  trans('uptizm.monitors.create_ai_analyzing_title'),
                  className: 'text-base font-semibold text-fg',
                ),
                WText(
                  _url,
                  className: 'truncate font-mono text-xs text-fg-muted',
                ),
              ],
            ),
          ],
        ),
        WDiv(
          className: 'mt-5 flex flex-col gap-3',
          children: [
            for (final String stepLabel in kAnalyzeSteps)
              _buildAnalyzeRow(stepLabel),
          ],
        ),
      ],
    );
  }

  /// Builds a single analyze-step row: a spinning `ai`-toned indicator followed
  /// by the step label.
  ///
  /// The spinner is a Wind `animate-spin` [WIcon] tinted `text-ai`, mirroring
  /// the React `<Spinner>` motion without resolving a raw [Color] (token-only).
  Widget _buildAnalyzeRow(String label) {
    return WDiv(
      className: 'flex flex-row items-center gap-3',
      children: [
        WIcon(
          Icons.autorenew,
          className: 'size-4 shrink-0 text-ai animate-spin',
        ),
        WText(label, className: 'text-sm text-fg'),
      ],
    );
  }

  /// Builds the AI review step: the [MonitorForm] pre-filled with the AI's
  /// choices, behind the AI summary banner. React lines 152-204.
  ///
  /// [_analysis] carries the live `POST /monitors/analyze` response by the
  /// time this step renders (set right before the [_AiStep.review]
  /// transition); the `_aiName`/default-regions fallbacks only guard against
  /// a null value defensively, they are never exercised in the wired flow.
  Widget _buildAiReview() {
    final MonitorAnalysis? analysis = _analysis;
    return MonitorForm(
      key: const ValueKey('monitor-form-ai-review'),
      initialName: analysis?.name ?? _aiName,
      initialUrl: _url,
      initialType: 'http',
      initialInterval: _intervalTokenForSeconds(
        analysis?.recommendedIntervalSeconds,
      ),
      initialRegions: analysis != null && analysis.recommendedRegions.isNotEmpty
          ? analysis.recommendedRegions
          : const ['eu-central'],
      initialHeaders: const [
        KeyValueRow(key: 'Accept', value: 'application/json'),
      ],
      startAdvanced: true,
      submitLabel: trans('uptizm.monitors.form_submit_create'),
      onSubmit: _submit,
      onCancel: _done,
      banner: _buildReviewBanner(),
    );
  }

  /// Maps [seconds] to the closest [kCheckIntervals] token (e.g. `45` ->
  /// `'30s'`), falling back to `'30s'` when [seconds] is `null`.
  ///
  /// [MonitorForm.initialInterval] only accepts one of the fixed interval
  /// tokens, so the backend's raw `recommended_interval_seconds` must be
  /// snapped to the nearest option rather than passed through directly.
  String _intervalTokenForSeconds(int? seconds) {
    if (seconds == null) return '30s';

    String closest = '30s';
    int bestDiff = 1 << 30;
    for (final MapEntry<String, int> entry in kIntervalSeconds.entries) {
      final int diff = (entry.value - seconds).abs();
      if (diff < bestDiff) {
        bestDiff = diff;
        closest = entry.key;
      }
    }
    return closest;
  }

  /// Builds the AI summary banner shown above the review form.
  ///
  /// An ai-wash surface (the dedicated `bg-ai-wash` / `border-ai-soft` tokens,
  /// mirroring the [AiInsight] banner recipe) carrying the glyph tile, the
  /// "AI configured this monitor" title with a high-confidence
  /// [AiConfidenceBadge], the summary sentence, and (when the backend
  /// actually proposed any) the suggested-metrics pills + help text. React
  /// lines 164-202.
  ///
  /// The suggested-metrics section is sourced from [_analysis]'s
  /// [MonitorAnalysis.suggestedMetrics] rather than a fixture, and renders
  /// nothing at all when that list is empty: an absent suggestion is honest,
  /// a placeholder metric is not.
  Widget _buildReviewBanner() {
    final List<AiMetricSeed> suggestedMetrics =
        _analysis?.suggestedMetrics ?? const [];
    return WDiv(
      className:
          'flex flex-row items-start gap-3 rounded-xl border border-ai-soft bg-ai-wash p-4',
      children: [
        _buildAiGlyphTile(),
        WDiv(
          className: 'min-w-0 flex-1 flex flex-col',
          children: [
            // 1. Title row: heading + confidence badge (wraps on narrow).
            WDiv(
              className: 'wrap items-center gap-2',
              children: [
                WText(
                  trans('uptizm.monitors.create_ai_review_banner_title'),
                  className: 'text-sm font-semibold text-fg',
                ),
                AiConfidenceBadge(AiConfidence.high),
              ],
            ),

            // 2. The AI summary sentence (carries the derived monitor name).
            WText(
              trans('uptizm.monitors.create_ai_review_summary', {
                'name': _aiName,
              }),
              className: 'mt-1 text-sm text-fg-muted',
            ),

            // 3. Suggested custom metrics: a label, the metric pills, the
            //    help, but only when the backend proposed at least one.
            if (suggestedMetrics.isNotEmpty)
              WDiv(
                className: 'mt-3 flex flex-col',
                children: [
                  WText(
                    trans('uptizm.monitors.create_ai_suggested_metrics'),
                    className: 'text-xs font-medium text-fg-muted',
                  ),
                  WDiv(
                    className: 'mt-1.5 wrap gap-1.5',
                    children: [
                      for (final AiMetricSeed metric in suggestedMetrics)
                        _buildMetricPill(metric),
                    ],
                  ),
                  WText(
                    trans('uptizm.monitors.create_ai_suggested_metrics_help'),
                    className: 'mt-1.5 text-xs text-fg-muted',
                  ),
                ],
              ),
          ],
        ),
      ],
    );
  }

  /// Builds a single suggested-metric pill: the label plus a monospace unit
  /// (when present). React lines 184-194.
  Widget _buildMetricPill(AiMetricSeed metric) {
    return WDiv(
      className:
          'flex flex-row items-center gap-1 rounded-md border border-color-border bg-surface px-2 py-0.5',
      children: [
        WText(metric.label, className: 'text-xs text-fg'),
        if (metric.unit.isNotEmpty)
          WText(metric.unit, className: 'font-mono text-xs text-fg-muted'),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Shared AI chrome
  // ---------------------------------------------------------------------------

  /// Builds the ai-wash surface shared by the input and analyzing steps: a
  /// `rounded-xl border-ai-soft bg-ai-wash p-6` column. Uses the dedicated AI
  /// tokens (not a magic_starter [Card], whose theme fill would override the AI
  /// tone) so the surface reads as an AI affordance in both light and dark.
  Widget _buildAiCard({required List<Widget> children}) {
    return WDiv(
      className:
          'flex flex-col rounded-xl border border-ai-soft bg-ai-wash p-6',
      children: children,
    );
  }

  /// Builds the AI card header: the glyph tile beside the "AI setup" title.
  Widget _buildAiCardHeader(String title) {
    return WDiv(
      className: 'flex flex-row items-center gap-2.5',
      children: [
        _buildAiGlyphTile(),
        WText(title, className: 'text-base font-semibold text-fg'),
      ],
    );
  }

  /// Builds the rounded ai-soft glyph tile carrying the sparkle mark.
  ///
  /// Mirrors the `AiInsight` banner glyph tile (`size-8 rounded-lg bg-ai-soft`
  /// with a `text-ai` sparkle), the codebase's AI-surface glyph idiom.
  Widget _buildAiGlyphTile() {
    return WDiv(
      className:
          'size-8 shrink-0 flex items-center justify-center rounded-lg bg-ai-soft',
      child: WText('✦', className: 'text-ai text-lg'),
    );
  }
}
