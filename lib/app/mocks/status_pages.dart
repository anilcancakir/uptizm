import 'package:flutter/widgets.dart' show Color, immutable;

import 'metrics.dart';
import 'monitors.dart' show MonitorSummary, UptimeSegment, monitors, uptime90, findMonitor;
import 'status.dart';
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

/// Configuration for a single public status page.
///
/// A status page is a CRUD resource in the panel: the operator assigns
/// monitors (which become public components carrying their linked incidents)
/// and custom metrics, sets branding (color + logo), and picks how it is
/// served. Mirrors the `StatusPageConfig` interface in the React status mock.
///
/// ```dart
/// final page = statusPages.first;
/// print(pageUrl(page)); // "uptizm.com/status/acme"
/// ```
@immutable
class StatusPageConfig {
  /// Stable identifier used for routing, e.g. `'acme'`.
  final String id;

  /// Human-readable page name shown in the header.
  final String name;

  /// URL-safe handle used in the public URL.
  final String slug;

  /// How the page is served (subdomain vs. path).
  final DomainMode domainMode;

  /// Per-page brand color (the header tint and accent).
  ///
  /// This is content data, the direct analogue of the React source's inline
  /// `style={{ background: brandColor }}`, so it lives here as a raw [Color]
  /// (the `Team.color` precedent), NOT a semantic Wind token.
  final Color brandColor;

  /// One-to-two character logo fallback shown when no logo image is set.
  final String logoText;

  /// Short description shown under the page name.
  final String description;

  /// Monitor ids assigned as public components.
  final List<String> monitorIds;

  /// Custom/aggregate metric ids surfaced publicly (`monitorId.key`).
  final List<String> metricKeys;

  /// When true, visitors can subscribe by email and the subscribe box shows.
  final bool subscriptionsEnabled;

  const StatusPageConfig({
    required this.id,
    required this.name,
    required this.slug,
    required this.domainMode,
    required this.brandColor,
    required this.logoText,
    required this.description,
    required this.monitorIds,
    required this.metricKeys,
    required this.subscriptionsEnabled,
  });

