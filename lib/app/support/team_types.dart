import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart' show trans;

import '../enums/invoice_status.dart' show InvoiceStatus;
import '../enums/team_role.dart' show TeamRole;

/// A person with access to the current team.
///
/// Mirrors the `Member` interface in the React teams mock.
@immutable
class TeamMember {
  /// Stable identifier used for the active-on-call and self checks.
  final String id;

  /// Full display name.
  final String name;

  /// Account email shown under the name.
  final String email;

  /// Two-letter avatar initials.
  final String initials;

  /// Access level within the team.
  final TeamRole role;

  /// Relative join date string, e.g. `"3 months ago"`.
  final String joinedAt;

  /// Whether this row is the signed-in user (drives the "Remove" hide rule).
  final bool isSelf;

  const TeamMember({
    required this.id,
    required this.name,
    required this.email,
    required this.initials,
    required this.role,
    required this.joinedAt,
    required this.isSelf,
  });
}

/// A teammate invited but not yet joined.
///
/// Mirrors the `Invite` interface in the React teams mock.
@immutable
class TeamInvitation {
  /// Stable identifier used for the revoke action.
  final String id;

  /// The invited email address.
  final String email;

  /// The role the invite will grant once accepted.
  final TeamRole role;

  /// Relative time string of when the invite was sent, e.g. `"2 days ago"`.
  final String invitedAt;

  const TeamInvitation({
    required this.id,
    required this.email,
    required this.role,
    required this.invitedAt,
  });
}

/// Two-letter uppercase avatar initials for [name].
///
/// Takes the first letter of up to the first two words, falling back to `'?'`
/// when [name] is null or blank. Shared by every on-call value object below so
/// an avatar tile never has to be fed a pre-baked `initials` wire field (the
/// backend does not send one).
String teamInitials(String? name) {
  final String trimmed = name?.trim() ?? '';
  if (trimmed.isEmpty) return '?';

  final List<String> words = trimmed.split(RegExp(r'\s+'));
  final String first = words[0][0];
  final String second = words.length > 1 && words[1].isNotEmpty
      ? words[1][0]
      : '';
  return (first + second).toUpperCase();
}

/// A real team member, decoded from the `GET /teams/{team}/members` payload
/// that `MagicStarterTeamController.members` caches.
///
/// Distinct from the fixture-shaped [TeamMember]: the wire roster carries only
/// `id`/`name`/`email`/`profile_photo_url`/`role`, so there is no `joinedAt` or
/// `isSelf` to fill and [role] stays nullable rather than defaulting to a level
/// the server never claimed.
@immutable
class TeamResponder {
  /// The member's user id (the `user_id` every on-call write posts).
  final String id;

  /// Full display name.
  final String name;

  /// Access level within the team; `null` when the roster omits it or sends a
  /// value this client does not know.
  final TeamRole? role;

  /// Creates a [TeamResponder].
  const TeamResponder({
    required this.id,
    required this.name,
    this.role,
  });

  /// Decodes one entry of the team-members roster.
  ///
  /// The id is stringified because the backend is UUID-optional (an integer
  /// key is just as valid as a UUID), and every on-call route path and
  /// `user_id` body field is a string on the wire.
  factory TeamResponder.fromMemberMap(Map<String, dynamic> map) {
    return TeamResponder(
      id: map['id']?.toString() ?? '',
      name: (map['name'] as String?) ?? '',
      role: _teamRoleFromWire(map['role'] as String?),
    );
  }

  /// Decodes the whole roster `MagicStarterTeamController.members` publishes,
  /// dropping any entry without both an id and a name.
  ///
  /// An unusable entry is skipped rather than rendered as a blank row: a picker
  /// offering a nameless responder invites paging nobody. Shared by every
  /// surface that targets a real person (the on-call rotation and override
  /// pickers, the escalation-rung target picker).
  static List<TeamResponder> listFromMemberMaps(
    List<Map<String, dynamic>> members,
  ) {
    final List<TeamResponder> responders = [];
    for (final Map<String, dynamic> member in members) {
      final TeamResponder responder = TeamResponder.fromMemberMap(member);
      if (responder.id.isEmpty || responder.name.isEmpty) continue;
      responders.add(responder);
    }

    return responders;
  }

  /// Avatar initials for the member's [name].
  String get initials => teamInitials(name);
}

