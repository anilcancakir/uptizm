import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/resources/views/incidents/weekly_digest_view.dart';
import 'package:uptizm/ui/components/kpi_stat_card/index.dart';
import 'package:uptizm/ui/components/status_badge/index.dart';

/// In-memory loader feeding the digest prose + status labels so [trans] returns
/// real wrappable strings instead of raw dot-separated i18n keys (which render
/// as long unbreakable strings and overflow the badge / KPI cells at the test
/// viewport). Mirrors the monitor-detail view test's loader pattern.
class _DigestLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'uptizm.status.up': 'Operational',
      'uptizm.status.down': 'Major outage',
      'uptizm.status.degraded': 'Degraded',
      'uptizm.status.paused': 'Paused',
      'uptizm.status.info': 'Maintenance',
      'uptizm.status.ai': 'AI',
      'uptizm.digest.title': 'Weekly AI digest',
      'uptizm.digest.description': 'What Uptizm did this week.',
      'uptizm.digest.back': 'Dashboard',
      'uptizm.digest.insight_label': 'This week',
      'uptizm.digest.insight_body':
          'Caught :caught, resolved :resolved, dismissed :dismissed.',
      'uptizm.digest.kpi_detected_label': 'Incidents detected',
      'uptizm.digest.kpi_detected_hint': 'by AI',
      'uptizm.digest.kpi_resolved_label': 'Auto-resolved',
      'uptizm.digest.kpi_resolved_hint': 'Auto mode',
      'uptizm.digest.kpi_dismissed_label': 'Anomalies dismissed',
      'uptizm.digest.kpi_dismissed_hint': 'your feedback',
      'uptizm.digest.kpi_confidence_label': 'Median confidence',
      'uptizm.digest.kpi_confidence_value': 'Medium',
      'uptizm.digest.kpi_confidence_hint': 'on detections',
      'uptizm.digest.section_caught': 'Caught by AI',
      'uptizm.digest.section_dismissed': 'Dismissed anomalies',
      'uptizm.digest.feedback_note': 'Folded into the baseline.',
    };
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card / PageHeader resolve their themes
    // via MagicStarter.* without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());
    Translator.instance.setLoader(_DigestLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  Widget wrap(Widget widget, {Size size = const Size(1280, 2200)}) {
    return MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(size: size),
        child: WindTheme(
          data: WindThemeData(),
          child: Scaffold(body: SingleChildScrollView(child: widget)),
        ),
      ),
    );
  }

  // The AI-owned incidents the digest surfaces in "Caught by AI".
  final List<IncidentSummary> aiIncidents = incidents
      .where((i) => i.aiOwned)
      .toList();

  testWidgets('WeeklyDigestView renders the back-aware page header', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const WeeklyDigestView()));
    await tester.pump();

    expect(find.byType(MSPageHeader), findsOneWidget);
    expect(find.text('Weekly AI digest'), findsOneWidget);
  });

  testWidgets('WeeklyDigestView renders the four KPI summary cards', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const WeeklyDigestView()));
    await tester.pump();

    expect(find.byType(KpiStatCard), findsNWidgets(4));
    // KpiStatCard renders its label through Wind's `uppercase`, which transforms
    // the string, so match the uppercased form.
    expect(find.text('INCIDENTS DETECTED'), findsOneWidget);
    expect(find.text('ANOMALIES DISMISSED'), findsOneWidget);
  });

  testWidgets('WeeklyDigestView renders both section headings', (tester) async {
    await tester.pumpWidget(wrap(const WeeklyDigestView()));
    await tester.pump();

    expect(find.text('Caught by AI'), findsOneWidget);
    expect(find.text('Dismissed anomalies'), findsOneWidget);
  });

  testWidgets(
    'WeeklyDigestView renders one StatusBadge per AI-caught incident',
    (tester) async {
      await tester.pumpWidget(wrap(const WeeklyDigestView()));
      await tester.pump();

      expect(find.byType(StatusBadge), findsNWidgets(aiIncidents.length));
      expect(aiIncidents, isNotEmpty);
    },
  );

  testWidgets('WeeklyDigestView renders the two dismissed-anomaly reasons', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const WeeklyDigestView()));
    await tester.pump();

    expect(find.text('Expected pattern'), findsOneWidget);
    expect(find.text('Not an anomaly (noise)'), findsOneWidget);
  });

  testWidgets('WeeklyDigestView does not overflow at a mobile width', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(const WeeklyDigestView(), size: const Size(360, 3200)),
    );
    await tester.pump();

    expect(tester.takeException(), isNull);
  });
}
