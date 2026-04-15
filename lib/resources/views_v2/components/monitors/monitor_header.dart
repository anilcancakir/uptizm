import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../ui/status_badge.dart';

/// Monitor detail page header: compact nav bar + info badges + action buttons.
///
/// ## Usage
/// ```dart
/// MonitorHeader(
///   name: 'Production API',
///   url: 'https://api.example.com/health',
///   status: 'up',
///   responseTime: '245ms',
///   interval: 'Every 30s',
///   lastChecked: '2m ago',
/// )
/// ```
class MonitorHeader extends StatelessWidget {
  const MonitorHeader({
    super.key,
    required this.name,
    required this.url,
    required this.status,
    this.responseTime,
    this.interval,
    this.lastChecked,
    this.onPause,
    this.onEdit,
    this.onNotifications,
  });

  final String name;
  final String url;
  final String status;
  final String? responseTime;
  final String? interval;
  final String? lastChecked;
  final VoidCallback? onPause;
  final VoidCallback? onEdit;
  final VoidCallback? onNotifications;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6',
      children: [
        // Nav bar: back + title + status badge
        _buildNavBar(),

        // Info section: url + badges + actions
        _buildInfoSection(),
      ],
    );
  }

  Widget _buildNavBar() {
    return WDiv(
      className: 'flex flex-row items-center gap-3',
      children: [
        WButton(
          onTap: () => MagicRoute.back(),
          className: 'p-3 rounded-lg',
          child: WIcon(
            Icons.arrow_back_ios_new_rounded,
            className: 'text-[20px] text-gray-900 dark:text-white',
          ),
        ),
        WDiv(
          className: 'flex-1',
          child: WText(
            name,
            className: '''
              text-lg font-semibold truncate
              text-gray-900 dark:text-white
            ''',
          ),
        ),
        StatusBadge(status: status),
      ],
    );
  }

  Widget _buildInfoSection() {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        WText(url, className: 'text-sm text-gray-500 dark:text-gray-400'),

        // Info badges
        WDiv(
          className: 'flex flex-row flex-wrap items-center gap-2',
          children: [
            if (responseTime != null)
              _buildInfoBadge(icon: Icons.speed_rounded, label: responseTime!),
            if (interval != null)
              _buildInfoBadge(icon: Icons.repeat_rounded, label: interval!),
            if (lastChecked != null)
              _buildInfoBadge(
                icon: Icons.schedule_rounded,
                label: lastChecked!,
              ),
          ],
        ),

        // Action buttons
        WDiv(
          className: 'flex flex-row gap-2',
          children: [
            WDiv(
              className: 'flex-1',
              child: WButton(
                onTap: onPause ?? () {},
                className: '''
                  w-full py-3.5 px-5 rounded-lg
                  bg-primary text-white text-center
                ''',
                child: WDiv(
                  className: 'flex flex-row items-center justify-center gap-2',
                  children: [
                    WIcon(
                      Icons.pause_rounded,
                      className: 'text-[18px] text-white',
                    ),
                    WText(
                      'Pause',
                      className: 'text-sm font-semibold text-white',
                    ),
                  ],
                ),
              ),
            ),
            WButton(
              onTap: onEdit ?? () {},
              className: '''
                py-3.5 px-4 rounded-lg
                bg-white dark:bg-gray-800
                border border-gray-200 dark:border-gray-700
              ''',
              child: WIcon(
                Icons.edit_outlined,
                className: 'text-[18px] text-gray-700 dark:text-gray-300',
              ),
            ),
            WButton(
              onTap: onNotifications ?? () {},
              className: '''
                py-3.5 px-4 rounded-lg
                bg-white dark:bg-gray-800
                border border-gray-200 dark:border-gray-700
              ''',
              child: WIcon(
                Icons.notifications_outlined,
                className: 'text-[18px] text-gray-700 dark:text-gray-300',
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildInfoBadge({required IconData icon, required String label}) {
    return WDiv(
      className: '''
        flex flex-row items-center gap-1.5 px-2.5 py-1 rounded-full
        bg-gray-100 dark:bg-gray-800
      ''',
      children: [
        WIcon(icon, className: 'text-[12px] text-gray-400 dark:text-gray-500'),
        WText(
          label,
          className: 'text-xs font-medium text-gray-600 dark:text-gray-300',
        ),
      ],
    );
  }
}