  /// Returns a copy of this config with the given fields replaced.
  StatusPageConfig copyWith({
    String? id,
    String? name,
    String? slug,
    DomainMode? domainMode,
    Color? brandColor,
    String? logoText,
    String? description,
    List<String>? monitorIds,
    List<String>? metricKeys,
    bool? subscriptionsEnabled,
  }) {
    return StatusPageConfig(
      id: id ?? this.id,
      name: name ?? this.name,
      slug: slug ?? this.slug,
      domainMode: domainMode ?? this.domainMode,
      brandColor: brandColor ?? this.brandColor,
      logoText: logoText ?? this.logoText,
      description: description ?? this.description,
      monitorIds: monitorIds ?? this.monitorIds,
      metricKeys: metricKeys ?? this.metricKeys,
      subscriptionsEnabled: subscriptionsEnabled ?? this.subscriptionsEnabled,
    );
  }
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

/// Design-lab status-page fixtures. Deterministic; no network.
///
/// Two pages: a customer-facing page assigning all monitors, and an internal
/// ops page assigning a subset. Ids match the [monitors] fixture.
const List<StatusPageConfig> statusPages = [
  StatusPageConfig(
    id: 'acme',
    name: 'Acme Status',
    slug: 'acme',
    domainMode: DomainMode.path,
    brandColor: Color(0xFF16A34A),
    logoText: 'A',
    description: "Real-time status of Acme's services.",
    monitorIds: ['marketing', 'api', 'checkout', 'docs'],
    metricKeys: ['api.response_time', 'api.req_rate', 'marketing.dom_load'],
    subscriptionsEnabled: true,
  ),
  StatusPageConfig(
    id: 'internal',
    name: 'Acme Internal Ops',
    slug: 'internal-ops',
    domainMode: DomainMode.subdomain,
    brandColor: Color(0xFF6366F1),
    logoText: 'I',
    description: 'Internal platform health for the engineering org.',
    monitorIds: ['api', 'checkout'],
    metricKeys: ['api.response_time', 'api.cpu_load', 'checkout.queue_depth'],
    subscriptionsEnabled: false,
  ),
];

/// Per-page subscriber fixtures, keyed by [StatusPageConfig.id].
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
/// state in the editor). Mirrors `pageUrl` in the React status mock. Retyped to
/// the [StatusPage] ORM model in Wave 2 (the `slug` accessor is now nullable).
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

/// Find a status page by [id] among the design-lab fixtures, hydrated into a
/// [StatusPage] model. Returns `null` when none matches.
///
/// Test-facing after Wave 2: production reads flow through
/// `StatusPageController.reload` (`StatusPage.all()`), not this fixture lookup.
StatusPage? findStatusPage(String? id) {
  if (id == null) return null;
  for (final StatusPageConfig p in statusPages) {
    if (p.id == id) return statusPageFromConfig(p);
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
/// bar. Mirrors `componentsFor` in the React status mock. The parameter is the
/// [StatusPage] model (its callers hold `StatusPage` after Wave 2); the monitor
/// resolution below stays on [MonitorSummary] until Step 9 retypes it to
/// `Monitor`.
List<PublicComponent> componentsFor(StatusPage c) {
  final List<PublicComponent> result = [];
  for (final String id in c.monitorIds) {
    final MonitorSummary? m = findMonitor(id);
    if (m == null) continue;
    result.add(
      PublicComponent(
        name: m.name,
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
/// `metricsFor` in the React status mock. Retyped to the [StatusPage] ORM
/// model in Wave 2.
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

// ---------------------------------------------------------------------------
// StatusPageConfig <-> StatusPage boundary converters (Wave 2 transitional).
//
// The status views migrated their reads to the [StatusPage] ORM model, but the
// EDITOR keeps a mutable [StatusPageConfig] draft (its per-keystroke `copyWith`
// machinery and the `generateWithAi` draft both trade in the value object).
// These converters bridge the two at the view boundary: the editor seeds its
// draft from a fetched model and renders the live preview through a model.
// Both die with `StatusPageConfig` in Wave 5.
// ---------------------------------------------------------------------------

/// Hydrates a [StatusPage] model from a [StatusPageConfig] value object.
///
/// Stores the fields in their wire shape so the model's reverse-cast accessors
/// ([StatusPage.domainMode]/[StatusPage.brandColor]/[StatusPage.monitorIds])
/// read them back: `domain_mode` as the [DomainMode] name (the in-app
/// representation, distinct from the backend `path`/`custom` write payload),
/// `brand_color` as `#rrggbb`, and the monitor pivot as `{id}` rows.
StatusPage statusPageFromConfig(StatusPageConfig c) {
  final String hex = c.brandColor.toARGB32().toRadixString(16).substring(2);
  return StatusPage.fromMap(<String, dynamic>{
    'id': c.id,
    'name': c.name,
    'slug': c.slug,
    'domain_mode': c.domainMode.name,
    'brand_color': '#$hex',
    'logo_text': c.logoText,
    'description': c.description,
    'subscriptions_enabled': c.subscriptionsEnabled,
    'monitors': [
      for (final String id in c.monitorIds) <String, dynamic>{'id': id},
    ],
    'metric_keys': c.metricKeys,
  });
}

/// Projects a [StatusPage] model back into a [StatusPageConfig] draft.
///
/// Fills the value object's non-null String fields from the model's nullable
/// accessors so the editor can seed its `copyWith`-driven draft from a fetched
/// page.
StatusPageConfig statusPageConfigFrom(StatusPage page) {
  return StatusPageConfig(
    id: page.id,
    name: page.name ?? '',
    slug: page.slug ?? '',
    domainMode: page.domainMode,
    brandColor: page.brandColor,
    logoText: page.logoText ?? '',
    description: page.description ?? '',
    monitorIds: page.monitorIds,
    metricKeys: page.metricKeys,
    subscriptionsEnabled: page.subscriptionsEnabled,
  );
}
