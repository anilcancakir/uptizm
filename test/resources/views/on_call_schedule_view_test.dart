import 'package:flutter/material.dart' hide Card;
import 'package:flutter/rendering.dart' show RenderParagraph;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/controllers/on_call_controller.dart';
import 'package:uptizm/resources/views/teams/on_call_schedule_view.dart';

/// In-memory loader feeding the on-call schedule prose so [trans] returns
/// short, wrappable strings instead of raw key tokens, mirroring the other
/// view tests (e.g. `monitors_list_view_test.dart`).
class _OnCallLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'uptizm.common.retry': 'Try again',
      'uptizm.teams.oncall_title': 'On-call schedule',
      'uptizm.teams.oncall_description': 'Who answers first.',
      'uptizm.status.editor_breadcrumb_back': 'Back',
      'uptizm.teams.oncall_override_button': 'Override',
      'uptizm.teams.oncall_override_label': 'Hand the pager to',
      'uptizm.teams.oncall_current_header': 'On call now',
      'uptizm.teams.oncall_rotation_header': 'Rotation',
      'uptizm.teams.oncall_add_button': '+ Add to rotation',
      'uptizm.teams.oncall_remove_button': 'Remove',
      'uptizm.teams.oncall_remove_confirm_title': 'Remove :name?',
      'uptizm.teams.oncall_remove_confirm_description': 'Confirm removal.',
      'uptizm.teams.oncall_remove_confirm_label': 'Remove',
      'uptizm.teams.oncall_escalation_reference': 'Configure escalation.',
      'uptizm.teams.on_call_error_title': 'Could not update the schedule',
      'uptizm.teams.on_call_error_description': 'Please try again.',
      'uptizm.teams.oncall_empty_title': 'No on-call schedule yet',
      'uptizm.teams.oncall_empty_description': 'Create one to page someone.',
      'uptizm.teams.oncall_create_button': 'Create schedule',
      'uptizm.teams.oncall_default_schedule_name': 'Primary rotation',
      'uptizm.teams.oncall_load_error_title': 'Could not load the schedule',
      'uptizm.teams.oncall_load_error_description': 'Nothing is shown.',
      'uptizm.teams.oncall_nobody_title': 'No one is on call',
      'uptizm.teams.oncall_nobody_description': 'Add a responder.',
      'uptizm.teams.oncall_rotation_empty_title': 'No responders',
      'uptizm.teams.oncall_rotation_empty_description': 'Add a member.',
      'uptizm.teams.oncall_shift_hours': ':hours h shift',
      'uptizm.teams.oncall_schedule_meta': ':name in :timezone',
      'uptizm.teams.oncall_min_responder_hint': 'Keep one responder.',
      'uptizm.teams.oncall_move_up_button': 'Move earlier',
      'uptizm.teams.oncall_move_down_button': 'Move later',
      'uptizm.teams.oncall_overrides_header': 'Overrides',
      'uptizm.teams.oncall_override_window': ':start to :end',
      'uptizm.teams.oncall_override_active_badge': 'Active',
      'uptizm.teams.oncall_override_until': 'Until :until',
      'uptizm.teams.oncall_override_remove_button': 'Lift',
      'uptizm.teams.oncall_override_remove_confirm_title': 'Lift for :name?',
      'uptizm.teams.oncall_override_remove_confirm_description': 'Back to ring.',
      'uptizm.teams.oncall_override_remove_confirm_label': 'Lift override',
    };
  }
}

/// One eager-loaded schedule row, in the index payload's shape.
Map<String, dynamic> _scheduleRow({
  List<Map<String, dynamic>> rotations = const [],
  List<Map<String, dynamic>> overrides = const [],
}) {
  return {
    'id': 'sched-1',
    'team_id': 'team-1',
    'name': 'Primary',
    'timezone': 'UTC',
    'rotations': [...rotations],
    'overrides': [...overrides],
  };
}

