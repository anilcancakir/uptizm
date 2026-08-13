import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/support/billing_types.dart' show Plan;
import 'package:uptizm/app/mocks/billing.dart';
import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/mocks/oncall.dart';
import 'package:uptizm/resources/views/monitors/monitor_form_support.dart';
import 'package:uptizm/ui/components/region_picker/region_picker.dart';

/// Feeds the one key [aiNameFromUrl] resolves so [trans] returns the real
/// English fallback instead of the raw key token.
class _FormSupportLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async => {
    'uptizm.monitors.new_monitor': 'New monitor',
  };
}

void main() {
  setUp(() async {
    Translator.instance.setLoader(_FormSupportLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

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
    test('maps all 5 allRegions entries', () {
      final List<Region> result = probeRegionsToRegions(allRegions);
      expect(result.length, equals(5));
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

    test('is scoped to the backend-supported types http and tcp', () {
      final List<String> values = kMonitorTypes.map((o) => o.value).toList();
      // The backend App\Enums\MonitorType has exactly these two cases; ping /
      // keyword / ssl are roadmap and must not be offered (they 422 on submit).
      expect(values, equals(['http', 'tcp']));
    });
  });

  group('kHttpMethods', () {
    test('is non-empty', () {
      expect(kHttpMethods, isNotEmpty);
    });

    test('contains the backend HttpMethod enum values get, post, head', () {
      final List<String> values = kHttpMethods.map((o) => o.value).toList();
      expect(values, containsAll(['get', 'post', 'head']));
      // PUT is not a backend App\Enums\HttpMethod case, so the form must not
      // offer it (posting it 422s the create/save).
      expect(values, isNot(contains('put')));
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

  group('AiMetricSeed.fromMap', () {
    test('decodes every pinned wire key', () {
      final AiMetricSeed seed = AiMetricSeed.fromMap({
        'key': 'p95_ms',
        'label': 'p95 latency',
        'type': 'numeric',
        'source': 'json',
        'path': r'$.latency.p95',
        'unit': 'ms',
        'warn': 300,
        'critical': 1000,
        'sample_value': '120',
      });

      expect(seed.key, equals('p95_ms'));
      expect(seed.label, equals('p95 latency'));
      expect(seed.type, equals('numeric'));
      expect(seed.source, equals('json'));
      expect(seed.path, equals(r'$.latency.p95'));
      expect(seed.unit, equals('ms'));
      expect(seed.warn, equals('300'));
      expect(seed.critical, equals('1000'));
      expect(seed.sampleValue, equals('120'));
    });

    test('defaults every field defensively on an empty map, never throwing', () {
      final AiMetricSeed seed = AiMetricSeed.fromMap(const {});

      expect(seed.key, equals(''));
      expect(seed.label, equals(''));
      expect(seed.type, equals(''));
      expect(seed.source, equals(''));
      expect(seed.path, equals(''));
      expect(seed.unit, equals(''));
      expect(seed.warn, equals(''));
      expect(seed.critical, equals(''));
      expect(seed.sampleValue, equals(''));
    });

    test('null warn/critical/unit degrade to empty strings, not "null"', () {
      final AiMetricSeed seed = AiMetricSeed.fromMap({
        'key': 'active_conns',
        'label': 'Active connections',
        'type': 'numeric',
        'source': 'json',
        'path': r'$.pool.active',
        'unit': null,
        'warn': null,
        'critical': null,
        'sample_value': '4',
      });

      expect(seed.unit, equals(''));
      expect(seed.warn, equals(''));
      expect(seed.critical, equals(''));
    });
  });

  group('AiMetricSeed.origin', () {
    test('decodes the author of a suggestion', () {
      // The backend proposes the service's own health verdict itself, because
      // across live runs the model declined to. A surface that could not tell
      // the two apart would badge a deterministic row as AI work.
      expect(
        AiMetricSeed.fromMap(const {'origin': 'rule'}).isRule,
        isTrue,
      );
      expect(
        AiMetricSeed.fromMap(const {'origin': 'model'}).isRule,
        isFalse,
      );
    });

    test('an absent origin is not a rule', () {
      // A backend older than the field sent no origin and every row on it was
      // the model's, so the absent case has to read as `model` rather than
      // marking every legacy suggestion as rule-authored.
      final AiMetricSeed seed = AiMetricSeed.fromMap(const {});

      expect(seed.origin, '');
      expect(seed.isRule, isFalse);
    });
  });

  group('MonitorAnalysis.fromMap', () {
    test('an empty map yields an empty suggestedMetrics list and does not throw', () {
      final MonitorAnalysis analysis = MonitorAnalysis.fromMap(const {});
      expect(analysis.suggestedMetrics, equals(const []));
    });

    test('decodes suggested_metrics into AiMetricSeed entries', () {
      final MonitorAnalysis analysis = MonitorAnalysis.fromMap({
        'suggested_metrics': [
          {
            'key': 'p95_ms',
            'label': 'p95 latency',
            'type': 'numeric',
            'source': 'json',
            'path': r'$.latency.p95',
            'unit': 'ms',
            'warn': 300,
            'critical': 1000,
            'sample_value': '120',
          },
          {
            'key': 'error_rate',
            'label': 'Error rate',
            'type': 'numeric',
            'source': 'json',
            'path': r'$.errors.rate',
            'unit': '%',
            'warn': 1,
            'critical': 5,
            'sample_value': '0.2',
          },
        ],
      });

      expect(analysis.suggestedMetrics, hasLength(2));
      expect(analysis.suggestedMetrics.first.key, equals('p95_ms'));
      expect(analysis.suggestedMetrics.last.key, equals('error_rate'));
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
