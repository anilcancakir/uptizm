import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/ui/components/key_value_editor/index.dart';
import 'package:uptizm/ui/components/key_value_editor/key_value_editor.preview.dart';

void main() {
  Widget wrap(Widget widget) => MaterialApp(
        home: WindTheme(
          data: WindThemeData(),
          child: Scaffold(body: SingleChildScrollView(child: widget)),
        ),
      );

  group('keyValueEditorRecipe', () {
    test('root stacks rows; remove is a square ghost', () {
      final slots = keyValueEditorRecipe(variants: const {});
      expect(slots['root'], contains('flex flex-col'));
      expect(slots['remove'], contains('size-10'));
      expect(slots['remove'], contains('rounded-md'));
    });
  });

  group('KeyValueRow', () {
    test('copyWith replaces only the given field', () {
      const row = KeyValueRow(key: 'A', value: '1');
      expect(row.copyWith(value: '2'), isA<KeyValueRow>());
      expect(row.copyWith(value: '2').key, 'A');
      expect(row.copyWith(value: '2').value, '2');
    });
  });

  testWidgets('renders the Add button + the supplied rows', (tester) async {
    await tester.pumpWidget(wrap(
      KeyValueEditor(
        value: const [KeyValueRow(key: 'Authorization', value: 'Bearer x')],
        onChanged: (_) {},
      ),
    ));
    expect(find.text('Add header'), findsOneWidget);
    expect(find.byType(KeyValueEditor), findsOneWidget);
  });

  testWidgets('Add appends an empty row', (tester) async {
    List<KeyValueRow> next = const [];
    await tester.pumpWidget(wrap(
      KeyValueEditor(value: const [], onChanged: (v) => next = v),
    ));
    await tester.tap(find.text('Add header'));
    await tester.pump();
    expect(next.length, 1);
    expect(next.first.key, '');
  });

  testWidgets('preview renders without error', (tester) async {
    await tester.pumpWidget(wrap(const KeyValueEditorPreview()));
    expect(find.byType(KeyValueEditor), findsOneWidget);
  });
}
