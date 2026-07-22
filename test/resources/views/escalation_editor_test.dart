import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/controllers/escalation_controller.dart';
import 'package:uptizm/app/models/escalation_policy.dart';
import 'package:uptizm/resources/views/teams/escalation_policy_editor_view.dart';
import 'package:uptizm/ui/layouts/page_container.dart';

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
      'uptizm.teams.escalation_editor_add_rung_button': 'Add rung',
      'uptizm.teams.escalation_editor_repeat_label': 'Repeat last rung',
      'uptizm.teams.escalation_editor_default_label': 'Use as default',
      'uptizm.teams.escalation_toast_error_title': 'Could not save',
      'uptizm.teams.form_name_error_required': 'Name is required.',
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

      expect(find.byType(PageContainer), findsOneWidget);
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

      await tester.pumpWidget(wrap(const EscalationPolicyEditorView(id: 'p1')));
      await tester.pump();

      // 'u2' is Mara Pohl in the team fixture: the user rung reconstructs to
      // her name as the selected target, not a channel free-string.
      expect(find.text('Mara Pohl'), findsOneWidget);
      expect(find.text('Slack #incidents'), findsNothing);
      expect(find.text('PagerDuty'), findsNothing);
    },
  );
}
