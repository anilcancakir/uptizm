import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/models/user.dart';
import '../../../app/models/incident.dart';
import '../components/ui/stat_card.dart';

/// Dashboard View
///
/// Main dashboard page showing stats overview, monitors list, and activity feed.
/// Responsive: 4-col stats on desktop, 2-col on mobile.
class DashboardView extends StatelessWidget {
  const DashboardView({super.key});

  @override
  Widget build(BuildContext context) {
    final userName = User.current.name ?? trans('common.guest');
    final isDesktop = wScreenIs(context, 'lg');

    return WDiv(
      className: 'flex-1 overflow-y-auto',
      scrollPrimary: true,
      child: WDiv(
        className: 'flex flex-col gap-6 p-4 pb-8',
        children: [
          // Welcome header
          WDiv(
            className: 'flex flex-col gap-1',
            children: [
              WText(
                trans('dashboard.welcome_greeting', {'name': userName}),
                className: 'text-2xl font-bold text-gray-900 dark:text-white',
              ),
              WText(
                trans('dashboard.welcome_subtitle'),
                className: 'text-sm text-gray-500 dark:text-gray-400',
              ),
            ],
          ),

          // Stat cards grid
          _buildStatCards(context, isDesktop),

          // Desktop: two-column layout for monitors + activity
          // Mobile: stacked
          if (isDesktop)
            WDiv(
              className: 'flex flex-row gap-5 w-full',
              children: [
                WDiv(className: 'flex-3', child: _buildMonitorsOverview()),
                WDiv(className: 'flex-2', child: _buildRecentActivity()),
              ],
            )
          else ...[
            _buildMonitorsOverview(),
            _buildRecentActivity(),
          ],
        ],
      ),
    );
  }

  Widget _buildStatCards(BuildContext context, bool isDesktop) {
    final cards = [
      StatCard(
        label: trans('dashboard.total_monitors'),
        value: '24',
        icon: Icons.monitor_heart,
      ),
      StatCard(
        label: trans('dashboard.systems_up'),
        value: '21',
        icon: Icons.check_circle_outline,
      ),
      FutureBuilder<List<Incident>>(
        future: Incident.all(),
        builder: (context, snapshot) {
          final count = snapshot.data?.where((i) => !i.isResolved).length ?? 0;
          return WButton(
            onTap: () => MagicRoute.to('/incidents'),
            child: StatCard(
              label: trans('dashboard.active_incidents'),
              value: count.toString(),
              icon: Icons.warning_amber_rounded,
            ),
          );
        },
      ),
      StatCard(
        label: trans('dashboard.avg_response_time'),
        value: '145ms',
        icon: Icons.speed,
      ),
    ];

    if (isDesktop) {
      // 4-column grid on desktop
      return WDiv(
        className: 'flex flex-row gap-5 w-full',
        children: cards
            .map((card) => WDiv(className: 'flex-1', child: card))
            .toList(),
      );
    }

    // 2x2 grid on mobile
    return WDiv(className: 'grid grid-cols-2 gap-3', children: cards);
  }

  Widget _buildMonitorsOverview() {
    // Mock data - will be replaced with real API data
    const monitors = [
      _MonitorRow(
        name: 'API Core Service',
        url: 'api.uptizm.com/health',
        status: _MonitorStatus.up,
        responseTime: '145ms',
      ),
      _MonitorRow(
        name: 'Web Dashboard',
        url: 'app.uptizm.com',
        status: _MonitorStatus.up,
        responseTime: '89ms',
      ),
      _MonitorRow(
        name: 'Payment Gateway',
        url: 'pay.uptizm.com/status',
        status: _MonitorStatus.down,
        responseTime: '2.5s',
      ),
      _MonitorRow(
        name: 'CDN Endpoint',
        url: 'cdn.uptizm.com/ping',
        status: _MonitorStatus.degraded,
        responseTime: '520ms',
      ),
      _MonitorRow(
        name: 'Auth Service',
        url: 'auth.uptizm.com/health',
        status: _MonitorStatus.up,
        responseTime: '67ms',
      ),
    ];

    return WDiv(
      className: '''
        flex flex-col
        bg-white dark:bg-gray-800
        rounded-2xl
        border border-gray-100 dark:border-gray-700
        overflow-hidden w-full
      ''',
      children: [
        // Section header
        WDiv(
          className: 'flex flex-row items-center justify-between px-5 py-4',
          children: [
            WText(
              trans('dashboard.monitors_overview').toUpperCase(),
              className:
                  'text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400',
            ),
            WAnchor(
              onTap: () => MagicRoute.to('/monitors'),
              child: WText(
                trans('dashboard.view_all'),
                className: 'text-xs font-semibold text-primary',
              ),
            ),
          ],
        ),

        // Monitor list
        ...monitors,
      ],
    );
  }

