import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/resources/views/teams/team_members_view.dart';

void main() {
  test('TeamMembersView can be instantiated', () {
    // Verify the widget can be created without errors
    const widget = TeamMembersView();
    expect(widget, isNotNull);
    expect(widget, isA<TeamMembersView>());
  });
}
