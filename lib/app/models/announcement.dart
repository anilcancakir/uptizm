import 'package:magic/magic.dart';
import '../enums/announcement_type.dart';

class Announcement extends Model with HasTimestamps, InteractsWithPersistence {
  Announcement() : super();

  @override
  String get table => 'announcements';

  @override
  String get resource => 'announcements';

  @override
  List<String> get fillable => [
    'status_page_id',
    'title',
    'body',
    'type',
    'scheduled_at',
    'published_at',
    'ended_at',
  ];

  @override
  bool get incrementing => false;

  // Typed getters
  @override
  String? get id => get<String>('id');

  String? get statusPageId => get<String>('status_page_id');

  String? get title => get<String>('title');

  String? get body => get<String>('body');

  AnnouncementType? get type => AnnouncementType.fromValue(get<String>('type'));

  Carbon? get scheduledAt {
    final value = get<String>('scheduled_at');
    return value != null ? Carbon.parse(value) : null;
  }

  Carbon? get publishedAt {
    final value = get<String>('published_at');
    return value != null ? Carbon.parse(value) : null;
  }

  Carbon? get endedAt {
    final value = get<String>('ended_at');
    return value != null ? Carbon.parse(value) : null;
  }

  // Typed setters
  set title(String? value) => set('title', value);

  set body(String? value) => set('body', value);

  set type(AnnouncementType? value) => set('type', value?.value);

  set scheduledAt(Carbon? value) => set('scheduled_at', value?.toString());

  set publishedAt(Carbon? value) => set('published_at', value?.toString());

  set endedAt(Carbon? value) => set('ended_at', value?.toString());

  // Computed properties
  bool get isActive {
    if (publishedAt == null) return false;
    if (endedAt == null) return true;
    final end = DateTime.parse(endedAt.toString());
    return end.isAfter(DateTime.now());
  }

  bool get isScheduled => scheduledAt != null;

  bool get isEnded => endedAt != null;

  // Static methods
  static Future<Announcement?> find(String id) async {
    return await InteractsWithPersistence.findById<Announcement>(
      id,
      Announcement.new,
    );
  }

  static Future<List<Announcement>> all() async {
    return await InteractsWithPersistence.allModels<Announcement>(
      Announcement.new,
    );
  }
}
