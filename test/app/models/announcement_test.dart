import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/enums/announcement_type.dart';
import 'package:uptizm/app/models/announcement.dart';

void main() {
  group('Announcement', () {
    test('fillable includes required fields', () {
      final announcement = Announcement();
      expect(announcement.fillable.contains('status_page_id'), true);
      expect(announcement.fillable.contains('title'), true);
      expect(announcement.fillable.contains('body'), true);
      expect(announcement.fillable.contains('type'), true);
      expect(announcement.fillable.contains('scheduled_at'), true);
      expect(announcement.fillable.contains('published_at'), true);
      expect(announcement.fillable.contains('ended_at'), true);
    });

    test('table and resource are correct', () {
      final announcement = Announcement();
      expect(announcement.table, 'announcements');
      expect(announcement.resource, 'announcements');
    });

    test('incrementing is false', () {
      final announcement = Announcement();
      expect(announcement.incrementing, false);
    });

    test('id getter returns string', () {
      final announcement = Announcement();
      announcement.setRawAttributes({'id': 'uuid-789'}, sync: true);
      expect(announcement.id, 'uuid-789');
    });

    test('statusPageId getter returns string', () {
      final announcement = Announcement();
      announcement.setRawAttributes({'status_page_id': 'page-123'}, sync: true);
      expect(announcement.statusPageId, 'page-123');
    });

    test('title getter and setter work', () {
      final announcement = Announcement();
      announcement.title = 'Scheduled Maintenance';
      expect(announcement.title, 'Scheduled Maintenance');
    });

    test('body getter and setter work', () {
      final announcement = Announcement();
      announcement.body = 'We will be down for maintenance';
      expect(announcement.body, 'We will be down for maintenance');
    });

    test('type getter returns enum', () {
      final announcement = Announcement();
      announcement.setRawAttributes({'type': 'maintenance'}, sync: true);
      expect(announcement.type, AnnouncementType.maintenance);
    });

    test('type setter stores value', () {
      final announcement = Announcement();
      announcement.type = AnnouncementType.improvement;
      expect(announcement.getAttribute('type'), 'improvement');
    });

    test('scheduledAt getter returns Carbon', () {
      final announcement = Announcement();
      announcement.setRawAttributes({
        'scheduled_at': '2024-02-01T10:00:00Z',
      }, sync: true);
      expect(announcement.scheduledAt, isNotNull);
    });

    test('scheduledAt getter returns null when not set', () {
      final announcement = Announcement();
      announcement.setRawAttributes({}, sync: true);
      expect(announcement.scheduledAt, isNull);
    });

    test('publishedAt getter returns Carbon', () {
      final announcement = Announcement();
      announcement.setRawAttributes({
        'published_at': '2024-02-01T10:00:00Z',
      }, sync: true);
      expect(announcement.publishedAt, isNotNull);
    });

    test('endedAt getter returns Carbon', () {
      final announcement = Announcement();
      announcement.setRawAttributes({
        'ended_at': '2024-02-02T10:00:00Z',
      }, sync: true);
      expect(announcement.endedAt, isNotNull);
    });

    test('isActive computed property', () {
      final announcement = Announcement();
      announcement.setRawAttributes({
        'published_at': '2024-01-01T10:00:00Z',
      }, sync: true);
      expect(announcement.isActive, true);

      announcement.setRawAttributes({
        'ended_at': '2024-01-01T10:00:00Z',
      }, sync: true);
      expect(announcement.isActive, false);
    });

    test('isScheduled computed property', () {
      final announcement = Announcement();
      announcement.setRawAttributes({
        'scheduled_at': '2024-02-01T10:00:00Z',
      }, sync: true);
      expect(announcement.isScheduled, true);

      announcement.setRawAttributes({}, sync: true);
      expect(announcement.isScheduled, false);
    });

    test('isEnded computed property', () {
      final announcement = Announcement();
      announcement.setRawAttributes({
        'ended_at': '2024-01-01T10:00:00Z',
      }, sync: true);
      expect(announcement.isEnded, true);

      announcement.setRawAttributes({}, sync: true);
      expect(announcement.isEnded, false);
    });
  });
}
