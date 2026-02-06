import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart';

import '../../resources/views/notifications/notifications_list_view.dart';
import '../../resources/views/settings/notification_preferences_view.dart';

/// NotificationController handles notification list and preferences.
class NotificationController extends MagicController {
  /// Singleton accessor.
  static NotificationController get instance =>
      Magic.findOrPut(NotificationController.new);

  /// Notification preference notifiers.
  final pushEnabledNotifier = ValueNotifier<bool>(true);
  final emailEnabledNotifier = ValueNotifier<bool>(true);
  final inAppEnabledNotifier = ValueNotifier<bool>(true);
  final typePreferencesNotifier = ValueNotifier<Map<String, Map<String, bool>>>(
    {},
  );
  final isLoadingNotifier = ValueNotifier<bool>(false);
  final isSavingNotifier = ValueNotifier<bool>(false);

  /// Show notifications list view.
  Widget index() => NotificationsListView(
    onMarkAsRead: (id) => Notify.markAsRead(id),
    onMarkAllAsRead: () => Notify.markAllAsRead(),
    onDelete: (id) => Notify.deleteNotification(id),
    onNavigate: (path) => MagicRoute.to(path),
  );

  /// Show notification preferences view.
  Widget preferences() => const NotificationPreferencesView();

  /// Fetch notification preferences from API.
  Future<void> fetchPreferences() async {
    isLoadingNotifier.value = true;

    try {
      final response = await Http.get('/notification-preferences');

      if (response.successful) {
        final data = response.data['data'] as Map<String, dynamic>?;
        if (data != null) {
          pushEnabledNotifier.value = data['push_enabled'] as bool? ?? true;
          emailEnabledNotifier.value = data['email_enabled'] as bool? ?? true;
          inAppEnabledNotifier.value = data['in_app_enabled'] as bool? ?? true;

          final typePrefs = data['type_preferences'] as Map<String, dynamic>?;
          if (typePrefs != null) {
            typePreferencesNotifier.value = typePrefs.map((key, value) {
              final prefs = value as Map<String, dynamic>;
              return MapEntry(key, {
                'push': prefs['push'] as bool? ?? true,
                'email': prefs['email'] as bool? ?? true,
                'in_app': prefs['in_app'] as bool? ?? true,
              });
            });
          }
        }
      }
    } catch (e) {
      Log.error('Failed to fetch notification preferences: $e');
    } finally {
      isLoadingNotifier.value = false;
    }
  }

  /// Update a global preference.
  Future<void> updateGlobalPreference(String key, bool value) async {
    // Optimistically update local state
    switch (key) {
      case 'push_enabled':
        pushEnabledNotifier.value = value;
        break;
      case 'email_enabled':
        emailEnabledNotifier.value = value;
        break;
      case 'in_app_enabled':
        inAppEnabledNotifier.value = value;
        break;
    }

    try {
      final response = await Http.put(
        '/notification-preferences',
        data: {key: value},
      );

      if (!response.successful) {
        // Revert on failure
        _revertGlobalPreference(key, !value);
        Magic.toast(trans('common.error'));
      }
    } catch (e) {
      Log.error('Failed to update preference: $e');
      _revertGlobalPreference(key, !value);
      Magic.toast(trans('errors.network_error'));
    }
  }

  void _revertGlobalPreference(String key, bool value) {
    switch (key) {
      case 'push_enabled':
        pushEnabledNotifier.value = value;
        break;
      case 'email_enabled':
        emailEnabledNotifier.value = value;
        break;
      case 'in_app_enabled':
        inAppEnabledNotifier.value = value;
        break;
    }
  }

  /// Update a type-specific preference.
  Future<void> updateTypePreference(
    String type,
    String channel,
    bool value,
  ) async {
    // Optimistically update local state
    final oldPrefs = Map<String, Map<String, bool>>.from(
      typePreferencesNotifier.value.map(
        (k, v) => MapEntry(k, Map<String, bool>.from(v)),
      ),
    );

    final typePrefs =
        typePreferencesNotifier.value[type] ??
        {'push': true, 'email': true, 'in_app': true};

    typePreferencesNotifier.value = {
      ...typePreferencesNotifier.value,
      type: {...typePrefs, channel: value},
    };

    try {
      final response = await Http.put(
        '/notification-preferences',
        data: {'type_preferences': typePreferencesNotifier.value},
      );

      if (!response.successful) {
        // Revert on failure
        typePreferencesNotifier.value = oldPrefs;
        Magic.toast(trans('common.error'));
      }
    } catch (e) {
      Log.error('Failed to update type preference: $e');
      typePreferencesNotifier.value = oldPrefs;
      Magic.toast(trans('errors.network_error'));
    }
  }

  /// Get type preference value.
  bool getTypePreference(String type, String channel) {
    final typePrefs = typePreferencesNotifier.value[type];
    if (typePrefs == null) return true; // Default to enabled
    return typePrefs[channel] ?? true;
  }

  @override
  void dispose() {
    pushEnabledNotifier.dispose();
    emailEnabledNotifier.dispose();
    inAppEnabledNotifier.dispose();
    typePreferencesNotifier.dispose();
    isLoadingNotifier.dispose();
    isSavingNotifier.dispose();
    super.dispose();
  }
}
