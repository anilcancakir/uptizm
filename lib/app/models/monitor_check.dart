import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:uptizm/app/enums/check_status.dart';
import 'package:uptizm/app/enums/monitor_location.dart';

class MonitorCheck {
  MonitorCheck._();

  final Map<String, dynamic> _attributes = {};

  // Typed getters
  int? get id => _attributes['id'] as int?;
  int? get monitorId => _attributes['monitor_id'] as int?;
  MonitorLocation? get location =>
      MonitorLocation.fromValue(_attributes['location'] as String?);
  CheckStatus? get status =>
      CheckStatus.fromValue(_attributes['status'] as String?);
  int? get responseTimeMs => _attributes['response_time_ms'] as int?;
  int? get statusCode => _attributes['status_code'] as int?;
  String? get responseBody => _attributes['response_body'] as String?;
  Map<String, dynamic>? get parsedMetrics =>
      _attributes['parsed_metrics'] as Map<String, dynamic>?;
  bool? get assertionsPassed => _attributes['assertions_passed'] as bool?;
  List<Map<String, dynamic>>? get assertionResults =>
      (_attributes['assertion_results'] as List?)?.cast<Map<String, dynamic>>();
  String? get errorMessage => _attributes['error_message'] as String?;
  Carbon? get checkedAt {
    final value = _attributes['checked_at'];
    return value != null ? Carbon.parse(value.toString()) : null;
  }

  // Computed properties
  bool get isUp => status?.value == 'up';
  bool get isDown => status?.value == 'down';
  bool get isDegraded => status?.value == 'degraded';
  bool get hasError => errorMessage != null && errorMessage!.isNotEmpty;

  // Factory constructor
  static MonitorCheck fromMap(Map<String, dynamic> map) {
    final check = MonitorCheck._();
    check._attributes.addAll(map);
    return check;
  }

  // Fetch check history for a monitor
  static Future<List<MonitorCheck>> forMonitor(
    int monitorId, {
    int page = 1,
  }) async {
    try {
      final response = await Http.get(
        '/monitors/$monitorId/checks',
        query: {'page': page},
      );

      if (response.successful && response.data != null) {
        // Handle both direct list and {data: [...]} structure
        List items;
        if (response.data is List) {
          items = response.data;
        } else if (response.data is Map && response.data.containsKey('data')) {
          items = response.data['data'] as List;
        } else {
          return [];
        }

        return items
            .map((item) => MonitorCheck.fromMap(item))
            .toList();
      }
      return [];
    } catch (e) {
      Log.error('Failed to fetch monitor checks', e);
      return [];
    }
  }
}
