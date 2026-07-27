import 'package:flutter/material.dart' show Icons, CircularProgressIndicator;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/enums/ai_confidence.dart';
import '../../../app/support/digest_types.dart';
import '../../../ui/components/ai_insight/index.dart';
import '../../../ui/components/kpi_stat_card/index.dart';
import '../../../ui/layouts/page_container.dart';

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
class WeeklyDigestView extends StatefulWidget {
  /// Creates the [WeeklyDigestView].
  const WeeklyDigestView({super.key});

  @override
  State<WeeklyDigestView> createState() => _WeeklyDigestViewState();
}

/// The four render phases of the digest fetch.
enum _DigestPhase { loading, ready, empty, error, gated }

class _WeeklyDigestViewState extends State<WeeklyDigestView> {
  _DigestPhase _phase = _DigestPhase.loading;
  WeeklyDigest? _digest;

  /// The plan wall the digest read hit, when it did.
  ///
  /// A plan refusal is not a read failure: the generic error state offered a
  /// Retry that could never succeed, so [_DigestPhase.gated] renders the wall
  /// with its upgrade action instead.
  PlanUpgradeRequirement? _gate;

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// Fetches the live digest. A 404 means no digest has been generated yet (an
  /// honest empty state, not an error); any other non-2xx or a thrown transport
  /// error surfaces the error state so the read failure is never swallowed into
  /// a misleading "no digest" claim.
  Future<void> _load() async {
    setState(() => _phase = _DigestPhase.loading);
    try {
      final MagicResponse response = await Http.get('/incidents/digest');
      if (!mounted) return;
      final Object? payload = response.data;
      final Object? data =
          payload is Map<String, dynamic> ? payload['data'] : null;
      if (response.successful && data is Map<String, dynamic>) {
        setState(() {
          _digest = WeeklyDigest.fromMap(data);
          _phase = _DigestPhase.ready;
        });
      } else if (response.statusCode == 404) {
        setState(() => _phase = _DigestPhase.empty);
      } else {
        final PlanUpgradeRequirement? gate = PlanUpgradeRequirement.fromResponse(
          response,
        );
        setState(() {
          _gate = gate;
          _phase = gate != null ? _DigestPhase.gated : _DigestPhase.error;
        });
      }
    } catch (e, stackTrace) {
      Log.error('[WeeklyDigestView._load] $e\n$stackTrace');
      if (mounted) setState(() => _phase = _DigestPhase.error);
    }
  }

  @override
  Widget build(BuildContext context) {
    return PageContainer(
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
    final WeeklyDigest? d = _digest;
    if (d != null && d.weekStart != null && d.weekEnd != null) {
      return trans('uptizm.digest.week_range', {
        'start': d.weekStart!,
        'end': d.weekEnd!,
      });
    }
    return trans('uptizm.digest.description');
  }

  List<Widget> _buildBody() {
    switch (_phase) {
      case _DigestPhase.loading:
        return const [
          WDiv(
            className: 'py-16 flex items-center justify-center',
            child: CircularProgressIndicator(),
          ),
        ];
      case _DigestPhase.empty:
        return [
          MSEmptyState(
            icon: Icons.auto_awesome_outlined,
            title: trans('uptizm.digest.empty_title'),
            description: trans('uptizm.digest.empty_description'),
          ),
        ];
      case _DigestPhase.error:
        return [
          MSErrorState(
            title: trans('uptizm.digest.error_title'),
            description: trans('uptizm.digest.error_description'),
            action: MSButton(
              size: ButtonSize.sm,
              onPressed: _load,
              child: WText(trans('uptizm.digest.error_retry')),
            ),
          ),
        ];
      case _DigestPhase.gated:
        final PlanUpgradeRequirement gate = _gate!;
        return [
          MSUpgradeNudge(
            message: gate.message,
            requiredPlan: gate.planLabel,
            onUpgrade: () => UpgradePrompt.startUpgrade(gate.requiredPlan),
          ),
        ];
      case _DigestPhase.ready:
        return _buildDigest(_digest!);
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

  /// Title-cases the confidence enum name for display (`high` -> `High`).
  String _confidenceLabel(AiConfidence c) {
    final String name = c.name;
    return name[0].toUpperCase() + name.substring(1);
  }

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
                  className:
                      'flex flex-row items-start gap-3 px-5 py-3.5'
                      '${i == highlights.length - 1 ? '' : ' border-b border-color-border'}',
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
