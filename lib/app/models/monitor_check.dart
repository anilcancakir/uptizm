import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:uptizm/app/enums/check_status.dart';
import 'package:uptizm/app/enums/monitor_location.dart';
import 'package:uptizm/app/models/paginated_checks.dart';

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
  Map<String, dynamic>? get parsedMetrics {
    final value = _attributes['parsed_metrics'];
    if (value == null) return null;
    if (value is Map<String, dynamic>) return value;
    if (value is Map) return Map<String, dynamic>.from(value);
    return null;
  }

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
  static Future<PaginatedChecks> forMonitor(
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
        // The API now returns {data: [...], meta: {...}}
        if (response.data is Map<String, dynamic>) {
          return PaginatedChecks.fromResponse(
            response.data as Map<String, dynamic>,
          );
        }

        // Fallback for legacy structure if any
        if (response.data is List) {
          return PaginatedChecks(
            checks: (response.data as List)
                .map((item) => MonitorCheck.fromMap(item))
                .toList(),
            currentPage: 1,
            lastPage: 1,
            perPage: (response.data as List).length,
            total: (response.data as List).length,
          );
        }
      }
      return const PaginatedChecks(
        checks: [],
        currentPage: 1,
        lastPage: 1,
        perPage: 15,
        total: 0,
      );
    } catch (e) {
      Log.error('Failed to fetch monitor checks', e);
      return const PaginatedChecks(
        checks: [],
        currentPage: 1,
        lastPage: 1,
        perPage: 15,
        total: 0,
      );
    }
  }
}
