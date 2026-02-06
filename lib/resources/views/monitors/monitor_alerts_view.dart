import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/monitor_controller.dart';
import 'monitor_alerts_tab.dart';

/// Monitor Alerts View
///
/// Full page view for managing alert rules and viewing alerts for a specific monitor.
class MonitorAlertsView extends MagicStatefulView<MonitorController> {
  const MonitorAlertsView({super.key});

  @override
  State<MonitorAlertsView> createState() => _MonitorAlertsViewState();
}

class _MonitorAlertsViewState
    extends MagicStatefulViewState<MonitorController, MonitorAlertsView> {
  String? _monitorId;

  @override
  void onInit() {
    super.onInit();

    // Extract ID from route parameters
    final idParam = MagicRouter.instance.pathParameter('id');

    if (idParam != null) {
      _monitorId = idParam;
      // Load monitor data
      WidgetsBinding.instance.addPostFrameCallback((_) async {
        await controller.loadMonitor(_monitorId!);
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_monitorId == null) {
      return WDiv(
        className: 'flex items-center justify-center h-full',
        children: [
          WText(
            trans('errors.monitor_not_found'),
            className: 'text-gray-600 dark:text-gray-400',
          ),
        ],
      );
    }

    return ValueListenableBuilder(
      valueListenable: controller.selectedMonitorNotifier,
      builder: (context, monitor, _) {
        if (monitor == null) {
          return WDiv(
            className: 'flex items-center justify-center h-full',
            children: const [CircularProgressIndicator()],
          );
        }

        return WDiv(
          className: 'overflow-y-auto flex flex-col gap-6 p-4 lg:p-6',
          scrollPrimary: true,
          children: [
            // Header with back button
            WDiv(
              className: 'flex flex-row items-center gap-4',
              children: [
                WButton(
                  onTap: () => MagicRoute.to('/monitors/$_monitorId'),
                  className:
                      'p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700',
                  child: WIcon(
                    Icons.arrow_back,
                    className: 'text-xl text-gray-700 dark:text-gray-300',
                  ),
                ),
                WDiv(
                  className: 'flex flex-col flex-1',
                  children: [
                    WText(
                      monitor.name ?? 'Unnamed Monitor',
                      className:
                          'text-2xl font-bold text-gray-900 dark:text-white',
                    ),
                    WText(
                      trans('alerts.alert_management'),
                      className: 'text-sm text-gray-600 dark:text-gray-400',
                    ),
                  ],
                ),
              ],
            ),

            // Alerts Tab Content
            MonitorAlertsTab(monitorId: _monitorId!),
          ],
        );
      },
    );
  }
}
