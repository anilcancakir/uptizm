import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/monitor_controller.dart';
import 'monitor_alerts_tab.dart';

/// Monitor Alerts View
///
/// Full page view for managing alert rules and viewing alerts for a specific monitor.
class MonitorAlertsView extends MagicStatefulView<MonitorController> {
  const MonitorAlertsView({super.key, required this.monitorId});

  final String monitorId;

  @override
  State<MonitorAlertsView> createState() => _MonitorAlertsViewState();
}

class _MonitorAlertsViewState
    extends MagicStatefulViewState<MonitorController, MonitorAlertsView> {
  String get _monitorId => widget.monitorId;

  @override
  void onInit() {
    super.onInit();

    // Load monitor data
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await controller.loadMonitor(_monitorId);
    });
  }

  @override
  Widget build(BuildContext context) {
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
            MagicStarterPageHeader(
              title: monitor.name ?? trans('monitors.unnamed'),
              subtitle: trans('alerts.alert_management'),
              leading: WButton(
                onTap: () => MagicRoute.to('/monitors/$_monitorId'),
                child: WIcon(
                  Icons.arrow_back,
                  className: 'text-xl text-gray-600 dark:text-gray-400',
                ),
              ),
            ),

            // Alerts Tab Content
            MonitorAlertsTab(monitorId: _monitorId),
          ],
        );
      },
    );
  }
}
