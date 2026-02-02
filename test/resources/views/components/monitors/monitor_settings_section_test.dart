import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:fluttersdk_wind/fluttersdk_wind.dart';

import '../../../../../lib/app/enums/monitor_location.dart';
import '../../../../../lib/resources/views/components/monitors/monitor_settings_section.dart';

void main() {
  group('MonitorSettingsSection', () {
    late MagicFormData form;

    setUp(() {
      form = MagicFormData({
        'check_interval': '60',
        'timeout': '30',
      });
    });

    Widget buildSubject({
      List<MonitorLocation> selectedLocations = const [MonitorLocation.usEast],
      ValueChanged<List<MonitorLocation>>? onLocationsChanged,
    }) {
      return WindTheme(
        data: WindThemeData(),
        child: MaterialApp(
          home: Scaffold(
            body: SingleChildScrollView(
              child: MonitorSettingsSection(
                form: form,
                selectedLocations: selectedLocations,
                onLocationsChanged: onLocationsChanged ?? (_) {},
              ),
            ),
          ),
        ),
      );
    }

    testWidgets('renders all location checkboxes', (tester) async {
      await tester.pumpWidget(buildSubject());
      await tester.pumpAndSettle();

      for (final location in MonitorLocation.values) {
        expect(find.text(location.label), findsOneWidget);
      }
    });

    testWidgets('fires onLocationsChanged when location toggled',
        (tester) async {
      List<MonitorLocation>? updatedLocations;

      await tester.pumpWidget(buildSubject(
        selectedLocations: [MonitorLocation.usEast],
        onLocationsChanged: (locs) => updatedLocations = locs,
      ));
      await tester.pumpAndSettle();

      // Tap a different location to add it
      await tester.tap(find.text(MonitorLocation.euWest.label));
      expect(updatedLocations, contains(MonitorLocation.euWest));
      expect(updatedLocations, contains(MonitorLocation.usEast));
    });
  });
}
