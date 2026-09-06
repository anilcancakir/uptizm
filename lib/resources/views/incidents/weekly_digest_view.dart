import 'package:flutter/material.dart' show Icons, CircularProgressIndicator;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/digest_controller.dart';
import '../../../app/enums/ai_confidence.dart';
import '../../../app/support/digest_types.dart';
import '../../../ui/components/ai_insight/index.dart';
import '../../../ui/components/kpi_stat_card/index.dart';

/// **The Weekly AI digest screen at `/incidents/digest`.**
///
/// Fetches the team's live weekly digest from `GET /incidents/digest` (the
/// server-composed [WeeklyDigest]) and renders it: a "this week" [AiInsight]
/// banner carrying the AI summary, a KPI row (uptime, incidents, confidence),
/// and the AI highlights. A 404 (no digest generated yet) shows an honest
/// [MSEmptyState]; a transport/parse failure shows an [MSErrorState] with
/// retry, so a read failure is never swallowed into a misleading "no digest"
/// claim.
///
/// Rendered INSIDE [AppLayout]; reached from the dashboard AI inbox's "Weekly
/// digest" link.
///
/// ### Example
/// ```dart
/// // Registered as the routed `/incidents/digest` content (wrapped by the shell):
/// MagicStarter.view.makeLayout('layout.app', child: const WeeklyDigestView())
/// ```
@immutable
class WeeklyDigestView extends MagicStatefulView<DigestController> {
  /// Creates the [WeeklyDigestView].
  const WeeklyDigestView({super.key});

  @override
  State<WeeklyDigestView> createState() => _WeeklyDigestViewState();
}

class _WeeklyDigestViewState
    extends MagicStatefulViewState<DigestController, WeeklyDigestView> {
  @override
  void initState() {
    // Register before the base state resolves it via Magic.find<T>(), which
    // throws when unregistered. Idempotent.
    Magic.findOrPut(DigestController.new);
    super.initState();
  }

  @override
  Widget build(BuildContext context) {
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-8',
        children: [
          MSPageHeader(
            title: trans('uptizm.digest.title'),
            subtitle: _headerSubtitle(),
            backLabel: trans('uptizm.digest.back'),
            backFallback: '/',
          ),
          ..._buildBody(),
        ],
      ),
    );
  }

  /// The header subtitle: the covered week range once a digest is loaded,
  /// otherwise the generic description.
  String _headerSubtitle() {
    final WeeklyDigest? d = controller.digest;
    if (d != null && d.weekStart != null && d.weekEnd != null) {
      return trans('uptizm.digest.week_range', {
        'start': d.weekStart!,
        'end': d.weekEnd!,
      });
    }
    return trans('uptizm.digest.description');
  }

  List<Widget> _buildBody() {
    switch (controller.phase) {
      case DigestPhase.loading:
        return const [
          WDiv(
            className: 'py-16 flex items-center justify-center',
            child: CircularProgressIndicator(),
          ),
        ];
      case DigestPhase.empty:
        return [
          MSEmptyState(
            icon: Icons.auto_awesome_outlined,
            title: trans('uptizm.digest.empty_title'),
            description: trans('uptizm.digest.empty_description'),
          ),
        ];
      case DigestPhase.error:
        return [
          MSErrorState(
            title: trans('uptizm.digest.error_title'),
            description: trans('uptizm.digest.error_description'),
            action: MSButton(
              size: ButtonSize.sm,
              onPressed: controller.load,
              child: WText(trans('uptizm.digest.error_retry')),
            ),
          ),
        ];
      case DigestPhase.gated:
        final PlanUpgradeRequirement gate = controller.gate!;
        return [
          MSUpgradeNudge(
            message: gate.message,
            requiredPlan: gate.planLabel,
            onUpgrade: () => UpgradePrompt.startUpgrade(gate.requiredPlan),
          ),
        ];
      case DigestPhase.ready:
        return _buildDigest(controller.digest!);
    }
  }

  List<Widget> _buildDigest(WeeklyDigest d) {
    return [
      WDiv(
        className: 'flex flex-col gap-6',
        children: [
          // No lead-in label: the AI summary is self-contained prose (it opens
          // with its own "This week ..."), so a "This week" label would read as
          // a stutter. The sparkle glyph alone marks it as the AI narrative.
          AiInsight(
            tone: 'banner',
            child: WText(d.summary, className: 'text-sm text-fg'),
          ),
          _buildKpiGrid(d),
        ],
      ),
      if (d.highlights.isNotEmpty) _buildHighlights(d.highlights),
      if (d.generatedAt != null)
        WText(
          trans('uptizm.digest.generated_prefix', {'date': d.generatedAt!}),
          className: 'px-1 font-mono text-xs tabular-nums text-fg-muted',
        ),
    ];
  }

  /// The three-up KPI summary (uptime / incidents / AI confidence).
  Widget _buildKpiGrid(WeeklyDigest d) {
    return WDiv(
      className: 'grid grid-cols-1 sm:grid-cols-3 gap-4 items-stretch',
      children: [
        KpiStatCard(
          label: trans('uptizm.digest.kpi_uptime_label'),
          value: '${d.uptimePercent.toStringAsFixed(2)}%',
          hint: trans('uptizm.digest.kpi_uptime_hint'),
        ),
        KpiStatCard(
          label: trans('uptizm.digest.kpi_incidents_label'),
          value: '${d.incidentCount}',
          hint: trans('uptizm.digest.kpi_incidents_hint'),
        ),
        KpiStatCard(
          label: trans('uptizm.digest.kpi_confidence_label'),
          value: _confidenceLabel(d.confidence),
          hint: trans('uptizm.digest.kpi_confidence_hint'),
        ),
      ],
    );
  }

  /// The localized label for a confidence level.
  ///
  /// This used to title-case the enum name, so the digest's confidence KPI read
  /// "High" / "Medium" / "Low" in English on a Turkish UI. The keys it needs
  /// already existed: `ai_confidence_badge.dart` renders this same enum through
  /// them and a test pins all three.
  String _confidenceLabel(AiConfidence c) =>
      trans('uptizm.ai.confidence_${c.name}');

  /// The AI highlights: a heading over a bordered [MSCard] of check-marked rows.
  Widget _buildHighlights(List<String> highlights) {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        WText(
          trans('uptizm.digest.section_highlights'),
          className: 'text-sm font-semibold text-fg',
        ),
        MSCard(
          noPadding: true,
          child: WDiv(
            className: 'flex flex-col',
            children: [
              for (int i = 0; i < highlights.length; i++)
                WDiv(
                  // Two whole literals, the shape every other divider row in
                  // this slice already uses (`status_page_subscribers_view`,
                  // `on_call_schedule_view`, `incident_detail_view`), rather
                  // than a Dart conditional spliced into the string. The cost
                  // here is one extra cache entry, so the reason to match is
                  // that this is the pattern the next row gets copied from, and
                  // the next one may not carry a two-case constant.
                  className: i == highlights.length - 1
                      ? 'flex flex-row items-start gap-3 px-5 py-3.5'
                      : 'flex flex-row items-start gap-3 px-5 py-3.5 '
                            'border-b border-color-border',
                  children: [
                    WIcon(
                      Icons.check_circle_outline,
                      className: 'text-base text-up shrink-0 mt-0.5',
                    ),
                    WText(highlights[i], className: 'flex-1 text-sm text-fg'),
                  ],
                ),
            ],
          ),
        ),
      ],
    );
  }
}
