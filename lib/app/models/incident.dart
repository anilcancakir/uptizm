import 'package:magic/magic.dart';
import '../enums/incident_impact.dart';
import '../enums/incident_status.dart';
import './incident_update.dart';
import './monitor.dart';

class Incident extends Model with HasTimestamps, InteractsWithPersistence {
  Incident() : super();

  @override
  String get table => 'incidents';

  @override
  String get resource => 'incidents';

  @override
  List<String> get fillable => [
    'title',
    'impact',
    'status',
    'is_auto_created',
    'started_at',
    'resolved_at',
    'monitor_ids',
  ];

  @override
  bool get incrementing => false;

  // Typed getters
  @override
  String? get id => get<String>('id');

  String? get title => get<String>('title');

  IncidentImpact? get impact => IncidentImpact.fromValue(get<String>('impact'));

  IncidentStatus? get status => IncidentStatus.fromValue(get<String>('status'));

  bool get isAutoCreated => get<bool>('is_auto_created') ?? false;

  Carbon? get startedAt {
    final value = get<String>('started_at');
    return value != null ? Carbon.parse(value) : null;
  }

  Carbon? get resolvedAt {
    final value = get<String>('resolved_at');
    return value != null ? Carbon.parse(value) : null;
  }

  List<String> get monitorIds {
    final ids = get<List>('monitor_ids');
    if (ids == null) return [];
    return ids.map((e) => e.toString()).toList();
  }

  List<Monitor> get monitors {
    final data = get<List>('monitors');
    if (data == null) return [];
    return data
        .map((m) => Monitor()..setRawAttributes(Map<String, dynamic>.from(m)))
        .toList();
  }

  List<IncidentUpdate> get updates {
    final data = get<List>('updates');
    if (data == null) return [];
    return data
        .map(
          (u) =>
              IncidentUpdate()..setRawAttributes(Map<String, dynamic>.from(u)),
        )
        .toList();
  }

  // Typed setters
  set title(String? value) => set('title', value);

  set impact(IncidentImpact? value) => set('impact', value?.value);

  set status(IncidentStatus? value) => set('status', value?.value);

  set monitorIds(List<String> value) => set('monitor_ids', value);

  // Computed properties
  bool get isResolved => status?.value == 'resolved';

  bool get isActive => !isResolved;

  Duration get duration {
    if (startedAt == null) return Duration.zero;
    final start = DateTime.parse(startedAt.toString());
    final end = resolvedAt != null
        ? DateTime.parse(resolvedAt.toString())
        : DateTime.now();
    return end.difference(start);
  }

  // Static methods
  static Future<Incident?> find(String id) async {
    return await InteractsWithPersistence.findById<Incident>(id, Incident.new);
  }

  static Future<List<Incident>> all() async {
    return await InteractsWithPersistence.allModels<Incident>(Incident.new);
  }
}
