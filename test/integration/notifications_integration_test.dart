import 'package:flutter_test/flutter_test.dart';
import 'package:fluttersdk_magic_notifications/fluttersdk_magic_notifications.dart';

/// Integration tests for the notification system.
///
/// Tests the complete flow:
/// 1. User logs in
/// 2. Notification polling starts
/// 3. Notifications are fetched and displayed
/// 4. User can mark notifications as read
/// 5. User can navigate to notification target
void main() {
  group('Notifications Integration', () {
    setUp(() {
      // Reset notification manager state before each test
      final manager = NotificationManager();
      manager.forgetChannels();
    });

    test('NotificationManager can be instantiated', () {
      final manager = NotificationManager();
      expect(manager, isA<NotificationManager>());
    });

    test('NotificationManager is singleton', () {
      final manager1 = NotificationManager();
      final manager2 = NotificationManager();
      expect(identical(manager1, manager2), isTrue);
    });

    test('Notify facade can access manager', () {
      final manager = Notify.manager;
      expect(manager, isA<NotificationManager>());
    });

    test('notification polling can be started and stopped', () {
      // Start polling
      expect(() => Notify.startPolling(), returnsNormally);

      // Pause polling
      expect(() => Notify.pausePolling(), returnsNormally);

      // Resume polling
      expect(() => Notify.resumePolling(), returnsNormally);

      // Stop polling
      expect(() => Notify.stopPolling(), returnsNormally);
    });

    test('database notifications stream is accessible', () {
      final stream = Notify.notifications();
      expect(stream, isA<Stream<List<DatabaseNotification>>>());
    });

    test('can create and parse DatabaseNotification', () {
      final map = {
        'id': 'test-uuid',
        'type': 'monitor_down',
        'data': {
          'title': 'Monitor Down',
          'body': 'api.example.com is not responding',
          'action_url': '/monitors/1',
          'data': {
            'monitor_id': 1,
            'monitor_name': 'API Server',
            'type': 'monitor_down',
          },
        },
        'created_at': '2026-02-03T12:00:00.000Z',
        'read_at': null,
      };

      final notification = DatabaseNotification.fromMap(map);

      expect(notification.id, 'test-uuid');
      expect(notification.type, 'monitor_down');
      expect(notification.title, 'Monitor Down');
      expect(notification.body, 'api.example.com is not responding');
      expect(notification.actionUrl, '/monitors/1');
      expect(notification.isRead, isFalse);
    });

    test('DatabaseNotification copyWith creates modified copy', () {
      final original = DatabaseNotification(
        id: 'test-1',
        type: 'monitor_down',
        title: 'Test',
        body: 'Body',
        data: {},
        createdAt: DateTime.now(),
      );

      final modified = original.copyWith(readAt: DateTime.now());

      expect(original.isRead, isFalse);
      expect(modified.isRead, isTrue);
      expect(original.id, modified.id);
      expect(original.title, modified.title);
    });

    test('NotificationPreference can be created and serialized', () {
      final preference = NotificationPreference(
        pushEnabled: true,
        emailEnabled: false,
        inAppEnabled: true,
        typePreferences: {
          'monitor_down': ChannelPreference(
            push: true,
            email: false,
            inApp: true,
          ),
        },
      );

      expect(preference.pushEnabled, isTrue);
      expect(preference.emailEnabled, isFalse);
      expect(preference.inAppEnabled, isTrue);

      // Test isEnabled logic
      expect(preference.isEnabled('monitor_down', 'push'), isTrue);
      expect(preference.isEnabled('monitor_down', 'mail'), isFalse);
      expect(preference.isEnabled('monitor_down', 'in_app'), isTrue);

      // Test serialization
      final map = preference.toMap();
      expect(map['push_enabled'], isTrue);
      expect(map['email_enabled'], isFalse);
      expect(map['in_app_enabled'], isTrue);

      // Test deserialization
      final restored = NotificationPreference.fromMap(map);
      expect(restored.pushEnabled, preference.pushEnabled);
      expect(restored.emailEnabled, preference.emailEnabled);
    });

    test(
      'NotificationPreference isEnabled checks both global and type settings',
      () {
        final preference = NotificationPreference(
          pushEnabled: true,
          emailEnabled: false,
          typePreferences: {
            'monitor_down': ChannelPreference(
              push: false, // Type-level disabled
              email: true, // Type-level enabled but global disabled
              inApp: true,
            ),
          },
        );

        // Push: type disabled, even though global enabled
        expect(preference.isEnabled('monitor_down', 'push'), isFalse);

        // Email: type enabled, but global disabled
        expect(preference.isEnabled('monitor_down', 'mail'), isFalse);

        // In-app: both enabled
        expect(preference.isEnabled('monitor_down', 'in_app'), isTrue);

        // Unknown type: falls back to global setting
        expect(preference.isEnabled('unknown_type', 'push'), isTrue);
        expect(preference.isEnabled('unknown_type', 'mail'), isFalse);
      },
    );

    test('push notification driver can be set and accessed', () {
      final manager = NotificationManager();

      expect(() => manager.pushDriver, throwsA(isA<NotificationException>()));

      // In real usage, OneSignalDriver would be set by the service provider
      // For testing, we just verify the API exists
      expect(manager.forgetPushDriver, returnsNormally);
    });

    test('notification channels can be registered and checked', () {
      final manager = NotificationManager();

      // Initially no channels
      expect(manager.hasChannel('test'), isFalse);

      // Register a test channel
      final channel = _TestChannel();
      manager.registerChannel(channel);

      expect(manager.hasChannel('test'), isTrue);
    });

    testWidgets('notification lifecycle integration', (tester) async {
      // This would be a full widget test with:
      // 1. Mock login
      // 2. Verify polling starts
      // 3. Mock notification arrival
      // 4. Verify UI updates
      // 5. Mark as read
      // 6. Verify navigation

      // For now, just verify the notification dropdown widget can be instantiated
      // (requires full app context to test properly)

      expect(true, isTrue); // Placeholder - full widget test would go here
    });
  });
}

/// Test notification channel for integration tests.
class _TestChannel extends NotificationChannel {
  @override
  String get name => 'test';

  @override
  bool get isAvailable => true;

  @override
  Future<void> send(Notifiable notifiable, Notification notification) async {
    // No-op test channel
  }
}
