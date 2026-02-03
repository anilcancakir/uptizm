import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:uptizm/app/enums/metric_type.dart';
import 'package:uptizm/app/models/metric_mapping.dart';
import 'package:uptizm/resources/views/components/metric_mapping_editor.dart';

void main() {
  Widget wrapWithTheme(Widget child) {
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp(
        home: Scaffold(body: SingleChildScrollView(child: child)),
      ),
    );
  }

  group('MetricMappingEditor', () {
    testWidgets('renders empty state with Add button', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(
          MetricMappingEditor(mappings: const [], onChanged: (_) {}),
        ),
      );

      expect(find.text('Add Metric Mapping'), findsOneWidget);
    });

    testWidgets('adds empty row on button tap', (tester) async {
      List<MetricMapping> captured = [];

      await tester.pumpWidget(
        wrapWithTheme(
          MetricMappingEditor(
            mappings: const [],
            onChanged: (m) => captured = m,
          ),
        ),
      );

      await tester.tap(find.text('Add Metric Mapping'));
      await tester.pumpAndSettle();

      expect(captured.length, 1);
      expect(captured[0].type, MetricType.numeric);
      expect(captured[0].label, '');
      expect(captured[0].path, '');
    });

    testWidgets('removes row on delete tap', (tester) async {
      List<MetricMapping> captured = [];

      final mappings = [
        MetricMapping(label: 'A', path: 'data.a', type: MetricType.numeric),
        MetricMapping(label: 'B', path: 'data.b', type: MetricType.string),
      ];

      await tester.pumpWidget(
        wrapWithTheme(
          MetricMappingEditor(
            mappings: mappings,
            onChanged: (m) => captured = m,
          ),
        ),
      );

      final deleteButtons = find.byIcon(Icons.close);
      await tester.tap(deleteButtons.first);
      await tester.pumpAndSettle();

      expect(captured.length, 1);
      expect(captured[0].label, 'B');
    });

    testWidgets('initializes with existing mappings', (tester) async {
      final mappings = [
        MetricMapping(
          label: 'DB Connections',
          path: 'data.database.active_connections',
          type: MetricType.numeric,
          unit: 'conn',
        ),
      ];

      await tester.pumpWidget(
        wrapWithTheme(
          MetricMappingEditor(mappings: mappings, onChanged: (_) {}),
        ),
      );

      expect(
        find.text(
          'DB Connections: data.database.active_connections (numeric, conn)',
        ),
        findsOneWidget,
      );
    });
  });
}
