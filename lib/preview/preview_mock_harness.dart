import 'dart:async';

import 'package:flutter/widgets.dart' show WidgetsBinding;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

/// The render state a feature-screen preview should portray.
///
/// Feature views are controller-backed, so the catalog drives them through the
/// network layer: [success] resolves canned data immediately, [loading] never
/// resolves (the view stays on its spinner), and [error] resolves a non-2xx
/// response so the view shows its error branch. No backend is ever contacted.
enum PreviewState {
  /// Requests resolve with sample data so the view renders its filled state.
  success,

  /// Requests never resolve so the view stays on its loading indicator.
  loading,

  /// Requests resolve with a 500 so the view renders its error branch.
  error,
}

/// Sample backend-free data shared by every feature-screen preview.
///
/// These maps mirror the `api/v1` resource shapes the real backend returns, so
/// the magic_starter views and controllers render exactly as they would in
/// production without a running server.
final class PreviewSampleData {
  PreviewSampleData._();

  /// A representative authenticated user.
  static Map<String, dynamic> get user => <String, dynamic>{
    'id': 1,
    'name': 'Ada Lovelace',
    'email': 'ada@example.com',
    'email_verified_at': '2026-01-01T00:00:00.000Z',
    'two_factor_enabled': true,
    'current_team_id': 10,
    'profile_photo_url': null,
    'created_at': '2026-01-01T00:00:00.000Z',
  };

  /// A representative set of teams the sample user belongs to.
  static List<Map<String, dynamic>> get teams => <Map<String, dynamic>>[
    <String, dynamic>{
      'id': 10,
      'name': 'Engineering',
      'personal_team': false,
      'role': 'owner',
    },
    <String, dynamic>{
      'id': 11,
      'name': 'Design',
      'personal_team': false,
      'role': 'admin',
    },
    <String, dynamic>{
      'id': 12,
      'name': 'Personal',
      'personal_team': true,
      'role': 'owner',
    },
  ];

  /// A representative notifications page.
  static List<Map<String, dynamic>> get notifications => <Map<String, dynamic>>[
    <String, dynamic>{
      'id': 'n1',
      'type': 'team.invitation',
      'data': <String, dynamic>{
        'title': 'Team invitation',
        'body': 'You were invited to join Design.',
      },
      'read_at': null,
      'created_at': '2026-06-20T10:00:00.000Z',
    },
    <String, dynamic>{
      'id': 'n2',
      'type': 'profile.updated',
      'data': <String, dynamic>{
        'title': 'Profile updated',
        'body': 'Your profile changes were saved.',
      },
      'read_at': '2026-06-19T08:00:00.000Z',
      'created_at': '2026-06-19T08:00:00.000Z',
    },
  ];
}

/// A contract-mock network driver for the preview catalog.
///
/// It mirrors the `MockNetworkDriver implements NetworkDriver` pattern the
/// magic_starter test suite uses (tracking [lastMethod]/[lastUrl]/[lastData]),
/// but instead of single-response queueing it answers every request from a
/// [PreviewState], so a preview can render loading, success, or error without a
/// backend. In [PreviewState.loading] requests return a [Future] that never
/// completes, leaving controller-backed views on their spinner.
final class PreviewMockNetworkDriver implements NetworkDriver {
  /// Creates a driver answering every request according to [state].
  PreviewMockNetworkDriver(this.state);

  /// The state every response is shaped to portray.
  final PreviewState state;

  /// The HTTP verb of the most recent request (diagnostic parity with tests).
  String? lastMethod;

  /// The URL of the most recent request.
  String? lastUrl;

  /// The payload of the most recent request.
  dynamic lastData;

  /// Resolve a request for [url] to a canned response for the current [state].
  Future<MagicResponse> _respond(String method, String url, {dynamic data}) {
    lastMethod = method;
    lastUrl = url;
    lastData = data;

    // 1. Loading: never resolve so the view stays on its loading indicator.
    if (state == PreviewState.loading) {
      return Completer<MagicResponse>().future;
    }

    // 2. Error: a non-2xx response drives every view to its error branch.
    if (state == PreviewState.error) {
      return Future<MagicResponse>.delayed(
        Duration.zero,
        () => MagicResponse(
          data: <String, dynamic>{'message': 'Preview error state.'},
          statusCode: 500,
          message: 'Preview error state.',
        ),
      );
    }

    // 3. Success: route-shaped sample data. Resolved on the event queue
    //    (Future.delayed, not Future.value) so a controller that fires its
    //    fetch during the view's first build receives the response AFTER the
    //    frame; a synchronously-completed future would deliver the result
    //    mid-build and trip "setState() called during build" in the view.
    return Future<MagicResponse>.delayed(Duration.zero, () => _successFor(url));
  }

