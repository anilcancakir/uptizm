import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'monitor_form.dart';
import 'monitor_form_support.dart';
import '../../../app/controllers/monitor_controller.dart';
import '../../../app/enums/ai_confidence.dart';
import '../../../app/support/monitor_types.dart'
    show AnalyzeFailure, AnalyzeRunProgress, AnalyzeStepState;
import '../../../ui/components/ai_confidence_badge/index.dart';
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

  /// A real run is in flight: the analyze-step list, each row rendering the
  /// state the worker reported for that ordinal (see [_AnalyzeRowState]).
  analyzing,

  /// The AI-prefilled [MonitorForm] behind the AI summary banner.
  review,
}

/// How one row of the analyze-step list renders.
///
/// FIVE cases for FOUR the operator normally sees. The wire's per-step vocabulary
/// is terminal only (`done` / `skipped` / `failed`), so [pending] and [running]
/// are both DERIVED from which ordinals have reported rather than reported
/// themselves, and [failed] is the transient one: a step that raised also fails
/// the run, and the flow leaves for the input step with the reason on screen. It
/// still gets its own case, because a switch that folded it into [done] would
/// paint a failure as a success for exactly as long as it was visible.
enum _AnalyzeRowState {
  /// Nothing has reported this ordinal and it is not the one in flight.
  pending,

  /// The row after the last terminal tick: the step the worker is on now.
  running,

  /// The step ran and produced its finding.
  done,

  /// The step genuinely did not run (no body to digest, no response time to
  /// detect against, no budget left for the model call).
  skipped,

  /// The step raised and ended the run.
  failed,
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
///   resets the step to [_AiStep.input] and ABANDONS the run in flight, which is
///   load-bearing rather than tidy (see [_MonitorCreateViewState._switchMode]).
/// - [_AiStep] (`input` -> `analyzing` -> `review`, default `input`): only
///   meaningful in AI mode. A failed analyze falls back to [_AiStep.input]
///   with a line on the card naming the cause it can actually name.
///
/// **The analyze is ASYNCHRONOUS and the step list is not decoration.**
/// `POST /monitors/analyze` answers 202 and a worker does the model calls, so
/// [_AiStep.analyzing] can last minutes and every row renders the state the run
/// reported for its ordinal, `skipped` included. That state arrives on
/// [MonitorController.analyzeProgress] and this view rebuilds on it; the ordinals
/// are a contract with the backend, documented on `kAnalyzeSteps`.
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

  /// Whether the credential disclosure on the input card is open.
  ///
  /// Closed by default, and staying closed is what keeps the ordinary case (a
  /// public URL) one field and one button. A closed disclosure sends no
  /// credential at all, whatever [_credential] happens to hold.
  bool _authDisclosureOpen = false;

  /// The credential the operator composed for the probe.
  ///
  /// Kept while the disclosure is closed so reopening it restores what was
  /// typed, but only read by [_analyzeAuthConfig] when the disclosure is open.
  MonitorCredential _credential = const MonitorCredential();

  /// Inline credential errors keyed by the wire field name, from the same
  /// client-side check [MonitorForm] runs. Without it an incomplete credential
  /// reaches `AnalyzeMonitorRequest`, whose `required_if` answers 422, and the
  /// input card would then blame the URL for being unreachable.
  Map<String, String> _credentialErrors = const <String, String>{};

  /// The `key`s of suggested metrics the operator declined on the review step.
  ///
  /// A declined SET rather than an accepted one, so the default is accept: the
  /// operator asked for an AI setup and the proposals are the setup. Keyed by
  /// `key` rather than by index because the backend already guarantees it
  /// unique within one answer, and an index would drift the moment the list is
  /// re-rendered.
  final Set<String> _declinedMetricKeys = <String>{};

  /// Which analyze attempt the pending [_analyze] continuation belongs to.
  ///
  /// Bumped on every start AND on every abandon, because the future
  /// [MonitorController.analyze] hands back settles with `null` for BOTH a real
  /// failure and an abandoned run, and those two must not look the same on
  /// screen. Without it, switching to Manual mid-run would drop the operator on
  /// the input card with "couldn't analyze that URL" for a run they cancelled
  /// themselves.
  int _analyzeAttempt = 0;

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

