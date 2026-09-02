import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/controllers/notification_channel_controller.dart';
import 'package:uptizm/app/enums/channel_type.dart' show ChannelType;
import 'package:uptizm/resources/views/teams/notification_channels_view.dart';

import '../../support/skeleton_matchers.dart';

/// In-memory language loader supplying every [trans] key the live
/// [NotificationChannelsView] exercises. Scoped to this test file so it does
/// not have to reuse or extend the shared `teams_views_test.dart` loader
/// (which still backs the now-obsolete mock-view assertions elsewhere).
///
/// Every value below is the VERBATIM `assets/lang/en.json` copy, not a
/// shortened stand-in: the phone-width group measures real layout pressure, so
/// a paraphrased (shorter) string would silently weaken that gate the same way
/// a missing loader would exaggerate it by rendering raw i18n keys.
class _NotificationChannelsLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'validation.required': 'The :attribute field is required.',
      'common.error_occurred': 'An unexpected error occurred.',
      'notifications.channel_push_unconfigured': 'Push not yet configured',
      'uptizm.enums.channel_type.slack': 'Slack',
      'uptizm.enums.channel_type.webhook': 'Webhook',
      'uptizm.enums.channel_type.pagerduty': 'PagerDuty',
      'uptizm.enums.channel_type.teams': 'Microsoft Teams',
      'uptizm.teams.channels_title': 'Notification channels',
      'uptizm.teams.channels_description':
          'Team-level integrations your alerts route to. Which monitors alert '
          'is set per monitor.',
      'uptizm.teams.channels_slack_desc': 'Post alerts to a Slack channel.',
      'uptizm.teams.channels_webhook_desc':
          'POST alerts to your own HTTP endpoint.',
      'uptizm.teams.channels_pagerduty_desc':
          'Trigger and resolve PagerDuty incidents.',
      'uptizm.teams.channels_pagerduty_routing_key_label': 'Routing key',
      'uptizm.teams.channels_teams_desc':
          'Post alerts to a Microsoft Teams channel.',
      'uptizm.teams.channels_teams_webhook_label': 'Incoming webhook URL',
      'uptizm.teams.channels_teams_webhook_hint':
          'Create a Workflows incoming webhook in Teams and paste its URL.',
      'uptizm.teams.channels_teams_webhook_placeholder':
          'https://prod-00.westus.logic.azure.com/workflows/...',
      'uptizm.teams.channels_severity_critical': 'Critical only',
      'uptizm.teams.channels_severity_all': 'All alerts',
      'uptizm.teams.channels_connect_button': 'Connect',
      'uptizm.teams.channels_save_button': 'Save',
      'uptizm.teams.channels_test_button': 'Send test',
      'uptizm.teams.channels_slack_channel_label': 'Channel',
      'uptizm.teams.channels_slack_channel_placeholder': '#incidents',
      'uptizm.teams.channels_webhook_url_label': 'Endpoint URL',
      'uptizm.teams.channels_webhook_secret_label': 'Signing secret',
      'uptizm.teams.channels_webhook_secret_hint':
          'Sent as the X-Uptizm-Signature header.',
      'uptizm.teams.channels_severity_label': 'Deliver',
      'uptizm.teams.channels_severity_hint':
          'Which alerts this channel receives.',
      'uptizm.teams.channels_slack_token_label': 'Bot token',
      'uptizm.teams.channels_slack_token_placeholder': 'xoxb-...',
      'uptizm.teams.channels_webhook_url_placeholder': 'https://...',
    };
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card/Button/Input/Switch/Badge/
    // PageHeader resolve their themes without a full app boot (mirrors
    // `teams_views_test.dart`).
    Magic.singleton('magic_starter', () => MagicStarterManager());
    // Bind LogManager so Log.error() works inside the controller's write
    // failure path (mirrors `monitor_metrics_controller_test.dart`).
    Magic.singleton('log', () => LogManager());
    // Bind a fake network driver so the controller's wired reload/create/
    // update actions resolve the `network` service.
    Http.fake();

    Translator.instance.setLoader(_NotificationChannelsLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme] under a
  /// configurable [MediaQuery] size, mirroring the harness established in
  /// `teams_views_test.dart`.
  Widget wrap(Widget widget, {Size size = const Size(1280, 4000)}) {
    return MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(size: size),
        child: WindTheme(
          data: WindThemeData(),
          child: Scaffold(body: SingleChildScrollView(child: widget)),
        ),
      ),
    );
  }

  testWidgets('renders the title and a row for all four team channel types', (
    tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(1280, 4000));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(const NotificationChannelsView()));
    await tester.pump();

    expect(tester.takeException(), isNull);
    expect(find.text('Notification channels'), findsOneWidget);
    expect(find.text('Slack'), findsOneWidget);
    expect(find.text('Webhook'), findsOneWidget);
    expect(find.text('PagerDuty'), findsOneWidget);
    expect(find.text('Microsoft Teams'), findsOneWidget);
    // Email/SMS stay per-user preferences, never team channels.
    expect(find.text('Email'), findsNothing);
    expect(find.text('SMS'), findsNothing);
  });

  testWidgets('shows a skeleton before the first read resolves, not four '
      'unconfigured rows', (tester) async {
    // The regression this pins: every row decides between "Connect" and a live
    // switch purely on whether the roster holds a record for its type, so a
    // pending read rendered four Connect buttons and told a team with Slack
    // already wired that it had no integrations at all.
    await tester.binding.setSurfaceSize(const Size(1280, 4000));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    // Deliberately NOT pumped again: the first frame is painted before the
    // initState fetch resolves, which is exactly the moment the operator used to
    // be told nothing was connected.
    await tester.pumpWidget(wrap(const NotificationChannelsView()));

    expect(find.byType(MSSkeleton), findsWidgets);
    expectVisibleSkeletons(tester);
    expect(
      find.text('Connect'),
      findsNothing,
      reason: 'a pending read must never claim a channel is unconfigured',
    );

    // Once it resolves (the fake answers nothing), the skeleton gives way to the
    // honest four unconnected rows.
    await tester.pump();
    expect(find.byType(MSSkeleton), findsNothing);
    expect(find.text('Connect'), findsNWidgets(4));
  });

  testWidgets('a resolved empty roster shows the connect rows, not a skeleton', (
    tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(1280, 4000));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    // Seeding is a resolved state, so an empty seed is a known-empty roster.
    NotificationChannelController.instance.seedForTest(const []);

    await tester.pumpWidget(wrap(const NotificationChannelsView()));
    await tester.pump();

    expect(find.byType(MSSkeleton), findsNothing);
    expect(find.text('Connect'), findsNWidgets(4));
  });

  testWidgets(
    'connecting PagerDuty reveals a routing key field and Teams a url field',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const NotificationChannelsView()));
      await tester.pump();

      // Connect buttons render in _types order: slack, webhook, pagerduty,
      // teams. Index 2 is PagerDuty, index 3 is Teams.
      await tester.tap(find.text('Connect').at(2));
      await tester.pump();
      expect(find.text('Routing key'), findsOneWidget);

      await tester.tap(find.text('Connect').at(3));
      await tester.pump();
      expect(find.text('Incoming webhook URL'), findsOneWidget);
    },
  );

  testWidgets(
    'connecting Slack and saving with an empty token shows an inline '
    'required error and does not call the backend',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final FakeNetworkDriver fake = Http.fake();

      await tester.pumpWidget(wrap(const NotificationChannelsView()));
      await tester.pump();

      await tester.tap(find.text('Connect').first);
      await tester.pump();
      await tester.tap(find.text('Save').first);
      await tester.pump();

      expect(find.text('The Bot token field is required.'), findsOneWidget);
      expect(fake.recorded.any((entry) => entry.$1.method == 'POST'), isFalse);
    },
  );

  testWidgets(
    'connecting the webhook with a URL but no secret is blocked client-side',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // The regression: the secret has a hint and no required marker, the
      // client check covered only the URL, and the backend declares it
      // `required_if:channel_type,webhook`. So Save posted a body without it,
      // took a 422 keyed `credentials.secret`, and the form had no slot for
      // that key and no unmapped fallback: no toast, no inline error, no state
      // change. The operator tapped Save on a form that never reacted.
      final FakeNetworkDriver fake = Http.fake();

      await tester.pumpWidget(wrap(const NotificationChannelsView()));
      await tester.pump();

      await tester.tap(find.text('Connect').at(1));
      await tester.pump();
      await tester.enterText(
        find.byType(EditableText).first,
        'https://hooks.example.com/uptizm',
      );
      await tester.pump();
      await tester.tap(find.text('Save'));
      await tester.pump();

      expect(
        find.text('The Signing secret field is required.'),
        findsOneWidget,
      );
      expect(
        fake.recorded.any((entry) => entry.$1.method == 'POST'),
        isFalse,
        reason: 'a request the backend will certainly reject is not worth '
            'making',
      );
    },
  );

  testWidgets(
    'a server 422 on the signing secret paints its inline slot',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // The other half of the same defect: even once a request is made, the
      // form mapped only token/url/routing_key, so a 422 keyed
      // `credentials.secret` was assigned nowhere and `create` raises no toast
      // of its own. Save produced no feedback at all.
      //
      // Reached by bypassing the client check with a secret the SERVER rejects
      // (too long for its `max:255`), which is the shape of every 422 that can
      // still arrive once the client-side guard above is satisfied.
      Http.fake({
        'notification-channels': Http.response({
          'message': 'The given data was invalid.',
          'errors': {
            'credentials.secret': [
              'The signing secret may not be greater than 255 characters.',
            ],
          },
        }, 422),
      });

      await tester.pumpWidget(wrap(const NotificationChannelsView()));
      await tester.pump();

      await tester.tap(find.text('Connect').at(1));
      await tester.pump();
      await tester.enterText(
        find.byType(EditableText).first,
        'https://hooks.example.com/uptizm',
      );
      await tester.pump();
      await tester.enterText(find.byType(EditableText).at(1), 'too-long');
      await tester.pump();
      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();

      expect(
        find.text(
          'The signing secret may not be greater than 255 characters.',
        ),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'connecting the webhook and saving with an empty URL shows an inline '
    'required error',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      Http.fake();

      await tester.pumpWidget(wrap(const NotificationChannelsView()));
      await tester.pump();

      // Index 1 is the webhook row (order: slack, webhook, pagerduty, teams).
      await tester.tap(find.text('Connect').at(1));
      await tester.pump();
      await tester.tap(find.text('Save'));
      await tester.pump();

      expect(
        find.text('The Endpoint URL field is required.'),
        findsOneWidget,
      );
    },
  );

  testWidgets(
    'shows the localized push-not-provisioned hint when the backend reports '
    'app_id empty, off the controller index request alone',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final FakeNetworkDriver fake = Http.fake().stub(
        '/notification-channels',
        MagicResponse(
          data: <String, dynamic>{
            'data': <dynamic>[],
            'meta': <String, dynamic>{'push_provisioned': false},
          },
          statusCode: 200,
        ),
      );

      await tester.pumpWidget(wrap(const NotificationChannelsView()));
      await tester.pump();
      await tester.pump();

      expect(find.text('Push not yet configured'), findsOneWidget);
      expect(
        NotificationChannelController.instance.pushProvisioned,
        isFalse,
      );
      // The view reads the flag off the controller; it must never fetch the
      // index a second time of its own.
      fake.assertSentCount(1);
    },
  );

  testWidgets(
    'hides the push-not-provisioned hint when push is provisioned',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      Http.fake().stub(
        '/notification-channels',
        MagicResponse(
          data: <String, dynamic>{
            'data': <dynamic>[],
            'meta': <String, dynamic>{'push_provisioned': true},
          },
          statusCode: 200,
        ),
      );

      await tester.pumpWidget(wrap(const NotificationChannelsView()));
      await tester.pump();
      await tester.pump();

      expect(find.text('Push not yet configured'), findsNothing);
      expect(NotificationChannelController.instance.pushProvisioned, isTrue);
    },
  );

  testWidgets(
    'a connected channel renders its severity badge and an enabled switch, '
    'and Send test is offered',
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      Http.fake();
      NotificationChannelController.instance.seedForTest([
        const NotificationChannelRecord(
          id: 'nc1',
          type: ChannelType.slack,
          name: 'Slack',
          isEnabled: true,
          severity: 'critical',
          hasCredentials: true,
          detail: '#incidents',
        ),
      ]);

      await tester.pumpWidget(wrap(const NotificationChannelsView()));
      await tester.pump();

      expect(find.text('Critical only'), findsOneWidget);
      expect(find.text('#incidents'), findsOneWidget);
      // Webhook, PagerDuty, and Teams remain unconnected and still offer it.
      expect(find.text('Connect'), findsNWidgets(3));

      await tester.tap(find.text('Slack'));
      await tester.pump();

      expect(find.text('Send test'), findsOneWidget);
    },
  );

  testWidgets(
    "a connected channel's enable switch is named after the channel",
    (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      Http.fake();
      NotificationChannelController.instance.seedForTest([
        const NotificationChannelRecord(
          id: 'nc1',
          type: ChannelType.slack,
          name: 'Slack',
          isEnabled: true,
          severity: 'all',
          hasCredentials: true,
          detail: '#incidents',
        ),
      ]);

      await tester.pumpWidget(wrap(const NotificationChannelsView()));
      await tester.pump();

      // Read the property rather than `find.bySemanticsLabel`: the channel name
      // also renders as a sibling WText, so a label lookup passes even when the
      // switch itself is anonymous, which is the exact defect this pins.
      final MSSwitch toggle = tester.widget<MSSwitch>(find.byType(MSSwitch));

      expect(toggle.semanticLabel, 'Slack');
    },
  );

  /// The phone-width regression gate: this view used to blow past a 390px
  /// viewport, which no desktop-sized test could catch. Two flexes overflowed
  /// (the push heads-up row, and the severity segmented control inside the
  /// inline config form) because neither had a shrinkable child, so every case
  /// below pumps at the narrowest supported phone width and asserts on the
  /// absence of a `RenderFlex overflowed` layout exception.
  group('at a 390px phone width', () {
    const Size phone = Size(390, 1600);

    /// Pumps the view at [phone] with the index request stubbed to report
    /// [pushProvisioned] and hydrate [channels], so both the heads-up above the
    /// card and the connected/unconnected row shape are deterministic.
    Future<void> pumpAtPhoneWidth(
      WidgetTester tester, {
      required bool pushProvisioned,
      List<Map<String, dynamic>> channels = const [],
    }) async {
      await tester.binding.setSurfaceSize(phone);
      addTearDown(() => tester.binding.setSurfaceSize(null));

      Http.fake().stub(
        '/notification-channels',
        MagicResponse(
          data: <String, dynamic>{
            'data': channels,
            'meta': <String, dynamic>{'push_provisioned': pushProvisioned},
          },
          statusCode: 200,
        ),
      );

      await tester.pumpWidget(
        wrap(const NotificationChannelsView(), size: phone),
      );
      await tester.pump();
      await tester.pump();
    }

    testWidgets('renders every channel row without overflow, hint visible', (
      tester,
    ) async {
      await pumpAtPhoneWidth(tester, pushProvisioned: false);

      expect(find.text('Push not yet configured'), findsOneWidget);
      expect(find.text('Microsoft Teams'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('renders every channel row without overflow, hint hidden', (
      tester,
    ) async {
      await pumpAtPhoneWidth(tester, pushProvisioned: true);

      expect(find.text('Push not yet configured'), findsNothing);
      expect(find.text('Microsoft Teams'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets(
      'renders a connected row with a long detail line without overflow',
      (tester) async {
        await pumpAtPhoneWidth(
          tester,
          pushProvisioned: true,
          channels: [
            <String, dynamic>{
              'id': 'nc1',
              'channel_type': 'teams',
              'name': 'Microsoft Teams',
              'is_enabled': true,
              'severity': 'critical',
              'credentials': <String, dynamic>{
                'has_url': true,
                'url_host':
                    'prod-00.westus.logic.azure.com/workflows/'
                    '4f2c9a1b8d3e4f5a6b7c8d9e0f1a2b3c/triggers/manual/invoke',
              },
            },
          ],
        );

        expect(find.text('Critical only'), findsOneWidget);
        expect(tester.takeException(), isNull);
      },
    );

    testWidgets('renders the inline config form without overflow', (
      tester,
    ) async {
      await pumpAtPhoneWidth(tester, pushProvisioned: true);

      await tester.tap(find.text('Connect').first);
      await tester.pump();

      expect(find.text('Bot token'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}
