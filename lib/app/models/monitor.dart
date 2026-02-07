import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/check_status.dart';
import 'package:uptizm/app/enums/http_method.dart';
import 'package:uptizm/app/enums/monitor_location.dart';
import 'package:uptizm/app/enums/monitor_status.dart';
import 'package:uptizm/app/enums/monitor_type.dart';
import 'package:uptizm/app/models/monitor_auth_config.dart';

class Monitor extends Model with HasTimestamps, InteractsWithPersistence {
  Monitor() : super();

  @override
  String get table => 'monitors';

  @override
  String get resource => 'monitors';

  @override
  List<String> get fillable => [
    'team_id',
    'name',
    'type',
    'url',
    'method',
    'headers',
    'body',
    'expected_status_code',
    'check_interval',
    'timeout',
    'incident_threshold',
    'monitoring_locations',
    'assertion_rules',
    'metric_mappings',
    'tags',
    'status',
    'auth_config',
  ];

  @override
  bool get incrementing => false;

  // Typed getters
  @override
  String? get id => get<String>('id');
  String? get teamId => get<String>('team_id');
  String? get name => get<String>('name');
  MonitorType? get type => MonitorType.fromValue(get<String>('type'));
  String? get url => get<String>('url');
  HttpMethod? get method => HttpMethod.fromValue(get<String>('method'));
  Map<String, dynamic>? get headers => get<Map<String, dynamic>>('headers');
  String? get body => get<String>('body');
  int? get expectedStatusCode => get<int>('expected_status_code');
  int? get checkInterval => get<int>('check_interval');
  int? get timeout => get<int>('timeout');
  int? get incidentThreshold => (get('incident_threshold') as num?)?.toInt();
  List<MonitorLocation>? get monitoringLocations =>
      MonitorLocation.fromValueList(get<List>('monitoring_locations'));
  List<Map<String, dynamic>>? get assertionRules =>
      get<List>('assertion_rules')?.cast<Map<String, dynamic>>();
  List<Map<String, dynamic>>? get metricMappings =>
      get<List>('metric_mappings')?.cast<Map<String, dynamic>>();
  List<String>? get tags => get<List>('tags')?.cast<String>();
  MonitorStatus? get status => MonitorStatus.fromValue(get<String>('status'));
  CheckStatus? get lastStatus =>
      CheckStatus.fromValue(get<String>('last_status'));
  Carbon? get lastCheckedAt {
    final value = get<String>('last_checked_at');
    return value != null ? Carbon.parse(value) : null;
  }

  int? get lastResponseTimeMs => get<int>('last_response_time_ms');

  // Typed setters
  set teamId(String? value) => set('team_id', value);
  set name(String? value) => set('name', value);
  set type(MonitorType? value) => set('type', value?.value);
  set url(String? value) => set('url', value);
  set method(HttpMethod? value) => set('method', value?.value);
  set headers(Map<String, dynamic>? value) => set('headers', value);
  set body(String? value) => set('body', value);
  set expectedStatusCode(int? value) => set('expected_status_code', value);
  set checkInterval(int? value) => set('check_interval', value);
  set timeout(int? value) => set('timeout', value);
  set incidentThreshold(int? value) => set('incident_threshold', value);
  set monitoringLocations(List<MonitorLocation>? value) =>
      set('monitoring_locations', MonitorLocation.toValueList(value ?? []));
  set assertionRules(List<Map<String, dynamic>>? value) =>
      set('assertion_rules', value);
  set metricMappings(List<Map<String, dynamic>>? value) =>
      set('metric_mappings', value);
  set tags(List<String>? value) => set('tags', value);
  set status(MonitorStatus? value) => set('status', value?.value);

  // Auth config
  MonitorAuthConfig get authConfig =>
      MonitorAuthConfig.fromMap(get<Map<String, dynamic>>('auth_config'));
  set authConfig(MonitorAuthConfig? value) =>
      set('auth_config', value?.toMap());

  // Computed properties
  bool get isUp => lastStatus?.value == 'up';
  bool get isDown => lastStatus?.value == 'down';
  bool get isDegraded => lastStatus?.value == 'degraded';
  bool get isActive => status?.value == 'active';
  bool get isPaused => status?.value == 'paused';
  bool get isHttp => type?.value == 'http';
  bool get isPing => type?.value == 'ping';

  // Static methods
  // Use framework's built-in persistence methods
  // Framework automatically handles {data: {...}} response structure

  static Future<Monitor?> find(String id) async {
    Log.debug('Monitor.find called with ID: $id');
    Log.debug('Monitor resource: monitors');
    Log.debug('Calling InteractsWithPersistence.findById...');
    final result = await InteractsWithPersistence.findById<Monitor>(
      id,
      Monitor.new,
    );
    Log.debug(
      'Monitor.find result: ${result != null ? "found (${result.name})" : "null"}',
    );
    return result;
  }

  static Future<List<Monitor>> all() async {
    Log.debug('Monitor.all called');
    final result = await InteractsWithPersistence.allModels<Monitor>(
      Monitor.new,
    );
    Log.debug('Monitor.all returned ${result.length} monitors');
    return result;
  }
}
