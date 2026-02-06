import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/controllers/status_page_controller.dart';
import 'package:uptizm/resources/views/status_pages/status_pages_index_view.dart';
import 'package:uptizm/resources/views/status_pages/status_page_create_view.dart';
import 'package:uptizm/resources/views/status_pages/status_page_edit_view.dart';

void main() {
  setUp(() {
    Magic.flush();
  });

  tearDown(() {
    Magic.flush();
  });

  group('StatusPageController', () {
    test('singleton access via StatusPageController.instance', () {
      final instance1 = StatusPageController.instance;
      final instance2 = StatusPageController.instance;
      expect(instance1, same(instance2));
    });

    test('index() returns StatusPagesIndexView widget', () {
      final controller = StatusPageController.instance;
      final result = controller.index();
      expect(result, isA<StatusPagesIndexView>());
    });

    test('create() returns StatusPageCreateView widget', () {
      final controller = StatusPageController.instance;
      final result = controller.create();
      expect(result, isA<StatusPageCreateView>());
    });

    test('edit() returns StatusPageEditView widget', () {
      final controller = StatusPageController.instance;
      final result = controller.edit();
      expect(result, isA<StatusPageEditView>());
    });

    test(
      'notifiers initialized (statusPagesNotifier, selectedStatusPageNotifier)',
      () {
        final controller = StatusPageController.instance;
        expect(controller.statusPagesNotifier, isA<ValueNotifier>());
        expect(controller.selectedStatusPageNotifier, isA<ValueNotifier>());
        expect(controller.statusPagesNotifier.value, isEmpty);
        expect(controller.selectedStatusPageNotifier.value, isNull);
      },
    );
  });
}
