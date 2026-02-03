import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/resources/views/monitors/monitors_index_view.dart';

void main() {
  group('MonitorsIndexView', () {
    test('can be instantiated', () {
      const view = MonitorsIndexView();
      expect(view, isA<MonitorsIndexView>());
    });
  });
}
