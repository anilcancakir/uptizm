import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/models/status_page.dart';

void main() {
  group('StatusPage', () {
    test('fillable fields are correct', () {
      final statusPage = StatusPage();
      expect(statusPage.fillable, [
        'name',
        'slug',
        'description',
        'logo_url',
        'favicon_url',
        'primary_color',
        'is_published',
        'monitor_ids',
      ]);
    });

    test('typed getters and setters work correctly', () {
      final statusPage = StatusPage();

      statusPage.name = 'Uptizm Status';
      expect(statusPage.name, 'Uptizm Status');
      expect(statusPage.get<String>('name'), 'Uptizm Status');

      statusPage.slug = 'uptizm';
      expect(statusPage.slug, 'uptizm');
      expect(statusPage.get<String>('slug'), 'uptizm');

      statusPage.description = 'System Status';
      expect(statusPage.description, 'System Status');
      expect(statusPage.get<String>('description'), 'System Status');

      statusPage.logoUrl = 'https://logo.com';
      expect(statusPage.logoUrl, 'https://logo.com');
      expect(statusPage.get<String>('logo_url'), 'https://logo.com');

      statusPage.faviconUrl = 'https://favicon.com';
      expect(statusPage.faviconUrl, 'https://favicon.com');
      expect(statusPage.get<String>('favicon_url'), 'https://favicon.com');

      statusPage.primaryColor = '#FF0000';
      expect(statusPage.primaryColor, '#FF0000');
      expect(statusPage.get<String>('primary_color'), '#FF0000');

      statusPage.isPublished = true;
      expect(statusPage.isPublished, true);
      expect(statusPage.get<bool>('is_published'), true);

      statusPage.monitorIds = [1, 2, 3];
      expect(statusPage.monitorIds, [1, 2, 3]);
      expect(statusPage.get<List>('monitor_ids'), [1, 2, 3]);
    });

    test('primaryColor has default value #009E60', () {
      final statusPage = StatusPage();
      expect(statusPage.primaryColor, '#009E60');
    });

    test('isPublished has default value false', () {
      final statusPage = StatusPage();
      expect(statusPage.isPublished, false);
    });

    test('publicUrl returns correct URL based on slug', () {
      final statusPage = StatusPage();
      statusPage.slug = 'uptizm';
      expect(statusPage.publicUrl, 'https://uptizm.uptizm.com');
    });

    test('monitorIds handles numeric values correctly', () {
      final statusPage = StatusPage();
      statusPage.setRawAttributes({
        'monitor_ids': [1.0, 2, 3.5],
      }, sync: true);
      expect(statusPage.monitorIds, [1, 2, 3]);
    });

    test('monitors getter returns list of Monitor models', () {
      final statusPage = StatusPage();
      statusPage.setRawAttributes({
        'monitors': [
          {'id': 1, 'name': 'Monitor 1'},
          {'id': 2, 'name': 'Monitor 2'},
        ],
      }, sync: true);

      final monitors = statusPage.monitors;
      expect(monitors.length, 2);
      expect(monitors[0], isA<Monitor>());
      expect(
        monitors[0].id,
        isNull,
      ); // fill() skips 'id' as it's not in fillable
      expect(monitors[0].name, 'Monitor 1');
    });

    test('monitors getter returns empty list when field is null', () {
      final statusPage = StatusPage();
      expect(statusPage.monitors, []);
    });

    test('fromMap creates model with correct attributes', () {
      final statusPage = StatusPage.fromMap({
        'id': 1,
        'name': 'Test Page',
        'slug': 'test-page',
      });
      expect(statusPage.id, 1);
      expect(statusPage.name, 'Test Page');
      expect(statusPage.slug, 'test-page');
    });
  });
}
