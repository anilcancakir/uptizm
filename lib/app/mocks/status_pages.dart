import 'package:flutter/widgets.dart' show Color, immutable;

import 'metrics.dart';
import 'monitors.dart' show UptimeSegment, monitors, uptime90, findMonitor;
import 'status.dart';
import '../models/monitor.dart';
import '../models/status_page.dart';
import '../../resources/views/monitors/monitor_metrics_support.dart' show MetricOption;

// ---------------------------------------------------------------------------
// Domain types
// ---------------------------------------------------------------------------

/// How a status page is served to the public.
///
/// - [subdomain]: `slug.uptizm.com`.
/// - [path]: `uptizm.com/status/slug`.
///
/// Mirrors the `DomainMode` union in the React status mock.
enum DomainMode {
  /// Served on a dedicated subdomain, e.g. `acme.uptizm.com`.
  subdomain,

  /// Served under a shared path, e.g. `uptizm.com/status/acme`.
  path;

  /// Human-readable label shown in the editor's domain-mode control.
  String get label => switch (this) {
    DomainMode.subdomain => 'Subdomain',
    DomainMode.path => 'Path',
  };
}

/// A monitor resolved to a public component (name + current health + history).
///
/// Reuses [StatusKey] from `status.dart` and the existing [UptimeSegment] from
/// `monitors.dart` so [segments] unifies with the monitors-layer type consumed
/// by later status components. Mirrors the `PublicComponent` interface in the
/// React status mock.
@immutable
class PublicComponent {
  /// Public display name of the component.
  final String name;

  /// Current health status.
  final StatusKey status;

  /// Human-formatted trailing uptime string, e.g. `"99.94% uptime"`.
  final String uptime;

  /// 90-day uptime history bar segments.
  final List<UptimeSegment> segments;

  const PublicComponent({
    required this.name,
    required this.status,
    required this.uptime,
    required this.segments,
  });
}

/// A subscriber to a status page's email updates.
///
/// Mirrors the `Subscriber` interface in the React status mock.
@immutable
class Subscriber {
  /// Subscriber email address.
  final String email;

  /// Relative time string of when they subscribed, e.g. `"3 days ago"`.
  final String subscribedAt;

  const Subscriber({required this.email, required this.subscribedAt});
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/// Design-lab status-page fixtures, projected onto the [StatusPage] ORM model.
///
/// Two pages: a customer-facing page assigning all monitors, and an internal
/// ops page assigning a subset. Ids match the [monitors] fixture. The
/// predecessor `StatusPageConfig` value object was deleted once the status
/// views, controller, and editor migrated to [StatusPage]; these fixtures are
/// hydrated through [StatusPage.fromMap] from `StatusPageResource`-shaped maps
/// (`domain_mode` as the in-app [DomainMode] name, `brand_color` as `#rrggbb`,
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
// Helpers
// ---------------------------------------------------------------------------

/// Public URL a status page is served at, by domain mode.
///
/// Falls back to `your-page` when the slug is empty or absent (live-preview
/// state in the editor). Mirrors `pageUrl` in the React status mock. Reads the
/// [StatusPage] ORM model (the `slug` accessor is nullable).
///
/// ```dart
/// pageUrl(page); // "uptizm.com/status/acme"
/// ```
String pageUrl(StatusPage c) {
  final String? raw = c.slug;
  final String slug = (raw == null || raw.isEmpty) ? 'your-page' : raw;
  return switch (c.domainMode) {
    DomainMode.subdomain => '$slug.uptizm.com',
    DomainMode.path => 'uptizm.com/status/$slug',
  };
}

/// Clones [page] into a fresh [StatusPage], replacing only the fields named in
/// the overrides.
///
/// The editable status-page draft (editor, preview variants, and the fixture
/// tests) needs a copy-with-overrides that no longer flows through the deleted
/// `StatusPageConfig.copyWith`. This rehydrates a new model from the source's
/// raw attributes, then patches the wire keys for any provided override so the
/// clone reads them back through the model's reverse-cast accessors.
StatusPage cloneStatusPage(
  StatusPage page, {
  String? name,
  String? slug,
  DomainMode? domainMode,
  Color? brandColor,
  List<String>? monitorIds,
  List<String>? metricKeys,
}) {
  final Map<String, dynamic> map = Map<String, dynamic>.from(page.attributes);
  if (name != null) map['name'] = name;
  if (slug != null) map['slug'] = slug;
  if (domainMode != null) map['domain_mode'] = domainMode.name;
  if (brandColor != null) {
    map['brand_color'] =
        '#${brandColor.toARGB32().toRadixString(16).substring(2)}';
  }
  if (monitorIds != null) {
    map['monitors'] = <Map<String, dynamic>>[
      for (final String id in monitorIds) <String, dynamic>{'id': id},
    ];
  }
  if (metricKeys != null) map['metric_keys'] = metricKeys;
  return StatusPage.fromMap(map);
}

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

/// Worst component status, for the overall banner tone.
///
/// Ranks the statuses `down` (4) > `degraded` (3) > `info` (2) > `paused` (1)
/// > `up`/`ai` (0) and returns the highest-ranked status among [components],
/// defaulting to [StatusKey.up] for an empty list. Mirrors `worstStatus` in
/// the React status mock.
StatusKey worstStatus(List<PublicComponent> components) {
  int rank(StatusKey s) => switch (s) {
    StatusKey.down => 4,
    StatusKey.degraded => 3,
    StatusKey.info => 2,
    StatusKey.paused => 1,
    StatusKey.up => 0,
    StatusKey.ai => 0,
  };

  StatusKey worst = StatusKey.up;
  for (final PublicComponent c in components) {
    if (rank(c.status) > rank(worst)) worst = c.status;
  }
  return worst;
}
