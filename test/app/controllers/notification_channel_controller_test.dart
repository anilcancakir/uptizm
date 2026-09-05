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
      'decodes pagerduty and teams rows with the correct masked shape',
      () async {
        Http.fake({
          'notification-channels': Http.response({
            'data': [
              {
                'id': 'nc3',
                'team_id': 't1',
                'name': 'PagerDuty',
                'channel_type': 'pagerduty',
                'is_enabled': true,
                'severity': 'critical',
                'credentials': {'has_routing_key': true},
              },
              {
                'id': 'nc4',
                'team_id': 't1',
                'name': 'Microsoft Teams',
                'channel_type': 'teams',
                'is_enabled': true,
                'severity': 'all',
                'credentials': {
                  'has_url': true,
                  'url_host': 'acme.webhook.office.com',
                },
              },
            ],
          }),
        });
        final NotificationChannelController controller =
            NotificationChannelController.instance;

        await controller.reload();

        expect(controller.channels, hasLength(2));
        final pagerduty = controller.channelOfType(ChannelType.pagerduty)!;
        expect(pagerduty.id, equals('nc3'));
        expect(pagerduty.hasCredentials, isTrue);
        // PagerDuty exposes only a presence boolean, never a display hint.
        expect(pagerduty.detail, isNull);

        final teams = controller.channelOfType(ChannelType.teams)!;
        expect(teams.id, equals('nc4'));
        expect(teams.hasCredentials, isTrue);
        expect(teams.detail, equals('acme.webhook.office.com'));
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

    test('pushProvisioned is optimistically true before any reload', () {
      final NotificationChannelController controller =
          NotificationChannelController.instance;

      expect(controller.pushProvisioned, isTrue);
    });

    test(
      'reload publishes meta.push_provisioned off the same index request',
      () async {
        final FakeNetworkDriver fake = Http.fake({
          'notification-channels': Http.response({
            'data': <dynamic>[],
            'meta': {'push_provisioned': false},
          }),
        });
        final NotificationChannelController controller =
            NotificationChannelController.instance;

        await controller.reload();

        expect(controller.pushProvisioned, isFalse);
        // The roster and the push flag ride ONE index request; no view may
        // fetch the flag a second time.
        fake.assertSentCount(1);
      },
    );

    test(
      'reload keeps the last known push flag when the payload carries no meta',
      () async {
        Http.fake({
          'notification-channels': Http.response({
            'data': <dynamic>[],
            'meta': {'push_provisioned': false},
          }),
        });
        final NotificationChannelController controller =
            NotificationChannelController.instance;
        await controller.reload();
        expect(controller.pushProvisioned, isFalse);

        // A payload without `meta` is a degradation, not a claim that push
        // became provisioned; the last known value has to survive it.
        Http.fake({
          'notification-channels': Http.response({'data': <dynamic>[]}),
        });
        await controller.reload();

        expect(controller.pushProvisioned, isFalse);
      },
    );

    test('reload notifies listeners once the index response lands', () async {
      Http.fake({
        'notification-channels': Http.response({
          'data': <dynamic>[],
          'meta': {'push_provisioned': false},
        }),
      });
      final NotificationChannelController controller =
          NotificationChannelController.instance;
      int notifications = 0;
      controller.addListener(() => notifications++);

      await controller.reload();

      expect(notifications, equals(1));
    });
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

      final bool ok = await controller.create({
        'name': 'Slack',
        'channel_type': 'slack',
        'credentials': {'token': 'xoxb-secret', 'channel': '#incidents'},
        'is_enabled': true,
        'severity': 'all',
      });

      expect(ok, isTrue);
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
      'maps a 422 credentials.token field error inline, namespaced under '
      'the channel type, and does not hydrate',
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

        final bool ok = await controller.create({
          'name': 'Slack',
          'channel_type': 'slack',
          'credentials': {'token': ''},
        });

        expect(ok, isFalse);
        expect(
          controller.validationErrors,
          equals({
            'slack.credentials.token':
                'The credentials.token field is required.',
          }),
        );
        expect(controller.channels, isEmpty);
      },
    );

    test(
      'returns false with no field errors on a non-field failure and does '
      'not hydrate',
      () async {
        Http.fake({
          'notification-channels': Http.response({
            'message': 'Server error',
          }, 500),
        });
        final NotificationChannelController controller =
            NotificationChannelController.instance;

        final bool ok = await controller.create({
          'name': 'Slack',
          'channel_type': 'slack',
        });

        expect(ok, isFalse);
        expect(controller.validationErrors, isEmpty);
        expect(controller.channels, isEmpty);
      },
    );

    test(
      'returns false with EMPTY validationErrors on a caught transport '
      'failure, distinct from a 422 which populates it',
      () async {
        // The branch the old `Future<Map<String, String>>` contract could not
        // express: both a caught exception and a successful write used to
        // `return const {}`, so the caller could not tell "nothing wrong,
        // already told" apart from "there is nothing to correct because it
        // actually worked". `Http.unfake()` with no driver bound throws
        // inside `create`'s own try/catch, exercising the `catch` branch
        // rather than the `!response.successful` one.
        Http.unfake();
        final NotificationChannelController controller =
            NotificationChannelController.instance;

        final bool ok = await controller.create({
          'name': 'Slack',
          'channel_type': 'slack',
        });

        expect(ok, isFalse);
        expect(controller.validationErrors, isEmpty);
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

      final bool ok = await controller.update('nc1', ChannelType.slack, {
        'is_enabled': false,
        'severity': 'critical',
      });

      expect(ok, isTrue);
      fake.assertSent(
        (r) =>
            r.method == 'PUT' && r.url == '/notification-channels/nc1',
      );
      expect(
        controller.channelOfType(ChannelType.slack)?.severity,
        equals('critical'),
      );
    });

    test(
      'maps a 422 credentials.url field error inline on a failed update, '
      'namespaced under the channel type',
      () async {
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

        final bool ok = await controller.update('nc2', ChannelType.webhook, {
          'credentials': {'url': 'not-a-url'},
        });

        expect(ok, isFalse);
        expect(
          controller.validationErrors,
          equals({
            'webhook.credentials.url':
                'The credentials.url field must be a valid URL.',
          }),
        );
      },
    );

    test(
      'a 422 from an enabled/severity-only update (no channel_type in the '
      'payload, matching the real view call sites) namespaces under the '
      'passed-in type, never falling back to slack',
      () async {
        Http.fake({
          'notification-channels/nc5': Http.response({
            'message': 'The severity field is invalid.',
            'errors': {
              'severity': ['The severity field is invalid.'],
            },
          }, 422),
        });
        final NotificationChannelController controller =
            NotificationChannelController.instance;

        // No `channel_type` key: `_setEnabled` and the severity `onChanged`
        // in `NotificationChannelsView` never send one, and the record's own
        // type is passed as [type] instead of being re-derived from [fields].
        final bool ok = await controller.update('nc5', ChannelType.pagerduty, {
          'severity': 'bogus',
        });

        expect(ok, isFalse);
        expect(
          controller.validationErrors,
          equals({'pagerduty.severity': 'The severity field is invalid.'}),
          reason: 'an update with no channel_type must namespace under the '
              "passed-in type, never the slack fallback",
        );
      },
    );
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

  // ---------------------------------------------------------------------------
  // resetForSession: clear the previous identity's roster + push flag, then
  // refetch.
  // ---------------------------------------------------------------------------

  group('resetForSession', () {
    test('clears the roster and re-optimizes the push flag on a failed refetch', () async {
      Http.fake({
        'notification-channels': Http.response({
          'data': [
            {
              'id': 'nc1',
              'channel_type': 'slack',
              'name': 'Slack',
              'is_enabled': true,
              'severity': 'all',
              'credentials': {'has_token': true, 'channel': '#alerts'},
            },
          ],
          'meta': {'push_provisioned': false},
        }),
      });
      final NotificationChannelController controller =
          NotificationChannelController.instance;
      await controller.reload();
      expect(controller.channels, hasLength(1));
      expect(controller.pushProvisioned, isFalse);

      // The new identity's refetch fails. `reload` alone keeps both values,
      // which would show the previous team's Slack wiring (and its channel
      // hint) to another team.
      Http.fake((r) => Http.response({'message': 'down'}, 500));
      var notifications = 0;
      controller.addListener(() => notifications++);

      await controller.resetForSession();

      expect(notifications, greaterThan(0));
      expect(controller.channels, isEmpty);
      expect(controller.channelOfType(ChannelType.slack), isNull);
      // Back to the optimistic pre-fetch default: a cleared state must not
      // claim push is unconfigured for the new team.
      expect(controller.pushProvisioned, isTrue);
    });

    test('refetches the roster of the new identity', () async {
      Http.fake({
        'notification-channels': Http.response({
          'data': [
            {
              'id': 'nc1',
              'channel_type': 'slack',
              'name': 'Slack',
              'is_enabled': true,
              'severity': 'all',
              'credentials': {'has_token': true, 'channel': '#alerts'},
            },
          ],
        }),
      });
      final NotificationChannelController controller =
          NotificationChannelController.instance;
      await controller.reload();

      Http.fake({
        'notification-channels': Http.response({
          'data': [
            {
              'id': 'nc9',
              'channel_type': 'webhook',
              'name': 'Webhook',
              'is_enabled': true,
              'severity': 'critical',
              'credentials': {'has_url': true, 'url_host': 'hooks.northwind.io'},
            },
          ],
        }),
      });

      await controller.resetForSession();

      expect(
        controller.channels.map((NotificationChannelRecord c) => c.id).toList(),
        equals(['nc9']),
      );
      expect(controller.channelOfType(ChannelType.slack), isNull);
      expect(controller.channelOfType(ChannelType.webhook)?.id, equals('nc9'));
    });
  });
}
