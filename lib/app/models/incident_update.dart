import 'package:magic/magic.dart';
import '../enums/incident_status.dart';

class IncidentUpdate extends Model with HasTimestamps {
  IncidentUpdate() : super();

  @override
  String get table => 'incident_updates';

  @override
  String get resource => 'incident_updates';

  @override
  List<String> get fillable => ['incident_id', 'status', 'title', 'message'];

  // Typed getters
  @override
  String? get id => get<String>('id');

  String? get incidentId => get<String>('incident_id');

  IncidentStatus? get status => IncidentStatus.fromValue(get<String>('status'));

  String? get title => get<String>('title');

  String? get message => get<String>('message');

  // Typed setters
  set status(IncidentStatus? value) => set('status', value?.value);

  set title(String? value) => set('title', value);

  set message(String? value) => set('message', value);
}
