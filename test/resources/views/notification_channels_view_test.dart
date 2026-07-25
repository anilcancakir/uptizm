import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/controllers/notification_channel_controller.dart';
import 'package:uptizm/app/enums/channel_type.dart' show ChannelType;
import 'package:uptizm/resources/views/teams/notification_channels_view.dart';

/// In-memory language loader supplying every [trans] key the live
/// [NotificationChannelsView] exercises. Scoped to this test file so it does
/// not have to reuse or extend the shared `teams_views_test.dart` loader
/// (which still backs the now-obsolete mock-view assertions elsewhere).
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
      'uptizm.teams.channels_description': 'Team-level integrations.',
      'uptizm.teams.channels_slack_desc': 'Post alerts to Slack.',
      'uptizm.teams.channels_webhook_desc': 'POST alerts to an endpoint.',
      'uptizm.teams.channels_pagerduty_desc': 'Trigger PagerDuty incidents.',
      'uptizm.teams.channels_pagerduty_routing_key_label': 'Routing key',
      'uptizm.teams.channels_teams_desc': 'Post alerts to a Teams channel.',
      'uptizm.teams.channels_teams_webhook_label': 'Incoming webhook URL',
      'uptizm.teams.channels_teams_webhook_hint': 'Paste your Workflows URL.',
      'uptizm.teams.channels_severity_critical': 'Critical only',
      'uptizm.teams.channels_severity_all': 'All alerts',
      'uptizm.teams.channels_connect_button': 'Connect',
      'uptizm.teams.channels_save_button': 'Save',
      'uptizm.teams.channels_test_button': 'Send test',
      'uptizm.teams.channels_slack_channel_label': 'Channel',
      'uptizm.teams.channels_slack_channel_placeholder': '#incidents',
      'uptizm.teams.channels_webhook_url_label': 'Endpoint URL',
      'uptizm.teams.channels_webhook_secret_label': 'Signing secret',
      'uptizm.teams.channels_webhook_secret_hint': 'Sent as a header.',
      'uptizm.teams.channels_severity_label': 'Deliver',
      'uptizm.teams.channels_severity_hint': 'Which alerts this channel gets.',
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
}
