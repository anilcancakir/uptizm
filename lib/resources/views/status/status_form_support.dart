import 'package:flutter/widgets.dart' show Color;

import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/enums/domain_mode.dart' show DomainMode;
import 'package:uptizm/app/mocks/status_pages.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/models/status_page.dart';
import 'package:uptizm/resources/views/monitors/monitor_metrics_support.dart'
    show MetricOption;
import 'package:uptizm/ui/components/region_picker/region_picker.dart';

// ---------------------------------------------------------------------------
// Brand palette.
//
// Preset brand colors offered in the status-page editor's color picker. These
// are content data (the per-page brand tint the operator chooses), the direct
// analogue of the React source's `BRAND_COLORS` hex array, so they live here
// as raw [Color]s (the `Team.color` / `StatusPageConfig.brandColor`
// precedent), NOT semantic Wind tokens.
// ---------------------------------------------------------------------------

/// The eight preset brand-color swatches offered in the editor.
///
/// Mirrors `BRAND_COLORS` in the React `StatusPageEditor` source.
const List<Color> kBrandColors = [
  Color(0xFF16A34A),
  Color(0xFF2563EB),
  Color(0xFF6366F1),
  Color(0xFF7C3AED),
  Color(0xFFDB2777),
  Color(0xFFE11D48),
  Color(0xFFEA580C),
  Color(0xFF0D9488),
];

// ---------------------------------------------------------------------------
// Region option lists (label / value pairs for the editor's RegionPicker
// components: assigned monitors and the System/Custom metric pickers).
// ---------------------------------------------------------------------------

/// Maps the [monitors] fixture to [Region] instances for the assigned-monitors
/// picker. Mirrors `MONITOR_OPTIONS` in the React `StatusPageEditor` source.
///
/// ```dart
/// RegionPicker(regions: monitorRegions(), value: monitorIds, onChanged: ...);
/// ```
List<Region> monitorRegions() {
  return [
    for (final Monitor m in monitors) Region(label: m.name ?? '', value: m.id),
  ];
}

/// System metric options for the given monitor [ids] as [Region] instances.
///
/// The value is the composite `monitorId.key` metric id. Feeds the editor's
/// System metric picker. Mirrors `systemOptions` in `StatusPageEditor`.
List<Region> systemMetricRegions(List<String> ids) {
  return [
    for (final MetricOption o in systemMetricOptions(ids))
      Region(label: o.label, value: o.value),
  ];
}

/// Custom metric options for the given monitor [ids] as [Region] instances.
///
/// The value is the composite `monitorId.key` metric id. Feeds the editor's
/// Custom metric picker. Mirrors `customOptions` in `StatusPageEditor`.
List<Region> customMetricRegions(List<String> ids) {
  return [
    for (final MetricOption o in customMetricOptions(ids))
      Region(label: o.label, value: o.value),
  ];
}

// ---------------------------------------------------------------------------
// Pure helpers.
// ---------------------------------------------------------------------------

/// The "Draft with AI" mock: compose a starter [StatusPage] draft from the
/// given [monitorIds].
///
/// Groups every provided monitor into public components and writes a starter
/// name, slug, and description, plus a preset metric selection filtered to the
/// metrics that actually resolve for the chosen monitors. Mirrors
/// `generateWithAi` in the React `StatusPageEditor` source: the description is
/// composed from the monitors themselves, nothing external. The draft is a
/// fresh (unsaved) [StatusPage] the editor seeds its fields from.
///
/// ```dart
/// final draft = aiDraftFor(monitors.map((m) => m.id).toList());
/// ```
StatusPage aiDraftFor(List<String> monitorIds) {
  // 1. Preset metric ids the React source seeds, kept only when they resolve
  //    for the selected monitors (an unassigned monitor drops its metrics).
  const List<String> presetMetricKeys = [
    'api.response_time',
    'api.req_rate',
    'marketing.dom_load',
  ];
  final Set<String> available = {
    for (final Region r in systemMetricRegions(monitorIds)) r.value,
    for (final Region r in customMetricRegions(monitorIds)) r.value,
  };
  final List<String> metricKeys = [
    for (final String key in presetMetricKeys)
      if (available.contains(key)) key,
  ];

  // 2. Assemble the draft model. The brand color defaults to the first preset
  //    swatch, matching the editor's initial state.
  final Color brand = kBrandColors.first;
  return StatusPage.fromMap(<String, dynamic>{
    'id': 'draft',
    'name': 'Acme Status',
    'slug': 'acme',
    'domain_mode': DomainMode.path.name,
    'brand_color': '#${brand.toARGB32().toRadixString(16).substring(2)}',
    'logo_text': '',
    'description':
        'Real-time status of our services. Subscribe to get notified about '
        'incidents and maintenance.',
    'subscriptions_enabled': true,
    'monitors': <Map<String, dynamic>>[
      for (final String id in monitorIds) <String, dynamic>{'id': id},
    ],
    'metric_keys': metricKeys,
  });
}

/// Whether the draft satisfies the editor's Save-enabled rule.
///
/// Requires a non-empty [StatusPage.name], [StatusPage.slug], and at least one
/// assigned monitor. Mirrors the `canSave` guard in the React `StatusPageEditor`
/// source.
bool isConfigValid(StatusPage c) {
  return (c.name ?? '').trim().isNotEmpty &&
      (c.slug ?? '').trim().isNotEmpty &&
      c.monitorIds.isNotEmpty;
}