/// Decodes a team-membership `role` wire string into a [TeamRole].
///
/// Returns `null` (rather than defaulting to [TeamRole.member]) for an absent
/// or unrecognized value, so a role badge is omitted instead of asserting an
/// access level the server never sent.
TeamRole? _teamRoleFromWire(String? wire) {
  return switch (wire) {
    'owner' => TeamRole.owner,
    'admin' => TeamRole.admin,
    'member' => TeamRole.member,
    _ => null,
  };
}

/// One responder slot in a schedule's rotation ring, as returned by
/// `GET /on-call/schedules` (`OnCallScheduleResource`'s `rotations` array).
///
/// The backend models a slot as a position plus a shift LENGTH
/// (`shift_hours`), not a wall-clock span: the actual window a responder holds
/// is derived server-side by `RotationResolver` from the schedule anchor, so
/// this object never carries (or invents) a `"Mon 09:00 - Wed 09:00"` label.
@immutable
class OnCallRotationSlot {
  /// The backend rotation row id (the `DELETE`/reorder target).
  final String id;

  /// The responder's user id.
  final String userId;

  /// The responder's display name; `null` when the server could not resolve
  /// the user behind the slot.
  final String? userName;

  /// Ascending order within the ring.
  final int position;

  /// How many hours this responder holds the pager per cycle.
  final int shiftHours;

  /// Creates an [OnCallRotationSlot].
  const OnCallRotationSlot({
    required this.id,
    required this.userId,
    required this.userName,
    required this.position,
    required this.shiftHours,
  });

  /// Decodes one `rotations[]` wire entry.
  factory OnCallRotationSlot.fromMap(Map<String, dynamic> map) {
    return OnCallRotationSlot(
      id: map['id']?.toString() ?? '',
      userId: map['user_id']?.toString() ?? '',
      userName: map['user_name'] as String?,
      position: (map['position'] as num?)?.toInt() ?? 0,
      shiftHours: (map['shift_hours'] as num?)?.toInt() ?? 0,
    );
  }

  /// Avatar initials for the slot's responder.
  String get initials => teamInitials(userName);
}

/// A temporary responder swap layered over the ring, as returned by
/// `GET /on-call/schedules` (`OnCallScheduleResource`'s `overrides` array).
@immutable
class OnCallOverrideWindow {
  /// The backend override row id (the `DELETE` target).
  final String id;

  /// The overriding responder's user id.
  final String userId;

  /// The overriding responder's display name; `null` when the server could
  /// not resolve the user behind the override.
  final String? userName;

  /// When the override starts holding the pager; `null` when the wire omits
  /// or malforms it.
  final DateTime? startsAt;

  /// When the override hands the pager back; `null` when the wire omits or
  /// malforms it.
  final DateTime? endsAt;

  /// Creates an [OnCallOverrideWindow].
  const OnCallOverrideWindow({
    required this.id,
    required this.userId,
    required this.userName,
    required this.startsAt,
    required this.endsAt,
  });

  /// Decodes one `overrides[]` wire entry.
  factory OnCallOverrideWindow.fromMap(Map<String, dynamic> map) {
    return OnCallOverrideWindow(
      id: map['id']?.toString() ?? '',
      userId: map['user_id']?.toString() ?? '',
      userName: map['user_name'] as String?,
      startsAt: DateTime.tryParse((map['starts_at'] as String?) ?? ''),
      endsAt: DateTime.tryParse((map['ends_at'] as String?) ?? ''),
    );
  }

  /// Avatar initials for the overriding responder.
  String get initials => teamInitials(userName);

  /// Whether this window contains [moment] (inclusive on both ends, matching
  /// the backend `RotationResolver`'s `betweenIncluded` comparison).
  ///
  /// Used ONLY to label the hero card ("override until ..." versus "shift"):
  /// WHO holds the pager is resolved server-side by `GET /on-call/current` and
  /// is never recomputed here. A window missing either bound covers nothing.
  bool covers(DateTime moment) {
    final DateTime? start = startsAt;
    final DateTime? end = endsAt;
    if (start == null || end == null) return false;

    return !moment.isBefore(start) && !moment.isAfter(end);
  }
}

/// The responder `GET /on-call/current` resolved for a schedule.
///
/// A `null` [OnCallResponder] (rather than an instance with a blank name) is
/// how "nobody is on call" travels: the endpoint answers `user: null` for an
/// empty ring with no covering override, and that must never be softened into
/// a placeholder person.
@immutable
class OnCallResponder {
  /// The responder's user id.
  final String id;

  /// The responder's display name.
  final String name;

  /// The responder's account email; `null` when the wire omits it.
  final String? email;

  /// Creates an [OnCallResponder].
  const OnCallResponder({
    required this.id,
    required this.name,
    this.email,
  });

