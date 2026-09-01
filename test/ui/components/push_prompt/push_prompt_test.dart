import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart'
    show
        Notify,
        PushDriver,
        PushIdentityChange,
        PushNotificationEvent,
        PushPermissionState,
        PushPromptAction,
        PushReachability;
import 'package:magic_starter/magic_starter.dart' show MagicStarterConfig;
import 'package:uptizm/ui/components/push_prompt/index.dart';
import 'package:uptizm/ui/components/push_prompt/push_prompt.preview.dart';

import '../../../support/bundled_lang.dart';

/// Feeds [trans] the app's shipped catalogue for one locale.
///
/// The prompt is measured against the words an operator actually reads: a
/// widget test that renders a raw i18n key lays out the wrong width and
/// overflows for a reason that has nothing to do with the widget.
class _BundledLangLoader implements TranslationLoader {
  /// The locale whose shipped catalogue to serve.
  final String locale;

  const _BundledLangLoader(this.locale);

  @override
  Future<Map<String, dynamic>> load(Locale requested) async =>
      readBundledLang(locale);
}

/// A push driver double that records every permission request it is asked for.
///
/// Contract inheritance rather than a mock package, matching
/// `test/app/providers/push_identity_test.dart`'s `_RecordingPushDriver`. The
/// COUNT is the subject: the whole point of a soft prompt is that a decline
/// never reaches the platform, because the OS prompt fires once per install and
/// a declined one cannot be re-asked.
class _RecordingPushDriver extends PushDriver {
  /// Creates a double answering [permission] / [optedIn] / [subscriptionId].
  _RecordingPushDriver({
    this.permission = PushPermissionState.notDetermined,
    this.optedIn = false,
    this.subscriptionId,
    this.opensPlatformSettings = false,
  });

  /// The permission the platform reports.
  final PushPermissionState permission;

  /// Whether this fake device is opted in.
  final bool optedIn;

  /// The subscription id the platform holds, or null for none.
  final String? subscriptionId;

  /// Whether a request on a DENIED device routes the user to the platform
  /// setting, which is the mobile `fallback_to_settings` capability. False is
  /// the browser, where no API opens the site settings panel from a page.
  final bool opensPlatformSettings;

  /// How many times [requestPermission] was called.
  int permissionRequests = 0;

  @override
  bool get canOpenPlatformSettings => opensPlatformSettings;

  @override
  String get name => 'onesignal';

  @override
  bool get isSupported => true;

  @override
  bool get isOptedIn => optedIn;

  @override
  Future<PushPermissionState> permissionState() async => permission;

  @override
  Future<void> initialize(Map<String, dynamic> config) async {}

  @override
  Future<void> login(String externalId) async {}

  @override
  Future<void> logout() async {}

  @override
  Future<String?> currentExternalId() async => 'user_u1';

  @override
  Future<String?> currentSubscriptionId() async => subscriptionId;

  @override
  Future<bool> requestPermission() async {
    permissionRequests++;

    return true;
  }

  @override
  Future<void> optIn() async {}

  @override
  Future<void> optOut() async {}

  @override
  Future<void> setTags(Map<String, String> tags) async {}

  @override
  Future<void> removeTag(String key) async {}

  @override
  Stream<PushNotificationEvent> get onNotificationReceived =>
      const Stream<PushNotificationEvent>.empty();

  @override
  Stream<PushNotificationEvent> get onNotificationClicked =>
      const Stream<PushNotificationEvent>.empty();

  @override
  Stream<PushPermissionState> get onPermissionChanged =>
      const Stream<PushPermissionState>.empty();

  @override
  Stream<PushIdentityChange> get onIdentityChanged =>
      const Stream<PushIdentityChange>.empty();
}

/// A [_RecordingPushDriver] whose [requestPermission] throws.
///
/// Reproduces a platform SDK failure (a denied browser permission API, a
/// missing native module) so the row's boundary handling can be exercised
/// without a real device.
class _ThrowingRequestPushDriver extends _RecordingPushDriver {
  /// How many times [permissionState] was read, which only ever grows
  /// through [PushDriver.reachability]; used to prove the row re-read the
  /// platform after the throw instead of getting stuck.
  int permissionStateReads = 0;

