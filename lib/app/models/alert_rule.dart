import 'package:magic/magic.dart';
import '../enums/alert_rule_type.dart';
import '../enums/alert_severity.dart';
import '../enums/alert_operator.dart';

class AlertRule extends Model with HasTimestamps, InteractsWithPersistence {
  @override
  String get table => 'alert_rules';

  @override
  String get resource => 'alert-rules';

  @override
  List<String> get fillable => [
    'team_id',
    'monitor_id',
    'name',
    'type',
    'enabled',
    'metric_key',
    'operator',
    'threshold_value',
    'threshold_min',
    'threshold_max',
    'severity',
    'consecutive_checks',
  ];

  // Getters
  int? get id => (getAttribute('id') as num?)?.toInt();
  int? get teamId => (getAttribute('team_id') as num?)?.toInt();
  int? get monitorId => (getAttribute('monitor_id') as num?)?.toInt();
  String? get name => getAttribute('name') as String?;

  AlertRuleType get type =>
      AlertRuleType.fromValue(getAttribute('type') as String? ?? 'threshold') ??
      AlertRuleType.threshold;

  bool get enabled {
    final value = getAttribute('enabled');
    if (value == null) return true; // Default to true
    return value == true || value == 1;
  }

  String? get metricKey => getAttribute('metric_key') as String?;

  AlertOperator? get operator => getAttribute('operator') != null
      ? AlertOperator.fromValue(getAttribute('operator') as String)
      : null;

  double? get thresholdValue =>
      (getAttribute('threshold_value') as num?)?.toDouble();
  double? get thresholdMin =>
      (getAttribute('threshold_min') as num?)?.toDouble();
  double? get thresholdMax =>
      (getAttribute('threshold_max') as num?)?.toDouble();

  AlertSeverity get severity =>
      AlertSeverity.fromValue(
        getAttribute('severity') as String? ?? 'warning',
      ) ??
      AlertSeverity.warning;

  int get consecutiveChecks =>
      (getAttribute('consecutive_checks') as num?)?.toInt() ?? 1;

  // Setters
  set teamId(int? value) => setAttribute('team_id', value);
  set monitorId(int? value) => setAttribute('monitor_id', value);
  set name(String? value) => setAttribute('name', value);
  set type(AlertRuleType value) => setAttribute('type', value.value);
  set enabled(bool value) => setAttribute('enabled', value);
  set metricKey(String? value) => setAttribute('metric_key', value);
  set operator(AlertOperator? value) => setAttribute('operator', value?.value);
  set thresholdValue(double? value) => setAttribute('threshold_value', value);
  set thresholdMin(double? value) => setAttribute('threshold_min', value);
  set thresholdMax(double? value) => setAttribute('threshold_max', value);
  set severity(AlertSeverity value) => setAttribute('severity', value.value);
  set consecutiveChecks(int value) => setAttribute('consecutive_checks', value);

  // Computed properties
  bool get isTeamLevel => monitorId == null;
  bool get isMonitorLevel => monitorId != null;

  // Static methods
  static Future<AlertRule?> find(dynamic id) =>
      InteractsWithPersistence.findById<AlertRule>(id, AlertRule.new);

  static AlertRule fromMap(Map<String, dynamic> map) => AlertRule()
    ..setRawAttributes(map, sync: true)
    ..exists = map.containsKey('id');
}
