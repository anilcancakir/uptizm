import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/models/user.dart';
import 'package:uptizm/app/services/locale_onboarding_gate.dart';
import 'package:uptizm/resources/views/onboarding/locale_onboarding_view.dart';

/// Records the last request the profile controller sends so the confirm test
/// can assert on the `locale`/`timezone` wire body without a real backend.
class _MockNetworkDriver implements NetworkDriver {
  MagicResponse response = MagicResponse(data: {'data': {}}, statusCode: 200);
  String? putUrl;
  dynamic putData;

  MagicResponse _record(String method, String url, {dynamic data}) {
    if (method == 'PUT') {
      putUrl = url;
      putData = data;
    }
    return response;
  }

  @override
  void addInterceptor(MagicNetworkInterceptor interceptor) {}

  @override
  Future<MagicResponse> get(
    String url, {
    Map<String, dynamic>? query,
    Map<String, String>? headers,
  }) async => _record('GET', url);

  @override
  Future<MagicResponse> post(
    String url, {
    dynamic data,
    Map<String, String>? headers,
  }) async => _record('POST', url, data: data);

  @override
  Future<MagicResponse> put(
    String url, {
    dynamic data,
    Map<String, String>? headers,
  }) async => _record('PUT', url, data: data);

  @override
  Future<MagicResponse> delete(
    String url, {
    Map<String, String>? headers,
  }) async => _record('DELETE', url);

  @override
  Future<MagicResponse> index(
    String resource, {
    Map<String, dynamic>? filters,
    Map<String, String>? headers,
  }) async => _record('INDEX', resource);

  @override
  Future<MagicResponse> show(
    String resource,
    String id, {
    Map<String, String>? headers,
  }) async => _record('SHOW', '$resource/$id');

  @override
  Future<MagicResponse> store(
    String resource,
    Map<String, dynamic> data, {
    Map<String, String>? headers,
  }) async => _record('STORE', resource, data: data);

  @override
  Future<MagicResponse> update(
    String resource,
    String id,
    Map<String, dynamic> data, {
    Map<String, String>? headers,
  }) async => _record('UPDATE', '$resource/$id', data: data);

  @override
  Future<MagicResponse> destroy(
    String resource,
    String id, {
    Map<String, String>? headers,
  }) async => _record('DESTROY', '$resource/$id');

  @override
  Future<MagicResponse> upload(
    String url, {
    required Map<String, dynamic> data,
    required Map<String, dynamic> files,
    Map<String, String>? headers,
  }) async => _record('UPLOAD', url, data: data);
}

/// Minimal guard exposing the authenticated user the view reads for name/email.
class _MockGuard implements Guard {
  _MockGuard(this._user);

  Authenticatable? _user;
  final ValueNotifier<int> _state = ValueNotifier<int>(0);

  @override
  Future<void> login(Map<String, dynamic> data, Authenticatable user) async {
    _user = user;
  }

  @override
  Future<void> logout() async => _user = null;

  @override
  bool check() => _user != null;

  @override
  bool get guest => !check();

  @override
  T? user<T extends Model>() => _user as T?;

  @override
  dynamic id() => _user?.authIdentifier;

  @override
  void setUser(Authenticatable user) => _user = user;

  @override
  Future<bool> hasToken() async => true;

  @override
  Future<String?> getToken() async => 'token';

  @override
  Future<bool> refreshToken() async => true;

  @override
  Future<void> restore() async {}

  @override
  ValueNotifier<int> get stateNotifier => _state;
}

/// In-memory loader so [Lang] resolves a real locale without asset bundles.
class _StubLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async => {};
}

