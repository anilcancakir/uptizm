import 'package:magic/magic.dart';
import 'package:magic_deeplink/magic_deeplink.dart'
    show DeeplinkHandler, DeeplinkSource;

import '../models/team.dart' show Team;
import '../models/user.dart' show User;

/// The single entry point for every link that arrived from outside this app.
///
/// A tapped push and an operating-system link both reach this one handler
/// through `magic_deeplink`'s manager, which walks its handlers and calls the
/// first whose [canHandle] answers true. Before this existed the app routed
/// tapped pushes itself, out of `AppServiceProvider`, and registering any
/// handler at all would have navigated twice for one tap.
///
/// ## The source is the whole reason it is one handler and not two
///
/// The URI cannot say where the instruction came from, and the two paths are
/// not equally trusted. A [DeeplinkSource.push] payload was authored by the
/// server that sent the notification; a [DeeplinkSource.osLink] is whatever the
/// operating system was asked to open, so anyone who can send this device a
/// link can craft one. Everything beyond navigating a path this app serves,
/// which here means the team switch, is gated on the first of those.
///
/// ## The two shapes a link arrives in
///
/// A push carries a relative path and an OS link carries the whole address the
/// OS claimed, so [canHandle] accepts a leading-slash path OR an `http`/`https`
/// URI whose host equals the configured `deeplink.domain`, and refuses every
/// other authority. Both then go through the same route allowlist. The guard
/// this replaced refused every authority, which was correct for the push path
/// it came from and would have refused every real Universal Link.
class UptizmDeeplinkHandler implements DeeplinkHandler {
  /// The route families a link from outside may name.
  ///
  /// A prefix rather than a route pattern, because the parameter segment is the
  /// part a link carries: `/incidents/` covers `/incidents/inc-1` and nothing
  /// this app does not serve. Without it the guard said "any string starting
  /// with a slash", which is how `/main.dart.js` and every other asset the web
  /// build serves read as a destination.
  static const List<String> _routePrefixes = <String>[
    '/incidents/',
    '/monitors/',
    '/status/',
    '/teams/',
    '/invitations/',
    '/settings/',
  ];

  /// The one exact path outside [_routePrefixes], since a prefix match on `/`
  /// would accept every path there is.
  static const String _rootPath = '/';

  /// The push payload key naming the team that OWNS what the link opens.
  ///
  /// Read from the payload and never from the URI's query, whatever the source:
  /// a query is part of the link, and a link is exactly what an attacker gets
  /// to write.
  static const String _pushTeamKey = 'team_id';

  /// Switches the session's active team and re-points the store rail at it,
  /// answering whether the switch took.
  ///
  /// `AppServiceProvider.switchTeamAndIdentifyStore`, handed in rather than
  /// reached for: that method is `@visibleForTesting`, and it stays that way
  /// because the team is the paying subject and the app deliberately keeps one
  /// call site for moving it. Taking it as a collaborator also keeps this file
  /// from importing the provider that registers this handler.
  final Future<bool> Function(String teamId) switchTeam;

  /// Creates the handler.
  UptizmDeeplinkHandler({required this.switchTeam});

  @override
  bool canHandle(Uri uri) => _serves(uri);

  /// Opens what [uri] names, and answers whether it was opened.
  ///
  /// Never throws, per the handler contract: the manager awaits this from a
  /// stream subscription, so an escaping error is an unhandled async error that
  /// takes a tapped notification with it. Every failure is reported and
  /// answered as false instead.
  @override
  Future<bool> handle(
    Uri uri, {
    required DeeplinkSource source,
    Map<String, dynamic>? payload,
  }) async {
    // 1. The manager asks [canHandle] first and walks past a handler that says
    //    no, so this arm is reached only by a direct call. It still refuses
    //    rather than trusting its caller, because the guard is the thing this
    //    class is for.
    if (!_serves(uri)) {
      Log.warning(
        '[UptizmDeeplinkHandler] a link from ${source.name} names no in-app '
        'destination: "$uri"',
      );

      return false;
    }

    try {
      return await _open(uri, source: source, payload: payload);
    } catch (error) {
      Log.error(
        '[UptizmDeeplinkHandler] opening $uri failed, so the link did not '
        'reach the screen it names: $error',
      );

      return false;
    }
  }