  @override
  Future<PushPermissionState> permissionState() async {
    permissionStateReads++;

    return super.permissionState();
  }

  @override
  Future<bool> requestPermission() async {
    permissionRequests++;

    throw StateError('push permission request failed');
  }
}

/// A [_RecordingPushDriver] whose [permissionState] always throws.
///
/// Reproduces a platform-channel failure on the very FIRST read, before any
/// tap: `reachability()` (the base class method `_read` calls) reaches
/// [permissionState] unconditionally, so a throw here reproduces the boot-time
/// defect rather than the enable-button one [_ThrowingRequestPushDriver]
/// covers.
class _ThrowingReachabilityPushDriver extends _RecordingPushDriver {
  @override
  Future<PushPermissionState> permissionState() async {
    throw StateError('permission state read failed');
  }
}

/// A [MagicVaultService] whose [put] throws, reproducing secure storage being
/// unavailable (a browser with no storage backend, a locked keychain).
class _ThrowingPutVaultService extends MagicVaultService {
  _ThrowingPutVaultService() : super.forTesting();

  /// How many times [put] was attempted.
  int putAttempts = 0;

  @override
  Future<void> put(String key, String value) async {
    putAttempts++;

    throw MagicVaultException('vault write failed', 'disk full');
  }

  @override
  Future<String?> get(String key) async => null;
}

