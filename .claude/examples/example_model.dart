import 'dart:convert';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

/// Example Model - Monitor
///
/// Demonstrates all Eloquent ORM features in Magic Framework.
/// This is a REFERENCE example showing best practices.
///
/// ## Key Features
/// - Type-safe accessors (name, url, status)
/// - Computed properties (isUp, isDown)
/// - Timestamps with Carbon (createdAt, updatedAt)
/// - JSON casting (settings)
/// - Static helpers (find, all)
/// - Factory methods (fromMap, fromJson)
/// - Active Record pattern (save, delete, refresh)
///
/// ## Usage Examples
///
/// ```dart
/// // Find by ID
/// final monitor = await Monitor.find(1);
/// print(monitor?.name); // Type-safe
///
/// // Create new
/// final monitor = Monitor()
///   ..name = 'My Website'
///   ..url = 'https://example.com'
///   ..interval = 60;
/// await monitor.save();
///
/// // Update
/// monitor?.status = 'up';
/// await monitor?.save();
///
/// // Delete
/// await monitor?.delete();
///
/// // Get all
/// final monitors = await Monitor.all();
///
/// // Check if changed
/// monitor?.name = 'Updated';
/// if (monitor?.isDirty('name') == true) {
///   print('Name changed!');
/// }
/// ```
class Monitor extends Model with HasTimestamps, InteractsWithPersistence {
  // ---------------------------------------------------------------------------
  // Model Configuration
  // ---------------------------------------------------------------------------

  /// The table associated with the model.
  @override
  String get table => 'monitors';

  /// The API resource for remote operations.
  @override
  String get resource => 'monitors';

  /// The attributes that are mass assignable.
  @override
  List<String> get fillable => [
        'name',
        'url',
        'type',
        'interval',
        'timeout',
        'status',
        'settings',
        'team_id',
      ];

  /// The attributes that should be cast.
  @override
  Map<String, String> get casts => {
        'interval': 'int',
        'timeout': 'int',
        'settings': 'json', // Auto JSON encode/decode
        'last_checked_at': 'datetime', // Auto Carbon conversion
        'next_check_at': 'datetime',
      };

  /// The attributes that should be hidden for serialization.
  @override
  List<String> get hidden => ['internal_notes'];

  // ---------------------------------------------------------------------------
  // Typed Accessors (Recommended for type safety)
  // ---------------------------------------------------------------------------

  /// Get the monitor name.
  String? get name => getAttribute('name') as String?;

  /// Set the monitor name.
  set name(String? value) => setAttribute('name', value);

  /// Get the monitor URL.
  String? get url => getAttribute('url') as String?;

  /// Set the monitor URL.
  set url(String? value) => setAttribute('url', value);

  /// Get the monitor type (http, ping, port).
  String? get type => getAttribute('type') as String?;

  /// Set the monitor type.
  set type(String? value) => setAttribute('type', value);

  /// Get the check interval in seconds.
  int? get interval => getAttribute('interval') as int?;

  /// Set the check interval.
  set interval(int? value) => setAttribute('interval', value);

  /// Get the timeout in seconds.
  int? get timeout => getAttribute('timeout') as int?;

  /// Set the timeout.
  set timeout(int? value) => setAttribute('timeout', value);

  /// Get the current status (up, down, degraded, paused).
  String? get status => getAttribute('status') as String?;

  /// Set the status.
  set status(String? value) => setAttribute('status', value);

  /// Get the settings (auto-decoded from JSON).
  Map<String, dynamic>? get settings =>
      getAttribute('settings') as Map<String, dynamic>?;

  /// Set the settings (auto-encoded to JSON).
  set settings(Map<String, dynamic>? value) => setAttribute('settings', value);

  /// Get the team ID.
  int? get teamId => getAttribute('team_id') as int?;

  /// Set the team ID.
  set teamId(int? value) => setAttribute('team_id', value);

  /// Get the last checked timestamp (auto-converted to Carbon).
  Carbon? get lastCheckedAt => getAttribute('last_checked_at') as Carbon?;

  /// Set the last checked timestamp.
  set lastCheckedAt(dynamic value) => setAttribute('last_checked_at', value);

  /// Get the next check timestamp (auto-converted to Carbon).
  Carbon? get nextCheckAt => getAttribute('next_check_at') as Carbon?;

  /// Set the next check timestamp.
  set nextCheckAt(dynamic value) => setAttribute('next_check_at', value);

  // ---------------------------------------------------------------------------
  // Computed Properties
  // ---------------------------------------------------------------------------

  /// Check if monitor is currently up.
  bool get isUp => status == 'up';

  /// Check if monitor is currently down.
  bool get isDown => status == 'down';

  /// Check if monitor is degraded.
  bool get isDegraded => status == 'degraded';

  /// Check if monitor is paused.
  bool get isPaused => status == 'paused';

  /// Get status color for UI.
  String get statusColor {
    switch (status) {
      case 'up':
        return 'green';
      case 'down':
        return 'red';
      case 'degraded':
        return 'yellow';
      case 'paused':
        return 'gray';
      default:
        return 'gray';
    }
  }

  /// Get human-readable status text.
  String get statusText {
    switch (status) {
      case 'up':
        return 'Online';
      case 'down':
        return 'Offline';
      case 'degraded':
        return 'Degraded';
      case 'paused':
        return 'Paused';
      default:
        return 'Unknown';
    }
  }

  /// Get formatted interval text.
  String get intervalText {
    if (interval == null) return 'Unknown';
    if (interval! < 60) return '$interval seconds';
    final minutes = interval! ~/ 60;
    if (minutes < 60) return '$minutes minutes';
    final hours = minutes ~/ 60;
    return '$hours hours';
  }