void main() {
  late _MockNetworkDriver network;

  Future<void> bootMagic() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    MagicApp.reset();
    Magic.flush();
    Translator.reset();
    DateManager.reset();
    MagicRouter.reset();
    LocaleOnboardingGate.instance.resetForTesting();

    Config.set('magic_starter.features.timezones', true);
    // Mirror the app's localization config so DateManager.boot() takes the
    // auto-detect branch (the else/UTC branch trips a tz-database lookup on
    // this host); the detected value is then overridden below.
    Config.set('localization.auto_detect_timezone', true);

    network = _MockNetworkDriver();
    Magic.singleton('network', () => network);
    Magic.singleton('log', () => LogManager());
    Vault.fake();

    // Auth: an authenticated user carrying the detected preferences.
    Magic.singleton('auth', () => AuthManager());
    Auth.manager.forgetGuards();
    final guard = _MockGuard(
      User.fromMap({
        'id': '1',
        'name': 'Alice',
        'email': 'alice@example.com',
        'locale': 'tr',
        'timezone': 'Europe/Istanbul',
      }),
    );
    Auth.manager.extend('mock', (_) => guard);
    Config.set('auth.defaults.guard', 'mock');
    Config.set('auth.guards', {
      'mock': {'driver': 'mock'},
    });

    Magic.singleton('magic_starter', () => MagicStarterManager());
    Magic.put(MagicStarterProfileController());

    // Detected runtime state the view pre-fills from.
    Lang.setSupportedLocales([const Locale('en'), const Locale('tr')]);
    Translator.instance.setLoader(_StubLangLoader());
    await Translator.instance.setLocale(const Locale('tr'));

    await DateManager.instance.boot();
    DateManager.instance.setTimezone('Europe/Istanbul');
  }

  setUp(bootMagic);

  tearDown(() {
    Auth.manager.forgetGuards();
    Vault.unfake();
    MagicRouter.reset();
    Translator.reset();
    DateManager.reset();
    LocaleOnboardingGate.instance.resetForTesting();
  });

  Widget wrapDirect(Widget child) {
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp(home: child),
    );
  }

  Widget wrapRouted() {
    MagicRoute.page(
      '/',
      () => const SizedBox.shrink(key: ValueKey('home-stub')),
    );
    MagicRoute.page('/onboarding/locale', () => const LocaleOnboardingView());
    MagicRouter.instance.setInitialLocation('/onboarding/locale');
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp.router(
        routerConfig: MagicRouter.instance.routerConfig,
      ),
    );
  }

  testWidgets(
    'pre-fills the language and timezone selectors from detected state',
    (tester) async {
      await tester.pumpWidget(wrapDirect(const LocaleOnboardingView()));
      await tester.pumpAndSettle();

      // The language selector is the first WFormSelect in the tree; the
      // timezone selector nests its own WFormSelect below it.
      final languageSelect = tester.widget<WFormSelect<String>>(
        find.byType(WFormSelect<String>).first,
      );
      expect(languageSelect.initialValue, 'tr');

      final timezoneSelect = tester.widget<MagicStarterTimezoneSelect>(
        find.byType(MagicStarterTimezoneSelect),
      );
      expect(timezoneSelect.value, 'Europe/Istanbul');
    },
  );

  testWidgets(
    'confirm persists locale + timezone, applies the locale, marks the gate',
    (tester) async {
      await tester.pumpWidget(wrapRouted());
      await tester.pumpAndSettle();

      final confirm = find.byKey(const ValueKey('onboarding-confirm'));
      expect(confirm, findsOneWidget);
      await tester.ensureVisible(confirm);
      await tester.tap(confirm);
      await tester.pumpAndSettle();
      // Drain the success-toast timer scheduled by `doUpdateProfile` so it does
      // not leak past the test as a pending timer.
      await tester.pump(const Duration(seconds: 2));
      await tester.pumpAndSettle();

      // 1. Persisted via the profile-update path with the canonical wire keys.
      expect(network.putUrl, contains('/user/profile'));
      expect((network.putData as Map)['locale'], 'tr');
      expect((network.putData as Map)['timezone'], 'Europe/Istanbul');

      // 2. Applied to the running localization runtime.
      expect(Lang.current.languageCode, 'tr');

      // 3. Gate flipped so a later login skips onboarding.
      expect(LocaleOnboardingGate.instance.isCompleted, isTrue);
    },
  );

  testWidgets(
    'confirm keeps the gate open and does not navigate when the persist fails',
    (tester) async {
      await tester.pumpWidget(wrapRouted());
      await tester.pumpAndSettle();

      // Backend rejects the profile update.
      network.response = MagicResponse(
        data: {'message': 'nope'},
        statusCode: 422,
      );

      final confirm = find.byKey(const ValueKey('onboarding-confirm'));
      await tester.ensureVisible(confirm);
      await tester.tap(confirm);
      await tester.pumpAndSettle();
      // Drain the MagicFeedback overlay-toast timer (4s) so it does not leak.
      await tester.pump(const Duration(seconds: 5));
      await tester.pumpAndSettle();

      // A failed persist must NOT burn the one-shot gate...
      expect(LocaleOnboardingGate.instance.isCompleted, isFalse);
      // ...and must NOT leave the onboarding screen for the dashboard.
      expect(find.byType(LocaleOnboardingView), findsOneWidget);
      expect(find.byKey(const ValueKey('home-stub')), findsNothing);
    },
  );
}
