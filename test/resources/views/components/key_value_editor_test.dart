import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/resources/views/components/key_value_editor.dart';

void main() {
  setUpAll(() {
    Magic.init();
  });

  Widget wrapWithTheme(Widget child) {
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp(home: Scaffold(body: child)),
    );
  }

  group('KeyValueEditor', () {
    testWidgets('renders empty state with Add button', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(KeyValueEditor(entries: const {}, onChanged: (_) {})),
      );

      // In test mode, trans() returns the key when translations aren't loaded
      expect(find.textContaining('add_header'), findsOneWidget);
      expect(find.byType(TextField), findsNothing);
    });

    testWidgets('renders existing entries', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(
          KeyValueEditor(
            entries: const {
              'Content-Type': 'application/json',
              'Authorization': 'Bearer token',
            },
            onChanged: (_) {},
          ),
        ),
      );

      expect(find.text('Content-Type'), findsOneWidget);
      expect(find.text('application/json'), findsOneWidget);
      expect(find.text('Authorization'), findsOneWidget);
      expect(find.text('Bearer token'), findsOneWidget);
      expect(find.byType(TextField), findsNWidgets(4)); // 2 keys + 2 values
    });

    testWidgets('adds new entry when Add button clicked', (tester) async {
      // ignore: unused_local_variable
      Map<String, String> capturedEntries = {};

      await tester.pumpWidget(
        wrapWithTheme(
          KeyValueEditor(
            entries: const {},
            onChanged: (entries) => capturedEntries = entries,
          ),
        ),
      );

      // Click Add button (find by containing the trans key)
      await tester.tap(find.textContaining('add_header'));
      await tester.pump();

      // Should have one empty row with 2 text fields (key + value)
      expect(find.byType(TextField), findsNWidgets(2));
    });

    testWidgets('calls onChanged when entry is added', (tester) async {
      Map<String, String> capturedEntries = {};

      await tester.pumpWidget(
        wrapWithTheme(
          KeyValueEditor(
            entries: const {},
            onChanged: (entries) => capturedEntries = entries,
          ),
        ),
      );

      // Click Add button (find by containing the trans key)
      await tester.tap(find.textContaining('add_header'));
      await tester.pumpAndSettle();

      // Find the key and value fields
      final keyFields = find.byType(TextField);
      expect(keyFields, findsNWidgets(2));

      // Enter key
      await tester.enterText(keyFields.at(0), 'X-Custom-Header');
      await tester.pump();

      // Enter value
      await tester.enterText(keyFields.at(1), 'custom-value');
      await tester.pump();

      expect(capturedEntries['X-Custom-Header'], 'custom-value');
    });

    testWidgets('calls onChanged when entry is removed', (tester) async {
      Map<String, String> capturedEntries = {};

      await tester.pumpWidget(
        wrapWithTheme(
          KeyValueEditor(
            entries: const {'Header1': 'Value1', 'Header2': 'Value2'},
            onChanged: (entries) => capturedEntries = entries,
          ),
        ),
      );

      // Find and tap the first delete button
      final deleteButtons = find.byIcon(Icons.close);
      await tester.tap(deleteButtons.first);
      await tester.pump();

      expect(capturedEntries.containsKey('Header1'), false);
      expect(capturedEntries['Header2'], 'Value2');
    });
  });
}
