import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import '../models/alert_rule.dart';
import '../models/alert.dart';
import '../../resources/views/alerts/alert_rules_index_view.dart';
import '../../resources/views/alerts/alert_rule_create_view.dart';
import '../../resources/views/alerts/alert_rule_edit_view.dart';
import '../../resources/views/alerts/alerts_index_view.dart';

/// Alert Controller
///
/// Handles alert and alert rule operations and state management.
class AlertController extends MagicController {
  AlertController._();
  static final instance = AlertController._();

  // State notifiers
  final alertRulesNotifier = ValueNotifier<List<AlertRule>>([]);
  final alertsNotifier = ValueNotifier<List<Alert>>([]);
  final activeAlertsNotifier = ValueNotifier<List<Alert>>([]);
  final isLoadingNotifier = ValueNotifier<bool>(false);

  // Computed getters
  int get activeAlertCount =>
      alertsNotifier.value.where((a) => a.isAlerting).length;

  int get criticalAlertCount {
    return alertsNotifier.value.where((alert) {
      if (!alert.isAlerting) return false;
      final alertRule =
          alert.getAttribute('alert_rule') as Map<String, dynamic>?;
      return alertRule?['severity'] == 'critical';
    }).length;
  }

  List<AlertRule> get teamLevelRules =>
      alertRulesNotifier.value.where((r) => r.isTeamLevel).toList();

  List<AlertRule> get monitorLevelRules =>
      alertRulesNotifier.value.where((r) => r.isMonitorLevel).toList();

  // API Methods

  /// Fetch alert rules for current team
  /// Backend automatically uses user's current_team_id
  Future<void> fetchAlertRules() async {
    isLoadingNotifier.value = true;

    try {
      Log.debug('Fetching alert rules...');

      // Use Eloquent-style query (like MonitorController)
      final response = await Http.get('/alert-rules');

      Log.debug('Alert rules response - successful: ${response.successful}');
      Log.debug('Alert rules response - status: ${response.statusCode}');
      Log.debug(
        'Alert rules response - data type: ${response.data?.runtimeType}',
      );

      if (response.successful) {
        // Handle resource collection format: {data: [...]}
        final dataWrapper = response.data;
        if (dataWrapper is Map && dataWrapper.containsKey('data')) {
          final data = dataWrapper['data'] as List;
          Log.debug('Found ${data.length} alert rules');
          alertRulesNotifier.value = data
              .map((r) => AlertRule.fromMap(r as Map<String, dynamic>))
              .toList();
        } else if (dataWrapper is List) {
          // Direct array response
          Log.debug('Found ${dataWrapper.length} alert rules (direct array)');
          alertRulesNotifier.value = dataWrapper
              .map((r) => AlertRule.fromMap(r as Map<String, dynamic>))
              .toList();
        } else {
          Log.error('Unexpected response format: ${dataWrapper.runtimeType}');
          Magic.toast('Unexpected API response format');
        }
      } else {
        Log.error('Failed to fetch alert rules - HTTP ${response.statusCode}');
        Log.error('Response message: ${response.message}');
        Magic.toast(response.message ?? trans('errors.network_error'));
      }
    } catch (e, stackTrace) {
      Log.error('Failed to fetch alert rules: $e\n$stackTrace', e);
      Magic.toast(trans('errors.network_error'));
    } finally {
      isLoadingNotifier.value = false;
    }
  }

  /// Fetch alert rules for a specific monitor
  Future<void> fetchMonitorAlertRules(String monitorId) async {
    isLoadingNotifier.value = true;

    try {
      final response = await Http.get('/monitors/$monitorId/alert-rules');
      if (response.successful) {
        final data = response.data['data'] as List;
        alertRulesNotifier.value = data
            .map((r) => AlertRule.fromMap(r))
            .toList();
      }
    } catch (e, s) {
      Log.error('Failed to fetch monitor alert rules: $e\n$s', e);
      Magic.toast(trans('errors.network_error'));
    } finally {
      isLoadingNotifier.value = false;
    }
  }

