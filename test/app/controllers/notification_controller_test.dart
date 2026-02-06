import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/controllers/notification_controller.dart';

void main() {
  group('NotificationController', () {
    late NotificationController controller;

    setUp(() {
      controller = NotificationController.instance;
      // Reset notifiers to default values
      controller.pushEnabledNotifier.value = true;
      controller.emailEnabledNotifier.value = true;
      controller.inAppEnabledNotifier.value = true;
      controller.typePreferencesNotifier.value = {};
      controller.isLoadingNotifier.value = false;
      controller.isSavingNotifier.value = false;
    });

    test('is singleton', () {
      final controller1 = NotificationController.instance;
      final controller2 = NotificationController.instance;

      expect(controller1, isA<NotificationController>());
      expect(identical(controller1, controller2), isTrue);
    });

    test('preferences() returns widget', () {
      final widget = controller.preferences();
      expect(widget, isA<Widget>());
    });

    test('index() returns widget', () {
      final widget = controller.index();
      expect(widget, isA<Widget>());
    });

    group('ValueNotifiers', () {
      test('pushEnabledNotifier defaults to true', () {
        expect(controller.pushEnabledNotifier.value, isTrue);
      });

      test('emailEnabledNotifier defaults to true', () {
        expect(controller.emailEnabledNotifier.value, isTrue);
      });

      test('inAppEnabledNotifier defaults to true', () {
        expect(controller.inAppEnabledNotifier.value, isTrue);
      });

      test('typePreferencesNotifier defaults to empty', () {
        expect(controller.typePreferencesNotifier.value, isEmpty);
      });

      test('isLoadingNotifier defaults to false', () {
        expect(controller.isLoadingNotifier.value, isFalse);
      });

      test('isSavingNotifier defaults to false', () {
        expect(controller.isSavingNotifier.value, isFalse);
      });
    });

    group('getTypePreference', () {
      test('returns true when no type preference is set', () {
        expect(controller.getTypePreference('monitor_down', 'push'), isTrue);
        expect(controller.getTypePreference('monitor_down', 'email'), isTrue);
        expect(controller.getTypePreference('unknown_type', 'in_app'), isTrue);
      });

      test('returns stored value when type preference is set', () {
        controller.typePreferencesNotifier.value = {
          'monitor_down': {'push': false, 'email': true, 'in_app': false},
        };

        expect(controller.getTypePreference('monitor_down', 'push'), isFalse);
        expect(controller.getTypePreference('monitor_down', 'email'), isTrue);
        expect(controller.getTypePreference('monitor_down', 'in_app'), isFalse);
      });

      test('returns true for missing channel in type preference', () {
        controller.typePreferencesNotifier.value = {
          'monitor_down': {'push': false},
        };

        expect(controller.getTypePreference('monitor_down', 'email'), isTrue);
      });
    });
  });
}
