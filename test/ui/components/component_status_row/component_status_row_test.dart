import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/ui/components/component_status_row/index.dart';
import 'package:uptizm/ui/components/component_status_row/component_status_row.preview.dart';

/// Feeds the 90-day footer labels so [trans] returns real English prose
/// instead of the raw key tokens.
class _RowLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async => {
    'uptizm.monitors.uptime_90_days_ago': '90 days ago',
    'uptizm.monitors.uptime_today': 'Today',
  };
}

void main() {
  setUp(() async {
    Translator.instance.setLoader(_RowLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  Widget wrap(Widget widget) => MaterialApp(
    home: WindTheme(
      data: WindThemeData(),
      child: Scaffold(body: SingleChildScrollView(child: widget)),
    ),
  );

  group('componentStatusRowRecipe', () {
    test('root carries the row divider + layout tokens', () {
      final slots = componentStatusRowRecipe(variants: const {});
      expect(slots['root'], contains('border-b'));
      expect(slots['root'], contains('border-color-border'));
      expect(slots['root'], contains('flex flex-col'));
    });

    test('footer text is mono tabular muted', () {
      final slots = componentStatusRowRecipe(variants: const {});
      expect(slots['footerText'], contains('font-mono'));
      expect(slots['footerText'], contains('tabular-nums'));
      expect(slots['footerText'], contains('text-fg-muted'));
    });
  });

  testWidgets('renders name + bar + footer when segments are supplied', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        ComponentStatusRow(
          name: 'Website',
          status: StatusKey.up,
          segments: uptime90(),
          uptimeLabel: '100.0% uptime',
        ),
      ),
    );
    expect(find.text('Website'), findsOneWidget);
    expect(find.text('100.0% uptime'), findsOneWidget);
    expect(find.text('90 days ago'), findsOneWidget);
    expect(find.text('Today'), findsOneWidget);
  });

  testWidgets('omits bar + footer when no segments', (tester) async {
    await tester.pumpWidget(
      wrap(
        const ComponentStatusRow(name: 'Status only', status: StatusKey.paused),
      ),
    );
    expect(find.text('Status only'), findsOneWidget);
    expect(find.text('Today'), findsNothing);
  });

  testWidgets('preview renders without error', (tester) async {
    await tester.pumpWidget(wrap(const ComponentStatusRowPreview()));
    expect(find.text('Website'), findsOneWidget);
    expect(find.text('Payments'), findsOneWidget);
  });
}
