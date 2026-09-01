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
        PushReachability;
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
  });

  /// The permission the platform reports.
  final PushPermissionState permission;

  /// Whether this fake device is opted in.
  final bool optedIn;

  /// The subscription id the platform holds, or null for none.
  final String? subscriptionId;

  /// How many times [requestPermission] was called.
  int permissionRequests = 0;

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
            onEnable: null,
          ),
        ),
      );

      // A blocked permission cannot be re-prompted from inside the app: the
      // platform answers the next request without showing anything. A toggle
      // there is a control that does nothing, so the row says where the switch
      // actually lives instead.
      expect(find.byKey(PushPrompt.blockedInstructionKey), findsOneWidget);
      expect(find.byType(WButton), findsNothing);
      expect(find.text(trans('uptizm.push_prompt.enable')), findsNothing);
    });

    testWidgets('the instruction is real copy, not a raw key', (tester) async {
      await tester.pumpWidget(
        wrap(const PushPrompt(reachability: PushReachability.blocked)),
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
  // Every state, in both shipped locales
  // ---------------------------------------------------------------------------

  for (final String locale in <String>['en', 'tr']) {
    testWidgets('every state lays out at the shipped $locale copy', (
      tester,
    ) async {
      Translator.instance.setLoader(_BundledLangLoader(locale));
      await Translator.instance.setLocale(Locale(locale));

      for (final PushReachability reachability in PushReachability.values) {
        await tester.pumpWidget(
          wrap(PushPrompt(reachability: reachability, onEnable: () async {})),
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
    expect(
      find.byType(PushPrompt),
      findsNWidgets(PushReachability.values.length + 1),
    );
  });
}
