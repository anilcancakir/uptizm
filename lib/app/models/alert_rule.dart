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

  @override
  bool get incrementing => false;

  // Getters
  @override
  String? get id => getAttribute('id')?.toString();
  String? get teamId => getAttribute('team_id')?.toString();
  String? get monitorId => getAttribute('monitor_id')?.toString();
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

  double? get thresholdValue => _toDouble(getAttribute('threshold_value'));
  double? get thresholdMin => _toDouble(getAttribute('threshold_min'));
  double? get thresholdMax => _toDouble(getAttribute('threshold_max'));

  static double? _toDouble(dynamic value) {
    if (value == null) return null;
    if (value is num) return value.toDouble();
    if (value is String) return double.tryParse(value);
    return null;
  }

  AlertSeverity get severity =>
      AlertSeverity.fromValue(
        getAttribute('severity') as String? ?? 'warning',
      ) ??
      AlertSeverity.warning;

  int get consecutiveChecks {
    final value = getAttribute('consecutive_checks');
    if (value == null) return 1;
    if (value is num) return value.toInt();
    if (value is String) return int.tryParse(value) ?? 1;
    return 1;
  }

  // Setters
  set teamId(String? value) => setAttribute('team_id', value);
  set monitorId(String? value) => setAttribute('monitor_id', value);
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
