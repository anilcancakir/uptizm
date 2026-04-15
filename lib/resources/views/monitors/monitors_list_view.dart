import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/monitor_controller.dart';
import '../../../app/enums/check_status.dart';
import '../../../app/enums/monitor_status.dart';
import '../../../app/models/monitor.dart';
import '../components/common/page_header.dart';
import '../components/common/segmented_control.dart';
import '../components/monitors/monitor_list_row.dart';
import '../components/monitors/monitors_empty_state.dart';
import '../components/monitors/monitors_stats_bar.dart';
import 'monitor_create_view.dart';

/// Monitors list view with stats, filtering, search, and monitor rows.
///
/// ## Usage
/// Registered as a route in `routes/app.dart` under `/v2/monitors`.
class MonitorsListView extends StatefulWidget {
  const MonitorsListView({super.key});

  @override
  State<MonitorsListView> createState() => _MonitorsListViewState();
}

enum _StatusFilter {
  all,
  up,
  down;

  String get label => switch (this) {
    _StatusFilter.all => trans('monitors.filter_all'),
    _StatusFilter.up => trans('monitors.filter_up'),
    _StatusFilter.down => trans('monitors.filter_down'),
  };
}

class _MonitorsListViewState extends State<MonitorsListView> {
  _StatusFilter _activeFilter = _StatusFilter.all;
  bool _isSearching = false;
  final _searchController = TextEditingController();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      MonitorController.instance.loadMonitors();
      MonitorController.instance.fetchMonitorStats();
    });
  }

  // -------  Computed  -------

  List<Monitor> _filteredMonitors(List<Monitor> monitors) {
    var result = monitors;

    if (_activeFilter == _StatusFilter.up) {
      result = result.where((m) => m.lastStatus == CheckStatus.up).toList();
    } else if (_activeFilter == _StatusFilter.down) {
      result = result
          .where(
            (m) =>
                m.lastStatus == CheckStatus.down ||
                m.lastStatus == CheckStatus.degraded,
          )
          .toList();
    }

    final query = _searchController.text.toLowerCase();
    if (query.isNotEmpty) {
      result = result
          .where(
            (m) =>
                (m.name?.toLowerCase().contains(query) ?? false) ||
                (m.url?.toLowerCase().contains(query) ?? false),
          )
          .toList();
    }

    return result;
  }

  String? _countOf(
    _StatusFilter filter,
    List<Monitor> monitors,
  ) => switch (filter) {
    _StatusFilter.all => null,
    _StatusFilter.up =>
      '${monitors.where((m) => m.lastStatus == CheckStatus.up).length}',
    _StatusFilter.down =>
      '${monitors.where((m) => m.lastStatus == CheckStatus.down || m.lastStatus == CheckStatus.degraded).length}',
  };

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  // -------  Build  -------

  @override
  Widget build(BuildContext context) {
    final controller = MonitorController.instance;

    return ListenableBuilder(
      listenable: Listenable.merge([
        controller.monitorsNotifier,
        controller.monitorStatsNotifier,
      ]),
      builder: (context, _) {
        final allMonitors = controller.monitorsNotifier.value;
        final stats = controller.monitorStatsNotifier.value;
        final monitors = _filteredMonitors(allMonitors);
        final isFiltered =
            _activeFilter != _StatusFilter.all ||
            _searchController.text.isNotEmpty;

        final avgResponse = stats?.avgResponseTimeMs != null
            ? '${stats!.avgResponseTimeMs!.round()}ms'
            : '0ms';

        return WDiv(
          className: 'flex-1 overflow-y-auto',
          scrollPrimary: true,
          child: WDiv(
            className: 'flex flex-col gap-6 p-4 pb-8',
            children: [
              // Top bar
              PageHeader(
                title: trans('monitors.title'),
                trailing: WDiv(
                  className: 'flex flex-row items-center gap-1',
                  children: [
                    WButton(
                      onTap: () => setState(() {
                        _isSearching = !_isSearching;
                        if (!_isSearching) _searchController.clear();
                      }),
                      states: {if (_isSearching) 'active'},
                      className: '''
                        p-3 rounded-lg
                        active:bg-primary-50 dark:active:bg-primary-900/30
                      ''',
                      child: WIcon(
                        Icons.search_rounded,
                        states: {if (_isSearching) 'active'},
                        className: '''
                          text-[22px]
                          text-gray-600 dark:text-gray-300
                          active:text-primary dark:active:text-primary-400
                        ''',
                      ),
                    ),
                    WButton(
                      onTap: () => MonitorCreateView.show(context),
                      className: 'p-3 rounded-lg',
                      child: WIcon(
                        Icons.add_rounded,
                        className: '''
                          text-[22px]
                          text-primary dark:text-primary-400
                        ''',
                      ),
                    ),
                  ],
                ),
              ),

              // Search input
              if (_isSearching)
                WFormInput(
                  controller: _searchController,
                  placeholder: trans('monitors.search_placeholder'),
                  onChanged: (_) => setState(() {}),
                  className: '''
                    h-12 px-4 rounded-lg
                    bg-gray-50 dark:bg-gray-800
                    border border-gray-300 dark:border-gray-600
                    focus:border-primary dark:focus:border-primary-400
                    text-base text-gray-900 dark:text-white
                  ''',
                  placeholderClassName: 'text-gray-400 dark:text-gray-500',
                ),

              // Stats bar
              MonitorsStatsBar(
                totalCount: stats?.total ?? 0,
                upCount: stats?.up ?? 0,
                downCount: stats?.down ?? 0,
                avgResponse: avgResponse,
              ),

              // Filter
              SegmentedControl<_StatusFilter>(
                items: _StatusFilter.values,
                selected: _activeFilter,
                labelOf: (f) => f.label,
                countOf: (f) => _countOf(f, allMonitors),
                onChanged: (f) => setState(() => _activeFilter = f),
              ),

              // Monitor list, loading skeleton, or empty state
              if (allMonitors.isEmpty && controller.isLoading)
                _LoadingSkeleton()
              else if (monitors.isEmpty)
                MonitorsEmptyState(
                  isFiltered: isFiltered,
                  onAddMonitor: () => MonitorCreateView.show(context),
                )
              else
                WDiv(
                  className: '''
                    flex flex-col rounded-xl
                    bg-gray-50 dark:bg-gray-800
                    border border-gray-200 dark:border-gray-700
                  ''',
                  children: [
                    for (var i = 0; i < monitors.length; i++)
                      MonitorListRow(
                        name: monitors[i].name ?? '',
                        url: monitors[i].url ?? '',
                        checkStatus: monitors[i].lastStatus ?? CheckStatus.down,
                        monitorStatus:
                            monitors[i].status ?? MonitorStatus.active,
                        responseTime: monitors[i].lastResponseTimeMs != null
                            ? '${monitors[i].lastResponseTimeMs}ms'
                            : null,
                        isLast: i == monitors.length - 1,
                        onTap: () =>
                            MagicRoute.to('/monitors/${monitors[i].id}'),
                      ),
                  ],
                ),
            ],
          ),
        );
      },
    );
  }
}

