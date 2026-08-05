import 'dart:convert';

import 'package:flutter/painting.dart' show Color;
import 'package:magic/magic.dart';

import '../enums/domain_mode.dart' show DomainMode;
import '../enums/status_key.dart' show StatusKey, statusKeyFromWire;
import '../enums/status_page_preview_status.dart'
    show StatusPagePreviewStatus, statusPagePreviewStatusFromWire;
import '../support/status_page_types.dart' show PublicComponent;

/// **A public status page.**
///
/// A magic Eloquent model backing the `status-pages` JSON resource. It
/// supersedes the `StatusPageConfig` fixture DTO, reproducing its full
/// accessor surface incl. the typed [domainMode] (enum) and [brandColor]
/// ([Color]) reverse casts the views read.
///
/// The monitor assignment is a sub-resource pivot (`status-pages/{id}/monitors`),
/// so [monitorIds] is read from the eager-loaded `monitors` array, NOT
/// mass-assigned; [attachMonitor]/[detachMonitor]/[reorderMonitors] stay on
/// the controller.
///
/// ```dart
/// final page = await StatusPage.find(slug);
/// print(page.name);
/// print(page.monitorIds);
/// ```
class StatusPage extends Model with HasTimestamps, InteractsWithPersistence {
  /// Creates an empty [StatusPage] (hydrate via [fromMap] or persistence).
  StatusPage();

  /// The table associated with the model.
  @override
  String get table => 'status_pages';

  /// The API resource for remote operations.
  @override
  String get resource => 'status-pages';

  /// UUID primary key; never auto-incrementing.
  @override
  bool get incrementing => false;

  /// Mass-assignable attributes (the writable columns; the monitor pivot is a
  /// sub-resource, not mass-assigned).
  ///
  /// `preview_image_url` / `preview_rendered_at` / `preview_render_status` are
  /// deliberately ABSENT: they are server-written and read-only, and a magic
  /// model strips any key not listed here from an outgoing write before it is
  /// sent, with no error anywhere. Adding a read-only field here would not
  /// make it writable, it would just silently keep working by accident until
  /// the day it stops; the two earlier production-severity defects this
  /// codebase already had from that exact silent strip are why the omission
  /// is deliberate, not an oversight.
  @override
  List<String> get fillable => [
    'name',
    'slug',
    'domain_mode',
    'custom_domain',
    'brand_color',
    'logo_path',
    'logo_text',
    'description',
    'is_public',
    'subscriptions_enabled',
  ];

  /// Attribute casts. Booleans + timestamps; `domain_mode` and `brand_color`
  /// are reverse-cast by their typed accessors (not via the casts map).
  @override
  Map<String, dynamic> get casts => {
    'is_public': 'bool',
    'subscriptions_enabled': 'bool',
    'created_at': 'datetime',
    'updated_at': 'datetime',
    'preview_rendered_at': 'datetime',
  };

  // ---------------------------------------------------------------------------
  // Typed Accessors
  // ---------------------------------------------------------------------------

  /// The page id.
  @override
  String get id => getAttribute('id')?.toString() ?? '';

  /// Human-readable page name.
  String? get name => getAttribute('name') as String?;

  /// Set the page name.
  set name(String? value) => setAttribute('name', value);

  /// URL-safe handle used in the public URL.
  String? get slug => getAttribute('slug') as String?;

  /// Set the slug.
  set slug(String? value) => setAttribute('slug', value);

  /// Optional tenant-owned hostname, used when [domainMode] is
  /// [DomainMode.custom].
  String? get customDomain => getAttribute('custom_domain') as String?;

  /// Set the custom domain.
  set customDomain(String? value) => setAttribute('custom_domain', value);

  /// Logo image path, if a logo is uploaded.
  String? get logoPath => getAttribute('logo_path') as String?;

  /// Set the logo path.
  set logoPath(String? value) => setAttribute('logo_path', value);

  /// One-to-two character logo fallback text.
  String? get logoText => getAttribute('logo_text') as String?;

