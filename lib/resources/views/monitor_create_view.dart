import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'monitor_form.dart';
import 'monitor_form_support.dart';
import '../../app/mocks/incidents.dart';
import '../../ui/components/ai_confidence_badge/index.dart';
import '../../ui/components/key_value_editor/key_value_editor.dart';
import '../../ui/layouts/page_container.dart';

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
/// default: the user pastes a URL, Uptizm "probes" it (a simulated 2.2s
/// [Timer], never a real request), then proposes optimal settings the user
/// reviews and edits in the shared [MonitorForm] before creating. A "Manual"
/// [SegmentedControl] toggle drops straight into the bare form.
///
/// The AI flow is a small state machine over two axes:
/// - [_CreateMode] (`ai` / `manual`, default `ai`) — switching mode always
///   resets the step to [_AiStep.input] and cancels any pending analyze timer.
/// - [_AiStep] (`input` -> `analyzing` -> `review`, default `input`) — only
///   meaningful in AI mode.
///
/// On the review step the [MonitorForm] is pre-filled with the AI's choices and
/// carries an AI summary banner (the `banner` slot) so the human stays in
/// control. The AI surfaces (the input/analyzing card and the review banner)
/// use the dedicated `bg-ai-wash` / `border-ai-soft` tokens, mirroring the
/// `AiInsight` banner recipe; no opacity-on-alias (`from-ai-soft/40` does not
/// expand in Wind) and no raw color anywhere.
///
/// Layout discipline mirrors [MonitorDetailView]: a plain Flutter [Column]
/// scaffolds the page body inside the shared [PageContainer], and Wind
/// utilities appear only on leaf containers. The footer buttons inside the
/// embedded form are auto-width (never `w-full` inside a `flex-row`).
///
/// This is a mock screen: nothing persists. Both submit and cancel navigate to
/// the monitors list (the React `done()` lands on a mock detail, but this app's
/// create flow returns to the list — both modes route to `/monitors`).
///
/// ### Example
/// ```dart
/// // Registered as the routed `/monitors/new` content (wrapped by the shell):
/// MagicStarter.view.makeLayout('layout.app', child: const MonitorCreateView())
/// ```
@immutable
class MonitorCreateView extends StatefulWidget {
  /// Creates the [MonitorCreateView].
  const MonitorCreateView({super.key});

  @override
  State<MonitorCreateView> createState() => _MonitorCreateViewState();
}

class _MonitorCreateViewState extends State<MonitorCreateView> {
  /// How long the simulated AI probe runs before flipping to the review step.
  /// Mirrors the React `setTimeout(..., 2200)` in `MonitorCreatePage.analyze`.
  static const Duration _analyzeDelay = Duration(milliseconds: 2200);

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

  /// The pending analyze timer; cancelled on dispose and whenever the mode
  /// switches so a stale callback never flips a torn-down step.
  Timer? _analyzeTimer;

  @override
  void dispose() {
    _analyzeTimer?.cancel();
    super.dispose();
  }

  /// The AI-derived display name for the current [_url] (React `aiName`).
  String get _aiName => aiNameFromUrl(_url);

  /// Starts the simulated AI probe: flip to [_AiStep.analyzing], then to
  /// [_AiStep.review] after [_analyzeDelay]. No network request runs (React
  /// `analyze`). Cancels any in-flight timer first so a double tap cannot queue
  /// two transitions.
  void _analyze() {
    _analyzeTimer?.cancel();
    setState(() => _step = _AiStep.analyzing);
    _analyzeTimer = Timer(_analyzeDelay, () {
      if (!mounted) return;
      setState(() => _step = _AiStep.review);
    });
  }

  /// Switches the setup mode and resets the AI step to [_AiStep.input],
  /// cancelling any pending analyze timer (React `switchMode`).
  void _switchMode(_CreateMode next) {
    _analyzeTimer?.cancel();
    setState(() {
      _mode = next;
      _step = _AiStep.input;
    });
  }

  /// Leaves the create flow for the monitors list (React `done`).
  void _done() {
    MagicRoute.to(_doneRoute);
  }

  @override
  Widget build(BuildContext context) {
    // A plain Flutter Column scaffolds the page body so each descendant
    // receives a bounded width from PageContainer (same discipline as the
    // sibling views); Wind utilities appear only on the leaf containers below.
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // 1. Header: title + description, with the back affordance.
          PageHeader(
            title: trans('uptizm.monitors.create_header_title'),
            subtitle: trans('uptizm.monitors.create_header_description'),
            backLabel: trans('uptizm.monitors.back_to_monitors'),
            backFallback: _doneRoute,
          ),
          const SizedBox(height: 24),

          // 2. Mode picker: AI setup vs Manual.
          _buildModePicker(),
          const SizedBox(height: 24),

          // 3. The mode/step body.
          _buildBody(),
        ],
      ),
    );
  }

  /// Builds the AI/Manual segmented control. Maps the tapped index back to the
  /// [_CreateMode] (React `MODES` + `onValueChange`).
  Widget _buildModePicker() {
    return SegmentedControl<String>(
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
      onSubmit: _done,
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
        WDiv(
          className: 'mt-5',
          child: MagicFormField(
            label: trans('uptizm.monitors.create_ai_url_label'),
            child: Input(
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
          child: Button(
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
  Widget _buildAiReview() {
    return MonitorForm(
      key: const ValueKey('monitor-form-ai-review'),
      initialName: _aiName,
      initialUrl: _url,
      initialType: 'http',
      initialInterval: '30s',
      initialRegions: const ['us-east', 'eu-west', 'ap-southeast'],
      initialHeaders: const [
        KeyValueRow(key: 'Accept', value: 'application/json'),
      ],
      startAdvanced: true,
      submitLabel: trans('uptizm.monitors.form_submit_create'),
      onSubmit: _done,
      onCancel: _done,
      banner: _buildReviewBanner(),
    );
  }

  /// Builds the AI summary banner shown above the review form.
  ///
  /// An ai-wash surface (the dedicated `bg-ai-wash` / `border-ai-soft` tokens,
  /// mirroring the [AiInsight] banner recipe) carrying the glyph tile, the
  /// "AI configured this monitor" title with a high-confidence
  /// [AiConfidenceBadge], the summary sentence, the suggested-metrics pills
  /// ([kAiMetrics]), and the help text. React lines 164-202.
  Widget _buildReviewBanner() {
    return WDiv(
      className: 'flex flex-row items-start gap-3 rounded-xl border border-ai-soft bg-ai-wash p-4',
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

            // 3. Suggested custom metrics: a label, the metric pills, the help.
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
                    for (final AiMetricSeed metric in kAiMetrics)
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
      className: 'flex flex-row items-center gap-1 rounded-md border border-color-border bg-surface px-2 py-0.5',
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
      className: 'flex flex-col rounded-xl border border-ai-soft bg-ai-wash p-6',
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
      className: 'size-8 shrink-0 flex items-center justify-center rounded-lg bg-ai-soft',
      child: WText('✦', className: 'text-ai text-lg'),
    );
  }
}
