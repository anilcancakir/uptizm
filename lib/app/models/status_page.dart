import 'package:magic/magic.dart';
import './monitor.dart';

class StatusPage extends Model with HasTimestamps, InteractsWithPersistence {
  StatusPage() : super();

  static StatusPage fromMap(Map<String, dynamic> map) {
    return StatusPage()
      ..setRawAttributes(map, sync: true)
      ..exists = map.containsKey('id');
  }

  @override
  String get table => 'status-pages';

  @override
  String get resource => 'status-pages';

  @override
  List<String> get fillable => [
    'name',
    'slug',
    'description',
    'logo_url',
    'favicon_url',
    'primary_color',
    'is_published',
    'monitor_ids',
  ];

  String get name => get<String>('name') ?? '';
  set name(String value) => set('name', value);

  String get slug => get<String>('slug') ?? '';
  set slug(String value) => set('slug', value);

  String? get description => get<String>('description');
  set description(String? value) => set('description', value);

  String? get logoUrl => get<String>('logo_url');
  set logoUrl(String? value) => set('logo_url', value);

  String? get faviconUrl => get<String>('favicon_url');
  set faviconUrl(String? value) => set('favicon_url', value);

  String get primaryColor => get<String>('primary_color') ?? '#009E60';
  set primaryColor(String value) => set('primary_color', value);

  bool get isPublished => get<bool>('is_published') ?? false;
  set isPublished(bool value) => set('is_published', value);

  String get publicUrl => 'https://$slug.uptizm.com';

  List<int> get monitorIds {
    final ids = get<List>('monitor_ids');
    return ids?.map((e) => (e as num).toInt()).toList() ?? [];
  }

  set monitorIds(List<int> value) => set('monitor_ids', value);

  List<Monitor> get monitors {
    final data = get<List>('monitors');
    if (data == null) return [];
    return data
        .map((m) => Monitor()..fill(Map<String, dynamic>.from(m)))
        .toList();
  }
}
