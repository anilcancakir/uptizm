import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../../app/models/user.dart';
import '../components/dashboard/stat_card.dart';
import '../components/dashboard/monitor_list_item.dart';
import '../components/dashboard/activity_item.dart';

/// Dashboard View
///
/// Main dashboard page showing stats overview, monitors list, and activity feed.
/// Responsive: 4-col stats on desktop, 2-col on mobile.
class DashboardView extends StatelessWidget {
  const DashboardView({super.key});

  @override
  Widget build(BuildContext context) {
    final userName = User.current.name ?? 'there';
    final isDesktop = wScreenIs(context, 'lg');

    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Welcome header
          WText(
            'Welcome back, $userName!',
            className: 'text-2xl font-bold text-gray-900 dark:text-white',
          ),
          const SizedBox(height: 4),
          WText(
            trans('dashboard.welcome_subtitle'),
            className: 'text-sm text-gray-500 dark:text-gray-400',
          ),
          const SizedBox(height: 24),

          // Stat cards grid
          _buildStatCards(context, isDesktop),
          const SizedBox(height: 24),

          // Desktop: two-column layout for monitors + activity
          // Mobile: stacked
          if (isDesktop)
            WDiv(
              className: 'flex flex-row gap-5 w-full',
              children: [
                Expanded(flex: 3, child: _buildMonitorsOverview()),
                Expanded(flex: 2, child: _buildRecentActivity()),
              ],
            )
          else ...[
            _buildMonitorsOverview(),
            const SizedBox(height: 20),
            _buildRecentActivity(),
          ],

          const SizedBox(height: 40),
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
      StatCard(
        label: trans('dashboard.active_incidents'),
        value: '2',
        icon: Icons.warning_amber,
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
        children: cards.map((card) => Expanded(child: card)).toList(),
      );
    }

    // 2x2 grid on mobile
    return Wrap(
      spacing: 12,
      runSpacing: 12,
      children: cards
          .map((card) => SizedBox(
                width: (MediaQuery.of(context).size.width - 52) / 2,
                child: card,
              ))
          .toList(),
    );
  }

  Widget _buildMonitorsOverview() {
    // Mock data - will be replaced with real API data
    final monitors = [
      const MonitorListItem(
        name: 'API Core Service',
        url: 'api.uptizm.com/health',
        status: MonitorStatus.up,
        responseTime: '145ms',
      ),
      const MonitorListItem(
        name: 'Web Dashboard',
        url: 'app.uptizm.com',
        status: MonitorStatus.up,
        responseTime: '89ms',
      ),
      const MonitorListItem(
        name: 'Payment Gateway',
        url: 'pay.uptizm.com/status',
        status: MonitorStatus.down,
        responseTime: '2.5s',
      ),
      const MonitorListItem(
        name: 'CDN Endpoint',
        url: 'cdn.uptizm.com/ping',
        status: MonitorStatus.degraded,
        responseTime: '520ms',
      ),
      const MonitorListItem(
        name: 'Auth Service',
        url: 'auth.uptizm.com/health',
        status: MonitorStatus.up,
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
              className: 'text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400',
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
    final activities = [
      const ActivityItem(
        title: 'Payment Gateway Down',
        description: 'pay.uptizm.com/status returned 503',
        timeAgo: '5m ago',
        type: ActivityType.incident,
      ),
      const ActivityItem(
        title: 'CDN Degraded',
        description: 'cdn.uptizm.com/ping response time > 500ms',
        timeAgo: '12m ago',
        type: ActivityType.warning,
      ),
      const ActivityItem(
        title: 'Auth Service Recovered',
        description: 'auth.uptizm.com/health is back online',
        timeAgo: '1h ago',
        type: ActivityType.recovery,
      ),
      const ActivityItem(
        title: 'SSL Certificate Check',
        description: 'api.uptizm.com certificate expires in 30 days',
        timeAgo: '3h ago',
        type: ActivityType.info,
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
            className: 'text-xs font-bold tracking-wide text-gray-500 dark:text-gray-400',
          ),
        ),

        // Activity list
        ...activities,
      ],
    );
  }
}

