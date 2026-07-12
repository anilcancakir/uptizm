import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/mocks/status.dart';
import 'package:uptizm/app/mocks/status_pages.dart';
import 'package:uptizm/app/models/status_page.dart';
import 'package:uptizm/ui/components/status_page_preview/index.dart';

void main() {
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
    final StatusPageConfig source = statusPages.first;
    final StatusPage config = statusPageFromConfig(source);

    await tester.pumpWidget(wrap(StatusPagePreview(config: config)));
    await tester.pump();

    expect(tester.takeException(), isNull);
    expect(find.text(source.name), findsOneWidget);
    expect(find.textContaining('powered by Uptizm'), findsOneWidget);
  });

  testWidgets('an outage config renders the major-outage banner label', (
    tester,
  ) async {
    // `checkout` is down in the uptime-history fixture; assigning only it
    // forces the overall status to `down`.
    final StatusPage config = statusPageFromConfig(
      statusPages.first.copyWith(monitorIds: const ['checkout']),
    );

    await tester.pumpWidget(wrap(StatusPagePreview(config: config)));
    await tester.pump();

    expect(tester.takeException(), isNull);
    expect(find.text('Major outage'), findsOneWidget);
  });

  testWidgets('a config with no assigned monitors renders the empty placeholder', (
    tester,
  ) async {
    final StatusPage config = statusPageFromConfig(
      statusPages.first.copyWith(monitorIds: const [], metricKeys: const []),
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