  /// Fetch alerts for current team with optional status filter
  /// Backend automatically uses user's current_team_id
  Future<void> fetchAlerts({String? status}) async {
    isLoadingNotifier.value = true;

    try {
      final query = <String, dynamic>{};
      if (status != null) query['status'] = status;

      Log.debug('Fetching alerts with filter: $status');
      final response = await Http.get('/alerts', query: query);

      Log.debug('Alerts response - successful: ${response.successful}');
      Log.debug('Alerts response - status: ${response.statusCode}');

      if (response.successful) {
        // Handle resource collection format: {data: [...]}
        final dataWrapper = response.data;
        if (dataWrapper is Map && dataWrapper.containsKey('data')) {
          final data = dataWrapper['data'] as List;
          Log.debug('Found ${data.length} alerts');
          alertsNotifier.value = data
              .map((a) => Alert.fromMap(a as Map<String, dynamic>))
              .toList();
        } else if (dataWrapper is List) {
          Log.debug('Found ${dataWrapper.length} alerts (direct array)');
          alertsNotifier.value = dataWrapper
              .map((a) => Alert.fromMap(a as Map<String, dynamic>))
              .toList();
        } else {
          Log.error('Unexpected alerts response format');
        }

        // Update active alerts
        activeAlertsNotifier.value = alertsNotifier.value
            .where((a) => a.isAlerting)
            .toList();
      } else {
        Log.error('Failed to fetch alerts - HTTP ${response.statusCode}');
        Magic.toast(response.message ?? trans('errors.network_error'));
      }
    } catch (e, stackTrace) {
      Log.error('Failed to fetch alerts: $e\n$stackTrace', e);
      Magic.toast(trans('errors.network_error'));
    } finally {
      isLoadingNotifier.value = false;
    }
  }

  /// Fetch monitor alerts
  Future<void> fetchMonitorAlerts(String monitorId) async {
    isLoadingNotifier.value = true;

    try {
      final response = await Http.get('/monitors/$monitorId/alerts');
      if (response.successful) {
        final data = response.data['data'] as List;
        alertsNotifier.value = data.map((a) => Alert.fromMap(a)).toList();
      }
    } catch (e, s) {
      Log.error('Failed to fetch monitor alerts: $e\n$s', e);
      Magic.toast(trans('errors.network_error'));
    } finally {
      isLoadingNotifier.value = false;
    }
  }

  /// Create a new alert rule
  Future<bool> createAlertRule(AlertRule rule) async {
    isLoadingNotifier.value = true;

    try {
      Log.debug('Creating alert rule: ${rule.toMap()}');
      final success = await rule.save();
      Log.debug('Alert rule save result: $success');

      if (success) {
        Magic.toast(trans('alerts.rule_created'));
        // Reload rules
        if (rule.monitorId != null) {
          await fetchMonitorAlertRules(rule.monitorId!);
        }
      } else {
        Magic.toast('Failed to save alert rule');
      }
      return success;
    } catch (e, stackTrace) {
      Log.error('Failed to create alert rule: $e\n$stackTrace', e);
      Magic.toast(trans('errors.network_error'));
      return false;
    } finally {
      isLoadingNotifier.value = false;
    }
  }

  /// Update an existing alert rule
  Future<bool> updateAlertRule(AlertRule rule) async {
    isLoadingNotifier.value = true;

    try {
      final success = await rule.save();
      if (success) {
        Magic.toast(trans('alerts.rule_updated'));
      }
      return success;
    } catch (e, s) {
      Log.error('Failed to update alert rule: $e\n$s', e);
      Magic.toast(trans('errors.network_error'));
      return false;
    } finally {
      isLoadingNotifier.value = false;
    }
  }

  /// Delete an alert rule
  Future<bool> deleteAlertRule(String ruleId) async {
    isLoadingNotifier.value = true;

    try {
      final rule = await AlertRule.find(ruleId);
      if (rule != null) {
        final success = await rule.delete();
        if (success) {
          Magic.toast(trans('alerts.rule_deleted'));
          // Remove from local state
          alertRulesNotifier.value = alertRulesNotifier.value
              .where((r) => r.id != ruleId)
              .toList();
        }
        return success;
      }
      return false;
    } catch (e, s) {
      Log.error('Failed to delete alert rule: $e\n$s', e);
      Magic.toast(trans('errors.network_error'));
      return false;
    } finally {
      isLoadingNotifier.value = false;
    }
  }