  /// Build a 200 response whose body matches the resource [url].
  MagicResponse _successFor(String url) {
    final body = <String, dynamic>{'data': _dataFor(url)};
    return MagicResponse(data: body, statusCode: 200);
  }

  /// Pick the sample payload for a given [url].
  dynamic _dataFor(String url) {
    if (url.contains('teams')) return PreviewSampleData.teams;
    if (url.contains('notifications')) return PreviewSampleData.notifications;
    if (url.contains('login') || url.contains('register')) {
      return <String, dynamic>{
        'token': 'preview-token',
        'user': PreviewSampleData.user,
      };
    }
    return PreviewSampleData.user;
  }

  @override
  void addInterceptor(MagicNetworkInterceptor interceptor) {}

  @override
  Future<MagicResponse> delete(String url, {Map<String, String>? headers}) =>
      _respond('DELETE', url);

  @override
  Future<MagicResponse> destroy(
    String resource,
    String id, {
    Map<String, String>? headers,
  }) => _respond('DESTROY', '$resource/$id');

  @override
  Future<MagicResponse> get(
    String url, {
    Map<String, dynamic>? query,
    Map<String, String>? headers,
  }) => _respond('GET', url);

  @override
  Future<MagicResponse> index(
    String resource, {
    Map<String, dynamic>? filters,
    Map<String, String>? headers,
  }) => _respond('INDEX', resource);

  @override
  Future<MagicResponse> post(
    String url, {
    dynamic data,
    Map<String, String>? headers,
  }) => _respond('POST', url, data: data);

  @override
  Future<MagicResponse> put(
    String url, {
    dynamic data,
    Map<String, String>? headers,
  }) => _respond('PUT', url, data: data);

  @override
  Future<MagicResponse> show(
    String resource,
    String id, {
    Map<String, String>? headers,
  }) => _respond('SHOW', '$resource/$id');

  @override
  Future<MagicResponse> store(
    String resource,
    Map<String, dynamic> data, {
    Map<String, String>? headers,
  }) => _respond('STORE', resource, data: data);

  @override
  Future<MagicResponse> update(
    String resource,
    String id,
    Map<String, dynamic> data, {
    Map<String, String>? headers,
  }) => _respond('UPDATE', '$resource/$id', data: data);

  @override
  Future<MagicResponse> upload(
    String url, {
    required Map<String, dynamic> data,
    required Map<String, dynamic> files,
    Map<String, String>? headers,
  }) => _respond('UPLOAD', url, data: data);
}

/// Installs a backend-free environment for the feature-screen previews.
///
/// The catalog renders previews inside the already-booted app, so this harness
/// rebinds the `network` singleton to a [PreviewMockNetworkDriver] for the
/// requested [PreviewState] and seeds [Auth] with a sample user so
/// authenticated views (dashboard, profile, teams) render their filled state.
/// It is idempotent per-state: re-installing the same state is a no-op, so the
/// preview builders can call it on every `build()` without churning the
/// singleton or the auth session.
///
/// This only ever runs from a `*.preview.dart` builder, which is reachable only
/// through the dev-only `/preview` boundary; it never touches a release build.
final class PreviewMockHarness {
  PreviewMockHarness._();

  static PreviewState? _installed;

  /// Bind the mock network for [state] and seed the sample auth session.
  ///
  /// Returns the bound driver so a caller can inspect the recorded request.
  static PreviewMockNetworkDriver install(PreviewState state) {
    final driver = PreviewMockNetworkDriver(state);

    // 1. Rebind the network layer so every controller request is mocked.
    Magic.singleton('network', () => driver);

    // 2. Seed the authenticated session once so authenticated views render
    //    their filled state. Deferred to a post-frame callback: install() is
    //    called from a preview builder DURING the build phase, and Auth.login
    //    notifies its state listeners synchronously, which would trigger
    //    "setState() called during build". Running it after the frame seeds the
    //    session and rebuilds cleanly. Errors are swallowed deliberately: a
    //    preview must render even if the guard cannot persist a token (e.g.
    //    secure storage is unavailable on the preview platform).
    if (_installed != state) {
      _installed = state;
      WidgetsBinding.instance.addPostFrameCallback(
        (_) => unawaited(_seedAuth()),
      );
    }

    return driver;
  }

  /// Seed [Auth] with the sample user so authenticated previews render filled.
  static Future<void> _seedAuth() async {
    try {
      final user = MagicStarter.createUser(PreviewSampleData.user);
      await Auth.login(<String, dynamic>{'token': 'preview-token'}, user);
    } catch (_) {
      // Preview-only: a failed seed leaves the view in its guest/empty state,
      // which is still a valid thing to preview.
    }
  }
}
