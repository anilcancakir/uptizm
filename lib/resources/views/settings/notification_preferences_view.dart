import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/notification_controller.dart';
import '../components/app_card.dart';

/// Notification Preferences View
///
/// Allows users to configure per-notification-type preferences dynamically
/// loaded from the notification preference matrix.
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
          return const WDiv(
            className: 'py-12 flex items-center justify-center',
            child: CircularProgressIndicator(),
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
                  _buildMatrixSettings(),
                ],
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _buildMatrixSettings() {
    return ValueListenableBuilder<Map<String, dynamic>>(
      valueListenable: controller.matrixNotifier,
      builder: (context, matrix, _) {
        if (matrix.isEmpty) {
          return const WDiv(
            className: 'py-6 flex items-center justify-center',
            child: WText('No notification preferences available.', className: 'text-gray-500'),
          );
        }

        final types = matrix.keys.toList();

        return WDiv(
          className: 'flex flex-col gap-6',
          children: [
            for (var i = 0; i < types.length; i++) ...[
              if (i > 0) const Divider(height: 1),
              _buildNotificationType(types[i], matrix[types[i]] as Map<String, dynamic>),
            ],
          ],
        );
      },
    );
  }

  Widget _buildNotificationType(String typeKey, Map<String, dynamic> typeData) {
    final title = typeData['label']?.toString() ?? typeKey;
    final channels = typeData['channels'] as Map<String, dynamic>? ?? {};
    final channelKeys = channels.keys.toList();

    return WDiv(
      className: 'py-3',
      children: [
        WDiv(
          className: 'flex flex-row items-start gap-4 mb-4',
          children: [
            // Content
            WDiv(
              className: 'flex-1 flex flex-col min-w-0',
              children: [
                WText(
                  title,
                  className:
                      'text-sm font-medium text-gray-900 dark:text-white',
                ),
              ],
            ),
          ],
        ),
        WDiv(
          className: 'flex items-center gap-6 flex-wrap',
          children: [
            for (final channel in channelKeys)
              _buildChannelCheckbox(
                typeKey,
                channel,
                channels[channel] as Map<String, dynamic>,
              ),
          ],
        ),
      ],
    );
  }

  Widget _buildChannelCheckbox(
    String type,
    String channel,
    Map<String, dynamic> channelData,
  ) {
    final bool isEnabled = channelData['enabled'] as bool? ?? false;
    final bool isLocked = channelData['locked'] as bool? ?? false;

    return WDiv(
      states: isLocked ? {'disabled'} : {},
      className: 'flex items-center disabled:opacity-50',
      children: [
        WCheckbox(
          value: isEnabled,
          onChanged: isLocked
              ? null
              : (newValue) {
                  controller.updateTypePreference(type, channel, newValue);
                },
          className: '''
            w-5 h-5 rounded
            border-2 border-gray-300 dark:border-gray-600
            checked:bg-primary checked:border-primary
            disabled:opacity-50 disabled:cursor-not-allowed
          ''',
        ),
        const WSpacer(className: 'w-2'),
        WText(
          _capitalize(channel),
          states: isLocked ? {'disabled'} : {},
          className: '''
            text-sm text-gray-700 dark:text-gray-300
            disabled:text-gray-400 dark:disabled:text-gray-500
          ''',
        ),
      ],
    );
  }

  String _capitalize(String text) {
    if (text.isEmpty) return text;
    return text[0].toUpperCase() + text.substring(1).replaceAll('_', ' ');
  }
}
