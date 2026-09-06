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
/// Pass [components] instead of [monitorIds] to control each component's public
/// label and live health as well as its id; the pivot rows are written verbatim,
/// matching `StatusPageResource`'s shape. [monitorIds] writes id-only rows, which
/// read back as Pending components (no `last_status` means no measurement).
StatusPage cloneStatusPage(
  StatusPage page, {
  String? name,
  String? slug,
  DomainMode? domainMode,
  Color? brandColor,
  List<String>? monitorIds,
  List<Map<String, dynamic>>? components,
}) {
  final Map<String, dynamic> map = Map<String, dynamic>.from(page.attributes);
  if (name != null) map['name'] = name;
  if (slug != null) map['slug'] = slug;
  if (domainMode != null) map['domain_mode'] = domainMode.name;
  if (brandColor != null) {
    map['brand_color'] =
        '#${brandColor.toARGB32().toRadixString(16).substring(2)}';
  }
  if (components != null) {
    map['monitors'] = components;
  } else if (monitorIds != null) {
    map['monitors'] = <Map<String, dynamic>>[
      for (final String id in monitorIds) <String, dynamic>{'id': id},
    ];
  }
  return StatusPage.fromMap(map);
}

/// Worst component status, for the overall banner tone, or `null` when there is
/// nothing to report.
///
/// Ranks `down` (4) > `degraded` (3) > `info` (2) > `up`/`ai` (0) and returns
/// the highest-ranked status among [components].
///
/// `paused` and `pending` carry NO rank and are skipped, because neither is a
/// reading: pausing is a switch the operator threw, and pending means nothing
/// has been probed yet. Ranking them let one paused monitor flip a healthy
/// page off "Operational".
///
/// An empty list answers `null`, NOT [StatusKey.up]. A page with no components
/// has made no measurement, so claiming "Operational" would be an unearned
/// all-clear: while the component list was resolved through a design-lab fixture
/// it was always empty, and this default is what made the status-page list and
/// preview read "Operational" for a page whose monitors were down. Callers must
/// render the absence (no badge, or an explicit "nothing published yet") rather
/// than substituting a healthy tone.
StatusKey? worstStatus(List<PublicComponent> components) {
  if (components.isEmpty) {
    return null;
  }

  // `paused` and `pending` carry NO rank, because neither is a reading. A
  // paused monitor is a switch the operator threw and a pending one has not
  // been probed yet, so ranking either above `up` let one paused component flip
  // a healthy page's badge off "Operational", and attaching a freshly created
  // monitor made the whole page read "Pending" until its first probe landed.
  // Both are claims about health derived from a value that is not one.
  int? rank(StatusKey s) => switch (s) {
    StatusKey.down => 4,
    StatusKey.degraded => 3,
    StatusKey.info => 2,
    StatusKey.up => 0,
    StatusKey.ai => 0,
    StatusKey.paused => null,
    StatusKey.pending => null,
  };

  StatusKey? worst;
  int worstRank = -1;
  for (final PublicComponent c in components) {
    final int? r = rank(c.status);
    if (r == null) continue;
    if (r > worstRank) {
      worstRank = r;
      worst = c.status;
    }
  }

  // `ai` and `up` share rank 0 and both mean operational, so a page holding
  // only those reports `up`. Preserved from the previous implementation, which
  // got it by seeding `worst` with `up`; the seed is gone now that a page can
  // legitimately have no rankable component at all.
  if (worstRank == 0) return StatusKey.up;

  // Null when nothing rankable remains, which is the same answer the empty-list
  // branch above gives: a page whose every component is paused or unprobed has
  // no health to report, and saying "Operational" would be inventing one.
  return worst;
}
