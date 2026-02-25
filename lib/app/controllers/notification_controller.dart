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

  /// Matrix holding notification preferences from the backend
  /// Structure: { "monitor_down": { "label": "...", "channels": { "mail": { "enabled": true, "locked": false } } } }
  final matrixNotifier = ValueNotifier<Map<String, dynamic>>({});

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
          matrixNotifier.value = data;
        }
      }
    } catch (e, s) {
      Log.error('Failed to fetch notification preferences: $e\n$s', e);
    } finally {
      isLoadingNotifier.value = false;
    }
  }

  /// Update a type-specific preference.
  Future<void> updateTypePreference(
    String type,
    String channel,
    bool isEnabled,
  ) async {
    // Optimistically update local state
    final oldMatrix = Map<String, dynamic>.from(matrixNotifier.value);
    
    final newMatrix = Map<String, dynamic>.from(matrixNotifier.value);
    if (newMatrix.containsKey(type)) {
      final typeData = Map<String, dynamic>.from(newMatrix[type]);
      if (typeData.containsKey('channels')) {
        final channelsData = Map<String, dynamic>.from(typeData['channels']);
        if (channelsData.containsKey(channel)) {
          final channelData = Map<String, dynamic>.from(channelsData[channel]);
          channelData['enabled'] = isEnabled;
          channelsData[channel] = channelData;
        }
        typeData['channels'] = channelsData;
      }
      newMatrix[type] = typeData;
    }

    matrixNotifier.value = newMatrix;

    try {
      final response = await Http.put(
        '/notification-preferences',
        data: {
          'type': type,
          'channel': channel,
          'is_enabled': isEnabled,
        },
      );

      if (!response.successful) {
        // Revert on failure
        matrixNotifier.value = oldMatrix;
        Magic.toast(trans('common.error'));
      }
    } catch (e, s) {
      Log.error('Failed to update type preference: $e\n$s', e);
      matrixNotifier.value = oldMatrix;
      Magic.toast(trans('errors.network_error'));
    }
  }

  @override
  void dispose() {
    matrixNotifier.dispose();
    isLoadingNotifier.dispose();
    isSavingNotifier.dispose();
    super.dispose();
  }
}