  /// Set the logo fallback text.
  set logoText(String? value) => setAttribute('logo_text', value);

  /// Short description shown under the page name.
  String? get description => getAttribute('description') as String?;

  /// Set the description.
  set description(String? value) => setAttribute('description', value);

  /// The URL this page is actually served at, as resolved by the backend from
  /// its own public route, or null for a draft the backend has not seen yet.
  ///
  /// Read-only: the public URL is the backend's fact, not an editable field.
  /// The client used to compose it from the slug and a hardcoded host, which
  /// produced an address no route answered.
  String? get publicUrl => getAttribute('public_url') as String?;

  /// Whether the page is publicly visible.
  bool get isPublic => (getAttribute('is_public') as bool?) ?? false;

  /// Set public visibility.
  set isPublic(bool value) => setAttribute('is_public', value);

  /// Whether email subscriptions are enabled for this page.
  bool get subscriptionsEnabled =>
      (getAttribute('subscriptions_enabled') as bool?) ?? false;

  /// Set subscriptions enabled.
  set subscriptionsEnabled(bool value) =>
      setAttribute('subscriptions_enabled', value);

  /// How the page is served, parsed from the wire `domain_mode` string into
  /// the [DomainMode] enum.
  ///
  /// Falls back to [DomainMode.path], which is the backend column's own default
  /// and the only mode that always resolves. The fallback used to be
  /// [DomainMode.subdomain], so a page the backend stored as path-addressed was
  /// displayed as subdomain-addressed whenever the wire value was absent.
  DomainMode get domainMode {
    final String? raw = getAttribute('domain_mode') as String?;
    if (raw == null) return DomainMode.path;
    for (final DomainMode mode in DomainMode.values) {
      if (mode.name == raw) return mode;
    }
    return DomainMode.path;
  }

  /// Set the domain mode (stored as its wire `name`).
  set domainMode(DomainMode value) => setAttribute('domain_mode', value.name);

  /// Per-page brand color, parsed from the wire `brand_color` hex string into
  /// a [Color]. Falls back to opaque black when the wire value is missing or
  /// malformed. The inverse of the controller's write-side `_wireBrandColor`.
  Color get brandColor {
    final String? hex = getAttribute('brand_color') as String?;
    if (hex == null || hex.isEmpty) return const Color(0xFF000000);
    final String digits = hex.startsWith('#') ? hex.substring(1) : hex;
    final int? value = int.tryParse(digits, radix: 16);
    if (value == null) return const Color(0xFF000000);
    // A 6-digit RGB hex needs the alpha prefix; an 8-digit ARGB hex does not.
    return Color(digits.length == 6 ? (value | 0xFF000000) : value);
  }

  /// Set the brand color (stored as a `#rrggbb` hex string).
  set brandColor(Color value) {
    final String hex = value.toARGB32().toRadixString(16).substring(2);
    setAttribute('brand_color', '#$hex');
  }

  /// Monitor ids assigned as public components, read from the eager-loaded
  /// `monitors` pivot array. Empty when the wire omits the pivot.
  List<String> get monitorIds {
    final Object? raw = getAttribute('monitors');
    if (raw is! List) return const [];
    return raw
        .whereType<Map<String, dynamic>>()
        .map((m) => m['id']?.toString())
        .whereType<String>()
        .toList();
  }