  /// Decodes the `data.user` object of a `GET /on-call/current` response.
  factory OnCallResponder.fromMap(Map<String, dynamic> map) {
    return OnCallResponder(
      id: map['id']?.toString() ?? '',
      name: (map['name'] as String?) ?? '',
      email: map['email'] as String?,
    );
  }

  /// Avatar initials for the responder's [name].
  String get initials => teamInitials(name);
}

/// One row in the team's billing history.
///
/// Mirrors the `Invoice` interface in the React billing mock, plus
/// [number]/[status] (not carried by the React source, which formats the
/// description client-side) so the badge and header have typed data to render.
@immutable
class Invoice {
  /// Stable identifier, e.g. `'inv-2026-06'`.
  final String id;

  /// Human-readable invoice number shown in the receipt link's context.
  final String number;

  /// Billing date, e.g. `"Jun 1, 2026"`.
  final String date;

  /// Formatted amount, e.g. `"$348.00"`.
  final String amount;

  /// Settlement state.
  final InvoiceStatus status;

  const Invoice({
    required this.id,
    required this.number,
    required this.date,
    required this.amount,
    required this.status,
  });

  /// Decodes an [Invoice] from a `GET /billing/invoices` `data[]` entry (see
  /// `backend/app/Http/Resources/InvoiceResource.php`).
  ///
  /// [amount] is rendered server-side (Cashier's `Invoice::total()` returns
  /// the formatted, currency-aware string), so no client-side money math is
  /// involved; [date] is reformatted from the ISO 8601 wire value into the
  /// screen's existing `"Jun 1, 2026"` display shape.
  factory Invoice.fromMap(Map<String, dynamic> map) {
    return Invoice(
      id: (map['id'] as String?) ?? '',
      number: (map['number'] as String?) ?? '',
      date: _formatInvoiceDate(map['date'] as String?),
      amount: (map['amount'] as String?) ?? '',
      status: _invoiceStatusFromWire(map['status'] as String?),
    );
  }
}

/// Decodes a Cashier/Stripe invoice `status` wire string into an
/// [InvoiceStatus].
///
/// Stripe's raw invoice statuses (`draft`, `open`, `paid`, `uncollectible`,
/// `void`) do not line up 1:1 with this screen's 3-state vocabulary; `open`/
/// `draft` map to [InvoiceStatus.pending] (still awaiting settlement) and
/// `uncollectible`/`void` map to [InvoiceStatus.failed] (never settled). An
/// absent/unrecognized value falls back to [InvoiceStatus.pending] rather
/// than silently defaulting to `paid`.
InvoiceStatus _invoiceStatusFromWire(String? wire) {
  return switch (wire) {
    'paid' => InvoiceStatus.paid,
    'open' => InvoiceStatus.pending,
    'draft' => InvoiceStatus.pending,
    'uncollectible' => InvoiceStatus.failed,
    'void' => InvoiceStatus.failed,
    _ => InvoiceStatus.pending,
  };
}

/// The short month names [_formatInvoiceDate] indexes by `DateTime.month`.
const List<String> _monthAbbreviations = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
];

/// Formats an ISO 8601 [iso] timestamp as `"Jun 1, 2026"`, matching the
/// design-lab fixture's display shape. Returns an empty string when [iso] is
/// absent or unparsable (never throws out of a decode path).
String _formatInvoiceDate(String? iso) {
  if (iso == null) return '';
  final DateTime? date = DateTime.tryParse(iso);
  if (date == null) return '';
  return '${_monthAbbreviations[date.month - 1]} ${date.day}, ${date.year}';
}

/// Like [_formatInvoiceDate], but returns `null` (rather than an empty
/// string) on an absent/unparsable [iso], so a caller can distinguish "no
/// date" from a formatted one with a plain `!= null` check.
String? _formatOptionalDate(String? iso) {
  if (iso == null) return null;
  final DateTime? date = DateTime.tryParse(iso);
  if (date == null) return null;
  return '${_monthAbbreviations[date.month - 1]} ${date.day}, ${date.year}';
}

/// A saved payment method shown on the billing page.
///
/// Every field is nullable: `GET /billing/payment-method` is the one
/// Stripe-live billing read and soft-fails server-side to an all-null
/// payload on a Stripe outage (see `BillingController::paymentMethod()`), so
/// this value object must represent "no card on file" without throwing.
@immutable
class PaymentMethod {
  /// Card network/brand, e.g. `"Visa"`; `null` when no card is on file.
  final String? brand;

  /// Last 4 digits of the card number; `null` when no card is on file.
  final String? last4;

