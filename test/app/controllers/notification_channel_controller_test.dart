import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/controllers/notification_channel_controller.dart';
import 'package:uptizm/app/enums/channel_type.dart' show ChannelType;

void main() {
  // The write actions' failure path surfaces a `Magic.error` toast, which
  // reads `MagicRouter.instance.navigatorKey.currentContext`; that getter
  // touches `WidgetsBinding.instance` even with no widget tree mounted, so a
  // plain `test()` needs the binding initialized once up front (mirrors
  // `monitor_metrics_controller_test.dart`).
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind LogManager so Log.error() works inside the write actions' failure
    // path (mirrors monitor_metrics_controller_test.dart).
    Magic.singleton('log', () => LogManager());
    // Bind a fake network driver; individual tests override it with
    // `Http.fake({...})` to seed a canned envelope, or a callback handler.
    Http.fake();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  test(
    'NotificationChannelController.instance registers and returns a singleton',
    () {
      final NotificationChannelController first =
          NotificationChannelController.instance;
      final NotificationChannelController second =
          NotificationChannelController.instance;

      expect(identical(first, second), isTrue);
    },
  );

  test('channels is empty before any reload', () {
    final NotificationChannelController controller =
        NotificationChannelController.instance;

    expect(controller.channels, isEmpty);
    expect(controller.channelOfType(ChannelType.slack), isNull);
  });

  // ---------------------------------------------------------------------------
  // reload: GET /notification-channels
  // ---------------------------------------------------------------------------

  group('reload', () {
    test(
      'decodes the roster from GET /notification-channels, masked credentials '
      'included',
      () async {
        Http.fake({
          'notification-channels': Http.response({
            'data': [
              {
                'id': 'nc1',
                'team_id': 't1',
                'name': 'Slack',
                'channel_type': 'slack',
                'is_enabled': true,
                'severity': 'all',
                'credentials': {'has_token': true, 'channel': '#incidents'},
              },
              {
                'id': 'nc2',
                'team_id': 't1',
                'name': 'Webhook',
                'channel_type': 'webhook',
                'is_enabled': false,
                'severity': 'critical',
                'credentials': {
                  'has_url': true,
                  'url_host': 'hooks.acme.dev',
                  'has_secret': true,
                },
              },
            ],
          }),
        });
        final NotificationChannelController controller =
            NotificationChannelController.instance;

        await controller.reload();

        expect(controller.channels, hasLength(2));
        final slack = controller.channelOfType(ChannelType.slack)!;
        expect(slack.id, equals('nc1'));
        expect(slack.isEnabled, isTrue);
        expect(slack.severity, equals('all'));
        expect(slack.hasCredentials, isTrue);
        expect(slack.detail, equals('#incidents'));

        final webhook = controller.channelOfType(ChannelType.webhook)!;
        expect(webhook.id, equals('nc2'));
        expect(webhook.isEnabled, isFalse);
        expect(webhook.severity, equals('critical'));
        expect(webhook.detail, equals('hooks.acme.dev'));
      },
    );

    test(
      'reload degrades to the last-known-good roster when the network is '
      'unavailable',
      () async {
        Http.unfake();
        final NotificationChannelController controller =
            NotificationChannelController.instance;

        await controller.reload();

        expect(controller.channels, isEmpty);
      },
    );
  });

  // ---------------------------------------------------------------------------
  // create: POST /notification-channels
  // ---------------------------------------------------------------------------

  group('create', () {
    test('posts the fields and reloads/hydrates on success', () async {
      final FakeNetworkDriver fake = Http.fake((request) {
        if (request.method == 'POST') {
          return Http.response({
            'data': {
              'id': 'nc1',
              'channel_type': 'slack',
              'name': 'Slack',
              'is_enabled': true,
              'severity': 'all',
              'credentials': {'has_token': true, 'channel': '#incidents'},
            },
          }, 201);
        }
        return Http.response({
          'data': [
            {
              'id': 'nc1',
              'channel_type': 'slack',
              'name': 'Slack',
              'is_enabled': true,
              'severity': 'all',
              'credentials': {'has_token': true, 'channel': '#incidents'},
            },
          ],
        });
      });
      final NotificationChannelController controller =
          NotificationChannelController.instance;

      final Map<String, String> result = await controller.create({
        'name': 'Slack',
        'channel_type': 'slack',
        'credentials': {'token': 'xoxb-secret', 'channel': '#incidents'},
        'is_enabled': true,
        'severity': 'all',
      });

      expect(result, isEmpty);
      fake.assertSent(
        (r) => r.method == 'POST' && r.url == '/notification-channels',
      );
      final Map<String, dynamic> payload = fake.recorded
          .firstWhere((entry) => entry.$1.method == 'POST')
          .$1
          .data as Map<String, dynamic>;
      expect(payload['channel_type'], equals('slack'));
      expect(
        (payload['credentials'] as Map)['token'],
        equals('xoxb-secret'),
      );
      expect(controller.channelOfType(ChannelType.slack)?.id, equals('nc1'));
    });

    test(
      'maps a 422 credentials.token field error inline and does not hydrate',
      () async {
        Http.fake({
          'notification-channels': Http.response({
            'message': 'The credentials.token field is required.',
            'errors': {
              'credentials.token': [
                'The credentials.token field is required.',
              ],
            },
          }, 422),
        });
        final NotificationChannelController controller =
            NotificationChannelController.instance;

        final Map<String, String> result = await controller.create({
          'name': 'Slack',
          'channel_type': 'slack',
          'credentials': {'token': ''},
        });

        expect(
          result,
          equals({
            'credentials.token': 'The credentials.token field is required.',
          }),
        );
        expect(controller.channels, isEmpty);
      },
    );

    test(
      'returns an empty map on a non-field failure and does not hydrate',
      () async {
        Http.fake({
          'notification-channels': Http.response({
            'message': 'Server error',
          }, 500),
        });
        final NotificationChannelController controller =
            NotificationChannelController.instance;

        final Map<String, String> result = await controller.create({
          'name': 'Slack',
          'channel_type': 'slack',
        });

        expect(result, isEmpty);
        expect(controller.channels, isEmpty);
      },
    );
  });

  // ---------------------------------------------------------------------------
  // update: PUT /notification-channels/:id
  // ---------------------------------------------------------------------------

  group('update', () {
    test('puts the fields and reloads on success', () async {
      final FakeNetworkDriver fake = Http.fake((request) {
        if (request.method == 'PUT' &&
            request.url == '/notification-channels/nc1') {
          return Http.response({'data': {}});
        }
        return Http.response({
          'data': [
            {
              'id': 'nc1',
              'channel_type': 'slack',
              'name': 'Slack',
              'is_enabled': false,
              'severity': 'critical',
              'credentials': {'has_token': true},
            },
          ],
        });
      });
      final NotificationChannelController controller =
          NotificationChannelController.instance;

      final Map<String, String> result = await controller.update('nc1', {
        'is_enabled': false,
        'severity': 'critical',
      });

      expect(result, isEmpty);
      fake.assertSent(
        (r) =>
            r.method == 'PUT' && r.url == '/notification-channels/nc1',
      );
      expect(
        controller.channelOfType(ChannelType.slack)?.severity,
        equals('critical'),
      );
    });

    test('maps a 422 credentials.url field error inline on a failed update', () async {
      Http.fake({
        'notification-channels/nc2': Http.response({
          'message': 'The credentials.url field must be a valid URL.',
          'errors': {
            'credentials.url': [
              'The credentials.url field must be a valid URL.',
            ],
          },
        }, 422),
      });
      final NotificationChannelController controller =
          NotificationChannelController.instance;

      final Map<String, String> result = await controller.update('nc2', {
        'credentials': {'url': 'not-a-url'},
      });

      expect(
        result,
        equals({
          'credentials.url': 'The credentials.url field must be a valid URL.',
        }),
      );
    });
  });

  // ---------------------------------------------------------------------------
  // delete: DELETE /notification-channels/:id
  // ---------------------------------------------------------------------------

  group('delete', () {
    test('deletes and reloads on success', () async {
      final FakeNetworkDriver fake = Http.fake((request) {
        if (request.method == 'DELETE') {
          return Http.response(null, 204);
        }
        return Http.response({'data': []});
      });
      final NotificationChannelController controller =
          NotificationChannelController.instance;

      final bool ok = await controller.delete('nc1');

      expect(ok, isTrue);
      fake.assertSent(
        (r) =>
            r.method == 'DELETE' &&
            r.url == '/notification-channels/nc1',
      );
    });

    test('returns false on a failed delete', () async {
      Http.fake({
        'notification-channels/nc1': Http.response({'message': 'Nope'}, 404),
      });
      final NotificationChannelController controller =
          NotificationChannelController.instance;

      final bool ok = await controller.delete('nc1');

      expect(ok, isFalse);
    });
  });

  // ---------------------------------------------------------------------------
  // sendTest: POST /notification-channels/:id/test
  // ---------------------------------------------------------------------------

  group('sendTest', () {
    test('returns true when the backend reports delivered:true', () async {
      Http.fake({
        'notification-channels/nc1/test': Http.response({
          'data': {'delivered': true},
        }),
      });
      final NotificationChannelController controller =
          NotificationChannelController.instance;

      final bool ok = await controller.sendTest('nc1');

      expect(ok, isTrue);
    });

    test(
      'returns false (honest failure) when the backend reports a 502 '
      'delivered:false',
      () async {
        Http.fake({
          'notification-channels/nc1/test': Http.response({
            'data': {'delivered': false},
          }, 502),
        });
        final NotificationChannelController controller =
            NotificationChannelController.instance;

        final bool ok = await controller.sendTest('nc1');

        expect(ok, isFalse);
      },
    );

    test(
      'returns false when the response is 2xx but delivered is not true',
      () async {
        Http.fake({
          'notification-channels/nc1/test': Http.response({
            'data': {'delivered': false},
          }),
        });
        final NotificationChannelController controller =
            NotificationChannelController.instance;

        final bool ok = await controller.sendTest('nc1');

        expect(ok, isFalse);
      },
    );
  });
}