  Widget _buildRecentActivity() {
    // Mock data
    const activities = [
      _ActivityRow(
        title: 'Payment Gateway Down',
        description: 'pay.uptizm.com/status returned 503',
        timeAgo: '5m ago',
        type: _ActivityType.incident,
      ),
      _ActivityRow(
        title: 'CDN Degraded',
        description: 'cdn.uptizm.com/ping response time > 500ms',
        timeAgo: '12m ago',
        type: _ActivityType.warning,
      ),
      _ActivityRow(
        title: 'Auth Service Recovered',
        description: 'auth.uptizm.com/health is back online',
        timeAgo: '1h ago',
        type: _ActivityType.recovery,
      ),
      _ActivityRow(
        title: 'SSL Certificate Check',
        description: 'api.uptizm.com certificate expires in 30 days',
        timeAgo: '3h ago',
        type: _ActivityType.info,
      ),
    ];

    return WDiv(
      className: '''
        flex flex-col
        bg-white dark:bg-gray-800
        rounded-2xl
        border border-gray-100 dark:border-gray-700
        overflow-hidden w-full
      ''',
      children: [
        // Section header
        WDiv(
          className: 'px-5 py-4',
          child: WText(
            trans('dashboard.recent_activity').toUpperCase(),
            className:
                'text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400',
          ),
        ),

        // Activity list
        ...activities,
      ],
    );
  }
}

/// Monitor status for dashboard overview row styling.
enum _MonitorStatus { up, down, degraded, paused }

/// Single row in the monitors overview list on the dashboard.
class _MonitorRow extends StatelessWidget {
  final String name;
  final String url;
  final _MonitorStatus status;
  final String responseTime;

  const _MonitorRow({
    required this.name,
    required this.url,
    required this.status,
    required this.responseTime,
  });

  String get _statusColorClass {
    switch (status) {
      case _MonitorStatus.up:
        return 'bg-green-500';
      case _MonitorStatus.down:
        return 'bg-red-500';
      case _MonitorStatus.degraded:
        return 'bg-amber-500';
      case _MonitorStatus.paused:
        return 'bg-gray-400';
    }
  }

  @override
  Widget build(BuildContext context) {
    return WAnchor(
      onTap: () {},
      child: WDiv(
        className: '''
          flex flex-row items-center gap-3 px-4 py-3 w-full
          hover:bg-gray-50 dark:hover:bg-gray-800/50
          border-b border-gray-100 dark:border-gray-700
          duration-150
        ''',
        children: [
          // Status dot
          WDiv(
            className: 'w-2.5 h-2.5 rounded-full $_statusColorClass',
            child: const SizedBox.shrink(),
          ),

          // Name + URL
          WDiv(
            className: 'flex-1 flex flex-col min-w-0',
            children: [
              WText(
                name,
                className:
                    'text-sm font-semibold text-gray-900 dark:text-white truncate',
              ),
              WText(
                url,
                className:
                    'text-xs text-gray-500 dark:text-gray-400 truncate font-mono',
              ),
            ],
          ),

          // Response time badge
          WDiv(
            className: '''
              px-2 py-1 rounded-md
              bg-gray-100 dark:bg-gray-700
              border border-gray-200 dark:border-gray-600
            ''',
            child: WText(
              responseTime,
              className:
                  'text-xs font-mono font-medium text-gray-700 dark:text-gray-300',
            ),
          ),
        ],
      ),
    );
  }
}

/// Activity type for dashboard recent-activity row styling.
enum _ActivityType { incident, recovery, warning, info }

/// Single entry in the recent activity timeline on the dashboard.
class _ActivityRow extends StatelessWidget {
  final String title;
  final String description;
  final String timeAgo;
  final _ActivityType type;

  const _ActivityRow({
    required this.title,
    required this.description,
    required this.timeAgo,
    this.type = _ActivityType.info,
  });

  IconData get _icon {
    switch (type) {
      case _ActivityType.incident:
        return Icons.error_outline;
      case _ActivityType.recovery:
        return Icons.check_circle_outline;
      case _ActivityType.warning:
        return Icons.warning_amber;
      case _ActivityType.info:
        return Icons.info_outline;
    }
  }

  String get _iconBgClass {
    switch (type) {
      case _ActivityType.incident:
        return 'bg-red-500/10';
      case _ActivityType.recovery:
        return 'bg-green-500/10';
      case _ActivityType.warning:
        return 'bg-amber-500/10';
      case _ActivityType.info:
        return 'bg-blue-500/10';
    }
  }

  String get _iconColorClass {
    switch (type) {
      case _ActivityType.incident:
        return 'text-red-500';
      case _ActivityType.recovery:
        return 'text-green-500';
      case _ActivityType.warning:
        return 'text-amber-500';
      case _ActivityType.info:
        return 'text-blue-500';
    }
  }

  @override
  Widget build(BuildContext context) {
    return WAnchor(
      onTap: () {},
      child: WDiv(
        className: '''
          flex flex-row items-start gap-3 px-4 py-3 w-full
          hover:bg-gray-50 dark:hover:bg-gray-800/50
          duration-150
        ''',
        children: [
          // Icon container
          WDiv(
            className:
                'w-9 h-9 rounded-lg $_iconBgClass flex items-center justify-center mt-0.5',
            child: WIcon(_icon, className: 'text-lg $_iconColorClass'),
          ),

          // Content
          WDiv(
            className: 'flex-1 flex flex-col min-w-0',
            children: [
              WText(
                title,
                className: 'text-sm font-medium text-gray-900 dark:text-white',
              ),
              const WSpacer(className: 'h-0.5'),
              WText(
                description,
                className: 'text-xs text-gray-500 dark:text-gray-400 truncate',
              ),
            ],
          ),

          // Timestamp
          WText(
            timeAgo,
            className:
                'text-xs text-gray-400 dark:text-gray-500 whitespace-nowrap',
          ),
        ],
      ),
    );
  }
}
