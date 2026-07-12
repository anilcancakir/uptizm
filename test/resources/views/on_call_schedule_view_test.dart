import 'package:flutter/material.dart' hide Card;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/controllers/on_call_controller.dart';
import 'package:uptizm/app/mocks/teams_data.dart';
import 'package:uptizm/resources/views/teams/on_call_schedule_view.dart';
import 'package:uptizm/ui/layouts/page_container.dart';

/// In-memory loader feeding the on-call schedule prose so [trans] returns
/// short, wrappable strings instead of raw key tokens, mirroring the other
/// view tests (e.g. `monitors_list_view_test.dart`).
class _OnCallLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
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
    };
  }
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
      // A top-level `builder` wraps every route (including the Remove confirm
      // dialog, which mounts through the root Navigator's Overlay, outside
      // `home`) in the same WindTheme, so its own WDiv widgets resolve a theme
      // too.
      builder: (context, child) => MediaQuery(
        data: MediaQueryData(size: size),
        child: WindTheme(data: WindThemeData(), child: child!),
      ),
      home: Scaffold(body: SingleChildScrollView(child: widget)),
    );
  }

  testWidgets('wraps its content in a PageContainer', (tester) async {
    Http.fake({
      'on-call/schedules': Http.response({'data': []}, 200),
    });

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();

    expect(find.byType(PageContainer), findsOneWidget);
  });

  testWidgets(
    'selecting an override member POSTs an override and moves the hero card',
    (tester) async {
      final fake = Http.fake({
        'on-call/schedules': Http.response({
          'data': [
            {'id': 'sched-1', 'name': 'Primary', 'timezone': 'UTC'},
          ],
        }, 200),
        'on-call/schedules/sched-1': Http.response({
          'data': {
            'id': 'sched-1',
            'name': 'Primary',
            'timezone': 'UTC',
            'rotations': [],
            'overrides': [],
          },
        }, 200),
        'on-call/schedules/sched-1/overrides': Http.response({
          'data': {
            'id': 'sched-1',
            'name': 'Primary',
            'timezone': 'UTC',
            'rotations': [],
            'overrides': [
              {
                'id': 'ov-1',
                'user_id': 'u4',
                'user_name': 'Ada Lovelace',
                'starts_at': '2026-07-12T00:00:00Z',
                'ends_at': '2026-07-13T00:00:00Z',
              },
            ],
          },
        }, 201),
      });
      await OnCallController.instance.reload();

      await tester.pumpWidget(wrap(const OnCallScheduleView()));
      await tester.pump();

      final TeamMember target = teamMembers.firstWhere((m) => m.id == 'u4');
      await tester.tap(find.text('Override'));
      await tester.pump();
      // The add-to-rotation dropdown also lists `target` (she is not in the
      // fixture rotation either), so its own (closed) item copy also matches
      // `find.text`; `.first` targets the just-opened Override dropdown's
      // item, which is built first in the widget tree.
      await tester.tap(find.text(target.name).first);
      await tester.pumpAndSettle();

      fake.assertSent(
        (r) =>
            r.method == 'POST' &&
            r.url.contains('on-call/schedules/sched-1/overrides') &&
            r.data is Map &&
            (r.data as Map)['user_id'] == 'u4',
      );
      expect(find.text(target.name), findsWidgets);
    },
  );

  testWidgets(
    'confirming Remove DELETEs the resolved rotation row and drops the row',
    (tester) async {
      final fake = Http.fake({
        'on-call/schedules': Http.response({
          'data': [
            {'id': 'sched-1', 'name': 'Primary', 'timezone': 'UTC'},
          ],
        }, 200),
        'on-call/schedules/sched-1': Http.response({
          'data': {
            'id': 'sched-1',
            'name': 'Primary',
            'timezone': 'UTC',
            'rotations': [
              {
                'id': 'rot-1',
                'user_id': 'u2',
                'user_name': 'Mara Pohl',
                'position': 0,
                'shift_hours': 48,
              },
              {
                'id': 'rot-2',
                'user_id': 'u3',
                'user_name': 'Ravi Shah',
                'position': 1,
                'shift_hours': 48,
              },
              {
                'id': 'rot-3',
                'user_id': 'u4',
                'user_name': 'Ada Lovelace',
                'position': 2,
                'shift_hours': 48,
              },
            ],
            'overrides': [],
          },
        }, 200),
        'on-call/schedules/sched-1/rotations/rot-2': Http.response(null, 204),
      });
      await OnCallController.instance.reload();

      await tester.pumpWidget(wrap(const OnCallScheduleView()));
      await tester.pump();

      expect(find.text('Ravi Shah'), findsOneWidget);
      await tester.tap(find.text('Remove').first);
      await tester.pumpAndSettle();
      await tester.tap(find.text('Remove').last);
      await tester.pumpAndSettle();

      fake.assertSent(
        (r) =>
            r.method == 'DELETE' &&
            r.url.contains('on-call/schedules/sched-1/rotations/rot-2'),
      );
      expect(find.text('Ravi Shah'), findsNothing);
    },
  );

  testWidgets('a failed override write does not move the hero card', (
    tester,
  ) async {
    Http.fake({
      'on-call/schedules': Http.response({
        'data': [
          {'id': 'sched-1', 'name': 'Primary', 'timezone': 'UTC'},
        ],
      }, 200),
      'on-call/schedules/sched-1': Http.response({
        'data': {
          'id': 'sched-1',
          'name': 'Primary',
          'timezone': 'UTC',
          'rotations': [],
          'overrides': [],
        },
      }, 200),
      'on-call/schedules/sched-1/overrides': Http.response({
        'message': 'Validation failed',
      }, 422),
    });
    await OnCallController.instance.reload();

    await tester.pumpWidget(wrap(const OnCallScheduleView()));
    await tester.pump();

    final TeamMember target = teamMembers.firstWhere((m) => m.id == 'u4');
    await tester.tap(find.text('Override'));
    await tester.pump();
    await tester.tap(find.text(target.name).first);
    await tester.pumpAndSettle();

    // The rotation's own current responder ("Mara Pohl", seeded from the
    // fixture) still holds the pager; the failed write never promoted
    // `target` into the hero card.
    expect(find.text('Mara Pohl'), findsWidgets);
  });
}
