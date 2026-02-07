import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/controllers/announcement_controller.dart';
import 'package:uptizm/resources/views/announcements/announcements_index_view.dart';
import 'package:uptizm/resources/views/announcements/announcement_create_view.dart';
import 'package:uptizm/resources/views/announcements/announcement_show_view.dart';
import 'package:uptizm/resources/views/announcements/announcement_edit_view.dart';

void main() {
  setUp(() {
    Magic.flush();
  });

  tearDown(() {
    Magic.flush();
  });

  group('AnnouncementController', () {
    test('singleton access via AnnouncementController.instance', () {
      final instance1 = AnnouncementController.instance;
      final instance2 = AnnouncementController.instance;
      expect(instance1, same(instance2));
    });

    test('index() returns AnnouncementsIndexView widget', () {
      final controller = AnnouncementController.instance;
      final result = controller.index('1');
      expect(result, isA<AnnouncementsIndexView>());
    });

    test('create() returns AnnouncementCreateView widget', () {
      final controller = AnnouncementController.instance;
      final result = controller.create('1');
      expect(result, isA<AnnouncementCreateView>());
    });

    test('show() returns AnnouncementShowView widget', () {
      final controller = AnnouncementController.instance;
      final result = controller.show('1', '1');
      expect(result, isA<AnnouncementShowView>());
    });

    test('edit() returns AnnouncementEditView widget', () {
      final controller = AnnouncementController.instance;
      final result = controller.edit('1', '1');
      expect(result, isA<AnnouncementEditView>());
    });

    test(
      'notifiers initialized (announcementsNotifier, selectedAnnouncementNotifier, typeFilterNotifier)',
      () {
        final controller = AnnouncementController.instance;
        expect(controller.announcementsNotifier, isA<ValueNotifier>());
        expect(controller.selectedAnnouncementNotifier, isA<ValueNotifier>());
        expect(controller.typeFilterNotifier, isA<ValueNotifier>());
        expect(controller.announcementsNotifier.value, isEmpty);
        expect(controller.selectedAnnouncementNotifier.value, isNull);
        expect(controller.typeFilterNotifier.value, isNull);
      },
    );
  });
}
