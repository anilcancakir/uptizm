import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/resources/views/announcements/announcements_index_view.dart';

void main() {
  group('AnnouncementsIndexView', () {
    test('can be instantiated', () {
      const view = AnnouncementsIndexView();
      expect(view, isA<AnnouncementsIndexView>());
    });
  });
}