  /// The page's published components, in display order, read from the
  /// eager-loaded `monitors` pivot array.
  ///
  /// Each entry carries the component's public label and the monitor's live
  /// health, so a caller can render the page's real composition and overall
  /// status. Empty when the wire omits the pivot.
  ///
  /// This replaces resolving [monitorIds] through a fixture monitor list, which
  /// could never match a real (uuid-keyed) monitor and so reported every page as
  /// having zero components.
  ///
  /// Mirrors the public page exactly, which means honouring BOTH gates on public
  /// visibility: a monitor has to be attached AND carry `show_on_status_page`
  /// (see `StatusPageAssembler`, which filters on it). Ignoring the second gate
  /// would let the in-app preview promise a component the real page hides.
  List<PublicComponent> get components {
    final Object? raw = getAttribute('monitors');
    if (raw is! List) return const [];

    final List<Map<String, dynamic>> rows = raw
        .whereType<Map<String, dynamic>>()
        // A row that predates the flag (or omits it) is treated as public, so an
        // older payload keeps rendering rather than silently emptying the page.
        .where((row) => row['show_on_status_page'] != false)
        .toList()
      ..sort((a, b) {
        final int left = (a['display_order'] as num?)?.toInt() ?? 0;
        final int right = (b['display_order'] as num?)?.toInt() ?? 0;
        return left.compareTo(right);
      });

    return [
      for (final Map<String, dynamic> row in rows)
        PublicComponent(
          // `custom_label` is the operator's public override; the monitor's own
          // name is the fallback, matching what the public page renders.
          name:
              (row['custom_label'] as String?)?.trim().isNotEmpty == true
              ? row['custom_label'] as String
              : (row['name'] as String? ?? ''),
          // A monitor with no check yet reads as Pending, never as up: the
          // absence of a measurement is not evidence of health.
          status: statusKeyFromWire(
            row['last_status'] as String?,
            fallback: StatusKey.pending,
          ),
          // Trailing uptime and the 90-day history are not carried on the pivot.
          // They are left empty rather than filled with a plausible number: an
          // invented uptime string is exactly the kind of claim the product's
          // honesty rules forbid.
          uptime: '',
          segments: const [],
        ),
    ];
  }

  // ---------------------------------------------------------------------------
  // Headless PNG preview render (read-only; see [fillable])
  // ---------------------------------------------------------------------------

  /// Signed absolute URL of the most recent headless-rendered PNG of this
  /// page, or `null` before the first render.
  ///
  /// Emitted by `StatusPageResource` from `show` only, never from the list
  /// envelope, so a wire payload from `GET /status-pages` omits the key
  /// entirely; that absence and an explicit `null` both read as "not
  /// available here", not "never rendered" (only [previewRenderStatus] means
  /// the latter). The URL is stable across a poll and changes only when the
  /// image itself changes, so it must never be normalized or have its query
  /// stripped: that query is what keys Flutter's `ImageCache`.
  String? get previewImageUrl => getAttribute('preview_image_url') as String?;

  /// When the current [previewImageUrl] (or the most recent render attempt)
  /// completed, or `null` before the first render.
  Carbon? get previewRenderedAt =>
      getAttribute('preview_rendered_at') as Carbon?;

  /// Lifecycle of the most recent preview render, or `null` when this page
  /// has never had one requested.
  ///
  /// There is no `pending` case: `null` on the wire IS "never rendered" (see
  /// [StatusPagePreviewStatus]).
  StatusPagePreviewStatus? get previewRenderStatus =>
      statusPagePreviewStatusFromWire(
        getAttribute('preview_render_status') as String?,
      );

  // ---------------------------------------------------------------------------
  // Static retrieval + hydration
  // ---------------------------------------------------------------------------

  /// Find a page by [id] via `GET /status-pages/{id}`.
  static Future<StatusPage?> find(dynamic id) =>
      InteractsWithPersistence.findById<StatusPage>(id, StatusPage.new);

  /// All pages via `GET /status-pages`.
  static Future<List<StatusPage>> all() =>
      InteractsWithPersistence.allModels<StatusPage>(StatusPage.new);

  /// Hydrate a page from a raw wire map (e.g. a `StatusPageResource` payload),
  /// bypassing mass-assignment protection.
  factory StatusPage.fromMap(Map<String, dynamic> map) {
    return StatusPage()
      ..setRawAttributes(map, sync: true)
      ..exists = map.containsKey('id');
  }

  /// Hydrate a page from a JSON string.
  factory StatusPage.fromJson(String json) =>
      StatusPage.fromMap(jsonDecode(json) as Map<String, dynamic>);
}
