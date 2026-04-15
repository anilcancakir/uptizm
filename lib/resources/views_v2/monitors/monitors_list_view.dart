import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/enums/check_status.dart';
import '../../../app/enums/monitor_status.dart';
import '../../../app/enums/monitor_type.dart';
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
class MonitorsListV2View extends StatefulWidget {
  const MonitorsListV2View({super.key});

  @override
  State<MonitorsListV2View> createState() => _MonitorsListV2ViewState();
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

class _MonitorsListV2ViewState extends State<MonitorsListV2View> {
  _StatusFilter _activeFilter = _StatusFilter.all;
  bool _isSearching = false;
  final _searchController = TextEditingController();

  // -------  Mock Data  -------

  static const _mockMonitors = [
    _MockMonitor(
      name: 'Production API',
      url: 'api.example.com',
      checkStatus: CheckStatus.up,
      monitorStatus: MonitorStatus.active,
      type: MonitorType.http,
      responseTime: '245ms',
    ),
    _MockMonitor(
      name: 'Marketing Website',
      url: 'example.com',
      checkStatus: CheckStatus.up,
      monitorStatus: MonitorStatus.active,
      type: MonitorType.http,
      responseTime: '132ms',
    ),
    _MockMonitor(
      name: 'Payment Gateway',
      url: 'pay.example.com',
      checkStatus: CheckStatus.down,
      monitorStatus: MonitorStatus.active,
      type: MonitorType.http,
      responseTime: null,
    ),
    _MockMonitor(
      name: 'Staging API',
      url: 'staging.example.com',
      checkStatus: CheckStatus.up,
      monitorStatus: MonitorStatus.active,
      type: MonitorType.http,
      responseTime: '389ms',
    ),
    _MockMonitor(
      name: 'Admin Panel',
      url: 'admin.example.com',
      checkStatus: CheckStatus.up,
      monitorStatus: MonitorStatus.active,
      type: MonitorType.http,
      responseTime: '178ms',
    ),
    _MockMonitor(
      name: 'Database Health',
      url: 'db.example.com:5432',
      checkStatus: CheckStatus.up,
      monitorStatus: MonitorStatus.paused,
      type: MonitorType.port,
      responseTime: '12ms',
    ),
    _MockMonitor(
      name: 'CDN Endpoint',
      url: 'cdn.example.com',
      checkStatus: CheckStatus.up,
      monitorStatus: MonitorStatus.active,
      type: MonitorType.ping,
      responseTime: '45ms',
    ),
    _MockMonitor(
      name: 'Auth Service',
      url: 'auth.example.com/health',
      checkStatus: CheckStatus.degraded,
      monitorStatus: MonitorStatus.active,
      type: MonitorType.http,
      responseTime: '1204ms',
    ),
  ];

  // -------  Computed  -------

  List<_MockMonitor> get _filteredMonitors {
    var monitors = _mockMonitors;

    if (_activeFilter == _StatusFilter.up) {
      monitors = monitors
          .where((m) => m.checkStatus == CheckStatus.up)
          .toList();
    } else if (_activeFilter == _StatusFilter.down) {
      monitors = monitors
          .where(
            (m) =>
                m.checkStatus == CheckStatus.down ||
                m.checkStatus == CheckStatus.degraded,
          )
          .toList();
    }

    final query = _searchController.text.toLowerCase();
    if (query.isNotEmpty) {
      monitors = monitors
          .where(
            (m) =>
                m.name.toLowerCase().contains(query) ||
                m.url.toLowerCase().contains(query),
          )
          .toList();
    }

    return monitors;
  }

  int get _totalCount => _mockMonitors.length;

  int get _upCount =>
      _mockMonitors.where((m) => m.checkStatus == CheckStatus.up).length;

  int get _downCount => _mockMonitors
      .where(
        (m) =>
            m.checkStatus == CheckStatus.down ||
            m.checkStatus == CheckStatus.degraded,
      )
      .length;

  String? _countOf(_StatusFilter filter) => switch (filter) {
    _StatusFilter.all => null,
    _StatusFilter.up => '$_upCount',
    _StatusFilter.down => '$_downCount',
  };

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  // -------  Build  -------

  @override
  Widget build(BuildContext context) {
    final monitors = _filteredMonitors;
    final isFiltered =
        _activeFilter != _StatusFilter.all || _searchController.text.isNotEmpty;

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
                  onTap: () => MonitorCreateV2View.show(context),
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
            totalCount: _totalCount,
            upCount: _upCount,
            downCount: _downCount,
            avgResponse: '245ms',
          ),

          // Filter
          SegmentedControl<_StatusFilter>(
            items: _StatusFilter.values,
            selected: _activeFilter,
            labelOf: (f) => f.label,
            countOf: _countOf,
            onChanged: (f) => setState(() => _activeFilter = f),
          ),

          // Monitor list or empty state
          if (monitors.isEmpty)
            MonitorsEmptyState(
              isFiltered: isFiltered,
              onAddMonitor: () => MonitorCreateV2View.show(context),
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
                    name: monitors[i].name,
                    url: monitors[i].url,
                    checkStatus: monitors[i].checkStatus,
                    monitorStatus: monitors[i].monitorStatus,
                    responseTime: monitors[i].responseTime,
                    isLast: i == monitors.length - 1,
                    onTap: () =>
                        MagicRoute.to('/monitors/${monitors[i].name.hashCode}'),
                  ),
              ],
            ),
        ],
      ),
    );
  }
}

// -------  Mock Data Model  -------

class _MockMonitor {
  const _MockMonitor({
    required this.name,
    required this.url,
    required this.checkStatus,
    required this.monitorStatus,
    required this.type,
    required this.responseTime,
  });

  final String name;
  final String url;
  final CheckStatus checkStatus;
  final MonitorStatus monitorStatus;
  final MonitorType type;
  final String? responseTime;
}