Map<String, dynamic> _rotationRow({
  required String id,
  required String userId,
  required String userName,
  int position = 0,
  int shiftHours = 24,
}) {
  return {
    'id': id,
    'user_id': userId,
    'user_name': userName,
    'position': position,
    'shift_hours': shiftHours,
  };
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so PageHeader/DropdownMenu/ConfirmDialog
    // resolve their themes via MagicStarter.* without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());
    Magic.singleton('log', () => LogManager());

    Translator.instance.setLoader(_OnCallLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  Widget wrap(Widget widget, {Size size = const Size(1280, 1600)}) {
    return MaterialApp(
      // A top-level `builder` wraps every route (including the confirm
      // dialogs, which mount through the root Navigator's Overlay, outside
      // `home`) in the same WindTheme, so their own WDiv widgets resolve a
      // theme too.
      builder: (context, child) => MediaQuery(
        data: MediaQueryData(size: size),
        child: WindTheme(data: WindThemeData(), child: child!),
      ),
      home: Scaffold(body: SingleChildScrollView(child: widget)),
    );
  }

  /// Seeds the real team roster the picker reads (the starter's team
  /// controller), exactly as `loadMembersAndInvitations` would publish it.
  void seedMembers(List<Map<String, dynamic>> members) {
    MagicStarterTeamController.instance.members.value = members;
  }

  // ---------------------------------------------------------------------------
  // Read path: everything on screen comes from the API
  // ---------------------------------------------------------------------------

  testWidgets('renders the schedule, the ring, and the resolved responder', (
    tester,
  ) async {
    Http.fake({
      'on-call/schedules': Http.response({
        'data': [
          _scheduleRow(
            rotations: [
              _rotationRow(
                id: 'rot-1',
                userId: 'u2',
                userName: 'Real Responder',
                shiftHours: 48,
              ),
              _rotationRow(
                id: 'rot-2',
                userId: 'u3',
                userName: 'Second Responder',
                position: 1,
              ),
            ],
          ),
        ],
      }, 200),
      'on-call/current': Http.response({
        'data': {
          'schedule_id': 'sched-1',
          'user': {'id': 'u2', 'name': 'Real Responder'},
        },
      }, 200),
    });
    await OnCallController.instance.reload();

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();

    expect(find.byType(MSPageContainer), findsOneWidget);
    // The hero name and the ring row both come from the API payload.
    expect(find.text('Real Responder'), findsWidgets);
    expect(find.text('Second Responder'), findsOneWidget);
    // The shift LENGTH the backend stores, never an invented wall-clock span.
    // Once in the hero card (why this person holds the pager) and once in
    // the ring row. The heading labels are uppercased by the `uppercase`
    // Wind utility, so they are matched in that form below.
    expect(find.text('48 h shift'), findsNWidgets(2));
    expect(find.text('Primary in UTC'), findsOneWidget);
    // Nothing from the deleted rotation fixture survives anywhere.
    expect(find.text('Mara Pohl'), findsNothing);
    expect(find.text('Ravi Shah'), findsNothing);
    expect(find.text('Ada Lovelace'), findsNothing);
  });

  testWidgets('renders the honest empty state when the team has no schedule', (
    tester,
  ) async {
    final fake = Http.fake({
      'on-call/schedules': Http.response({'data': []}, 200),
    });
    await OnCallController.instance.reload();

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();

    expect(find.byType(MSPageContainer), findsOneWidget);
    expect(find.text('No on-call schedule yet'), findsOneWidget);
    expect(find.text('Create schedule'), findsOneWidget);
    // No hero card, no rotation card, no "on call now" claim at all
    // (the hero label renders uppercased through the `uppercase` utility).
    expect(find.text('ON CALL NOW'), findsNothing);
    expect(find.text('Override'), findsNothing);
    fake.assertNotSent((r) => r.method == 'POST');
  });

  testWidgets('the empty state creates the schedule and re-reads', (
    tester,
  ) async {
    final List<Map<String, dynamic>> schedules = [];
    final fake = Http.fake((request) {
      final String path = request.url.startsWith('/')
          ? request.url.substring(1)
          : request.url;
      if (request.method == 'POST' && path == 'on-call/schedules') {
        schedules.add(_scheduleRow());
        return Http.response({'data': _scheduleRow()}, 201);
      }
      if (request.method == 'GET' && path == 'on-call/schedules') {
        return Http.response({'data': schedules}, 200);
      }
      if (request.method == 'GET' && path == 'on-call/current') {
        return Http.response({
          'data': {'schedule_id': 'sched-1', 'user': null},
        }, 200);
      }
      return Http.response({'message': 'Not found'}, 404);
    });
    await OnCallController.instance.reload();

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();
    await tester.tap(find.text('Create schedule'));
    await tester.pumpAndSettle();

    fake.assertSent(
      (r) => r.method == 'POST' && r.url.contains('on-call/schedules'),
    );
    // The created schedule genuinely has an empty ring and nobody on call.
    expect(find.text('No one is on call'), findsOneWidget);
    expect(find.text('No responders'), findsOneWidget);
  });

  testWidgets('reads honestly when the backend resolves nobody on call', (
    tester,
  ) async {
    Http.fake({
      'on-call/schedules': Http.response({
        'data': [_scheduleRow()],
      }, 200),
      'on-call/current': Http.response({
        'data': {'schedule_id': 'sched-1', 'user': null},
      }, 200),
    });
    await OnCallController.instance.reload();

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();

    expect(find.text('No one is on call'), findsOneWidget);
    expect(find.text('Add a responder.'), findsOneWidget);
    expect(find.text('No responders'), findsOneWidget);
  });

  /// Every avatar on this screen put its initials in the TOP-LEFT corner of the
  /// circle, half of them clipped by it, because the tile was spelled
  /// `grid ... place-items-center`. Wind implements `grid` and does NOT
  /// implement `place-items-*`, and an unknown token is a silent no-op there, so
  /// the box had no alignment at all. Measured at 1200px: the 56px hero circle
  /// rendered "DU" against its top-left edge.
  ///
  /// Asserted as a geometry relationship rather than a className, because the
  /// className is exactly what lied. Centring is font-independent: whatever the
  /// glyphs measure, a centred child shares its box's centre.
  testWidgets('the initials sit in the centre of their avatar circle', (
    tester,
  ) async {
    Http.fake({
      'on-call/schedules': Http.response({
        'data': [
          _scheduleRow(
            rotations: [
              _rotationRow(id: 'rot-1', userId: 'u2', userName: 'Real Responder'),
            ],
          ),
        ],
      }, 200),
      'on-call/current': Http.response({
        'data': {
          'schedule_id': 'sched-1',
          'user': {'id': 'u2', 'name': 'Real Responder'},
        },
      }, 200),
    });
    await OnCallController.instance.reload();

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();

    // Both avatars on screen carry the same initials (the hero at 56px and the
    // ring row at 36px), so the loop covers both sizes without naming either.
    final Finder initials = find.text('RR');
    expect(initials, findsWidgets);

    for (final Element element in initials.evaluate()) {
      final Finder text = find.byWidget(element.widget);

      // The GLYPHS, not the text widget's box. Both the box and the avatar
      // circle measure 56x56 here, so comparing those two centres is vacuous:
      // the text widget fills the circle and paints its glyphs wherever its
      // alignment says. `getBoxesForSelection` is where that shows up.
      final RenderParagraph paragraph = tester.renderObject<RenderParagraph>(
        text,
      );
      final List<TextBox> boxes = paragraph.getBoxesForSelection(
        const TextSelection(baseOffset: 0, extentOffset: 2),
      );
      expect(boxes, isNotEmpty, reason: 'the initials painted no glyphs');

      Rect glyphs = boxes.first.toRect();
      for (final TextBox box in boxes) {
        glyphs = glyphs.expandToInclude(box.toRect());
      }
      glyphs = glyphs.shift(tester.getRect(text).topLeft);

      final Rect circle = tester.getRect(
        find.ancestor(of: text, matching: find.byType(WDiv)).first,
      );

      expect(
        (glyphs.center.dx - circle.center.dx).abs(),
        lessThan(1.5),
        reason: 'initials are off-centre horizontally in their avatar',
      );
      expect(
        (glyphs.center.dy - circle.center.dy).abs(),
        lessThan(1.5),
        reason: 'initials are off-centre vertically in their avatar',
      );
    }
  });

/// One responder holding TWO slots is a real rota shape on a small team, and
  /// the badge used to match on the USER, so both of that person's rows claimed
  /// to be the current shift with two different shift lengths beside each other.
  /// The backend now names the slot (`data.rotation_id`) and exactly one row
  /// wears the badge.
  testWidgets('only the resolved slot is badged when one person holds two', (
    tester,
  ) async {
    Http.fake({
      'on-call/schedules': Http.response({
        'data': [
          _scheduleRow(
            rotations: [
              _rotationRow(
                id: 'rot-1',
                userId: 'u2',
                userName: 'Real Responder',
                shiftHours: 24,
              ),
              _rotationRow(
                id: 'rot-2',
                userId: 'u2',
                userName: 'Real Responder',
                position: 1,
                shiftHours: 8,
              ),
            ],
          ),
        ],
      }, 200),
      'on-call/current': Http.response({
        'data': {
          'schedule_id': 'sched-1',
          'rotation_id': 'rot-2',
          'user': {'id': 'u2', 'name': 'Real Responder'},
        },
      }, 200),
    });
    await OnCallController.instance.reload();

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();

    // Both slots are rendered, and they are the same person.
    expect(find.text('24 h shift'), findsWidgets);
    expect(find.text('8 h shift'), findsOneWidget);

    // The ring badge, exactly once. The hero card's own heading uses a different
    // key, so this finder cannot pick it up.
    expect(
      find.text(trans('uptizm.teams.oncall_current_header')),
      findsOneWidget,
    );
  });

  /// While an override holds the pager the ring is not on duty, so no row is
  /// badged: the backend answers `rotation_id: null` and the hero card above
  /// already names who has it.
  testWidgets('no ring row is badged while an override holds the pager', (
    tester,
  ) async {
    Http.fake({
      'on-call/schedules': Http.response({
        'data': [
          _scheduleRow(
            rotations: [
              _rotationRow(id: 'rot-1', userId: 'u2', userName: 'Real Responder'),
            ],
          ),
        ],
      }, 200),
      'on-call/current': Http.response({
        'data': {
          'schedule_id': 'sched-1',
          'rotation_id': null,
          'user': {'id': 'u9', 'name': 'Override Holder'},
        },
      }, 200),
    });
    await OnCallController.instance.reload();

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();

    expect(find.text('Override Holder'), findsWidgets);
    expect(find.text(trans('uptizm.teams.oncall_current_header')), findsNothing);
  });

  testWidgets('a failed read shows the error state, not an empty rotation', (
    tester,
  ) async {
    Http.fake({
      'on-call/schedules': Http.response({'message': 'boom'}, 500),
    });
    await OnCallController.instance.reload();

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();

    expect(find.text('Could not load the schedule'), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);
    expect(find.text('ON CALL NOW'), findsNothing);
    expect(find.text('No on-call schedule yet'), findsNothing);
  });

  // ---------------------------------------------------------------------------
  // Member picker: the real roster only
  // ---------------------------------------------------------------------------

  testWidgets('the picker lists real team members not already in the ring', (
    tester,
  ) async {
    Http.fake({
      'on-call/schedules': Http.response({
        'data': [
          _scheduleRow(
            rotations: [
              _rotationRow(id: 'rot-1', userId: 'u2', userName: 'In The Ring'),
            ],
          ),
        ],
      }, 200),
      'on-call/current': Http.response({
        'data': {
          'schedule_id': 'sched-1',
          'user': {'id': 'u2', 'name': 'In The Ring'},
        },
      }, 200),
    });
    await OnCallController.instance.reload();
    seedMembers([
      {'id': 'u2', 'name': 'In The Ring', 'email': 'ring@acme.test', 'role': 'admin'},
      {'id': 'u9', 'name': 'Free Agent', 'email': 'free@acme.test', 'role': 'member'},
    ]);

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();

    await tester.tap(find.text('+ Add to rotation'));
    await tester.pump();

    // Only the member who is NOT already a responder is offered.
    expect(find.text('Free Agent'), findsOneWidget);
    // "In The Ring" appears in the ring row and the hero, never in the picker.
    expect(find.text('In The Ring'), findsNWidgets(2));
  });

  // ---------------------------------------------------------------------------
  // Writes: each one hits the API and the view then shows the API's state
  // ---------------------------------------------------------------------------

  testWidgets('adding a member POSTs and the view shows the API ring', (
    tester,
  ) async {
    final List<Map<String, dynamic>> rotations = [];
    final fake = Http.fake((request) {
      final String path = request.url.startsWith('/')
          ? request.url.substring(1)
          : request.url;
      if (request.method == 'POST' &&
          path == 'on-call/schedules/sched-1/rotations') {
        rotations.add(
          _rotationRow(id: 'rot-9', userId: 'u9', userName: 'Server Agent'),
        );
        return Http.response({
          'data': _scheduleRow(rotations: rotations),
        }, 201);
      }
      if (request.method == 'GET' && path == 'on-call/schedules') {
        return Http.response({
          'data': [_scheduleRow(rotations: rotations)],
        }, 200);
      }
      if (request.method == 'GET' && path == 'on-call/current') {
        return Http.response({
          'data': {'schedule_id': 'sched-1', 'user': null},
        }, 200);
      }
      return Http.response({'message': 'Not found'}, 404);
    });
    await OnCallController.instance.reload();
    seedMembers([
      {'id': 'u9', 'name': 'Free Agent', 'email': 'free@acme.test', 'role': 'member'},
    ]);

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();
    // The rotation is empty here, so the add button sits under the taller
    // magic_starter empty state, past the 600px test surface. Scroll it in
    // before tapping (the same guard the incident and monitor view tests use).
    final Finder addButton = find.text('+ Add to rotation');
    await tester.ensureVisible(addButton);
    await tester.pump();
    await tester.tap(addButton);
    await tester.pump();
    await tester.tap(find.text('Free Agent'));
    await tester.pumpAndSettle();

    fake.assertSent(
      (r) =>
          r.method == 'POST' &&
          r.url == '/on-call/schedules/sched-1/rotations' &&
          (r.data as Map)['user_id'] == 'u9',
    );
    // The row carries the name the SERVER resolved, not the picker's label.
    expect(find.text('Server Agent'), findsOneWidget);
  });

  testWidgets('confirming Remove DELETEs the slot and the row disappears', (
    tester,
  ) async {
    final List<Map<String, dynamic>> rotations = [
      _rotationRow(id: 'rot-1', userId: 'u2', userName: 'First Responder'),
      _rotationRow(
        id: 'rot-2',
        userId: 'u3',
        userName: 'Second Responder',
        position: 1,
      ),
    ];
    final fake = Http.fake((request) {
      final String path = request.url.startsWith('/')
          ? request.url.substring(1)
          : request.url;
      if (request.method == 'DELETE' &&
          path == 'on-call/schedules/sched-1/rotations/rot-1') {
        rotations.removeWhere((row) => row['id'] == 'rot-1');
        return Http.response(null, 204);
      }
      if (request.method == 'GET' && path == 'on-call/schedules') {
        return Http.response({
          'data': [_scheduleRow(rotations: rotations)],
        }, 200);
      }
      if (request.method == 'GET' && path == 'on-call/current') {
        return Http.response({
          'data': {'schedule_id': 'sched-1', 'user': null},
        }, 200);
      }
      return Http.response({'message': 'Not found'}, 404);
    });
    await OnCallController.instance.reload();

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();

    expect(find.text('First Responder'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.delete_outline).first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Remove').last);
    await tester.pumpAndSettle();

    fake.assertSent(
      (r) =>
          r.method == 'DELETE' &&
          r.url == '/on-call/schedules/sched-1/rotations/rot-1',
    );
    expect(find.text('First Responder'), findsNothing);
    expect(find.text('Second Responder'), findsOneWidget);
  });

  testWidgets('moving a responder PUTs the reorder and the order follows', (
    tester,
  ) async {
    List<Map<String, dynamic>> rotations = [
      _rotationRow(id: 'rot-1', userId: 'u2', userName: 'First Responder'),
      _rotationRow(
        id: 'rot-2',
        userId: 'u3',
        userName: 'Second Responder',
        position: 1,
      ),
    ];
    final fake = Http.fake((request) {
      final String path = request.url.startsWith('/')
          ? request.url.substring(1)
          : request.url;
      if (request.method == 'PUT' &&
          path == 'on-call/schedules/sched-1/rotations/reorder') {
        final List<dynamic> order =
            (request.data as Map<String, dynamic>)['order'] as List;
        rotations = [
          for (final dynamic row in order)
            for (final Map<String, dynamic> slot in rotations)
              if (slot['id'] == (row as Map)['id'])
                {...slot, 'position': row['position']},
        ];
        return Http.response(null, 204);
      }
      if (request.method == 'GET' && path == 'on-call/schedules') {
        return Http.response({
          'data': [_scheduleRow(rotations: rotations)],
        }, 200);
      }
      if (request.method == 'GET' && path == 'on-call/current') {
        return Http.response({
          'data': {'schedule_id': 'sched-1', 'user': null},
        }, 200);
      }
      return Http.response({'message': 'Not found'}, 404);
    });
    await OnCallController.instance.reload();

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();

    // The first row's "move later" control swaps the two responders.
    await tester.tap(find.byIcon(Icons.arrow_downward).first);
    await tester.pumpAndSettle();

    fake.assertSent(
      (r) =>
          r.method == 'PUT' &&
          r.url == '/on-call/schedules/sched-1/rotations/reorder' &&
          ((r.data as Map)['order'] as List).first['id'] == 'rot-2',
    );
    expect(
      OnCallController.instance.rotation.map((s) => s.id).toList(),
      ['rot-2', 'rot-1'],
    );
  });

  testWidgets('overriding POSTs and the hero shows the re-read responder', (
    tester,
  ) async {
    final List<Map<String, dynamic>> overrides = [];
    Map<String, dynamic>? responder;
    final fake = Http.fake((request) {
      final String path = request.url.startsWith('/')
          ? request.url.substring(1)
          : request.url;
      if (request.method == 'POST' &&
          path == 'on-call/schedules/sched-1/overrides') {
        final Map<String, dynamic> body = request.data as Map<String, dynamic>;
        overrides.add({
          'id': 'ov-1',
          'user_id': body['user_id'],
          'user_name': 'Cover Person',
          'starts_at': body['starts_at'],
          'ends_at': body['ends_at'],
        });
        responder = {'id': 'u9', 'name': 'Cover Person'};
        return Http.response({
          'data': _scheduleRow(overrides: overrides),
        }, 201);
      }
      if (request.method == 'GET' && path == 'on-call/schedules') {
        return Http.response({
          'data': [_scheduleRow(overrides: overrides)],
        }, 200);
      }
      if (request.method == 'GET' && path == 'on-call/current') {
        return Http.response({
          'data': {'schedule_id': 'sched-1', 'user': responder},
        }, 200);
      }
      return Http.response({'message': 'Not found'}, 404);
    });
    await OnCallController.instance.reload();
    seedMembers([
      {'id': 'u9', 'name': 'Cover Person', 'email': 'cover@acme.test', 'role': 'member'},
    ]);

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();

    expect(find.text('No one is on call'), findsOneWidget);
    await tester.tap(find.text('Override'));
    await tester.pump();
    await tester.tap(find.text('Cover Person').first);
    await tester.pumpAndSettle();

    fake.assertSent(
      (r) =>
          r.method == 'POST' &&
          r.url == '/on-call/schedules/sched-1/overrides' &&
          (r.data as Map)['user_id'] == 'u9',
    );
    expect(find.text('No one is on call'), findsNothing);
    // Hero + overrides row, both from the post-write re-read.
    expect(find.text('Cover Person'), findsWidgets);
    expect(find.text('OVERRIDES'), findsOneWidget);
    expect(find.text('Active'), findsOneWidget);
  });

  testWidgets('a failed override write leaves the hero card alone', (
    tester,
  ) async {
    Http.fake((request) {
      final String path = request.url.startsWith('/')
          ? request.url.substring(1)
          : request.url;
      if (request.method == 'GET' && path == 'on-call/schedules') {
        return Http.response({
          'data': [
            _scheduleRow(
              rotations: [
                _rotationRow(
                  id: 'rot-1',
                  userId: 'u2',
                  userName: 'First Responder',
                ),
              ],
            ),
          ],
        }, 200);
      }
      if (request.method == 'GET' && path == 'on-call/current') {
        return Http.response({
          'data': {
            'schedule_id': 'sched-1',
            'user': {'id': 'u2', 'name': 'First Responder'},
          },
        }, 200);
      }
      return Http.response({'message': 'Validation failed'}, 422);
    });
    await OnCallController.instance.reload();
    seedMembers([
      {'id': 'u9', 'name': 'Cover Person', 'email': 'cover@acme.test', 'role': 'member'},
    ]);

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();

    await tester.tap(find.text('Override'));
    await tester.pump();
    await tester.tap(find.text('Cover Person').first);
    await tester.pumpAndSettle();

    // The ring's own responder still holds the pager: the failed write never
    // promoted anyone, and nothing was mutated locally to pretend otherwise.
    expect(find.text('First Responder'), findsWidgets);
    expect(find.text('OVERRIDES'), findsNothing);
  });

  testWidgets('lifting an override DELETEs it and the row disappears', (
    tester,
  ) async {
    List<Map<String, dynamic>> overrides = [
      {
        'id': 'ov-1',
        'user_id': 'u9',
        'user_name': 'Cover Person',
        'starts_at': '2026-07-12T00:00:00Z',
        'ends_at': '2026-07-13T00:00:00Z',
      },
    ];
    final fake = Http.fake((request) {
      final String path = request.url.startsWith('/')
          ? request.url.substring(1)
          : request.url;
      if (request.method == 'DELETE' &&
          path == 'on-call/schedules/sched-1/overrides/ov-1') {
        overrides = [];
        return Http.response(null, 204);
      }
      if (request.method == 'GET' && path == 'on-call/schedules') {
        return Http.response({
          'data': [_scheduleRow(overrides: overrides)],
        }, 200);
      }
      if (request.method == 'GET' && path == 'on-call/current') {
        return Http.response({
          'data': {'schedule_id': 'sched-1', 'user': null},
        }, 200);
      }
      return Http.response({'message': 'Not found'}, 404);
    });
    await OnCallController.instance.reload();

    // The overrides card sits below the fold of the default 800x600 test
    // surface, so the surface is grown to keep its Lift control hit-testable.
    const Size surface = Size(1280, 2000);
    await tester.binding.setSurfaceSize(surface);
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(wrap(const OnCallScheduleView(), size: surface));
    await tester.pump();

    expect(find.text('OVERRIDES'), findsOneWidget);
    await tester.tap(find.text('Lift'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Lift override'));
    await tester.pumpAndSettle();

    fake.assertSent(
      (r) =>
          r.method == 'DELETE' &&
          r.url == '/on-call/schedules/sched-1/overrides/ov-1',
    );
    expect(find.text('OVERRIDES'), findsNothing);
    expect(find.text('Cover Person'), findsNothing);
  });
}
