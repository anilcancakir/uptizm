import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/models/user.dart';
import 'package:uptizm/app/services/locale_onboarding_gate.dart';
import 'package:uptizm/resources/views/dashboard/locale_prompt_banner.dart';

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

/// Minimal guard exposing the authenticated user the banner reads for
/// name/email.
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

/// In-memory loader returning the flat, dotted translation keys the banner
/// reads, so [trans] resolves and interpolates a real detected message.
class _StubLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async => {
    'uptizm.onboarding.banner_detected': ':language ve :timezone algılandı.',
    'uptizm.onboarding.banner_confirm': 'Onayla',
    'uptizm.onboarding.banner_change': 'Değiştir',
    'uptizm.onboarding.banner_dismiss': 'Kapat',
  };
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

    // Detected runtime state the banner reports.
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

  /// Wraps the banner as the `/` route so [MagicRoute.to] navigation and the
  /// [Magic.toast] overlay both resolve through the real router.
  ///
  /// The route mounts [_BannerHost] rather than the banner directly because
  /// hiding is the CALLER's decision: the banner carries no `visible` state, so
  /// a harness that mounted it unconditionally would keep it on screen after an
  /// action and test a contract the app does not use.
  Widget wrapRouted() {
    MagicRoute.page('/', () => const _BannerHost());
    MagicRoute.page(
      '/settings/language',
      () => const SizedBox.shrink(key: ValueKey('language-stub')),
    );
    MagicRouter.instance.setInitialLocation('/');
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp.router(
        routerConfig: MagicRouter.instance.routerConfig,
      ),
    );
  }

  testWidgets(
    'shows the first-run banner with the detected language and timezone',
    (tester) async {
      await tester.pumpWidget(wrapRouted());
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('locale-prompt-banner')),
        findsOneWidget,
      );
      // The message interpolates the current locale's human label (Türkçe) and
      // the applied timezone.
      expect(find.textContaining('Türkçe'), findsOneWidget);
      expect(find.textContaining('Europe/Istanbul'), findsOneWidget);
    },
  );

  testWidgets(
    'confirm persists the applied locale + timezone, marks the gate, hides',
    (tester) async {
      await tester.pumpWidget(wrapRouted());
      await tester.pumpAndSettle();

      final confirm = find.byKey(const ValueKey('locale-banner-confirm'));
      expect(confirm, findsOneWidget);
      await tester.ensureVisible(confirm);
      await tester.tap(confirm);
      await tester.pumpAndSettle();
      // Drain the success-toast timer scheduled by `doUpdateProfile` so it does
      // not leak past the test as a pending timer.
      await tester.pump(const Duration(seconds: 5));
      await tester.pumpAndSettle();

      // 1. Persisted via the profile-update path with the canonical wire keys.
      expect(network.putUrl, contains('/user/profile'));
      expect((network.putData as Map)['locale'], 'tr');
      expect((network.putData as Map)['timezone'], 'Europe/Istanbul');

      // 2. Gate flipped so the banner never reappears on this device.
      expect(LocaleOnboardingGate.instance.isCompleted, isTrue);

      // 3. Banner gone.
      expect(
        find.byKey(const ValueKey('locale-prompt-banner')),
        findsNothing,
      );
    },
  );

  testWidgets(
    'change marks the gate and navigates to the language settings page',
    (tester) async {
      await tester.pumpWidget(wrapRouted());
      await tester.pumpAndSettle();

      final change = find.byKey(const ValueKey('locale-banner-change'));
      expect(change, findsOneWidget);
      await tester.ensureVisible(change);
      await tester.tap(change);
      await tester.pumpAndSettle();

      // 1. Gate flipped.
      expect(LocaleOnboardingGate.instance.isCompleted, isTrue);

      // 2. Navigated to the EXISTING language settings page (not a new picker).
      expect(find.byKey(const ValueKey('language-stub')), findsOneWidget);

      // 3. Banner gone (the router left the dashboard route).
      expect(
        find.byKey(const ValueKey('locale-prompt-banner')),
        findsNothing,
      );
    },
  );

  testWidgets('dismiss marks the gate and hides the banner', (tester) async {
    await tester.pumpWidget(wrapRouted());
    await tester.pumpAndSettle();

    final dismiss = find.byKey(const ValueKey('locale-banner-dismiss'));
    expect(dismiss, findsOneWidget);
    await tester.ensureVisible(dismiss);
    await tester.tap(dismiss);
    await tester.pumpAndSettle();

    expect(LocaleOnboardingGate.instance.isCompleted, isTrue);
    expect(
      find.byKey(const ValueKey('locale-prompt-banner')),
      findsNothing,
    );
  });

  testWidgets(
    'does not show once the gate is already completed',
    (tester) async {
      LocaleOnboardingGate.instance.resetForTesting(completed: true);

      await tester.pumpWidget(wrapRouted());
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('locale-prompt-banner')),
        findsNothing,
      );
      // ABSENT, not merely invisible: a zero-size child still consumes a gap
      // slot in the Wind flex the dashboard mounts this in, which is how a
      // silent banner pushed the page header down 24px for good.
      expect(find.byType(LocalePromptBanner), findsNothing);
    },
  );
}

/// Mirrors the dashboard's call site: gate on [LocalePromptBanner.shouldShow],
/// and drop the banner from the tree when it resolves.
class _BannerHost extends StatefulWidget {
  const _BannerHost();

  @override
  State<_BannerHost> createState() => _BannerHostState();
}

class _BannerHostState extends State<_BannerHost> {
  @override
  Widget build(BuildContext context) {
    if (!LocalePromptBanner.shouldShow) return const SizedBox.shrink();

    return LocalePromptBanner(onResolved: () => setState(() {}));
  }
}
