import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/models/analytics_response.dart';
import 'package:uptizm/resources/views/monitors/monitor_analytics_view.dart';

class AnalyticsController extends MagicController {
  static AnalyticsController get instance =>
      Magic.findOrPut(AnalyticsController.new);

  // State
  final analyticsNotifier = ValueNotifier<AnalyticsResponse?>(null);
  final selectedMetricsNotifier = ValueNotifier<List<String>>([]);
  final dateRangeNotifier = ValueNotifier<DateTimeRange?>(null);
  final granularityNotifier = ValueNotifier<String>('hourly');
  final selectedPresetNotifier = ValueNotifier<String?>('24h');

  // Actions
  Widget analytics() => const MonitorAnalyticsView();

  Future<void> fetchAnalytics(
    String monitorId, {
    DateTime? dateFrom,
    DateTime? dateTo,
    List<String>? metrics,
    String granularity = 'hourly',
  }) async {
    // Determine dates if not provided
    final now = DateTime.now();
    final start = dateFrom ?? now.subtract(const Duration(hours: 24));
    final end = dateTo ?? now;

    try {
      final response = await Http.get(
        '/monitors/$monitorId/analytics',
        query: {
          'date_from': start.toIso8601String(),
          'date_to': end.toIso8601String(),
          'granularity': granularity,
        },
      );

      if (response.successful) {
        final data = response.data['data'];
        final analyticsData = AnalyticsResponse.fromMap(data);
        analyticsNotifier.value = analyticsData;

        // Auto-select all metrics if none selected initially
        if (selectedMetricsNotifier.value.isEmpty) {
          final allKeys = analyticsData.numericSeries
              .map((s) => s.metricKey)
              .toList();
          selectedMetricsNotifier.value = allKeys;
        }
      } else {
        Magic.toast(trans('errors.load_failed'));
      }
    } catch (e) {
      Log.error('Fetch analytics failed', e);
      Magic.toast(trans('errors.network_error'));
    }
  }

  // Presets
  void setLast24Hours(String monitorId) {
    selectedPresetNotifier.value = '24h';
    granularityNotifier.value = 'hourly';
    dateRangeNotifier.value = null; // Clear custom range
    fetchAnalytics(
      monitorId,
      dateFrom: DateTime.now().subtract(const Duration(hours: 24)),
      granularity: 'hourly',
    );
  }

  void setLast7Days(String monitorId) {
    selectedPresetNotifier.value = '7d';
    granularityNotifier.value = 'daily';
    dateRangeNotifier.value = null;
    fetchAnalytics(
      monitorId,
      dateFrom: DateTime.now().subtract(const Duration(days: 7)),
      granularity: 'daily',
    );
  }

  void setLast30Days(String monitorId) {
    selectedPresetNotifier.value = '30d';
    granularityNotifier.value = 'daily';
    dateRangeNotifier.value = null;
    fetchAnalytics(
      monitorId,
      dateFrom: DateTime.now().subtract(const Duration(days: 30)),
      granularity: 'daily',
    );
  }

  void setLast90Days(String monitorId) {
    selectedPresetNotifier.value = '90d';
    granularityNotifier.value = 'daily';
    dateRangeNotifier.value = null;
    fetchAnalytics(
      monitorId,
      dateFrom: DateTime.now().subtract(const Duration(days: 90)),
      granularity: 'daily',
    );
  }

  void setCustomRange(String monitorId, DateTimeRange range) {
    selectedPresetNotifier.value = null;
    dateRangeNotifier.value = range;
    // Auto-determine granularity based on range duration
    final duration = range.end.difference(range.start);
    final granularity = duration.inDays > 2 ? 'daily' : 'hourly';
    granularityNotifier.value = granularity;

    fetchAnalytics(
      monitorId,
      dateFrom: range.start,
      dateTo: range.end,
      granularity: granularity,
    );
  }

  // Metric selection
  void toggleMetric(String metricKey) {
    final current = List<String>.from(selectedMetricsNotifier.value);
    if (current.contains(metricKey)) {
      current.remove(metricKey);
    } else {
      current.add(metricKey);
    }
    selectedMetricsNotifier.value = current;
  }

  void selectAllMetrics() {
    final response = analyticsNotifier.value;
    if (response != null) {
      final allKeys = response.numericSeries.map((s) => s.metricKey).toList();
      selectedMetricsNotifier.value = allKeys;
    }
  }

  void clearMetrics() {
    selectedMetricsNotifier.value = [];
  }
}
