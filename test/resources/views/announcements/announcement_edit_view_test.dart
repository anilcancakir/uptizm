import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/resources/views/announcements/announcement_edit_view.dart';

void main() {
  group('AnnouncementEditView', () {
    test('can be instantiated', () {
      const view = AnnouncementEditView();
      expect(view, isA<AnnouncementEditView>());
    });
  });
}
