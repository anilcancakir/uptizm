import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/http_method.dart';
import 'package:uptizm/app/enums/monitor_location.dart';
import 'package:uptizm/app/enums/monitor_type.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/models/monitor_auth_config.dart';
import 'package:uptizm/app/models/monitor_check.dart';
import 'package:uptizm/app/models/monitor_metric_value.dart';
import 'package:uptizm/app/models/paginated_checks.dart';
import 'package:uptizm/resources/views/monitors/monitors_index_view.dart';
import 'package:uptizm/resources/views/monitors/monitor_create_view.dart';
import 'package:uptizm/resources/views/monitors/monitor_show_view.dart';
import 'package:uptizm/resources/views/monitors/monitor_edit_view.dart';
import 'package:uptizm/resources/views/monitors/monitor_alerts_view.dart';

/// Monitor Controller
///
/// Handles monitor CRUD operations and state management.
class MonitorController extends MagicController
    with MagicStateMixin<bool>, ValidatesRequests {
  /// Singleton accessor.
  static MonitorController get instance =>
      Magic.findOrPut(MonitorController.new);

  /// State notifiers for reactive UI.
  ///
  /// Use [MagicBuilder] in views for cleaner syntax:
  ///
  /// ```dart
  /// // Instead of:
  /// ValueListenableBuilder<List<Monitor>>(
  ///   valueListenable: controller.monitorsNotifier,
  ///   builder: (context, monitors, _) => _buildList(monitors),
  /// )
  ///
  /// // Use:
  /// MagicBuilder<List<Monitor>>(
  ///   listenable: controller.monitorsNotifier,
  ///   builder: (monitors) => _buildList(monitors),
  /// )
  /// ```
  final monitorsNotifier = ValueNotifier<List<Monitor>>([]);
  final selectedMonitorNotifier = ValueNotifier<Monitor?>(null);
  final checksNotifier = ValueNotifier<List<MonitorCheck>>([]);
  final checksPaginationNotifier = ValueNotifier<PaginatedChecks?>(null);
  final statusMetricsNotifier = ValueNotifier<List<MonitorMetricValue>>([]);

  // Loading states
  bool _isLoading = false;
  @override
  bool get isLoading => _isLoading;

  /// Render index view
  Widget index() {
    return const MonitorsIndexView();
  }

  /// Render create monitor view
  Widget create() {
    return const MonitorCreateView();
  }

  /// Render show monitor view
  Widget show() {
    return const MonitorShowView();
  }

  /// Render edit monitor view
  Widget edit() {
    return const MonitorEditView();
  }

  /// Render monitor alerts view
  Widget alerts() {
    return const MonitorAlertsView();
  }

  /// Load monitors for current team
  Future<void> loadMonitors() async {
    _isLoading = true;
    notifyListeners();

    try {
      // Backend automatically uses user's current_team_id
      final monitors = await Monitor.all();
      monitorsNotifier.value = monitors;
    } catch (e) {
      Log.error('Failed to load monitors', e);
      Magic.toast(trans('errors.network_error'));
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// Load a single monitor
  Future<void> loadMonitor(String id) async {
    _isLoading = true;
    notifyListeners();

    Log.debug('Loading monitor with ID: $id');

    try {
      final monitor = await Monitor.find(id);
      Log.debug('Monitor loaded: ${monitor?.name ?? 'null'}');
      selectedMonitorNotifier.value = monitor;
    } catch (e) {
      Log.error('Failed to load monitor', e);
      Magic.toast(trans('errors.network_error'));
      selectedMonitorNotifier.value = null;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// Create a new monitor
  Future<void> store({
    required String name,
    required MonitorType type,
    required String url,
    HttpMethod? method,
    Map<String, dynamic>? headers,
    String? body,
    int? expectedStatusCode,
    required int checkInterval,
    required int timeout,
    int? incidentThreshold,
    required List<MonitorLocation> monitoringLocations,
    List<Map<String, dynamic>>? assertionRules,
    List<Map<String, dynamic>>? metricMappings,
    List<String>? tags,
    MonitorAuthConfig? authConfig,
  }) async {
    setLoading();
    clearErrors();

    try {
      final monitor = Monitor()
        // teamId is automatically set by backend from user's current_team_id
        ..name = name
        ..type = type
        ..url = url
        ..method = method
        ..headers = headers
        ..body = body
        ..expectedStatusCode = expectedStatusCode ?? 200
        ..checkInterval = checkInterval
        ..timeout = timeout
        ..incidentThreshold = incidentThreshold
        ..monitoringLocations = monitoringLocations
        ..assertionRules = assertionRules
        ..metricMappings = metricMappings
        ..tags = tags
        ..authConfig = authConfig ?? MonitorAuthConfig.none();

      final success = await monitor.save();

      if (success) {
        setSuccess(true);
        Magic.toast(trans('monitors.created_successfully'));

        // Navigate to monitor detail page to see first check results
        MagicRoute.to('/monitors/${monitor.id}');
      } else {
        setError(trans('monitors.create_failed'));
      }
    } catch (e) {
      Log.error('Failed to create monitor', e);
      setError(trans('errors.network_error'));
    }
  }

  /// Update an existing monitor
  Future<void> update(
    String id, {
    String? name,
    String? url,
    HttpMethod? method,
    Map<String, dynamic>? headers,
    String? body,
    int? expectedStatusCode,
    int? checkInterval,
    int? timeout,
    int? incidentThreshold,
    List<MonitorLocation>? monitoringLocations,
    List<Map<String, dynamic>>? assertionRules,
    List<Map<String, dynamic>>? metricMappings,
    List<String>? tags,
    MonitorAuthConfig? authConfig,
  }) async {
    setLoading();
    clearErrors();

    try {
      final monitor = await Monitor.find(id);
      if (monitor == null) {
        setError(trans('monitors.not_found'));
        return;
      }

      // Update only provided fields
      if (name != null) monitor.name = name;
      if (url != null) monitor.url = url;
      if (method != null) monitor.method = method;
      if (headers != null) monitor.headers = headers;
      if (body != null) monitor.body = body;
      if (expectedStatusCode != null) {
        monitor.expectedStatusCode = expectedStatusCode;
      }
      if (checkInterval != null) monitor.checkInterval = checkInterval;
      if (timeout != null) monitor.timeout = timeout;
      if (incidentThreshold != null)
        monitor.incidentThreshold = incidentThreshold;
      if (monitoringLocations != null) {
        monitor.monitoringLocations = monitoringLocations;
      }
      if (assertionRules != null) monitor.assertionRules = assertionRules;
      if (metricMappings != null) monitor.metricMappings = metricMappings;
      if (tags != null) monitor.tags = tags;
      if (authConfig != null) monitor.authConfig = authConfig;

      final success = await monitor.save();

      if (success) {
        setSuccess(true);
        Magic.toast(trans('monitors.updated_successfully'));

        // Navigate to monitor detail page
        MagicRoute.to('/monitors/$id');

        // Reload monitor after navigation
        await loadMonitor(id);
      } else {
        setError(trans('monitors.update_failed'));
      }
    } catch (e) {
      Log.error('Failed to update monitor', e);
      setError(trans('errors.network_error'));
    }
  }

  /// Delete a monitor
  Future<void> destroy(String id) async {
    final confirmed = await Magic.confirm(
      title: trans('common.confirm'),
      message: trans('monitors.delete_confirm'),
      confirmText: trans('common.delete'),
      cancelText: trans('common.cancel'),
    );

    if (!confirmed) return;

    setLoading();

    try {
      final monitor = await Monitor.find(id);
      if (monitor == null) {
        setError(trans('monitors.not_found'));
        return;
      }

      final success = await monitor.delete();

      if (success) {
        setSuccess(true);
        Magic.toast(trans('monitors.deleted_successfully'));

        // Navigate to monitors list
        MagicRoute.to('/monitors');

        // Reload monitors after navigation
        await loadMonitors();
      } else {
        setError(trans('monitors.delete_failed'));
      }
    } catch (e) {
      Log.error('Failed to delete monitor', e);
      setError(trans('errors.network_error'));
    }
  }

  /// Pause a monitor
  Future<void> pause(String id) async {
    setLoading();

    try {
      final response = await Http.post('/monitors/$id/pause');

      if (response.successful) {
        setSuccess(true);
        Magic.toast(trans('monitors.paused_successfully'));
        await loadMonitor(id);
      } else {
        setError(trans('monitors.pause_failed'));
      }
    } catch (e) {
      Log.error('Failed to pause monitor', e);
      setError(trans('errors.network_error'));
    }
  }

  /// Resume a monitor
  Future<void> resume(String id) async {
    setLoading();

    try {
      final response = await Http.post('/monitors/$id/resume');

      if (response.successful) {
        setSuccess(true);
        Magic.toast(trans('monitors.resumed_successfully'));
        await loadMonitor(id);
      } else {
        setError(trans('monitors.resume_failed'));
      }
    } catch (e) {
      Log.error('Failed to resume monitor', e);
      setError(trans('errors.network_error'));
    }
  }

  /// Load check history for a monitor
  Future<void> loadChecks(String monitorId, {int page = 1}) async {
    try {
      final paginatedChecks = await MonitorCheck.forMonitor(
        monitorId,
        page: page,
      );
      checksNotifier.value = paginatedChecks.checks;
      checksPaginationNotifier.value = paginatedChecks;
    } catch (e) {
      Log.error('Failed to load checks', e);
      Magic.toast(trans('errors.network_error'));
    }
  }

  /// Load next page of checks
  Future<void> loadNextPage(String monitorId) async {
    final currentPagination = checksPaginationNotifier.value;
    if (currentPagination != null && currentPagination.hasNextPage) {
      await loadChecks(monitorId, page: currentPagination.currentPage + 1);
    }
  }

  /// Load previous page of checks
  Future<void> loadPreviousPage(String monitorId) async {
    final currentPagination = checksPaginationNotifier.value;
    if (currentPagination != null && currentPagination.hasPreviousPage) {
      await loadChecks(monitorId, page: currentPagination.currentPage - 1);
    }
  }

  /// Fetch status metrics for a monitor
  Future<List<MonitorMetricValue>> fetchStatusMetrics(String monitorId) async {
    try {
      final response = await Http.get('/monitors/$monitorId/metrics/status');

      if (response.successful) {
        final data = response.data['data'] as List?;
        if (data != null) {
          final metrics = data
              .map((m) => MonitorMetricValue.fromMap(m))
              .toList();
          statusMetricsNotifier.value = metrics;
          return metrics;
        }
      }
      return [];
    } catch (e) {
      Log.error('Failed to fetch status metrics', e);
      return [];
    }
  }

  @override
  void dispose() {
    monitorsNotifier.dispose();
    selectedMonitorNotifier.dispose();
    checksNotifier.dispose();
    checksPaginationNotifier.dispose();
    statusMetricsNotifier.dispose();
    super.dispose();
  }
}
