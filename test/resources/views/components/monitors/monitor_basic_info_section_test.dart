import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/enums/monitor_type.dart';
import 'package:uptizm/resources/views/components/monitors/monitor_basic_info_section.dart';

void main() {
  group('MonitorBasicInfoSection', () {
    late MagicFormData form;

    setUp(() {
      form = MagicFormData({
        'name': '',
        'url': '',
        'method': 'GET',
        'expected_status_code': '200',
      });
    });

    Widget buildSubject({
      MonitorType selectedType = MonitorType.http,
      ValueChanged<MonitorType>? onTypeChanged,
      bool typeEditable = true,
      List<String> tags = const [],
    }) {
      return WindTheme(
        data: WindThemeData(),
        child: MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(
              child: MonitorBasicInfoSection(
                form: form,
                selectedType: selectedType,
                onTypeChanged: onTypeChanged ?? (_) {},
                typeEditable: typeEditable,
                tags: tags,
                tagOptions: const [],
                onTagsChanged: (_) {},
                onTagOptionsChanged: (_) {},
              ),
            ),
          ),
        ),
      );
    }

    testWidgets('renders type buttons for all monitor types', (tester) async {
      await tester.pumpWidget(buildSubject());
      await tester.pumpAndSettle();

      for (final type in MonitorType.values) {
        expect(find.text(type.label), findsOneWidget);
      }
    });

    testWidgets('fires onTypeChanged when type button tapped', (tester) async {
      MonitorType? changedTo;

      await tester.pumpWidget(
        buildSubject(onTypeChanged: (type) => changedTo = type),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text(MonitorType.ping.label));
      expect(changedTo, MonitorType.ping);
    });

    testWidgets('does not fire onTypeChanged when typeEditable is false', (
      tester,
    ) async {
      MonitorType? changedTo;

      await tester.pumpWidget(
        buildSubject(
          onTypeChanged: (type) => changedTo = type,
          typeEditable: false,
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text(MonitorType.ping.label));
      expect(changedTo, isNull);
    });

    testWidgets('shows method select for HTTP type', (tester) async {
      await tester.pumpWidget(buildSubject(selectedType: MonitorType.http));
      await tester.pumpAndSettle();

      // HttpMethod select should show current value 'GET'
      expect(find.text('GET'), findsWidgets);
    });
  });
}
