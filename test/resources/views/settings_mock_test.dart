import 'package:flutter_test/flutter_test.dart';

import 'package:uptizm/app/support/settings_types.dart'
    show AppTimezone, ChangelogRelease, DeviceSession;
import 'package:uptizm/app/mocks/settings.dart';

void main() {
  // ---------------------------------------------------------------------------
  // searchTimezones
  // ---------------------------------------------------------------------------

  group('searchTimezones', () {
    test('an empty query returns every fixture zone', () {
      final List<AppTimezone> result = searchTimezones('');
      expect(result.length, timezonesFromApi().length);
    });

    test('a whitespace-only query returns every fixture zone', () {
      final List<AppTimezone> result = searchTimezones('   ');
      expect(result.length, timezonesFromApi().length);
    });

    test('a city substring matches the zone', () {
      final List<AppTimezone> result = searchTimezones('istanbul');
      expect(result, hasLength(1));
      expect(result.single.value, 'Europe/Istanbul');
    });

    test('the match is case-insensitive', () {
      final List<AppTimezone> lower = searchTimezones('tokyo');
      final List<AppTimezone> upper = searchTimezones('TOKYO');
      final List<AppTimezone> mixed = searchTimezones('ToKyO');
      expect(lower, hasLength(1));
      expect(upper.single.value, lower.single.value);
      expect(mixed.single.value, lower.single.value);
    });

    test('a region substring matches every zone in that region', () {
      final List<AppTimezone> result = searchTimezones('asia');
      expect(result, isNotEmpty);
      for (final AppTimezone zone in result) {
        expect(zone.region.toLowerCase(), 'asia');
      }
    });

    test('an offset substring matches every zone at that offset', () {
      final List<AppTimezone> result = searchTimezones('gmt+09:00');
      expect(result, isNotEmpty);
      for (final AppTimezone zone in result) {
        expect(zone.offset.toLowerCase(), 'gmt+09:00');
      }
    });

    test('an IANA value substring matches the zone', () {
      final List<AppTimezone> result = searchTimezones('Europe/Berlin');
      expect(result, hasLength(1));
      expect(result.single.value, 'Europe/Berlin');
    });

    test('a non-matching query returns an empty list', () {
      expect(searchTimezones('not-a-real-place'), isEmpty);
    });
  });

  // ---------------------------------------------------------------------------
  // Fixture integrity
  // ---------------------------------------------------------------------------

  group('fixture integrity', () {
    test('appLanguages is non-empty', () {
      expect(appLanguages, isNotEmpty);
    });

    test('timezonesFromApi is non-empty', () {
      expect(timezonesFromApi(), isNotEmpty);
    });

    test('deviceSessions is non-empty', () {
      expect(deviceSessions, isNotEmpty);
    });

    test('exactly one deviceSession is the current session', () {
      final int currentCount = deviceSessions
          .where((DeviceSession session) => session.current)
          .length;
      expect(currentCount, 1);
    });

    test('changelog is non-empty', () {
      expect(changelog, isNotEmpty);
    });

    test('every changelog release has at least one change', () {
      for (final ChangelogRelease release in changelog) {
        expect(release.changes, isNotEmpty, reason: release.version);
      }
    });

    test('defaultNotificationPrefs is non-empty', () {
      expect(defaultNotificationPrefs, isNotEmpty);
    });
  });
}
