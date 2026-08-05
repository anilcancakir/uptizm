import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/ui/components/string_value_list/index.dart';
import 'package:uptizm/ui/components/string_value_list/string_value_list.preview.dart';

/// Feeds the component's default labels so [trans] returns real English prose
/// instead of the raw `uptizm.monitors.string_values_*` key tokens.
class _StringValueListLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async => {
    'uptizm.monitors.string_values_add': 'Add value',
    'uptizm.monitors.string_values_placeholder': 'Enter value',
  };
}

void main() {
  setUp(() async {
    Translator.instance.setLoader(_StringValueListLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  Widget wrap(Widget widget) => MaterialApp(
    home: WindTheme(
      data: WindThemeData(),
      child: Scaffold(body: SingleChildScrollView(child: widget)),
    ),
  );

  group('stringValueListRecipe', () {
    test('neutral chip sits on the surface-container-high token', () {
      final slots = stringValueListRecipe(
        variants: const {kStringValueListToneAxis: 'neutral'},
      );
      expect(slots['chip'], contains('bg-surface-container-high'));
    });

    test('warn chip uses the degraded soft pair', () {
      final slots = stringValueListRecipe(
        variants: const {kStringValueListToneAxis: 'warn'},
      );
      expect(slots['chip'], contains('bg-degraded-soft'));
      expect(slots['chip'], contains('text-degraded-soft-foreground'));
    });

    test('critical chip uses the down soft pair', () {
      final slots = stringValueListRecipe(
        variants: const {kStringValueListToneAxis: 'critical'},
      );
      expect(slots['chip'], contains('bg-down-soft'));
      expect(slots['chip'], contains('text-down-soft-foreground'));
    });
  });

  testWidgets('submitting text adds a chip', (tester) async {
    List<String> current = const [];
    await tester.pumpWidget(
      wrap(
        StatefulBuilder(
          builder: (context, setState) => StringValueList(
            value: current,
            onChanged: (v) => setState(() => current = v),
          ),
        ),
      ),
    );

    await tester.enterText(find.byType(WInput), 'ok');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pump();

    expect(current, ['ok']);
    expect(find.text('ok'), findsOneWidget);
  });

  testWidgets('submitting a duplicate does not add a second chip', (
    tester,
  ) async {
    List<String> current = const ['ok'];
    await tester.pumpWidget(
      wrap(
        StatefulBuilder(
          builder: (context, setState) => StringValueList(
            value: current,
            onChanged: (v) => setState(() => current = v),
          ),
        ),
      ),
    );

    await tester.enterText(find.byType(WInput), 'ok');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pump();

    expect(current, ['ok']);
    expect(find.text('ok'), findsOneWidget);
  });

  testWidgets('submitting whitespace does not add a chip', (tester) async {
    List<String> next = const ['sentinel'];
    await tester.pumpWidget(
      wrap(
        StringValueList(
          value: const [],
          onChanged: (v) => next = v,
        ),
      ),
    );

    await tester.enterText(find.byType(WInput), '   ');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pump();

    expect(next, ['sentinel']);
  });

  testWidgets('tapping remove drops exactly that chip', (tester) async {
    List<String> next = const [];
    await tester.pumpWidget(
      wrap(
        StringValueList(
          value: const ['ok', 'degraded', 'critical'],
          onChanged: (v) => next = v,
        ),
      ),
    );

    await tester.tap(find.byIcon(Icons.close).at(1));
    await tester.pump();

    expect(next, ['ok', 'critical']);
  });

  testWidgets('each mutation emits a new list rather than mutating the input', (
    tester,
  ) async {
    final original = <String>['ok'];
    List<String>? emitted;
    await tester.pumpWidget(
      wrap(
        StringValueList(
          value: original,
          onChanged: (v) => emitted = v,
        ),
      ),
    );

    await tester.enterText(find.byType(WInput), 'degraded');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pump();

    expect(emitted, isNot(same(original)));
    expect(emitted, ['ok', 'degraded']);
    expect(original, ['ok']);
  });

  testWidgets('preview renders every tone without error', (tester) async {
    await tester.pumpWidget(wrap(const StringValueListPreview()));
    expect(find.byType(StringValueList), findsWidgets);
  });
}
