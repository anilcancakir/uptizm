import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../../app/controllers/monitor_controller.dart';


/// Monitor Show View
///
/// Displays monitor details and check history.
/// TODO: Implement full view with charts and check history.
class MonitorShowView extends MagicStatefulView<MonitorController> {
  const MonitorShowView({super.key});

  @override
  State<MonitorShowView> createState() => _MonitorShowViewState();
}

class _MonitorShowViewState
    extends MagicStatefulViewState<MonitorController, MonitorShowView> {
  int? _monitorId;

  @override
  void onInit() {
    super.onInit();
    // Clear previous monitor state
    controller.selectedMonitorNotifier.value = null;

    // Extract ID from route parameters
    final idParam = MagicRouter.instance.pathParameter('id');
    Log.debug('MonitorShowView onInit - idParam: $idParam');

    if (idParam != null) {
      _monitorId = int.tryParse(idParam);
      if (_monitorId != null) {
        // Schedule after build to avoid setState-during-build
        WidgetsBinding.instance.addPostFrameCallback((_) {
          controller.loadMonitor(_monitorId!);
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder(
      valueListenable: controller.selectedMonitorNotifier,
      builder: (context, monitor, _) {
        if (controller.isLoading && monitor == null) {
          return const Center(child: CircularProgressIndicator());
        }

        if (monitor == null) {
          return Center(
            child: WText(
              'Monitor not found',
              className: 'text-gray-600 dark:text-gray-400',
            ),
          );
        }

        return SingleChildScrollView(
          child: WDiv(
            className: 'flex flex-col gap-6 p-4 lg:p-6',
            children: [
              // Header
              WDiv(
                className: 'flex flex-row items-center gap-3 mb-2',
                children: [
                  WButton(
                    onTap: () => MagicRoute.back(),
                    className:
                        'p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700',
                    child: WIcon(
                      Icons.arrow_back,
                      className: 'text-xl text-gray-700 dark:text-gray-300',
                    ),
                  ),
                  Expanded(
                    child: WDiv(
                      className: 'flex flex-col',
                      children: [
                        WText(
                          monitor.name ?? 'Unnamed Monitor',
                          className:
                              'text-2xl font-bold text-gray-900 dark:text-white',
                        ),
                        WText(
                          monitor.url ?? '',
                          className:
                              'text-sm text-gray-600 dark:text-gray-400 font-mono',
                        ),
                      ],
                    ),
                  ),
                  WButton(
                    onTap: () => MagicRoute.to('/monitors/$_monitorId/edit'),
                    className: '''
                      px-4 py-2 rounded-lg
                      bg-gray-200 dark:bg-gray-700
                      text-gray-700 dark:text-gray-200
                      hover:bg-gray-300 dark:hover:bg-gray-600
                      text-sm font-medium
                    ''',
                    child: WText('Edit'),
                  ),
                ],
              ),

              // Quick Stats
              WDiv(
                className: 'grid grid-cols-2 md:grid-cols-4 gap-4',
                children: [
                  _buildStatCard(
                    'Status',
                    monitor.lastStatus?.label ?? 'Unknown',
                  ),
                  _buildStatCard('Type', monitor.type?.label ?? ''),
                  _buildStatCard('Interval', '${monitor.checkInterval ?? 0}s'),
                  _buildStatCard(
                    'Response',
                    '${monitor.lastResponseTimeMs ?? 0}ms',
                  ),
                ],
              ),

              // Placeholder for charts and history
              WDiv(
                className: '''
                  bg-white dark:bg-gray-800
                  border border-gray-200 dark:border-gray-700
                  rounded-xl p-6
                  flex items-center justify-center h-64
                ''',
                child: WText(
                  'Check history charts coming soon',
                  className: 'text-gray-600 dark:text-gray-400',
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _buildStatCard(String label, String value) {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800
        border border-gray-200 dark:border-gray-700
        rounded-xl p-4
      ''',
      children: [
        WText(
          label,
          className: 'text-xs uppercase text-gray-600 dark:text-gray-400 mb-1',
        ),
        WText(
          value,
          className: 'text-xl font-bold text-gray-900 dark:text-white',
        ),
      ],
    );
  }
}
