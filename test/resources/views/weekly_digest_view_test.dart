import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/resources/views/incidents/weekly_digest_view.dart';
import 'package:uptizm/ui/components/empty_state/index.dart';
import 'package:uptizm/ui/components/kpi_stat_card/index.dart';

/// In-memory loader feeding the digest labels so [trans] returns real wrappable
/// strings instead of raw dot-separated i18n keys (which render as long
/// unbreakable strings and overflow the KPI cells at the test viewport).
class _DigestLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'uptizm.digest.title': 'Weekly AI digest',
      'uptizm.digest.description': 'What Uptizm observed this week.',
      'uptizm.digest.back': 'Dashboard',
      'uptizm.digest.insight_label': 'This week',
      'uptizm.digest.week_range': ':start to :end',
      'uptizm.digest.kpi_uptime_label': 'Uptime',
      'uptizm.digest.kpi_uptime_hint': 'this week',
      'uptizm.digest.kpi_incidents_label': 'Incidents',
      'uptizm.digest.kpi_incidents_hint': 'opened this week',
      'uptizm.digest.kpi_confidence_label': 'AI confidence',
      'uptizm.digest.kpi_confidence_hint': 'in this digest',
      'uptizm.digest.section_highlights': 'Highlights',
      'uptizm.digest.generated_prefix': 'Generated :date',
      'uptizm.digest.empty_title': 'No digest yet',
      'uptizm.digest.empty_description': 'A digest lands here every Monday.',
      'uptizm.digest.error_title': "Couldn't load the digest",
      'uptizm.digest.error_description': 'Try again in a moment.',
      'uptizm.digest.error_retry': 'Retry',
    };
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    Magic.singleton('magic_starter', () => MagicStarterManager());
    Translator.instance.setLoader(_DigestLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    Http.unfake();
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

  Map<String, dynamic> digestPayload() => {
    'data': {
      'week_start': '2026-06-16',
      'week_end': '2026-06-22',
      'uptime_percent': 99.44,
      'incident_count': 4,
      'confidence': 'high',
      'summary': 'Uptime dipped to 99.44% after two Checkout blips.',
      'highlights': [
        'Checkout degraded twice on Tuesday.',
        'API recovered on its own within the SLO.',
      ],
      'generated_at': '2026-06-22T09:00:00Z',
    },
  };

  /// Pumps until the async fetch resolves and the phase leaves loading.
  Future<void> settle(WidgetTester tester) async {
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
  }

  testWidgets('renders the live digest summary, KPIs, and highlights', (
    tester,
  ) async {
    Http.fake({'*incidents/digest': Http.response(digestPayload())});

    await tester.pumpWidget(wrap(const WeeklyDigestView()));
    await settle(tester);

    // The AI summary renders inside the AiInsight banner (which folds the label
    // + body WText into one RichText paragraph, so match by substring), and the
    // highlights render as their own rows.
    expect(
      find.textContaining('Uptime dipped to 99.44% after two Checkout blips.'),
      findsOneWidget,
    );
    expect(find.text('Checkout degraded twice on Tuesday.'), findsOneWidget);
    // Three KPI cards: uptime, incidents, confidence.
    expect(find.byType(KpiStatCard), findsNWidgets(3));
    // KpiStatCard uppercases its label via Wind.
    expect(find.text('UPTIME'), findsOneWidget);
    expect(find.text('99.44%'), findsOneWidget);
  });

  testWidgets('shows an honest empty state when no digest exists (404)', (
    tester,
  ) async {
    Http.fake({'*incidents/digest': Http.response(<String, dynamic>{}, 404)});

    await tester.pumpWidget(wrap(const WeeklyDigestView()));
    await settle(tester);

    expect(find.byType(EmptyState), findsOneWidget);
    expect(find.text('No digest yet'), findsOneWidget);
    // No KPI cards render in the empty state.
    expect(find.byType(KpiStatCard), findsNothing);
  });

  testWidgets('renders the back-aware header in every phase', (tester) async {
    Http.fake({'*incidents/digest': Http.response(digestPayload())});

    await tester.pumpWidget(wrap(const WeeklyDigestView()));
    await settle(tester);

    expect(find.byType(MSPageHeader), findsOneWidget);
    expect(find.text('Weekly AI digest'), findsOneWidget);
  });

  testWidgets('does not overflow at a mobile width', (tester) async {
    Http.fake({'*incidents/digest': Http.response(digestPayload())});

    await tester.pumpWidget(
      wrap(const WeeklyDigestView(), size: const Size(360, 3200)),
    );
    await settle(tester);

    expect(tester.takeException(), isNull);
  });
}