/// Every reading `pushPromptAdvice` can actually produce, as the pair the row
/// renders from.
///
/// Reachability alone no longer names a presentation: `blocked` splits on
/// whether this platform can route the tap back to a setting, and that split is
/// the whole point of [PushPromptAction]. A loop over `PushReachability.values`
/// would render four of the five and miss the one that only exists on mobile.
const List<(PushReachability, PushPromptAction)> _everyState =
    <(PushReachability, PushPromptAction)>[
      (PushReachability.off, PushPromptAction.request),
      (PushReachability.blocked, PushPromptAction.openSettings),
      (PushReachability.blocked, PushPromptAction.instructions),
      (PushReachability.on, PushPromptAction.none),
      (PushReachability.unavailable, PushPromptAction.none),
    ];

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    Vault.fake();

    Translator.instance.setLoader(const _BundledLangLoader('en'));
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    Vault.unfake();
    MagicApp.reset();
    Magic.flush();
  });

  /// Registers [driver] as the app's push rail, the way a consumer swaps one.
  ///
  /// `NotificationManager` is a `static final` singleton outliving
  /// `Magic.flush()`, so the teardown is not hygiene: without it the double
  /// survives into the next test and its recorded counts are read twice.
  void usePushDriver(_RecordingPushDriver driver) {
    Notify.extend('onesignal', () => driver);
    addTearDown(Notify.forgetDrivers);
  }

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme].
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: SingleChildScrollView(child: widget)),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // The blocked state: an instruction, never a control (QA case 1)
  // ---------------------------------------------------------------------------

  group('the blocked state', () {
    testWidgets('renders the instruction row and no control at all', (
      tester,
    ) async {
      await tester.pumpWidget(
        wrap(
          const PushPrompt(
            reachability: PushReachability.blocked,
            action: PushPromptAction.instructions,
            onEnable: null,
          ),
        ),
      );

      // A blocked permission cannot be re-prompted from inside the app: the
      // platform answers the next request without showing anything. Where
      // nothing can route the tap either (a browser, where no API opens the
      // site settings panel), a toggle is a control that does nothing, so the
      // row says where the switch actually lives instead.
      expect(find.byKey(PushPrompt.blockedInstructionKey), findsOneWidget);
      expect(find.byType(WButton), findsNothing);
      expect(find.text(trans('uptizm.push_prompt.enable')), findsNothing);
    });

    testWidgets('a browser gets the instruction, because nothing there can '
        'open site settings', (tester) async {
      // Through the HOST, so the platform capability is read from the driver
      // rather than declared by the test: `canOpenPlatformSettings` false is
      // what a browser reports.
      usePushDriver(
        _RecordingPushDriver(permission: PushPermissionState.denied),
      );

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      expect(find.byKey(PushPrompt.blockedInstructionKey), findsOneWidget);
      expect(find.byType(WButton), findsNothing);
    });

    testWidgets('a device the platform can route back offers a real action', (
      tester,
    ) async {
      // Mobile. The OS prompt is spent, but the SDK's `fallbackToSettings`
      // lands the same request on the app's settings page, so the row that used
      // to be a sentence is a control again. On a product that pages people,
      // that is the difference between a responder with a route back and one
      // stranded on a device that will never ring.
      final _RecordingPushDriver driver = _RecordingPushDriver(
        permission: PushPermissionState.denied,
        opensPlatformSettings: true,
      );
      usePushDriver(driver);

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      expect(
        find.text(trans('uptizm.push_prompt.open_settings')),
        findsOneWidget,
      );

      await tester.tap(find.text(trans('uptizm.push_prompt.open_settings')));
      await tester.pumpAndSettle();

      // The same call the enable control makes: the driver hands it
      // `canOpenPlatformSettings`, and the SDK turns it into the settings page.
      expect(driver.permissionRequests, 1);
    });

    testWidgets('the instruction is real copy, not a raw key', (tester) async {
      await tester.pumpWidget(
        wrap(
          const PushPrompt(
            reachability: PushReachability.blocked,
            action: PushPromptAction.instructions,
          ),
        ),
      );

      final WText instruction = tester.widget<WText>(
        find.descendant(
          of: find.byKey(PushPrompt.blockedInstructionKey),
          matching: find.byType(WText),
        ),
      );

      expect(instruction.data, isNotEmpty);
      expect(instruction.data, isNot(startsWith('uptizm.')));
    });
  });

  // ---------------------------------------------------------------------------
  // The soft prompt: a decline never reaches the platform (QA case 2)
  // ---------------------------------------------------------------------------

  group('the soft prompt', () {
    testWidgets('a decline does not call requestPermission', (tester) async {
      final _RecordingPushDriver driver = _RecordingPushDriver();
      usePushDriver(driver);

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      await tester.tap(find.text(trans('uptizm.push_prompt.not_now')));
      await tester.pumpAndSettle();

      // The OS prompt fires once per install; burning it on a decline leaves
      // the user with no way back to push at all.
      expect(driver.permissionRequests, 0);
    });

    testWidgets('a decline is recorded and leaves an explicit enable control', (
      tester,
    ) async {
      final _RecordingPushDriver driver = _RecordingPushDriver();
      usePushDriver(driver);

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      await tester.tap(find.text(trans('uptizm.push_prompt.not_now')));
      await tester.pumpAndSettle();

      expect(await Vault.get(PushPromptHost.declinedVaultKey), isNotNull);
      expect(find.text(trans('uptizm.push_prompt.not_now')), findsNothing);
      expect(find.text(trans('uptizm.push_prompt.enable')), findsOneWidget);
    });

    testWidgets('the explicit enable control does reach the platform', (
      tester,
    ) async {
      final _RecordingPushDriver driver = _RecordingPushDriver();
      usePushDriver(driver);

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      await tester.tap(find.text(trans('uptizm.push_prompt.enable')));
      await tester.pumpAndSettle();

      expect(driver.permissionRequests, 1);
    });
  });

  // ---------------------------------------------------------------------------
  // The reminder cadence: the host owns the decline TIMESTAMP
  // ---------------------------------------------------------------------------
  //
  // `magic_notifications` owns the POLICY (`pushPromptAdvice`) and deliberately
  // refuses to own the moment a decline happened, because that is the host's
  // own UI event. A boolean flag cannot express "declined 30 hours ago", so a
  // device that said no once was never asked again on an on-call product where
  // a device that cannot be paged is an outage nobody hears.

  group('the reminder cadence', () {
    /// The interval these cases drive, in hours. Deliberately not read from
    /// `notificationsConfig`: an assertion that took its expectation from the
    /// same value the code reads would pass for any shipped number, including
    /// the `0` that means never.
    const int repromptHours = 20;

    setUp(() {
      Config.set('notifications.push.reprompt_after_hours', repromptHours);
    });

    /// Records a decline [ago] before now, the way the host persists one.
    Future<void> declinedAgo(Duration ago) async {
      await Vault.put(
        PushPromptHost.declinedVaultKey,
        DateTime.now().toUtc().subtract(ago).toIso8601String(),
      );
    }

    testWidgets('a decline older than the interval is asked again', (
      tester,
    ) async {
      usePushDriver(_RecordingPushDriver());
      await declinedAgo(const Duration(hours: repromptHours + 1));

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      // The full soft prompt, decline control and all: the interval has
      // elapsed, so this device is due to be asked again rather than left with
      // the compact row a fresh decline leaves behind.
      expect(find.text(trans('uptizm.push_prompt.ask_title')), findsOneWidget);
      expect(find.text(trans('uptizm.push_prompt.not_now')), findsOneWidget);
    });

    testWidgets('a decline younger than the interval is not', (tester) async {
      usePushDriver(_RecordingPushDriver());
      await declinedAgo(const Duration(hours: repromptHours - 1));

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      // The other half, and the one that keeps the reminder from being a
      // nag: an operator who said no an hour ago is not asked again now.
      expect(find.text(trans('uptizm.push_prompt.ask_title')), findsNothing);
      expect(find.text(trans('uptizm.push_prompt.not_now')), findsNothing);
      expect(find.text(trans('uptizm.push_prompt.enable')), findsOneWidget);
    });

    testWidgets('a decline recorded by an older build is migrated, not read as '
        'never', (tester) async {
      // The bare `'1'` shipped builds wrote. It carries no time at all, so the
      // two wrong answers available are "never declined" (ask immediately,
      // undoing a decision the operator already made) and "declined at the
      // epoch" (which every interval has elapsed since, the same thing). It is
      // migrated to NOW instead.
      usePushDriver(_RecordingPushDriver());
      await Vault.put(PushPromptHost.declinedVaultKey, '1');

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      expect(find.text(trans('uptizm.push_prompt.ask_title')), findsNothing);
      expect(find.text(trans('uptizm.push_prompt.not_now')), findsNothing);
    });

    testWidgets('and the migrated decline is stamped, so the NEXT launch can '
        'age it', (tester) async {
      // Without the rewrite the flag stays unparseable forever, so every launch
      // migrates it to that launch's "now" and the reminder never comes back at
      // all: the exact defect the timestamp exists to remove, one level down.
      usePushDriver(_RecordingPushDriver());
      await Vault.put(PushPromptHost.declinedVaultKey, '1');

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      final String? stored = await Vault.get(PushPromptHost.declinedVaultKey);
      final DateTime? migrated = DateTime.tryParse(stored ?? '');

      expect(migrated, isNotNull);
      expect(
        DateTime.now().difference(migrated!).inMinutes.abs(),
        lessThan(1),
        reason: 'a legacy flag is stamped at the moment it is read',
      );
    });

    testWidgets('a fresh decline is recorded as a timestamp', (tester) async {
      usePushDriver(_RecordingPushDriver());

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      await tester.tap(find.text(trans('uptizm.push_prompt.not_now')));
      await tester.pumpAndSettle();

      final String? stored = await Vault.get(PushPromptHost.declinedVaultKey);

      expect(DateTime.tryParse(stored ?? ''), isNotNull);
    });
  });

  // ---------------------------------------------------------------------------
  // Two dropped futures on a boundary (QA defect 1)
  // ---------------------------------------------------------------------------

  group('a failing vault write on decline', () {
    testWidgets(
      'is handled, not an unhandled async error, and the decline is not '
      'reported as having worked',
      (tester) async {
        usePushDriver(_RecordingPushDriver());
        final _ThrowingPutVaultService throwingVault =
            _ThrowingPutVaultService();
        Magic.app.setInstance('vault', throwingVault);
        addTearDown(() => Magic.app.removeInstance('vault'));

        await tester.pumpWidget(wrap(const PushPromptHost()));
        await tester.pumpAndSettle();

        await tester.tap(find.text(trans('uptizm.push_prompt.not_now')));
        await tester.pumpAndSettle();

        // The throwing put() must not escape as an unhandled async error.
        expect(tester.takeException(), isNull);
        expect(throwingVault.putAttempts, 1);

        // The decline never landed, so the row must not claim it did: the
        // soft prompt (with its decline control) is still what is on screen,
        // not the resolved compact enable row.
        expect(find.text(trans('uptizm.push_prompt.not_now')), findsOneWidget);
        expect(find.text(trans('uptizm.push_prompt.ask_title')), findsOneWidget);
      },
    );
  });

  group('an initial reachability read that throws', () {
    testWidgets(
      'is handled, not an unhandled async error, and the row does not stay '
      'blank',
      (tester) async {
        usePushDriver(_ThrowingReachabilityPushDriver());

        await tester.pumpWidget(wrap(const PushPromptHost()));
        await tester.pumpAndSettle();

        // The throwing permissionState() must not escape as an unhandled
        // async error.
        expect(tester.takeException(), isNull);

        // A blank row is the bug this reproduces: the operator must see SOME
        // state, not the empty SizedBox the initial null reachability leaves
        // on screen when the read that would resolve it never completes.
        expect(find.byType(PushPrompt), findsOneWidget);
      },
    );
  });

  group('a failing requestPushPermission on enable', () {
    testWidgets('is handled and the row still refreshes', (tester) async {
      final _ThrowingRequestPushDriver driver = _ThrowingRequestPushDriver();
      usePushDriver(driver);

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      final int readsBeforeTap = driver.permissionStateReads;

      await tester.tap(find.text(trans('uptizm.push_prompt.enable')));
      await tester.pumpAndSettle();

      // The throw must not escape as an unhandled async error.
      expect(tester.takeException(), isNull);
      expect(driver.permissionRequests, 1);

      // The row must have re-read the platform after the throw (the `finally`
      // alone does not reach `_read()`; only falling all the way through the
      // catch does), proving it did not get stuck on the spinner.
      expect(driver.permissionStateReads, greaterThan(readsBeforeTap));
    });
  });

  // ---------------------------------------------------------------------------
  // A config key that gates the whole prompt (QA defect 2)
  // ---------------------------------------------------------------------------

  group('notifications.soft_prompt.enabled', () {
    testWidgets('false renders no prompt at all', (tester) async {
      Config.set('notifications.soft_prompt.enabled', false);
      usePushDriver(_RecordingPushDriver());

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      expect(find.byType(PushPrompt), findsNothing);
    });

    testWidgets('true (the default) still renders the prompt', (
      tester,
    ) async {
      Config.set('notifications.soft_prompt.enabled', true);
      usePushDriver(_RecordingPushDriver());

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      expect(find.byType(PushPrompt), findsOneWidget);
    });
  });

  // ---------------------------------------------------------------------------
  // The host reads reachability rather than declaring it
  // ---------------------------------------------------------------------------

  group('the host', () {
    testWidgets('renders the blocked row for a denied permission', (
      tester,
    ) async {
      usePushDriver(
        _RecordingPushDriver(permission: PushPermissionState.denied),
      );

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      expect(find.byKey(PushPrompt.blockedInstructionKey), findsOneWidget);
    });

    testWidgets('renders the on row for a subscribed device', (tester) async {
      usePushDriver(
        _RecordingPushDriver(
          permission: PushPermissionState.authorized,
          optedIn: true,
          subscriptionId: 'sub-1',
        ),
      );

      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      expect(find.text(trans('uptizm.push_prompt.on_body')), findsOneWidget);
      expect(find.byType(WButton), findsNothing);
    });

    testWidgets('renders the unavailable row when the build has no driver', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(const PushPromptHost()));
      await tester.pumpAndSettle();

      expect(
        find.text(trans('uptizm.push_prompt.unavailable_body')),
        findsOneWidget,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // The shell notice: push being off is visible OUTSIDE the settings screen
  // ---------------------------------------------------------------------------
  //
  // The soft prompt lives on the notification preferences screen, which is a
  // screen an on-call engineer opens roughly never. A device that cannot be
  // paged has to say so where they already are.

  group('the shell notice', () {
    testWidgets('warns while the permission has not been granted', (
      tester,
    ) async {
      usePushDriver(_RecordingPushDriver());

      await tester.pumpWidget(wrap(const PushOffNotice()));
      await tester.pumpAndSettle();

      expect(
        find.text(trans('uptizm.push_prompt.shell_notice')),
        findsOneWidget,
      );
    });

    testWidgets('warns while the permission is blocked', (tester) async {
      usePushDriver(
        _RecordingPushDriver(permission: PushPermissionState.denied),
      );

      await tester.pumpWidget(wrap(const PushOffNotice()));
      await tester.pumpAndSettle();

      expect(
        find.text(trans('uptizm.push_prompt.shell_notice')),
        findsOneWidget,
      );
    });

    testWidgets('says nothing when push can reach this device', (tester) async {
      usePushDriver(
        _RecordingPushDriver(
          permission: PushPermissionState.authorized,
          optedIn: true,
          subscriptionId: 'sub-1',
        ),
      );

      await tester.pumpWidget(wrap(const PushOffNotice()));
      await tester.pumpAndSettle();

      expect(find.text(trans('uptizm.push_prompt.shell_notice')), findsNothing);
    });

    testWidgets('says nothing when this build has no push at all', (
      tester,
    ) async {
      // Nothing an operator can act on: no driver means no permission to grant
      // and nowhere to send a tap. A permanent chip nobody can resolve is the
      // fastest way to train people to ignore the one that matters.
      await tester.pumpWidget(wrap(const PushOffNotice()));
      await tester.pumpAndSettle();

      expect(find.text(trans('uptizm.push_prompt.shell_notice')), findsNothing);
    });

    testWidgets('the compact form carries the same accessible name', (
      tester,
    ) async {
      // The mobile top bar has no room for the label, so the only name the
      // control has is the semantic one.
      final SemanticsHandle semantics = tester.ensureSemantics();
      usePushDriver(_RecordingPushDriver());

      await tester.pumpWidget(wrap(const PushOffNotice(compact: true)));
      await tester.pumpAndSettle();

      expect(find.text(trans('uptizm.push_prompt.shell_notice')), findsNothing);
      expect(
        find.bySemanticsLabel(trans('uptizm.a11y.push_off')),
        findsOneWidget,
      );

      semantics.dispose();
    });

    testWidgets('a tap opens the notification preferences screen', (
      tester,
    ) async {
      // Where the prompt with the real controls lives. A notice that only
      // states the problem leaves the operator to find the screen themselves.
      MagicRouter.reset();
      addTearDown(MagicRouter.reset);
      MagicRoute.page('/', () => const PushOffNotice());
      MagicRoute.page(
        MagicStarterConfig.notificationPreferencesRoute(),
        () => const SizedBox.shrink(),
      );
      usePushDriver(_RecordingPushDriver());

      await tester.pumpWidget(
        WindTheme(
          data: WindThemeData(),
          child: MaterialApp.router(
            routerConfig: MagicRouter.instance.routerConfig,
          ),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text(trans('uptizm.push_prompt.shell_notice')));
      await tester.pumpAndSettle();

      expect(
        MagicRouter.instance.currentPath,
        MagicStarterConfig.notificationPreferencesRoute(),
      );
    });
  });

  // ---------------------------------------------------------------------------
  // Every state, in both shipped locales
  // ---------------------------------------------------------------------------

  for (final String locale in <String>['en', 'tr']) {
    testWidgets('every state lays out at the shipped $locale copy', (
      tester,
    ) async {
      Translator.instance.setLoader(_BundledLangLoader(locale));
      await Translator.instance.setLocale(Locale(locale));

      for (final (PushReachability, PushPromptAction) state in _everyState) {
        await tester.pumpWidget(
          wrap(
            PushPrompt(
              reachability: state.$1,
              action: state.$2,
              onEnable: () async {},
            ),
          ),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
      }
    });
  }

  testWidgets('preview renders every state without error', (tester) async {
    await tester.pumpWidget(wrap(const PushPromptPreview()));
    await tester.pump();

    expect(tester.takeException(), isNull);
    // Six: the soft prompt, the compact enable a resolved ask leaves, the two
    // blocked rows (a control on mobile, an instruction on the web), on, and
    // unavailable.
    expect(find.byType(PushPrompt), findsNWidgets(6));
  });
}