  /// Expiry, e.g. `"08 / 27"`; `null` when no card is on file.
  final String? expiry;

  /// The subscription's next renewal date, formatted `"Jun 1, 2026"`; `null`
  /// when there is no active subscription or the date is unavailable.
  final String? renewalDate;

  const PaymentMethod({
    this.brand,
    this.last4,
    this.expiry,
    this.renewalDate,
  });

  /// Decodes a [PaymentMethod] from the `GET /billing/payment-method`
  /// response (see `backend/app/Http/Controllers/Api/V1/BillingController.php`
  /// `paymentMethod()`). Every field may be `null`, both on a genuine "no
  /// card on file" state and on the endpoint's Stripe-outage soft-fail.
  factory PaymentMethod.fromMap(Map<String, dynamic> map) {
    final int? expMonth = (map['exp_month'] as num?)?.toInt();
    final int? expYear = (map['exp_year'] as num?)?.toInt();

    return PaymentMethod(
      brand: map['brand'] as String?,
      last4: map['last4'] as String?,
      expiry: expMonth != null && expYear != null
          ? '${expMonth.toString().padLeft(2, '0')} / '
                '${(expYear % 100).toString().padLeft(2, '0')}'
          : null,
      renewalDate: _formatOptionalDate(map['renewal_date'] as String?),
    );
  }
}

/// A metered resource shown against its plan limit on the billing page.
///
/// `limit == null` means unlimited. Mirrors the `UsageItem` interface in the
/// React billing mock.
@immutable
class UsageStat {
  /// The resource's stable wire key (`monitors`, `responders`,
  /// `checks_this_month`), untranslated and never rendered.
  ///
  /// This is the field logic keys on. [label] is the same resource's display
  /// copy, resolved through the catalogue at decode time and therefore
  /// different in every language, so a gate that matched on it read zero usage
  /// for every non-English session and silently opened itself.
  final String key;

  /// Display label for [key], e.g. `"Monitors"`, already localized.
  final String label;

  /// Current usage count.
  final int used;

  /// Plan limit; `null` = unlimited.
  final int? limit;

  /// Short suffix appended after the numbers, e.g. `""` or `"checks"`.
  final String unit;

  const UsageStat({
    required this.key,
    required this.label,
    required this.used,
    required this.limit,
    required this.unit,
  });

  /// Decodes the three [UsageStat]s from a `GET /billing/usage` response
  /// (`{monitors, responders, checks_this_month}`, each `{used, limit}`; see
  /// `BillingController::usage()`), in the screen's existing display order.
  ///
  /// Each stat keeps the wire key it was read at, because that is the only
  /// stable handle on a resource: labels and units are display copy, not wire
  /// fields (the response carries only the numbers), and they come from the
  /// catalogue rather than from English literals because the billing page
  /// rendered "Monitors", "Responders", "Checks this month" and "checks" in
  /// English inside an otherwise fully Turkish page.
  ///
  /// Resolved here, at decode time, matching `formatters.dart` reading its words
  /// from the catalogue in this same layer. A locale change needs a fresh boot
  /// anyway (magic_starter persists it and nothing re-points the translator
  /// live), so there is no window where a decoded label is stale but visible.
  static List<UsageStat> fromWireMap(Map<String, dynamic> map) {
    return [
      _entryFromWire(
        map,
        'monitors',
        trans('uptizm.teams.usage_monitors'),
        '',
      ),
      _entryFromWire(
        map,
        'responders',
        trans('uptizm.teams.usage_responders'),
        '',
      ),
      _entryFromWire(
        map,
        'checks_this_month',
        trans('uptizm.teams.usage_checks_this_month'),
        trans('uptizm.teams.usage_unit_checks'),
      ),
    ];
  }

  /// Decodes the `{used, limit}` entry stored under [key] in [map] into a
  /// [UsageStat] carrying that same [key] plus the given display
  /// [label]/[unit].
  ///
  /// Reading the wire here rather than at the call site is what keeps the key a
  /// caller looks up by identical to the key the numbers came from.
  static UsageStat _entryFromWire(
    Map<String, dynamic> map,
    String key,
    String label,
    String unit,
  ) {
    final Object? entry = map[key];
    final Map<String, dynamic> values = entry is Map<String, dynamic>
        ? entry
        : const {};
    return UsageStat(
      key: key,
      label: label,
      used: (values['used'] as num?)?.toInt() ?? 0,
      limit: (values['limit'] as num?)?.toInt(),
      unit: unit,
    );
  }
}
