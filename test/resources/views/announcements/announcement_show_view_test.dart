import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/resources/views/announcements/announcement_show_view.dart';

void main() {
  group('AnnouncementShowView', () {
    test('can be instantiated', () {
      const view = AnnouncementShowView();
      expect(view, isA<AnnouncementShowView>());
    });
  });
}
