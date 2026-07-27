import 'package:flutter/widgets.dart' show Color;

import '../enums/domain_mode.dart' show DomainMode;
import '../enums/status_key.dart' show StatusKey;
import '../models/status_page.dart';
import 'status_page_types.dart' show PublicComponent;

/// The URL a status page is served at.
///
/// Prefers [StatusPage.publicUrl], the address the backend resolved from its
/// own public route, so what the operator reads is what their customers can
/// open. This used to be composed here as `uptizm.com/status/<slug>`, which no
/// route answers: the real page is served at `/s/<slug>`, so every URL the
/// editor showed was a 404 waiting to be pasted into a customer email.
///
/// The composed form survives only as the UNSAVED-DRAFT preview, where there is
/// no backend answer yet: the editor shows a shape of the address while the
/// operator is still typing the slug. It is deliberately host-less so it cannot
/// be mistaken for a working link.
///
/// ```dart
/// pageUrl(saved);   // "http://localhost:8000/s/acme"
/// pageUrl(draft);   // "/s/your-page"
/// ```
String pageUrl(StatusPage c) {
  final String? resolved = c.publicUrl;
  if (resolved != null && resolved.isNotEmpty) return resolved;

  final String? raw = c.slug;
  final String slug = (raw == null || raw.isEmpty) ? 'your-page' : raw;

  return '/s/$slug';
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
    StatusKey.pending => 1,
    StatusKey.up => 0,
    StatusKey.ai => 0,
  };

  StatusKey worst = StatusKey.up;
  for (final PublicComponent c in components) {
    if (rank(c.status) > rank(worst)) worst = c.status;
  }
  return worst;
}
