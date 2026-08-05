import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/app/support/status_page_support.dart' show cloneStatusPage;
import 'package:uptizm/app/mocks/status_pages.dart';
import 'package:uptizm/app/models/status_page.dart';
import 'package:uptizm/ui/components/status_page_preview/index.dart';

/// Feeds the status-page preview copy (banner labels, section headings,
/// subscribe box, footer) so [trans] returns real English prose instead of the
/// raw key tokens.
class _PreviewLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async => {
    'uptizm.status.banner_operational': 'All systems operational',
    'uptizm.status.banner_degraded': 'Degraded performance',
    'uptizm.status.banner_outage': 'Major outage',
    'uptizm.status.banner_maintenance': 'Maintenance in progress',
    'uptizm.status.banner_pending': 'Awaiting first checks',
    'uptizm.status.banner_paused': 'Some components paused',
    'uptizm.status.preview_default_name': 'Status',
    'uptizm.status.preview_updated_ago': 'updated 2m ago',
    'uptizm.status.preview_live_metrics_heading': 'Live metrics',
    'uptizm.status.preview_components_heading': 'Components',
    'uptizm.status.preview_components_empty':
        'No components yet. Assign monitors to show their status here.',
    'uptizm.status.preview_past_incidents_heading': 'Past incidents',
    'uptizm.status.preview_subscribe_heading': 'Subscribe to updates',
    'uptizm.status.preview_subscribe_description':
        'Get notified by email when an incident is opened, updated, or resolved.',
    'uptizm.status.preview_subscribe_placeholder': 'you@example.com',
    'uptizm.status.preview_subscribe_button': 'Subscribe',
    'uptizm.status.preview_powered_by': 'powered by Uptizm',
  };
}

void main() {
  setUp(() async {
    Translator.instance.setLoader(_PreviewLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme] so
  /// W-widgets can resolve Wind styles without a running Magic app, mirroring
  /// `status_dot_test.dart`.
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: SingleChildScrollView(child: widget)),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // statusPageBannerTones (recipe/tone map assertions, bulletproof)
  // ---------------------------------------------------------------------------

  group('statusPageBannerTones', () {
    test('every StatusKey has a tone entry', () {
      for (final StatusKey status in StatusKey.values) {
        expect(
          statusPageBannerTones.containsKey(status),
          isTrue,
          reason: '${status.name} is missing a banner tone',
        );
      }
    });

    test('up uses the up-soft family', () {
      final tone = statusPageBannerTones[StatusKey.up]!;
      expect(tone.box, 'bg-up-soft');
      expect(tone.text, 'text-up-soft-foreground');
      expect(tone.dot, 'bg-up');
      expect(tone.label, 'All systems operational');
    });

    test('down uses the down-soft family', () {
      final tone = statusPageBannerTones[StatusKey.down]!;
      expect(tone.box, 'bg-down-soft');
      expect(tone.text, 'text-down-soft-foreground');
      expect(tone.dot, 'bg-down');
    });

    test('degraded uses the degraded-soft family', () {
      final tone = statusPageBannerTones[StatusKey.degraded]!;
      expect(tone.box, 'bg-degraded-soft');
      expect(tone.text, 'text-degraded-soft-foreground');
      expect(tone.dot, 'bg-degraded');
    });

    test('info uses the info-soft family', () {
      final tone = statusPageBannerTones[StatusKey.info]!;
      expect(tone.box, 'bg-info-soft');
      expect(tone.text, 'text-info-soft-foreground');
      expect(tone.dot, 'bg-info');
    });

    test('paused uses the paused-soft family', () {
      final tone = statusPageBannerTones[StatusKey.paused]!;
      expect(tone.box, 'bg-paused-soft');
      expect(tone.text, 'text-paused-soft-foreground');
      expect(tone.dot, 'bg-paused');
    });

    test('ai is folded into the up tone (an AI-owned all-clear page)', () {
      final tone = statusPageBannerTones[StatusKey.ai]!;
      expect(tone.box, 'bg-up-soft');
      expect(tone.text, 'text-up-soft-foreground');
      expect(tone.dot, 'bg-up');
    });
  });

  // ---------------------------------------------------------------------------
  // Shell/section className constants (bulletproof)
  // ---------------------------------------------------------------------------

  group('shell and section classNames', () {
    test('the shell className bounds width and centers the column', () {
      expect(statusPagePreviewShellClassName, contains('mx-auto'));
      expect(statusPagePreviewShellClassName, contains('max-w-2xl'));
    });

    test('the components box className carries the surface token pair', () {
      expect(statusPagePreviewComponentsBoxClassName, contains('bg-surface'));
      expect(
        statusPagePreviewComponentsBoxClassName,
        contains('border-color-border'),
      );
    });

    test('the empty placeholder className is dashed', () {
      expect(
        statusPagePreviewEmptyPlaceholderClassName,
        contains('border-dashed'),
      );
    });
  });

  // ---------------------------------------------------------------------------
  // Widget smoke
  // ---------------------------------------------------------------------------

  testWidgets('renders the page name and the powered-by footer', (
    tester,
  ) async {
    final StatusPage config = statusPages.first;

    await tester.pumpWidget(wrap(StatusPagePreview(config: config)));
    await tester.pump();

    expect(tester.takeException(), isNull);
    expect(find.text(config.name!), findsOneWidget);
    expect(find.textContaining('powered by Uptizm'), findsOneWidget);
  });

  testWidgets('an outage config renders the major-outage banner label', (
    tester,
  ) async {
    // The component's health now comes from the pivot's own `last_status`, the
    // shape StatusPageResource sends, rather than from resolving its id against
    // a fixture monitor list (which could never match a real uuid).
    final StatusPage config = cloneStatusPage(
      statusPages.first,
      components: const <Map<String, dynamic>>[
        <String, dynamic>{
          'id': 'checkout',
          'name': 'Checkout',
          'display_order': 0,
          'last_status': 'down',
        },
      ],
    );

    await tester.pumpWidget(wrap(StatusPagePreview(config: config)));
    await tester.pump();

    expect(tester.takeException(), isNull);
    expect(find.text('Major outage'), findsOneWidget);
  });

  testWidgets('a config with no assigned monitors renders the empty placeholder', (
    tester,
  ) async {
    final StatusPage config = cloneStatusPage(
      statusPages.first,
      monitorIds: const [],
    );

    await tester.pumpWidget(wrap(StatusPagePreview(config: config)));
    await tester.pump();

    expect(tester.takeException(), isNull);
    expect(
      find.text(
        'No components yet. Assign monitors to show their status here.',
      ),
      findsOneWidget,
    );
  });
}