  /// The credential to probe with, or `null` for an unauthenticated probe.
  ///
  /// Null whenever the disclosure is closed or the scheme is `none`, so the
  /// request is byte for byte what it was before this control existed.
  ///
  /// Also what the review form is seeded with ([_buildAiReview]), so the
  /// monitor is created with the credential its analysis was actually read
  /// through: one source, so the probe and the monitor can never disagree.
  Map<String, dynamic>? _analyzeAuthConfig() {
    if (!_authDisclosureOpen) return null;

    return _credential.toWireMap();
  }

  /// Runs the live AI analyze and owns the whole run, not one request.
  ///
  /// **THE ONE CALL IS NO LONGER THE WHOLE OPERATION.** `POST /monitors/analyze`
  /// answers 202 and a worker does the model calls, so the await below is up to
  /// four minutes long: it still settles exactly once per operator action (the
  /// contract [MonitorController.analyze] pins), but the analyzing card has to
  /// render [MonitorController.analyzeProgress] the whole time it is pending, and
  /// it does: this state rebuilds on every `refreshUI()` the controller emits, so
  /// the step rows advance without this method touching them.
  ///
  /// What this method still decides is the exit. Four of them:
  /// - an analysis: flip to [_AiStep.review], prefilled from it;
  /// - a plan wall: the controller already showed the upgrade dialog, so bounce
  ///   to the input step silently;
  /// - a lost run: the run's cache entry is gone (evicted or expired), which is
  ///   NOT a fault of the target, so it gets its own copy;
  /// - anything else: the generic analyze-failed line.
  ///
  /// An incomplete credential never leaves the client: it would come back as a
  /// 422 the input card can only report as "check that the URL is reachable",
  /// which is a wrong diagnosis of a URL that is fine.
  Future<void> _analyze() async {
    final Map<String, dynamic>? authConfig = _analyzeAuthConfig();
    final Map<String, String> credentialErrors = authConfig == null
        ? const <String, String>{}
        : validateMonitorCredential(_credential);
    if (credentialErrors.isNotEmpty) {
      setState(() => _credentialErrors = credentialErrors);

      return;
    }

    final int attempt = ++_analyzeAttempt;
    setState(() {
      _step = _AiStep.analyzing;
      _analyzeError = null;
      _credentialErrors = const <String, String>{};
    });
    final MonitorAnalysis? result = await controller.analyze(
      _url,
      authConfig: authConfig,
    );
    // Two ways this continuation no longer speaks for the screen, and both are
    // reachable now that the await spans minutes rather than one request: the
    // view was disposed, or the run it belongs to was abandoned (switching mode
    // settles the future with null exactly as a failure does, and reporting a
    // failure the operator caused by leaving would be a lie).
    if (!mounted || attempt != _analyzeAttempt) return;

    if (result == null) {
      setState(() {
        _step = _AiStep.input;
        _analyzeError = _analyzeFailureMessage();
      });
      return;
    }
    setState(() {
      _analysis = result;
      _analyzeError = null;
      _step = _AiStep.review;
    });
  }

  /// The input-card line for a run that produced no analysis, or `null` when the
  /// operator has already been told why by something else.
  ///
  /// A plan wall has shown its own upgrade dialog and the URL is fine, so the
  /// reachability hint would be a wrong diagnosis. [AnalyzeFailure.lost] is the
  /// same class of wrongness for a different reason and it is the one this app
  /// used to get wrong: the run's cache entry was evicted or expired, so the
  /// target was never the problem and there is nothing to check. Every other
  /// cause keeps the generic line.
  String? _analyzeFailureMessage() {
    if (controller.lastAnalyzeWasGated) return null;

    if (controller.analyzeProgress?.failure == AnalyzeFailure.lost) {
      return trans('uptizm.monitors.create_ai_analyze_lost');
    }

    return trans('uptizm.monitors.create_ai_analyze_failed');
  }

  /// Switches the setup mode and resets the AI step to [_AiStep.input],
  /// dropping any previously resolved analysis (React `switchMode`).
  ///
  /// It also ABANDONS the run, and that is not tidying: the controller polls the
  /// run every 2500ms for up to four minutes, so a run nobody is watching keeps
  /// costing a request until it happens to finish. The attempt bump above it is
  /// what keeps the abandoned run's own continuation from painting a failure on
  /// the card the operator just switched to.
  void _switchMode(_CreateMode next) {
    _analyzeAttempt++;
    controller.abandonAnalyzeRun();
    setState(() {
      _mode = next;
      _step = _AiStep.input;
      _analysis = null;
      _analyzeError = null;
    });
  }

