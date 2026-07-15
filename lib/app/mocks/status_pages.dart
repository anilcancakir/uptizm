import 'metrics.dart'
    show customMetricsForMonitors, metricsForMonitors, systemMetricsForMonitors;
import 'monitors.dart' show findMonitor, uptime90;
import '../models/monitor.dart';
import '../models/status_page.dart';
import '../support/metric_types.dart' show MonitorMetric;
import '../support/monitor_types.dart' show UptimeSegment;
import '../support/status_page_types.dart' show PublicComponent, Subscriber;
import '../../resources/views/monitors/monitor_metrics_support.dart'
    show MetricOption;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/// Design-lab status-page fixtures, projected onto the [StatusPage] ORM model.
///
/// Two pages: a customer-facing page assigning all monitors, and an internal
/// ops page assigning a subset. Ids match the `monitors` fixture. The
/// predecessor `StatusPageConfig` value object was deleted once the status
/// views, controller, and editor migrated to [StatusPage]; these fixtures are
/// hydrated through [StatusPage.fromMap] from `StatusPageResource`-shaped maps
/// (`domain_mode` as the in-app `DomainMode` name, `brand_color` as `#rrggbb`,
/// the monitor pivot as `{id}` rows). Deterministic; no network.
final List<StatusPage> statusPages = [
  StatusPage.fromMap(<String, dynamic>{
    'id': 'acme',
    'name': 'Acme Status',
    'slug': 'acme',
    'domain_mode': 'path',
    'brand_color': '#16A34A',
    'logo_text': 'A',
    'description': "Real-time status of Acme's services.",
    'subscriptions_enabled': true,
    'monitors': <Map<String, dynamic>>[
      <String, dynamic>{'id': 'marketing'},
      <String, dynamic>{'id': 'api'},
      <String, dynamic>{'id': 'checkout'},
      <String, dynamic>{'id': 'docs'},
    ],
    'metric_keys': <String>[
      'api.response_time',
      'api.req_rate',
      'marketing.dom_load',
    ],
  }),
  StatusPage.fromMap(<String, dynamic>{
    'id': 'internal',
    'name': 'Acme Internal Ops',
    'slug': 'internal-ops',
    'domain_mode': 'subdomain',
    'brand_color': '#6366F1',
    'logo_text': 'I',
    'description': 'Internal platform health for the engineering org.',
    'subscriptions_enabled': false,
    'monitors': <Map<String, dynamic>>[
      <String, dynamic>{'id': 'api'},
      <String, dynamic>{'id': 'checkout'},
    ],
    'metric_keys': <String>[
      'api.response_time',
      'api.cpu_load',
      'checkout.queue_depth',
    ],
  }),
];

/// Per-page subscriber fixtures, keyed by [StatusPage.id].
///
/// The internal ops page has no subscribers. Mirrors the `SUBSCRIBERS` record
/// in the React status mock.
const Map<String, List<Subscriber>> _subscribers = {
  'acme': [
    Subscriber(email: 'devops@northwind.io', subscribedAt: '3 days ago'),
    Subscriber(email: 'sre-team@globex.com', subscribedAt: '1 week ago'),
    Subscriber(email: 'alerts@initech.dev', subscribedAt: '2 weeks ago'),
    Subscriber(email: 'ops@hooli.com', subscribedAt: '3 weeks ago'),
    Subscriber(email: 'platform@umbrella.co', subscribedAt: 'last month'),
  ],
  'internal': [],
};

/// Deterministic 90-day uptime history per monitor id.
///
/// Mirrors the `UPTIME_HISTORY` record in the React status mock: `api` shows a
/// short degraded window, `checkout` a two-day outage, `docs` a single down
/// day, and `marketing` a clean bar.
final Map<String, List<UptimeSegment>> _uptimeHistory = {
  'marketing': uptime90(),
  'api': uptime90(degraded: [85, 86, 87]),
  'checkout': uptime90(down: [88, 89]),
  'docs': uptime90(down: [20]),
};

// ---------------------------------------------------------------------------
// Fixture-data accessors
// ---------------------------------------------------------------------------

/// Find a status page among the design-lab fixtures by [id]. Returns `null`
/// when none matches.
///
/// Test-facing after the ORM migration: production reads flow through
/// `StatusPageController.reload` (`StatusPage.all()`), not this fixture lookup.
StatusPage? findStatusPage(String? id) {
  if (id == null) return null;
  for (final StatusPage page in statusPages) {
    if (page.id == id) return page;
  }
  return null;
}

/// Subscribers for the status page with [id]; empty when unknown.
///
/// Mirrors `subscribersFor` in the React status mock.
List<Subscriber> subscribersFor(String? id) {
  if (id == null) return const [];
  return _subscribers[id] ?? const [];
}

/// Resolve a page's assigned monitor ids to public components.
///
/// Unknown ids are dropped. Each component's [PublicComponent.segments] comes
/// from the per-monitor [_uptimeHistory] table, falling back to a clean 90-day
/// bar. Mirrors `componentsFor` in the React status mock. Reads the [StatusPage]
/// model; monitor resolution runs through the [Monitor]-typed [findMonitor].
List<PublicComponent> componentsFor(StatusPage c) {
  final List<PublicComponent> result = [];
  for (final String id in c.monitorIds) {
    final Monitor? m = findMonitor(id);
    if (m == null) continue;
    result.add(
      PublicComponent(
        name: m.name ?? '',
        status: m.status,
        uptime: '${m.uptime} uptime',
        segments: _uptimeHistory[id] ?? uptime90(),
      ),
    );
  }
  return result;
}

/// Resolve a page's assigned metric ids to the metrics of its monitors.
///
/// Only metrics belonging to currently-assigned monitors resolve, so
/// unassigning a monitor quietly drops its published metrics. Mirrors
/// `metricsFor` in the React status mock. Reads the [StatusPage] ORM model.
List<MonitorMetric> metricsFor(StatusPage c) {
  final List<MonitorMetric> available = metricsForMonitors(c.monitorIds);
  final List<MonitorMetric> result = [];
  for (final String id in c.metricKeys) {
    for (final MonitorMetric m in available) {
      if ('${m.monitorId}.${m.key}' == id) {
        result.add(m);
        break;
      }
    }
  }
  return result;
}

/// System metric options for the given monitor [ids], as label/value pairs.
///
/// The value is the composite `monitorId.key` metric id. Feeds the editor's
/// System metric picker.
List<MetricOption> systemMetricOptions(List<String> ids) {
  return [
    for (final MonitorMetric m in systemMetricsForMonitors(ids))
      MetricOption(label: m.label, value: '${m.monitorId}.${m.key}'),
  ];
}

/// Custom metric options for the given monitor [ids], as label/value pairs.
///
/// The value is the composite `monitorId.key` metric id. Feeds the editor's
/// Custom metric picker.
List<MetricOption> customMetricOptions(List<String> ids) {
  return [
    for (final MonitorMetric m in customMetricsForMonitors(ids))
      MetricOption(label: m.label, value: '${m.monitorId}.${m.key}'),
  ];
}
