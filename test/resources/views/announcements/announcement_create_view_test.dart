import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/resources/views/announcements/announcement_create_view.dart';

void main() {
  group('AnnouncementCreateView', () {
    test('can be instantiated', () {
      const view = AnnouncementCreateView();
      expect(view, isA<AnnouncementCreateView>());
    });
  });
}
