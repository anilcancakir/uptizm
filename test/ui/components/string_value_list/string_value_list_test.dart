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

  testWidgets('a stored value with padding is still a duplicate', (
    tester,
  ) async {
    // The server's write path applies distinct:ignore_case, which does NOT
    // trim, and Laravel's TrimStrings is ASCII-only, so a stored element can
    // carry a non-breaking space. Comparing only the new side trimmed would
    // then accept a visibly identical chip beside it, and both would resolve to
    // the same band at match time.
    List<String> padded = const [' ok '];
    await tester.pumpWidget(
      wrap(
        StatefulBuilder(
          builder: (context, setState) => StringValueList(
            value: padded,
            onChanged: (v) => setState(() => padded = v),
          ),
        ),
      ),
    );

    await tester.enterText(find.byType(WInput), 'OK');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pump();

    expect(padded, [' ok '], reason: 'no second chip may be committed');
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

  testWidgets('a chip and its remove button sit on one row', (tester) async {
    // Geometry rather than className, because the defect was a className that
    // parsed to nothing: wind's display map knows `flex`/`grid`/`wrap`/`block`,
    // so the original `inline-flex` was an unknown token, an unknown token is a
    // silent no-op, and the wrapper laid out with no axis. The remove button
    // then rendered UNDER its chip. Asserting the class string would have
    // passed against the broken value.
    await tester.pumpWidget(
      wrap(
        StringValueList(
          value: const ['degraded'],
          onChanged: (_) {},
          tone: StringValueListTone.warn,
        ),
      ),
    );

    final Rect chip = tester.getRect(find.text('degraded'));
    final Rect remove = tester.getRect(find.byIcon(Icons.close));

    expect(
      remove.left,
      greaterThan(chip.right - 1),
      reason: 'the remove button belongs to the right of its chip',
    );
    expect(
      (remove.center.dy - chip.center.dy).abs(),
      lessThan(8),
      reason: 'the remove button belongs on the same row as its chip',
    );
  });

  testWidgets('two chips sit side by side, not stacked', (tester) async {
    await tester.pumpWidget(
      wrap(
        StringValueList(
          value: const ['ok', 'operational'],
          onChanged: (_) {},
        ),
      ),
    );

    final Rect first = tester.getRect(find.text('ok'));
    final Rect second = tester.getRect(find.text('operational'));

    expect(second.left, greaterThan(first.right));
    expect((second.center.dy - first.center.dy).abs(), lessThan(8));
  });

  testWidgets('preview renders every tone without error', (tester) async {
    await tester.pumpWidget(wrap(const StringValueListPreview()));
    expect(find.byType(StringValueList), findsWidgets);
  });
}
