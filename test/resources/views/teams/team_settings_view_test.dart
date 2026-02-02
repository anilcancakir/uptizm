import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/resources/views/teams/team_settings_view.dart';

void main() {
  test('TeamSettingsView can be instantiated', () {
    // Verify the widget can be created without errors
    const widget = TeamSettingsView();
    expect(widget, isNotNull);
    expect(widget, isA<TeamSettingsView>());
  });
}