  /// Abandons any run still in flight when the screen goes away.
  ///
  /// Same reason as [_switchMode]: the poll outlives this widget (it lives on the
  /// controller, which is a container singleton), so without this an operator who
  /// navigates away mid-analyze leaves a read going out every 2500ms with nothing
  /// left to render it.
  @override
  void onClose() {
    controller.abandonAnalyzeRun();
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
    final List<Map<String, dynamic>> metrics = _acceptedMetricRows();

    return controller.create({
      ...fields,
      if (metrics.isNotEmpty) 'metrics': metrics,
    });
  }

  /// The suggested metrics the operator did not decline, as `metrics[]` rows.
  ///
  /// Empty on the manual path and on an analysis that proposed nothing, in
  /// which case the key is omitted entirely rather than sent as `[]`, so a
  /// manual create is byte for byte the request it was before this existed.
  ///
  /// The rename from the analyze response's wire shape to the write endpoint's
  /// column shape lives on [AiMetricSeed.toCreateRow], beside the fields it
  /// renames, rather than here.
  List<Map<String, dynamic>> _acceptedMetricRows() {
    final List<AiMetricSeed> suggested = _analysis?.suggestedMetrics ?? const [];

    return [
      for (final AiMetricSeed metric in suggested)
        if (!_declinedMetricKeys.contains(metric.key)) metric.toCreateRow(),
    ];
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
        _buildAuthDisclosure(),
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

  /// Builds the credential disclosure under the URL field: a switch row and,
  /// once it is on, the shared [MonitorCredentialFields] block.
  ///
  /// Closed by default, because a public URL is the ordinary case and a probe
  /// that needs no credential must stay one field and one button. Open, it
  /// probes the endpoint the way the monitor eventually will, so the analysis
  /// reads the real payload instead of a 401 page.
  ///
  /// The same widget the monitor form renders, not a second copy: one credential
  /// UI means one place where a secret is composed, obscured and bounded.
  Widget _buildAuthDisclosure() {
    return WDiv(
      className: 'mt-5 flex flex-col gap-3',
      children: [
        WDiv(
          className: 'flex flex-row items-center gap-3',
          children: [
            MSSwitch(
              value: _authDisclosureOpen,
              onChanged: (value) => setState(() {
                _authDisclosureOpen = value;
                _credentialErrors = const <String, String>{};
              }),
              semanticLabel: trans('uptizm.monitors.create_ai_auth_toggle'),
            ),
            WText(
              trans('uptizm.monitors.create_ai_auth_toggle'),
              className: 'min-w-0 text-sm text-fg',
            ),
          ],
        ),
        if (_authDisclosureOpen)
          WText(
            trans('uptizm.monitors.create_ai_auth_hint'),
            className: 'text-xs text-fg-muted',
          ),
        if (_authDisclosureOpen)
          MonitorCredentialFields(
            value: _credential,
            errors: _credentialErrors,
            onChanged: (next) => setState(() {
              _credential = next;
              _credentialErrors = const <String, String>{};
            }),
          ),
      ],
    );
  }

  /// Builds the AI analyzing step: the same ai-wash card with the "Analyzing
  /// endpoint…" title, the URL echoed in monospace, and one [kAnalyzeSteps] row
  /// per backend ordinal, each rendering that ordinal's real state.
  ///
  /// The rows used to be five identical spinners on nothing but a mounted widget,
  /// so the card said the same thing whatever the worker was doing and kept
  /// saying it after the worker had stopped. Now every row is derived from
  /// [MonitorController.analyzeProgress] ([_analyzeRowState]).
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
            // 1-BASED ORDINALS, and the index is the contract: entry N of
            // [kAnalyzeSteps] is the label for ordinal N of the backend's
            // `AnalyzeMonitorJob::STEPS`, which is what the run reports on. See
            // that getter's docblock for the mapping and for the test that pins
            // the count from the backend side.
            for (int ordinal = 1; ordinal <= kAnalyzeSteps.length; ordinal++)
              _buildAnalyzeRow(ordinal, kAnalyzeSteps[ordinal - 1]),
          ],
        ),
      ],
    );
  }

  /// The visual state of the analyze row at [ordinal], derived from the run
  /// [MonitorController.analyzeProgress] publishes.
  ///
  /// **SKIPPED IS NOT DECORATION, it is the state a naive implementation omits.**
  /// The worker reports a step `skipped` when it genuinely did not run: there was
  /// no response body to digest, no response time to detect against, or no budget
  /// left for the model call. At least one step routinely lands there, so a
  /// client with only pending/running/done would leave that row spinning on work
  /// that was never going to happen, for as long as the operator watched.
  ///
  /// The derivation reflects the wire's asymmetry, which is deliberate: ticks are
  /// TERMINAL ONLY (`done` / `skipped` / `failed`), nothing ever reports
  /// `running`, and the row in flight is the one after the last terminal tick
  /// ([AnalyzeRunProgress.inFlightStep]). No row can therefore claim to be
  /// working on its own behalf, which is what makes an eternal spinner
  /// structurally impossible rather than merely defended against.
  _AnalyzeRowState _analyzeRowState(int ordinal) {
    final AnalyzeRunProgress? progress = controller.analyzeProgress;
    // A null run means the accept itself is still in flight, and the relay probe
    // runs INSIDE that accepting request, so ordinal 1 is genuinely the one
    // working. Past the accept the run answers for itself, and a terminal run
    // answers `null` (nothing is in flight any more).
    final int? inFlight = progress == null ? 1 : progress.inFlightStep;

    return switch (progress?.stateOf(ordinal)) {
      AnalyzeStepState.done => _AnalyzeRowState.done,
      AnalyzeStepState.skipped => _AnalyzeRowState.skipped,
      AnalyzeStepState.failed => _AnalyzeRowState.failed,
      null => ordinal == inFlight
          ? _AnalyzeRowState.running
          : _AnalyzeRowState.pending,
    };
  }

  /// Builds a single analyze-step row: a state glyph followed by the step label,
  /// and for a skipped step the note that says why nothing happened.
  ///
  /// Each of the five states reads differently at a glance AND in words. The
  /// glyph alone would leave "skipped" and "done" a colour apart, which is
  /// exactly the distinction an operator has to be able to make: one step
  /// produced a finding, the other had nothing to produce. The running spinner is
  /// a Wind `animate-spin` [WIcon] tinted `text-ai`, the same motion the card
  /// used before any of this state existed; every colour is an alias or status
  /// token carrying its own `dark:` pair, so no raw [Color] is resolved here.
  Widget _buildAnalyzeRow(int ordinal, String label) {
    final _AnalyzeRowState state = _analyzeRowState(ordinal);

    return WDiv(
      className: 'flex flex-row items-center gap-3',
      children: [
        WIcon(
          switch (state) {
            _AnalyzeRowState.pending => Icons.radio_button_unchecked,
            _AnalyzeRowState.running => Icons.autorenew,
            _AnalyzeRowState.done => Icons.check_circle_outline,
            _AnalyzeRowState.skipped => Icons.remove_circle_outline,
            _AnalyzeRowState.failed => Icons.error_outline,
          },
          className: switch (state) {
            _AnalyzeRowState.pending => 'size-4 shrink-0 text-fg-disabled',
            _AnalyzeRowState.running =>
              'size-4 shrink-0 text-ai animate-spin',
            _AnalyzeRowState.done => 'size-4 shrink-0 text-up',
            _AnalyzeRowState.skipped => 'size-4 shrink-0 text-paused',
            _AnalyzeRowState.failed => 'size-4 shrink-0 text-destructive',
          },
        ),
        WText(
          label,
          className: switch (state) {
            _AnalyzeRowState.pending => 'text-sm text-fg-muted',
            _AnalyzeRowState.running => 'text-sm text-fg',
            _AnalyzeRowState.done => 'text-sm text-fg',
            _AnalyzeRowState.skipped => 'text-sm text-fg-muted',
            _AnalyzeRowState.failed => 'text-sm text-destructive',
          },
        ),
        // The word, not just the tone: a skipped step is a claim about what the
        // analysis did NOT do, and a muted glyph is not a claim anybody reads.
        if (state == _AnalyzeRowState.skipped)
          WText(
            trans('uptizm.monitors.create_ai_analyze_skipped_note'),
            className: 'text-xs text-paused',
          ),
      ],
    );
  }

  /// Builds the AI review step: the [MonitorForm] pre-filled with the AI's
  /// choices, behind the AI summary banner. React lines 152-204.
  ///
  /// [_analysis] carries the completed run's analysis by the time this step
  /// renders (set right before the [_AiStep.review] transition, from what
  /// [MonitorController.analyze] resolved with; a run is only ever reviewed once
  /// it has completed). Held locally rather than read back off the controller so
  /// the form under review cannot be blanked by anything that later abandons the
  /// run. The `_aiName`/default-regions fallbacks only guard against a null value
  /// defensively, they are never exercised in the wired flow.
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
      // The credential the probe just authenticated with, carried into the
      // review form secret and all, because this step is where the monitor is
      // created: without it the created monitor holds no credential and its
      // first check answers 401 on the endpoint the analysis just read. Null
      // whenever the disclosure stayed closed, so a public URL is unaffected.
      initialPendingAuthConfig: _analyzeAuthConfig(),
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
  /// "AI configured this monitor" title with the decoded [AiConfidenceBadge],
  /// the real backend [MonitorAnalysis.rationale] (never a canned template
  /// that asserts measurements nobody took), and (when the backend actually
  /// proposed any) the suggested-metrics pills + help text. React lines
  /// 164-202.
  ///
  /// The suggested-metrics section is sourced from [_analysis]'s
  /// [MonitorAnalysis.suggestedMetrics] rather than a fixture, and renders
  /// nothing at all when that list is empty: an absent suggestion is honest,
  /// a placeholder metric is not.
  Widget _buildReviewBanner() {
    final List<AiMetricSeed> suggestedMetrics =
        _analysis?.suggestedMetrics ?? const [];
    final String rationale = _analysis?.rationale ?? '';
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
                AiConfidenceBadge(_analysis?.confidence ?? AiConfidence.low),
              ],
            ),

            // 2. What the backend actually said. A degraded analyze (budget
            //    exhausted or the model unavailable) carries an empty
            //    rationale, so a neutral line replaces the model narration
            //    rather than rendering an empty box or inventing one.
            WText(
              rationale.isNotEmpty
                  ? rationale
                  : trans('uptizm.monitors.create_ai_review_no_rationale'),
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

  /// Builds a single suggested-metric pill: the label, the observed sample
  /// beside the proposed bound, and a tap target that declines the metric.
  ///
  /// The observation and the proposal are shown TOGETHER on purpose. A
  /// threshold is a policy default and not a measurement (the deterministic
  /// path ships `max(500, observed * 3)`), so a pill that showed only "warn at
  /// 400" would read as something the probe found. Showing "observed 120 ms,
  /// warn at 400" puts the one number that was measured next to the one that
  /// was chosen, and the help line underneath says the observation is a single
  /// reading.
  ///
  /// Declining is a tap on the whole pill rather than a separate control: the
  /// pill is already small, and the declined state is legible from the pill
  /// itself (muted, struck through) rather than from a checkbox beside it.
  Widget _buildMetricPill(AiMetricSeed metric) {
    final bool declined = _declinedMetricKeys.contains(metric.key);
    final String detail = _metricPillDetail(metric);

    // WAnchor rather than a bare GestureDetector, the same reason
    // MonitorListRow and the metrics tab use it: it sets the pointer cursor and
    // drives the `hover:` state, so the pill reads as something you can press.
    return WAnchor(
      onTap: () => setState(() {
        if (!_declinedMetricKeys.remove(metric.key)) {
          _declinedMetricKeys.add(metric.key);
        }
      }),
      child: WDiv(
        className: declined
            ? 'flex flex-row items-center gap-1 rounded-md border border-color-border-subtle bg-surface-container px-2 py-0.5 hover:bg-surface transition-colors'
            : 'flex flex-row items-center gap-1 rounded-md border border-color-border bg-surface px-2 py-0.5 hover:bg-surface-container transition-colors',
        children: [
          WText(
            metric.label,
            className: declined
                ? 'text-xs text-fg-disabled line-through'
                : 'text-xs text-fg',
          ),
          if (detail.isNotEmpty)
            WText(
              detail,
              className: declined
                  ? 'font-mono text-xs text-fg-disabled'
                  : 'font-mono text-xs text-fg-muted',
            ),
        ],
      ),
    );
  }

  /// The monospace half of a metric pill: what was observed, and what is being
  /// proposed on top of it.
  ///
  /// Degrades in the honest direction. With no sample there is nothing measured
  /// to report, so only the unit shows; with a sample but no bound the pill
  /// says what was seen and claims nothing further.
  String _metricPillDetail(AiMetricSeed metric) {
    final String unit = metric.unit.isNotEmpty ? ' ${metric.unit}' : '';

    if (metric.sampleValue.isEmpty) return metric.unit;

    if (metric.warn.isEmpty) {
      return trans('uptizm.monitors.create_ai_metric_observed', {
        'observed': '${metric.sampleValue}$unit',
      });
    }

    return trans('uptizm.monitors.create_ai_metric_observed_warn', {
      'observed': '${metric.sampleValue}$unit',
      'warn': metric.warn,
    });
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
