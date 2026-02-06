import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/notification_controller.dart';
import '../components/app_card.dart';

/// Notification Preferences View
///
/// Allows users to configure global notification settings and
/// per-notification-type preferences.
class NotificationPreferencesView
    extends MagicStatefulView<NotificationController> {
  const NotificationPreferencesView({super.key});

  @override
  State<NotificationPreferencesView> createState() =>
      _NotificationPreferencesViewState();
}

class _NotificationPreferencesViewState
    extends
        MagicStatefulViewState<
          NotificationController,
          NotificationPreferencesView
        > {
  @override
  void onInit() {
    super.onInit();
    // Fetch preferences on view mount
    controller.fetchPreferences();
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<bool>(
      valueListenable: controller.isLoadingNotifier,
      builder: (context, isLoading, _) {
        if (isLoading) {
          return WDiv(
            className: 'py-12 flex items-center justify-center',
            child: const CircularProgressIndicator(),
          );
        }

        return WDiv(
          className: 'overflow-y-auto flex flex-col gap-6 p-4 lg:p-6',
          scrollPrimary: true,
          children: [
            // Page Header
            WDiv(
              className: 'mb-2',
              children: [
                WText(
                  trans('notifications.preferences_title'),
                  className: 'text-2xl font-bold text-gray-900 dark:text-white',
                ),
                const WSpacer(className: 'h-2'),
                WText(
                  trans('notifications.preferences_description'),
                  className: 'text-sm text-gray-600 dark:text-gray-400',
                ),
              ],
            ),

            // Global Settings Card
            AppCard(
              title: trans('notifications.global_settings'),
              icon: Icons.settings_outlined,
              body: WDiv(
                className: 'flex flex-col gap-4',
                children: [
                  _buildGlobalToggle(
                    key: 'push_enabled',
                    notifier: controller.pushEnabledNotifier,
                    title: trans('notifications.push_enabled'),
                    hint: trans('notifications.push_enabled_hint'),
                    icon: Icons.notifications_outlined,
                  ),
                  const WDiv(
                    className: 'border-t border-gray-200 dark:border-gray-700',
                  ),
                  _buildGlobalToggle(
                    key: 'email_enabled',
                    notifier: controller.emailEnabledNotifier,
                    title: trans('notifications.email_enabled'),
                    hint: trans('notifications.email_enabled_hint'),
                    icon: Icons.email_outlined,
                  ),
                  const WDiv(
                    className: 'border-t border-gray-200 dark:border-gray-700',
                  ),
                  _buildGlobalToggle(
                    key: 'in_app_enabled',
                    notifier: controller.inAppEnabledNotifier,
                    title: trans('notifications.in_app_enabled'),
                    hint: trans('notifications.in_app_enabled_hint'),
                    icon: Icons.notifications_active_outlined,
                  ),
                ],
              ),
            ),

            // Per-Type Settings Card
            AppCard(
              title: trans('notifications.notification_types'),
              icon: Icons.tune_outlined,
              body: WDiv(
                className: 'flex flex-col gap-2',
                children: [
                  WText(
                    trans('notifications.types_description'),
                    className: 'text-sm text-gray-600 dark:text-gray-400 mb-4',
                  ),
                  _buildNotificationTypeSettings(),
                ],
              ),
            ),
          ],
        );
      },
    );
  }

  /// Build a global channel toggle switch.
  Widget _buildGlobalToggle({
    required String key,
    required ValueNotifier<bool> notifier,
    required String title,
    required String hint,
    required IconData icon,
  }) {
    return ValueListenableBuilder<bool>(
      valueListenable: notifier,
      builder: (context, value, _) {
        return WDiv(
          className: 'flex flex-row items-center gap-4 py-2',
          children: [
            // Icon
            WDiv(
              className: '''
                w-10 h-10 rounded-lg
                bg-primary/10 dark:bg-primary/20
                flex items-center justify-center
              ''',
              child: Icon(icon, size: 20, color: const Color(0xFF009E60)),
            ),
            // Content
            WDiv(
              className: 'flex-1 flex flex-col min-w-0',
              children: [
                WText(
                  title,
                  className:
                      'text-sm font-medium text-gray-900 dark:text-white',
                ),
                const WSpacer(className: 'h-1'),
                WText(
                  hint,
                  className: 'text-xs text-gray-500 dark:text-gray-400',
                ),
              ],
            ),
            // Toggle
            WCheckbox(
              value: value,
              onChanged: (newValue) {
                controller.updateGlobalPreference(key, newValue);
              },
              className: '''
                w-8 h-8 rounded
                border-2 border-gray-300 dark:border-gray-600
                checked:bg-primary checked:border-primary
              ''',
            ),
          ],
        );
      },
    );
  }

  /// Build notification type-specific settings.
  Widget _buildNotificationTypeSettings() {
    final types = [
      {
        'key': 'monitor_down',
        'title': trans('notifications.type_monitor_down'),
        'description': trans('notifications.type_monitor_down_description'),
        'icon': Icons.error_outline,
        'iconColor': const Color(0xFFEF4444), // Red
      },
      {
        'key': 'monitor_up',
        'title': trans('notifications.type_monitor_up'),
        'description': trans('notifications.type_monitor_up_description'),
        'icon': Icons.check_circle_outline,
        'iconColor': const Color(0xFF009E60), // Green
      },
      {
        'key': 'ssl_expiring',
        'title': trans('notifications.type_ssl_expiring'),
        'description': trans('notifications.type_ssl_expiring_description'),
        'icon': Icons.lock_outline,
        'iconColor': const Color(0xFFF59E0B), // Amber
      },
    ];

    return ValueListenableBuilder<Map<String, Map<String, bool>>>(
      valueListenable: controller.typePreferencesNotifier,
      builder: (context, typePrefs, _) {
        return WDiv(
          className: 'flex flex-col gap-6',
          children: [
            for (var i = 0; i < types.length; i++) ...[
              if (i > 0) const Divider(height: 1),
              _buildNotificationType(
                types[i]['key'] as String,
                types[i]['title'] as String,
                types[i]['description'] as String,
                types[i]['icon'] as IconData,
                types[i]['iconColor'] as Color,
              ),
            ],
          ],
        );
      },
    );
  }

  /// Build a single notification type configuration.
  Widget _buildNotificationType(
    String key,
    String title,
    String description,
    IconData icon,
    Color iconColor,
  ) {
    return WDiv(
      className: 'py-3',
      children: [
        WDiv(
          className: 'flex flex-row items-start gap-4 mb-4',
          children: [
            // Icon - DecoratedBox for dynamic color
            DecoratedBox(
              decoration: BoxDecoration(
                color: iconColor.withValues(alpha: 0.1),
                borderRadius: BorderRadius.circular(8),
              ),
              child: SizedBox(
                width: 40,
                height: 40,
                child: Center(child: Icon(icon, size: 20, color: iconColor)),
              ),
            ),
            // Content
            WDiv(
              className: 'flex-1 flex flex-col min-w-0',
              children: [
                WText(
                  title,
                  className:
                      'text-sm font-medium text-gray-900 dark:text-white',
                ),
                const WSpacer(className: 'h-1'),
                WText(
                  description,
                  className: 'text-xs text-gray-500 dark:text-gray-400',
                ),
              ],
            ),
          ],
        ),
        WDiv(
          className: 'flex items-center gap-6 ml-14',
          children: [
            _buildChannelCheckbox(key, 'push', 'Push'),
            _buildChannelCheckbox(key, 'email', 'Email'),
            _buildChannelCheckbox(key, 'in_app', 'In-App'),
          ],
        ),
      ],
    );
  }

  /// Build a channel checkbox for a notification type.
  Widget _buildChannelCheckbox(String type, String channel, String label) {
    final value = controller.getTypePreference(type, channel);

    // Check if global channel is enabled
    bool globalEnabled = true;
    switch (channel) {
      case 'push':
        globalEnabled = controller.pushEnabledNotifier.value;
        break;
      case 'email':
        globalEnabled = controller.emailEnabledNotifier.value;
        break;
      case 'in_app':
        globalEnabled = controller.inAppEnabledNotifier.value;
        break;
    }

    return WDiv(
      states: globalEnabled ? {} : {'disabled'},
      className: 'flex items-center disabled:opacity-50',
      children: [
        WCheckbox(
          value: value && globalEnabled,
          onChanged: globalEnabled
              ? (newValue) {
                  controller.updateTypePreference(type, channel, newValue);
                }
              : null,
          className: '''
            w-5 h-5 rounded
            border-2 border-gray-300 dark:border-gray-600
            checked:bg-primary checked:border-primary
            disabled:opacity-50 disabled:cursor-not-allowed
          ''',
        ),
        const WSpacer(className: 'w-2'),
        WText(
          label,
          states: globalEnabled ? {} : {'disabled'},
          className: '''
            text-sm text-gray-700 dark:text-gray-300
            disabled:text-gray-400 dark:disabled:text-gray-500
          ''',
        ),
      ],
    );
  }
}
