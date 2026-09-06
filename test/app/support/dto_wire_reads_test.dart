import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/app/support/billing_types.dart';
import 'package:uptizm/app/support/digest_types.dart';
import 'package:uptizm/app/support/incident_types.dart';
import 'package:uptizm/app/support/monitor_types.dart';
import 'package:uptizm/app/support/status_page_types.dart';
import 'package:uptizm/app/support/team_types.dart';

/// What these pin: the shared DTO decoders degrade a wrong-typed wire field to
/// their stated default instead of throwing.
///
/// These `fromMap` factories are reached from controller load paths, and those
/// paths mostly hold no try either, so a throw escaped as an unhandled future
/// and the surface below sat on its loading state forever rather than reaching
/// its error branch. That is a quieter failure than a crash, not a smaller one.
///
/// One case per decoder rather than one per field: the fields within a factory
/// share a single mechanism, so a second assertion on a sibling field re-tests
/// the helper rather than the decoder.
void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  test('PlanLimits reads an unusable allowance as null, not as a crash', () {
    final PlanLimits limits = PlanLimits.fromMap(<String, dynamic>{
      'monitors': 'unlimited',
      'check_interval_sec': 60,
      'white_label': 'yes',
    });

    // null is the shape this DTO already uses for "no ceiling", so an
    // unreadable allowance lands on the permissive side rather than gating a
    // paying team out of a feature it bought.
    expect(limits.monitors, isNull);
    expect(limits.checkIntervalSec, 60);
    // A non-bool is NOT truthy: an entitlement defaults off when unreadable.
    expect(limits.whiteLabel, isFalse);
  });

  test('Plan keeps the fields it can read beside one it cannot', () {
    final Plan plan = Plan.fromMap(<String, dynamic>{
      'id': 'pro',
      'name': 'Pro',
      'monthly': '29',
      'recommended': true,
    });

    expect(plan.id, 'pro');
    expect(plan.name, 'Pro');
    // A price is money: `intOrNull` does not parse a numeric string, because
    // inventing a figure the backend did not send is worse than showing none.
    expect(plan.monthly, isNull);
    expect(plan.recommended, isTrue);
  });

  test('CheckRow degrades one odd reading rather than the history table', () {
    final CheckRow row = CheckRow.fromMap(<String, dynamic>{
      'region': 'eu-west',
      'status': 'up',
      'response_ms': 'timeout',
      'status_code': 200,
    });

    expect(row.region, 'eu-west');
    expect(row.status, StatusKey.up);
    expect(row.responseMs, isNull);
    expect(row.statusCode, 200);
  });

  test('TimelineEntry survives a non-string status', () {
    final TimelineEntry entry = TimelineEntry.fromMap(<String, dynamic>{
      'actor': 'human',
      'status': 7,
      'message': 'Rolled back the deploy',
    });

    expect(entry.status, '');
    expect(entry.message, 'Rolled back the deploy');
  });

  test('WeeklyDigest reads a non-list highlights block as empty', () {
    final WeeklyDigest digest = WeeklyDigest.fromMap(<String, dynamic>{
      'summary': 'A quiet week.',
      'incident_count': 2,
      'highlights': <String, dynamic>{'unexpected': 'object'},
    });

    // Highlights are decoration; the digest is the number and the sentence.
    expect(digest.highlights, isEmpty);
    expect(digest.summary, 'A quiet week.');
    expect(digest.incidentCount, 2);
  });

  test('Subscriber reads a non-bool confirmed flag as unconfirmed', () {
    final Subscriber subscriber = Subscriber.fromMap(<String, dynamic>{
      'id': 's1',
      'email': 'ops@example.com',
      'confirmed': 1,
    });

    expect(subscriber.email, 'ops@example.com');
    // Defaulting to false is the safe side: an unconfirmed subscriber is not
    // sent to, and treating an unreadable flag as confirmed would mail someone
    // who never opted in.
    expect(subscriber.confirmed, isFalse);
  });

  test('OnCallRotationSlot reads an unusable position as zero', () {
    final OnCallRotationSlot slot = OnCallRotationSlot.fromMap(
      <String, dynamic>{
        'user_name': 9,
        'position': <int>[2],
        'shift_hours': 12,
      },
    );

    expect(slot.userName, isNull);
    expect(slot.position, 0);
    expect(slot.shiftHours, 12);
  });
}