// -------  Loading Skeleton  -------

class _LoadingSkeleton extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        flex flex-col rounded-xl
        bg-gray-50 dark:bg-gray-800
        border border-gray-200 dark:border-gray-700
      ''',
      children: [
        for (var i = 0; i < 4; i++)
          WDiv(
            states: {if (i < 3) 'bordered'},
            className: '''
              py-3.5 px-4
              bordered:border-b bordered:border-gray-200
              bordered:dark:border-gray-700
            ''',
            child: WDiv(
              className: 'flex flex-row items-center gap-3',
              children: [
                WDiv(
                  className: '''
                    w-2.5 h-2.5 rounded-full
                    bg-gray-200 dark:bg-gray-700
                  ''',
                ),
                WDiv(
                  className: 'flex-1 flex flex-col gap-1.5',
                  children: [
                    WDiv(
                      className: '''
                        h-3 w-32 rounded
                        bg-gray-200 dark:bg-gray-700
                      ''',
                    ),
                    WDiv(
                      className: '''
                        h-2.5 w-24 rounded
                        bg-gray-200 dark:bg-gray-700
                      ''',
                    ),
                  ],
                ),
                WDiv(
                  className: '''
                    h-3 w-12 rounded
                    bg-gray-200 dark:bg-gray-700
                  ''',
                ),
              ],
            ),
          ),
      ],
    );
  }
}
