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

  // Static methods for nested resource (announcements are under status-pages)
  /// Fetch all announcements for a specific status page
  static Future<List<Announcement>> allForStatusPage(
    String statusPageId,
  ) async {
    try {
      final response = await Http.get(
        '/status-pages/$statusPageId/announcements',
      );
      if (response.successful) {
        final data = response.data['data'] as List?;
        if (data == null) return [];
        return data.map((item) {
          final announcement = Announcement();
          announcement.fill(item as Map<String, dynamic>);
          return announcement;
        }).toList();
      }
      return [];
    } catch (e) {
      Log.error(
        'Failed to fetch announcements for status page $statusPageId',
        e,
      );
      return [];
    }
  }

  /// Find a specific announcement for a status page
  static Future<Announcement?> findForStatusPage(
    String statusPageId,
    String announcementId,
  ) async {
    try {
      final response = await Http.get(
        '/status-pages/$statusPageId/announcements/$announcementId',
      );
      if (response.successful) {
        final data = response.data['data'] as Map<String, dynamic>?;
        if (data == null) return null;
        final announcement = Announcement();
        announcement.fill(data);
        return announcement;
      }
      return null;
    } catch (e) {
      Log.error('Failed to fetch announcement $announcementId', e);
      return null;
    }
  }

  /// Create a new announcement for a status page
  static Future<Announcement?> createForStatusPage(
    String statusPageId,
    Map<String, dynamic> data,
  ) async {
    try {
      final response = await Http.post(
        '/status-pages/$statusPageId/announcements',
        data: data,
      );
      if (response.successful) {
        final responseData = response.data['data'] as Map<String, dynamic>?;
        if (responseData == null) return null;
        final announcement = Announcement();
        announcement.fill(responseData);
        return announcement;
      }
      return null;
    } catch (e) {
      Log.error(
        'Failed to create announcement for status page $statusPageId',
        e,
      );
      return null;
    }
  }

  /// Update an existing announcement
  Future<bool> updateForStatusPage(String statusPageId) async {
    try {
      final response = await Http.put(
        '/status-pages/$statusPageId/announcements/$id',
        data: toMap(),
      );
      return response.successful;
    } catch (e) {
      Log.error('Failed to update announcement $id', e);
      return false;
    }
  }

  /// Delete an announcement
  Future<bool> deleteForStatusPage(String statusPageId) async {
    try {
      final response = await Http.delete(
        '/status-pages/$statusPageId/announcements/$id',
      );
      return response.successful;
    } catch (e) {
      Log.error('Failed to delete announcement $id', e);
      return false;
    }
  }

  /// @deprecated Use allForStatusPage instead - announcements are nested under status pages
  static Future<Announcement?> find(String id) async {
    throw UnsupportedError(
      'Announcements are nested under status pages. Use findForStatusPage(statusPageId, id) instead.',
    );
  }

  /// @deprecated Use allForStatusPage instead - announcements are nested under status pages
  static Future<List<Announcement>> all() async {
    throw UnsupportedError(
      'Announcements are nested under status pages. Use allForStatusPage(statusPageId) instead.',
    );
  }
}
