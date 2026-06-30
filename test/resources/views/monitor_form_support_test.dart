import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/mocks/billing.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/mocks/oncall.dart';
import 'package:uptizm/resources/views/monitor_form_support.dart';
import 'package:uptizm/ui/components/region_picker/region_picker.dart';

void main() {
  // ---------------------------------------------------------------------------
  // aiNameFromUrl
  // ---------------------------------------------------------------------------

  group('aiNameFromUrl', () {
    test('returns "New monitor" for an empty string', () {
      expect(aiNameFromUrl(''), equals('New monitor'));
    });

    test('strips https:// and path, returning only the host', () {
      expect(
        aiNameFromUrl('https://api.example.com/health'),
        equals('api.example.com'),
      );
    });

    test('strips http:// scheme and path', () {
      expect(
        aiNameFromUrl('http://status.example.com/ping'),
        equals('status.example.com'),
      );
    });

    test('returns the raw string when no scheme is present and no slash follows', () {
      expect(aiNameFromUrl('not-a-url'), equals('not-a-url'));
    });

    test('strips path from a scheme-less string that has a slash', () {
      // Without a scheme, the leading regex does not fire, but the path-strip
      // regex fires: 'host/path' -> 'host'.
      expect(aiNameFromUrl('example.com/health'), equals('example.com'));
    });

    test('returns "New monitor" for a string that yields an empty host', () {
      // A bare "/" after stripping scheme and path produces an empty host.
      expect(aiNameFromUrl('/'), equals('New monitor'));
    });
  });

  // ---------------------------------------------------------------------------
  // probeRegionsToRegions
  // ---------------------------------------------------------------------------

  group('probeRegionsToRegions', () {
    test('maps all 6 allRegions entries', () {
      final List<Region> result = probeRegionsToRegions(allRegions);
      expect(result.length, equals(6));
    });

    test('preserves label, value, and flag for each region', () {
      final List<Region> result = probeRegionsToRegions(allRegions);
      for (int i = 0; i < allRegions.length; i++) {
        expect(
          result[i].label,
          equals(allRegions[i].label),
          reason: 'label must match at index $i',
        );
        expect(
          result[i].value,
          equals(allRegions[i].value),
          reason: 'value must match at index $i',
        );
        expect(
          result[i].flag,
          equals(allRegions[i].flag),
          reason: 'flag must match at index $i',
        );
      }
    });

    test('produces Region instances (not ProbeRegion)', () {
      final List<Region> result = probeRegionsToRegions(allRegions);
      expect(result.first, isA<Region>());
    });
  });

  // ---------------------------------------------------------------------------
  // Constants: non-empty and expected entries
  // ---------------------------------------------------------------------------

  group('kMonitorTypes', () {
    test('is non-empty', () {
      expect(kMonitorTypes, isNotEmpty);
    });

    test('contains http, ping, tcp, dns', () {
      final List<String> values = kMonitorTypes.map((o) => o.value).toList();
      expect(values, containsAll(['http', 'ping', 'tcp', 'dns']));
    });
  });

  group('kHttpMethods', () {
    test('is non-empty', () {
      expect(kHttpMethods, isNotEmpty);
    });

    test('contains GET, POST, PUT, HEAD', () {
      final List<String> values = kHttpMethods.map((o) => o.value).toList();
      expect(values, containsAll(['GET', 'POST', 'PUT', 'HEAD']));
    });
  });

  group('kCheckIntervals', () {
    test('is non-empty', () {
      expect(kCheckIntervals, isNotEmpty);
    });

    test('contains 10s, 30s, 1m, 5m options', () {
      final List<String> values = kCheckIntervals.map((o) => o.value).toList();
      expect(values, containsAll(['10s', '30s', '1m', '5m']));
    });
  });

  group('kIntervalSeconds', () {
    test('is non-empty', () {
      expect(kIntervalSeconds, isNotEmpty);
    });

    test('maps 10s to 10', () {
      expect(kIntervalSeconds['10s'], equals(10));
    });

    test('maps 30s to 30', () {
      expect(kIntervalSeconds['30s'], equals(30));
    });

    test('maps 1m to 60', () {
      expect(kIntervalSeconds['1m'], equals(60));
    });

    test('maps 5m to 300', () {
      expect(kIntervalSeconds['5m'], equals(300));
    });
  });

  group('kSloTargets', () {
    test('is non-empty', () {
      expect(kSloTargets, isNotEmpty);
    });

    test('first option has an empty value (no SLO)', () {
      expect(kSloTargets.first.value, equals(''));
    });
  });

  group('kAnalyzeSteps', () {
    test('is non-empty', () {
      expect(kAnalyzeSteps, isNotEmpty);
    });
  });

  group('kAiMetrics', () {
    test('is non-empty', () {
      expect(kAiMetrics, isNotEmpty);
    });
  });

  // ---------------------------------------------------------------------------
  // Billing: smallestPlanWhere
  // ---------------------------------------------------------------------------

  group('smallestPlanWhere', () {
    test('returns Business for checkIntervalSec <= 10', () {
      final Plan result = smallestPlanWhere((l) => l.checkIntervalSec <= 10);
      expect(result.id, equals('business'));
    });

    test('returns Pro for checkIntervalSec <= 30', () {
      final Plan result = smallestPlanWhere((l) => l.checkIntervalSec <= 30);
      expect(result.id, equals('pro'));
    });

    test('returns the last plan (Enterprise) when no plan satisfies the predicate', () {
      // An impossible predicate: checkIntervalSec <= 0 is never true.
      final Plan result = smallestPlanWhere((l) => l.checkIntervalSec <= 0);
      expect(result.id, equals('enterprise'));
    });

    test('currentLimits.checkIntervalSec equals 30 (the Pro plan)', () {
      // The design-lab mock team is on the Pro plan.
      expect(currentLimits.checkIntervalSec, equals(30));
    });
  });

  // ---------------------------------------------------------------------------
  // On-call: defaultEscalationPolicy
  // ---------------------------------------------------------------------------

  group('defaultEscalationPolicy', () {
    test('is a member of escalationPolicies', () {
      expect(escalationPolicies, contains(defaultEscalationPolicy));
    });

    test('has isDefault == true', () {
      expect(defaultEscalationPolicy.isDefault, isTrue);
    });
  });
}