  /// Toggle alert rule enabled status
  Future<bool> toggleAlertRule(String ruleId, bool enabled) async {
    try {
      final response = await Http.post(
        '/alert-rules/$ruleId/toggle',
        data: {'enabled': enabled},
      );

      if (response.successful) {
        // Update local state
        final index = alertRulesNotifier.value.indexWhere(
          (r) => r.id == ruleId,
        );
        if (index != -1) {
          final updatedRule = alertRulesNotifier.value[index];
          updatedRule.enabled = enabled;
          alertRulesNotifier.value = List.from(alertRulesNotifier.value);
        }
        Magic.toast(
          trans(enabled ? 'alerts.rule_enabled' : 'alerts.rule_disabled'),
        );
        return true;
      }
      return false;
    } catch (e, s) {
      Log.error('Failed to toggle alert rule: $e\n$s', e);
      Magic.toast(trans('errors.network_error'));
      return false;
    }
  }

  // View Actions (Laravel-style)

  /// Show alert rules list page
  Widget rulesIndex() {
    // Load alert rules (backend uses current_team_id automatically)
    fetchAlertRules();

    return ValueListenableBuilder<List<AlertRule>>(
      valueListenable: alertRulesNotifier,
      builder: (context, rules, _) {
        return ValueListenableBuilder<bool>(
          valueListenable: isLoadingNotifier,
          builder: (context, isLoading, _) {
            return AlertRulesIndexView(
              initialRules: rules,
              isLoading: isLoading,
              onAddRule: () => MagicRoute.to('/alert-rules/create'),
              onEditRule: (rule) =>
                  MagicRoute.to('/alert-rules/${rule.id}/edit'),
              onDeleteRule: (rule) async {
                if (await Magic.confirm(
                  title: 'Delete Alert Rule',
                  message: 'Are you sure you want to delete this alert rule?',
                )) {
                  await deleteAlertRule(rule.id!);
                }
              },
            );
          },
        );
      },
    );
  }

  /// Show create alert rule form
  Widget rulesCreate() {
    // Check if this is for a specific monitor
    final monitorIdParam = MagicRouter.instance.pathParameter('id');
    final monitorId = monitorIdParam;

    return AlertRuleCreateView(
      monitorId: monitorId,
      onSubmit: (rule) async {
        Log.debug('onSubmit called with rule: ${rule.name}');

        // Set monitor ID if creating for specific monitor
        if (monitorId != null) {
          rule.monitorId = monitorId;
        }

        final success = await createAlertRule(rule);
        Log.debug('createAlertRule returned: $success');
        if (success) {
          Log.debug('Navigating back...');
          // Navigate back to appropriate page
          if (monitorId != null) {
            MagicRoute.to('/monitors/$monitorId/alerts');
          } else {
            MagicRoute.to('/alert-rules');
          }
        } else {
          Log.error('Failed to create alert rule');
        }
      },
    );
  }

  /// Show edit alert rule form
  Widget rulesEdit() {
    // Get rule ID from route parameter
    final id = MagicRouter.instance.pathParameter('id');
    if (id == null) {
      Magic.toast('Alert rule ID not found');
      MagicRoute.back();
      return const SizedBox.shrink();
    }

    // Find rule from current list or fetch
    final rule = alertRulesNotifier.value
        .where((r) => r.id.toString() == id)
        .firstOrNull;

    if (rule == null) {
      Magic.toast('Alert rule not found');
      MagicRoute.back();
      return const SizedBox.shrink();
    }

    return AlertRuleEditView(
      rule: rule,
      onSubmit: (updatedRule) async {
        updatedRule.id = rule.id;
        final success = await updateAlertRule(updatedRule);
        if (success) {
          MagicRoute.to('/alert-rules');
        }
      },
    );
  }

  /// Show alerts list page
  Widget alertsIndex() {
    // Load alerts (backend uses current_team_id automatically)
    fetchAlerts();

    return ValueListenableBuilder<List<Alert>>(
      valueListenable: alertsNotifier,
      builder: (context, alerts, _) {
        return ValueListenableBuilder<bool>(
          valueListenable: isLoadingNotifier,
          builder: (context, isLoading, _) {
            return AlertsIndexView(
              initialAlerts: alerts,
              isLoading: isLoading,
              onAlertTap: (alert) {
                // Navigate to monitor detail if available
                if (alert.monitorId != null) {
                  MagicRoute.to('/monitors/${alert.monitorId}');
                }
              },
            );
          },
        );
      },
    );
  }

  @override
  void dispose() {
    alertRulesNotifier.dispose();
    alertsNotifier.dispose();
    activeAlertsNotifier.dispose();
    isLoadingNotifier.dispose();
    super.dispose();
  }
}
