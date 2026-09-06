import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/controllers/escalation_controller.dart';
import 'package:uptizm/app/models/escalation_policy.dart';
import 'package:uptizm/resources/views/teams/escalation_policy_editor_view.dart';

/// In-memory loader feeding the escalation-editor prose so [trans] returns
/// short, wrappable strings instead of raw key tokens, mirroring the other
/// view tests (e.g. `on_call_schedule_view_test.dart`).
class _EscalationEditorLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'uptizm.team_menu.escalation': 'Escalation',
      'uptizm.teams.escalation_editor_title_new': 'New policy',
      'uptizm.teams.escalation_editor_title_edit': 'Edit policy',
      'uptizm.teams.escalation_editor_description': 'How incidents escalate.',
      'uptizm.teams.escalation_editor_create_button': 'Create',
      'uptizm.teams.escalation_editor_save_button': 'Save',
      'uptizm.teams.escalation_editor_name_label': 'Name',
      'uptizm.teams.escalation_editor_name_placeholder': 'Primary',
      'uptizm.teams.escalation_editor_desc_label': 'Description',
      'uptizm.teams.escalation_editor_desc_placeholder': 'Optional',
      'uptizm.teams.escalation_editor_ladder_header': 'Ladder',
      'uptizm.teams.escalation_editor_rung_title': 'Rung :number',
      'uptizm.teams.escalation_editor_delay_label': 'Delay',
      'uptizm.teams.escalation_editor_targets_label': 'Notify',
      'uptizm.teams.escalation_editor_targets_hint': 'Who this rung pages.',
      'uptizm.teams.escalation_target_on_call': 'On-call rotation',
      'uptizm.teams.escalation_delay_immediate': 'Immediately',
      'uptizm.teams.escalation_delay_after': 'After :n min',
      'uptizm.teams.escalation_editor_add_rung_button': 'Add rung',
      'uptizm.teams.escalation_editor_repeat_label': 'Repeat last rung',
      'uptizm.teams.escalation_editor_default_label': 'Use as default',
      'uptizm.teams.escalation_toast_error_title': 'Could not save',
    };
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    Magic.singleton('magic_starter', () => MagicStarterManager());
    Magic.singleton('log', () => LogManager());
    // The editor's controller reload()/refreshDetail() resolve the `network`
    // service; no route is faked so both degrade to the seeded cache.
    Http.fake();

    Translator.instance.setLoader(_EscalationEditorLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  Widget wrap(Widget widget, {Size size = const Size(1280, 1600)}) {
    return MaterialApp(
      builder: (context, child) => MediaQuery(
        data: MediaQueryData(size: size),
        child: WindTheme(data: WindThemeData(), child: child!),
      ),
      home: Scaffold(body: SingleChildScrollView(child: widget)),
    );
  }

  testWidgets(
    'create mode defaults each rung to the on-call rotation picker',
    (tester) async {
      // Register the controller before the view resolves it.
      EscalationController.instance;

      await tester.pumpWidget(wrap(const EscalationPolicyEditorView()));
      await tester.pump();

      expect(find.byType(MSPageContainer), findsOneWidget);
      // The single default rung's target select renders its selected label.
      expect(find.text('On-call rotation'), findsOneWidget);
      // None of the removed free-string channel labels survive.
      expect(find.text('Slack #incidents'), findsNothing);
      expect(find.text('PagerDuty'), findsNothing);
      expect(find.text('Email team'), findsNothing);
    },
  );

  testWidgets(
    'edit mode reconstructs a user rung as the member picker',
    (tester) async {
      final EscalationController controller = EscalationController.instance;
      controller.seedForTest([
        EscalationPolicy.fromMap({
          'id': 'p1',
          'name': 'Primary',
          'steps': [
            {
              'id': 's1',
              'position': 0,
              'delay_minutes': 0,
              'target_type': 'user',
              'target_id': 'u2',
            },
          ],
        }),
      ]);

      // The rung's target must resolve against the team's REAL roster. This
      // used to assert a fixture name ('u2' was Mara Pohl in the mock), so the
      // test passed while the picker offered people who do not exist: a rung
      // pointed at one of them would page nobody during an outage.
      MagicStarterTeamController.instance.members.value = [
        {'id': 'u1', 'name': 'Real Owner', 'role': 'owner'},
        {'id': 'u2', 'name': 'Real Responder', 'role': 'member'},
      ];

      await tester.pumpWidget(wrap(const EscalationPolicyEditorView(id: 'p1')));
      await tester.pump();

      expect(find.text('Real Responder'), findsOneWidget);
      expect(find.text('Mara Pohl'), findsNothing);
      expect(find.text('Slack #incidents'), findsNothing);
      expect(find.text('PagerDuty'), findsNothing);
    },
  );

  testWidgets(
    'a policy that resolves after mount reseeds into edit mode',
    (tester) async {
      // A cold entry: a browser reload of /teams/escalation/p1, or a shared
      // link. The controller holds nothing yet, so initState can only seed the
      // create defaults; the policy arrives afterwards. Left unreseeded, the
      // editor offers Create over an existing policy and saving writes a
      // SECOND one, taking the team-wide default with it when the default
      // switch is on.
      final EscalationController controller = EscalationController.instance;
      expect(controller.detailById('p1'), isNull);

      await tester.pumpWidget(wrap(const EscalationPolicyEditorView(id: 'p1')));
      await tester.pump();

      controller.seedForTest([
        EscalationPolicy.fromMap({
          'id': 'p1',
          'name': 'Primary',
          'steps': [
            {
              'id': 's1',
              'position': 0,
              'delay_minutes': 0,
              'target_type': 'on_call',
            },
          ],
        }),
      ]);
      await tester.pump();

      expect(find.text('Edit policy'), findsOneWidget);
      expect(find.text('New policy'), findsNothing);
      // The submit label is the write path: 'Create' here takes the branch that
      // POSTs a new policy.
      expect(find.text('Save'), findsOneWidget);
      expect(find.text('Create'), findsNothing);
    },
  );

  testWidgets(
    'moving to a second policy reseeds it once it resolves too',
    (tester) async {
      // The second entry path into the same defect. The reseed listener detaches
      // the moment the FIRST policy lands, so an id change on a reused State
      // (a URL edit on web between two /teams/escalation/:id routes) has to
      // re-arm it. Without that, p2 renders in create mode and saving writes a
      // duplicate exactly as a cold entry did.
      final EscalationController controller = EscalationController.instance;

      // p1 must resolve LATE, not be pre-seeded: seeding it first would make
      // initState find it, set edit mode directly, and leave the listener
      // attached and unfired, so the detach this test is about never happens
      // and the assertions below would hold with or without the re-arm.
      await tester.pumpWidget(wrap(const EscalationPolicyEditorView(id: 'p1')));
      await tester.pump();

      controller.seedForTest([
        EscalationPolicy.fromMap({'id': 'p1', 'name': 'Primary', 'steps': []}),
      ]);
      await tester.pump();
      expect(find.text('Edit policy'), findsOneWidget);

      // Same widget type and position, new id: the State is reused and
      // didUpdateWidget reseeds from a cache that does not hold p2 yet.
      await tester.pumpWidget(wrap(const EscalationPolicyEditorView(id: 'p2')));
      await tester.pump();

      controller.seedForTest([
        EscalationPolicy.fromMap({'id': 'p2', 'name': 'Secondary', 'steps': []}),
      ]);
      await tester.pump();

      expect(find.text('Edit policy'), findsOneWidget);
      expect(find.text('New policy'), findsNothing);
      expect(find.text('Save'), findsOneWidget);
      expect(find.text('Create'), findsNothing);
    },
  );
}