  /// Navigates to [destination], switching team first when a push says the page
  /// belongs to one the session is not on.
  Future<bool> _open(
    Uri destination, {
    required DeeplinkSource source,
    required Map<String, dynamic>? payload,
  }) async {
    // 1. Whose page it is. Only a push can answer, and only from its own
    //    payload. An OS link naming another team navigates anyway and meets the
    //    backend's deliberate 404, which is a better answer than a link nobody
    //    authored moving the scoping and paying subject of the session.
    final String owner = source == DeeplinkSource.push
        ? (payload?[_pushTeamKey]?.toString().trim() ?? '')
        : '';

    // 2. An ABSENT team is not evidence of a mismatch, the way an absent
    //    `subject` is not evidence of misaddressing in the notification
    //    manager: a server older than this key must not leave a paged responder
    //    on whatever screen they were on. An UNRESOLVED local team reads the
    //    same way, since a restored session whose user has not resolved yet has
    //    no team to compare against and an empty-string fallback would read as
    //    a mismatch instead of the absence it is.
    final Team? localTeam = User.current.currentTeam;
    if (owner.isEmpty || localTeam == null || owner == localTeam.id) {
      _navigate(destination);

      return true;
    }

    // 3. The page came from a TEAM-scoped rota and the responder is sitting on
    //    another team, so the page would 404: the backend resolves it against
    //    `users.current_team_id`, and that 404 is a deliberate non-disclosure
    //    choice rather than something to weaken. Switch rather than ask: during
    //    an outage the responder wants the incident, not a dialog. Through
    //    [switchTeam] rather than the team controller directly, because the
    //    team is also the paying subject and the store rail has to follow it.
    final bool switched = await switchTeam(owner);
    if (!switched) {
      // NOT navigating is the point: the backend still resolves the page
      // against a team the session is not on, so going anyway lands on the same
      // 404 with an extra step. Logged as well as surfaced because nothing
      // retries, and a responder who cannot reach a live incident is an
      // operational failure rather than a UI hiccup.
      Log.error(
        '[UptizmDeeplinkHandler] could not switch to team $owner; staying put '
        'rather than opening $destination on a 404',
      );
      Magic.error(trans('common.error_occurred'), trans('teams.switch_failed'));

      return false;
    }

    // 4. Say so. The switch is silent otherwise, and a responder who resolves
    //    the incident and carries on would be reading another team's dashboard
    //    believing they are still on their own.
    Magic.success(
      trans('uptizm.incidents.push_team_switch_toast_title'),
      trans('uptizm.incidents.push_team_switch_toast_description'),
    );
    _navigate(destination);

    return true;
  }

  /// Hands the router the value [_serves] validated, and no other.
  ///
  /// The parsed path rather than the string it was parsed from. `Uri` resolves
  /// dot segments while parsing, so the two differ for any link carrying a
  /// traversal, and validating one value while navigating the other is the
  /// shape every bypass of this guard would take.
  void _navigate(Uri destination) {
    MagicRoute.to(destination.path, query: destination.queryParameters);
  }

  /// Whether [uri] names a page this app serves.
  ///
  /// Two questions, in order: does the URI address THIS app, and does its path
  /// name a route. The second is the allowlist; the first is [_addressesThisApp]
  /// and it is the one that has to admit two shapes, because the two sources
  /// deliver different ones.
  static bool _serves(Uri uri) {
    if (!_addressesThisApp(uri)) return false;

    final String path = uri.path;
    if (!path.startsWith('/')) return false;
    if (path == _rootPath) return true;

    return _routePrefixes.any(path.startsWith);
  }

  /// Whether [uri] addresses this app rather than somewhere else.
  ///
  /// A push carries a RELATIVE path: the server writes `/incidents/{id}` into
  /// the payload and a test pins it, so anything with a scheme or an authority
  /// on that path did not come from the server.
  ///
  /// An OS link carries an ABSOLUTE one. `magic_deeplink`'s mobile driver
  /// publishes `app_links`' stream unchanged and the provider passes it
  /// verbatim, so a Universal Link arrives as the whole address the OS claimed
  /// (`https://app.uptizm.com/incidents/1`). Refusing every authority, which is
  /// what the push-tap guard this replaced did, therefore refuses every real
  /// deep link while every push test stays green.
  ///
  /// Accepting our own host is not a hole, and it is the only authority
  /// accepted. The OS hands an app a link only for a host in its entitlement or
  /// its manifest, and only after fetching the association file from that host
  /// and verifying it names this app, so the authority was checked before this
  /// code ran. Equality rather than a suffix test, because `notapp.uptizm.com`
  /// and `app.uptizm.com.evil.test` both pass an `endsWith`. The scheme has to
  /// be one the association files cover, and a port or a userinfo means an
  /// address the OS never verified.
  ///
  /// An absent `deeplink.domain` refuses every absolute URI rather than
  /// accepting any: a build that does not know its own host cannot recognise
  /// its own link, and guessing is the one answer that could be wrong.
  static bool _addressesThisApp(Uri uri) {
    if (!uri.hasScheme && !uri.hasAuthority) return true;

    if (uri.scheme != 'https' && uri.scheme != 'http') return false;
    if (uri.hasPort || uri.userInfo.isNotEmpty) return false;

    final String domain = Config.get<String>('deeplink.domain', '') ?? '';
    if (domain.isEmpty) return false;

    return uri.host.toLowerCase() == domain.toLowerCase();
  }
}
