import 'package:flutter/widgets.dart' show Color;

import '../enums/domain_mode.dart' show DomainMode;
import '../enums/status_key.dart' show StatusKey;
import '../models/status_page.dart';
import 'status_page_types.dart' show PublicComponent;

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