  /// Check if monitor is overdue for check.
  bool get isOverdue {
    if (nextCheckAt == null) return false;
    return Carbon.now().isAfter(nextCheckAt!);
  }

  /// Get time until next check.
  String get nextCheckIn {
    if (nextCheckAt == null) return 'Unknown';
    return nextCheckAt!.diffForHumans();
  }

  // ---------------------------------------------------------------------------
  // Custom Methods
  // ---------------------------------------------------------------------------

  /// Check if monitor has a specific setting.
  bool hasSetting(String key) {
    return settings?.containsKey(key) == true;
  }

  /// Get a setting value with default.
  T? getSetting<T>(String key, {T? defaultValue}) {
    return settings?[key] as T? ?? defaultValue;
  }

  /// Set a setting value.
  void setSetting(String key, dynamic value) {
    final currentSettings = settings ?? {};
    currentSettings[key] = value;
    settings = currentSettings;
  }

  /// Remove a setting.
  void removeSetting(String key) {
    settings?.remove(key);
  }

  // ---------------------------------------------------------------------------
  // Static Helpers
  // ---------------------------------------------------------------------------

  /// Find a monitor by ID.
  ///
  /// ```dart
  /// final monitor = await Monitor.find(1);
  /// ```
  static Future<Monitor?> find(dynamic id) =>
      InteractsWithPersistence.findById<Monitor>(id, Monitor.new);

  /// Get all monitors.
  ///
  /// ```dart
  /// final monitors = await Monitor.all();
  /// ```
  static Future<List<Monitor>> all() =>
      InteractsWithPersistence.allModels<Monitor>(Monitor.new);

  /// Find monitors by status.
  ///
  /// Note: This uses Http facade as it requires filtering.
  /// Eloquent is for single-record operations, Http for queries.
  ///
  /// ```dart
  /// final upMonitors = await Monitor.whereStatus('up');
  /// ```
  static Future<List<Monitor>> whereStatus(String status) async {
    final response = await Http.get('/monitors', queryParams: {
      'status': status,
    });

    if (response.success) {
      return (response.data as List)
          .map((m) => Monitor.fromMap(m as Map<String, dynamic>))
          .toList();
    }

    return [];
  }

  /// Find monitors by team.
  ///
  /// ```dart
  /// final teamMonitors = await Monitor.whereTeam(teamId);
  /// ```
  static Future<List<Monitor>> whereTeam(int teamId) async {
    final response = await Http.get('/monitors', queryParams: {
      'team_id': teamId.toString(),
    });

    if (response.success) {
      return (response.data as List)
          .map((m) => Monitor.fromMap(m as Map<String, dynamic>))
          .toList();
    }

    return [];
  }

  // ---------------------------------------------------------------------------
  // Factory Methods
  // ---------------------------------------------------------------------------

  /// Create a Monitor from a Map.
  ///
  /// ```dart
  /// final monitor = Monitor.fromMap({
  ///   'id': 1,
  ///   'name': 'My Website',
  ///   'url': 'https://example.com'
  /// });
  /// ```
  static Monitor fromMap(Map<String, dynamic> map) {
    return Monitor()
      ..setRawAttributes(map, sync: true)
      ..exists = map.containsKey('id');
  }

  /// Create a Monitor from JSON.
  ///
  /// ```dart
  /// final monitor = Monitor.fromJson('{"id":1,"name":"My Website"}');
  /// ```
  static Monitor fromJson(String json) {
    final map = jsonDecode(json) as Map<String, dynamic>;
    return Monitor.fromMap(map);
  }

  // ---------------------------------------------------------------------------
  // Override toString for debugging
  // ---------------------------------------------------------------------------

  @override
  String toString() {
    return 'Monitor(id: $id, name: $name, url: $url, status: $status)';
  }
}

/// Example usage in a controller:
///
/// ```dart
/// class MonitorController extends MagicController {
///   final monitorsNotifier = ValueNotifier<List<Monitor>>([]);
///
///   Future<void> loadMonitors() async {
///     final monitors = await Monitor.all();
///     monitorsNotifier.value = monitors;
///   }
///
///   Future<void> createMonitor({
///     required String name,
///     required String url,
///     required int interval,
///   }) async {
///     final monitor = Monitor()
///       ..name = name
///       ..url = url
///       ..type = 'http'
///       ..interval = interval
///       ..timeout = 30
///       ..status = 'paused';
///
///     final success = await monitor.save();
///     if (success) {
///       Magic.toast('Monitor created successfully');
///       await loadMonitors();
///     }
///   }
///
///   Future<void> updateMonitor(Monitor monitor, String newName) async {
///     monitor.name = newName;
///     final success = await monitor.save();
///     if (success) {
///       Magic.toast('Monitor updated successfully');
///     }
///   }
///
///   Future<void> deleteMonitor(Monitor monitor) async {
///     final confirmed = await Magic.confirm(
///       title: 'Delete Monitor',
///       message: 'Are you sure you want to delete ${monitor.name}?',
///     );
///
///     if (confirmed) {
///       await monitor.delete();
///       Magic.toast('Monitor deleted successfully');
///       await loadMonitors();
///     }
///   }
///
///   Future<void> pauseMonitor(Monitor monitor) async {
///     monitor.status = 'paused';
///     await monitor.save();
///     Magic.toast('Monitor paused');
///   }
///
///   Future<void> resumeMonitor(Monitor monitor) async {
///     monitor.status = 'up';
///     await monitor.save();
///     Magic.toast('Monitor resumed');
///   }
/// }
/// ```
