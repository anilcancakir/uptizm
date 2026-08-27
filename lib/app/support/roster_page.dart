import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';

/// One page of a team roster, plus everything the caller needs to ask for the
/// next one.
///
/// The five rosters (monitors, incidents, status pages, escalation policies,
/// maintenance windows) all read the same way and all read it wrong in the same
/// two ways before this existed:
///
/// - They went through `Model.all()`, which absorbs every transport failure and
///   resolves an EMPTY LIST. A 500 and a team that owns nothing were the same
///   value, so a screen could state one as the other.
/// - They took whatever page the endpoint happened to answer and treated it as
///   the whole collection. The endpoints paginate, so a Pro team with fifty
///   monitors read fifteen of them and every count derived from the roster was
///   a count of one accidental page.
///
/// A page therefore carries three things a bare list cannot: whether the read
/// answered at all, where the next page starts, and the envelope's `meta`, out
/// of which a screen reads the collection-wide totals it can no longer count
/// for itself.
@immutable
class RosterPage<T> {
  /// The decoded rows, or null when the read did not answer.
  ///
  /// Null is the distinction the old path could not make: an empty list here
  /// means the collection is empty, and null means nobody knows.
  final List<T>? rows;

  /// The token that fetches the page after this one, or null at the end.
  final String? nextCursor;

  /// The response envelope's `meta`, empty when the payload carried none.
  final Map<String, dynamic> meta;

  /// Creates a [RosterPage].
  const RosterPage({
    required this.rows,
    required this.nextCursor,
    required this.meta,
  });

  /// A page that never arrived.
  const RosterPage.failed()
    : rows = null,
      nextCursor = null,
      meta = const <String, dynamic>{};

  /// Whether the read failed rather than answering an empty collection.
  bool get failed => rows == null;
}

/// Reads one page of a team roster.
///
/// [cursor] continues from a previous page; omit it for the first. [filters]
/// carries the screen's own query (a status tab, a search box), which lives on
/// the server now because filtering a page in Dart answers a question about the
/// rows in hand rather than about the collection.
///
/// Never throws. A transport fault, a non-2xx and a 2xx whose body carries no
/// list all resolve to [RosterPage.failed], which is a distinct state from an
/// empty page and is what lets a screen offer a retry instead of claiming the
/// team owns nothing.
Future<RosterPage<T>> readRosterPage<T>({
  required String resource,
  required T Function(Map<String, dynamic>) fromMap,
  required String logTag,
  int perPage = 50,
  String? cursor,
  Map<String, dynamic> filters = const <String, dynamic>{},
}) async {
  late final MagicResponse response;

  try {
    response = await Http.index(
      resource,
      filters: <String, dynamic>{
        'per_page': perPage,
        'cursor': ?cursor,
        ...filters,
      },
    );
  } catch (error) {
    // A throw is a transport fault the driver could not shape into a response,
    // including an unregistered `network` service in a bare test host. Recorded
    // rather than swallowed: the caller renders a retry and never sees a throw.
    Log.error('[$logTag] $error');

    return RosterPage<T>.failed();
  }

  // `!successful` rather than `failed`: an offline device yields statusCode 0,
  // which is neither, so `failed` would read a dead connection as a success.
  if (!response.successful) {
    Log.error('[$logTag] ${response.statusCode} ${response.errorMessage ?? ''}');

    return RosterPage<T>.failed();
  }

  final Object? payload = response.data;
  final Map<String, dynamic> meta = payload is Map<String, dynamic>
      ? (payload['meta'] is Map<String, dynamic>
            ? payload['meta'] as Map<String, dynamic>
            : const <String, dynamic>{})
      : const <String, dynamic>{};

  final Object? raw = payload is Map<String, dynamic>
      ? payload['data']
      : payload;

  if (raw is! List) {
    // A 2xx whose body is not a list is a malformed payload, not an empty
    // roster. Reading it as emptiness would let one bad response wipe a
    // collection while reporting success.
    Log.error('[$logTag] unreadable index payload');

    return RosterPage<T>.failed();
  }

  final Object? next = meta['next_cursor'];

  return RosterPage<T>(
    rows: raw.whereType<Map<String, dynamic>>().map(fromMap).toList(),
    nextCursor: next is String && next.isNotEmpty ? next : null,
    meta: meta,
  );
}
