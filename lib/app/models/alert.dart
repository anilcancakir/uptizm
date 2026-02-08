import 'package:magic/magic.dart';
import '../enums/alert_status.dart';
import 'alert_rule.dart';

class Alert extends Model with HasTimestamps, InteractsWithPersistence {
  @override
  String get table => 'alerts';

  @override
  String get resource => 'alerts';

  @override
  List<String> get fillable => [
    'alert_rule_id',
    'monitor_id',
    'status',
    'triggered_at',
    'resolved_at',
    'trigger_value',
    'trigger_message',
  ];

  @override
  bool get incrementing => false;

  // Getters
  @override
  String? get id => getAttribute('id')?.toString();
  String? get alertRuleId => getAttribute('alert_rule_id')?.toString();
  String? get monitorId => getAttribute('monitor_id')?.toString();

  AlertStatus get status =>
      AlertStatus.fromValue(getAttribute('status') as String? ?? 'alerting') ??
      AlertStatus.alerting;

  DateTime? get triggeredAt => getAttribute('triggered_at') != null
      ? DateTime.tryParse(getAttribute('triggered_at') as String)
      : null;

  DateTime? get resolvedAt => getAttribute('resolved_at') != null
      ? DateTime.tryParse(getAttribute('resolved_at') as String)
      : null;

  double? get triggerValue => _toDouble(getAttribute('trigger_value'));

  static double? _toDouble(dynamic value) {
    if (value == null) return null;
    if (value is num) return value.toDouble();
    if (value is String) return double.tryParse(value);
    return null;
  }

  String? get triggerMessage => getAttribute('trigger_message') as String?;

  // Relationships
  AlertRule? get alertRule {
    final data = getAttribute('alert_rule');
    if (data == null) return null;
    if (data is Map<String, dynamic>) {
      return AlertRule.fromMap(data);
    }
    return null;
  }

  // Computed properties
  bool get isAlerting => status == AlertStatus.alerting;
  bool get isResolved => status == AlertStatus.resolved;

  Duration? get duration {
    if (triggeredAt == null) return null;
    final end = resolvedAt ?? DateTime.now();
    return end.difference(triggeredAt!);
  }

  // Setters
  set alertRuleId(String? value) => setAttribute('alert_rule_id', value);
  set monitorId(String? value) => setAttribute('monitor_id', value);
  set status(AlertStatus value) => setAttribute('status', value.value);
  set triggeredAt(DateTime? value) =>
      setAttribute('triggered_at', value?.toIso8601String());
  set resolvedAt(DateTime? value) =>
      setAttribute('resolved_at', value?.toIso8601String());
  set triggerValue(double? value) => setAttribute('trigger_value', value);
  set triggerMessage(String? value) => setAttribute('trigger_message', value);

  // Static methods
  static Future<Alert?> find(dynamic id) =>
      InteractsWithPersistence.findById<Alert>(id, Alert.new);

  static Alert fromMap(Map<String, dynamic> map) => Alert()
    ..setRawAttributes(map, sync: true)
    ..exists = map.containsKey('id');
}
