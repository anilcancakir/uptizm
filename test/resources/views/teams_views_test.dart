import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/mocks/oncall.dart';
import 'package:uptizm/app/mocks/teams.dart';
import 'package:uptizm/app/mocks/teams_data.dart';
import 'package:uptizm/resources/views/teams/escalation_policies_view.dart';
import 'package:uptizm/resources/views/teams/escalation_policy_editor_view.dart';
import 'package:uptizm/resources/views/teams/invite_accept_view.dart';
import 'package:uptizm/resources/views/teams/notification_channels_view.dart';
import 'package:uptizm/resources/views/teams/on_call_schedule_view.dart';
import 'package:uptizm/resources/views/teams/plan_billing_view.dart';
import 'package:uptizm/resources/views/teams/team_create_view.dart';
import 'package:uptizm/resources/views/teams/team_members_view.dart';
import 'package:uptizm/resources/views/teams/team_settings_view.dart';

/// In-memory language loader supplying every [trans] key exercised by the 9
/// teams views' smoke tests, mirroring the pattern established in
/// `settings_views_test.dart`. Short, wrappable strings avoid RenderFlex
/// overflow at the test viewport.
class _TeamsViewsLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      // Shared.
      'common.cancel': 'Cancel',
      'nav.dashboard': 'Dashboard',
      'uptizm.nav.dashboard': 'Dashboard',
      'uptizm.settings.hub_title': 'Settings',
      'uptizm.status.editor_breadcrumb_back': 'Status pages',
      'uptizm.team_menu.escalation': 'Escalation policies',

      // Team create.
      'uptizm.teams.create_title': 'Create a team',
      'uptizm.teams.create_subtitle': 'A team owns its own monitors.',
      'uptizm.teams.create_name_label': 'Team name',
      'uptizm.teams.create_name_placeholder': 'Acme Inc.',
      'uptizm.teams.create_url_label': 'Team URL',
      'uptizm.teams.create_url_placeholder': 'acme',
      'uptizm.teams.create_color_label': 'Avatar color',
      'uptizm.teams.create_invites_label': 'Invite teammates',
      'uptizm.teams.create_invites_hint': 'Optional.',
      'uptizm.teams.create_invites_placeholder': 'mara@acme.com',
      'uptizm.teams.create_button': 'Create team',

      // Team settings.
      'uptizm.teams.settings_title': 'Team settings',
      'uptizm.teams.settings_description': 'Configuration for :name.',
      'uptizm.teams.settings_team_header': 'Team',
      'uptizm.teams.settings_name_label': 'Team name',
      'uptizm.teams.settings_url_label': 'Team URL',
      'uptizm.teams.settings_color_label': 'Avatar color',
      'uptizm.teams.settings_ai_header': 'AI mode',
      'uptizm.teams.settings_ai_description': 'How much AI does on its own.',
      'uptizm.teams.settings_ai_off_title': 'Off',
      'uptizm.teams.settings_ai_off_desc': 'No AI. Threshold rules only.',
      'uptizm.teams.settings_ai_suggest_title': 'Suggest',
      'uptizm.teams.settings_ai_suggest_desc': 'AI proposes incidents.',
      'uptizm.teams.settings_ai_auto_title': 'Auto',
      'uptizm.teams.settings_ai_auto_desc': 'AI resolves incidents.',
      'uptizm.teams.settings_ai_digest_label': 'Weekly AI digest email',
      'uptizm.teams.settings_save_button': 'Save changes',
      'uptizm.teams.settings_danger_header': 'Danger zone',
      'uptizm.teams.settings_danger_description': "Can't be undone.",
      'uptizm.teams.settings_delete_button': 'Delete this team',
      'uptizm.teams.settings_delete_confirm_title': 'Delete :name?',
      'uptizm.teams.settings_delete_confirm_description': "Can't be undone.",
      'uptizm.teams.settings_delete_confirm_label': 'Delete team',

      // Team members.
      'uptizm.teams.members_title': 'Members',
      'uptizm.teams.members_description': 'People with access to :name.',
      'uptizm.teams.members_invite_header': 'Invite a teammate',
      'uptizm.teams.members_invite_hint': "They'll get an invite to :name.",
      'uptizm.teams.members_invite_placeholder': 'teammate@company.com',
      'uptizm.teams.members_send_button': 'Send invite',
      'uptizm.teams.members_list_header': 'Members · :count',
      'uptizm.teams.members_pending_header': 'Pending invites · :count',
      'uptizm.teams.members_remove_button': 'Remove',
      'uptizm.teams.members_remove_confirm_title': 'Remove :name?',
      'uptizm.teams.members_remove_confirm_description':
          ':name loses access to :team.',
      'uptizm.teams.members_remove_confirm_label': 'Remove',
      'uptizm.teams.members_revoke_button': 'Revoke',
      'uptizm.teams.members_revoke_confirm_title': 'Revoke this invite?',
      'uptizm.teams.members_revoke_confirm_description':
          'The invite to :email stops.',
      'uptizm.teams.members_revoke_confirm_label': 'Revoke invite',

      // Notification channels.
      'uptizm.teams.channels_title': 'Notification channels',
      'uptizm.teams.channels_description': 'Team-level integrations.',
      'uptizm.teams.channels_email_desc': 'Send alerts to team members.',
      'uptizm.teams.channels_sms_desc': 'Receive alerts by text.',
      'uptizm.teams.channels_slack_desc': 'Post alerts to Slack.',
      'uptizm.teams.channels_teams_desc': 'Post alerts to Teams.',
      'uptizm.teams.channels_webhook_desc': 'POST alerts to an endpoint.',
      'uptizm.teams.channels_severity_critical': 'Critical only',
      'uptizm.teams.channels_severity_all': 'All alerts',
      'uptizm.teams.channels_connect_button': 'Connect',
      'uptizm.teams.channels_save_button': 'Save',
      'uptizm.teams.channels_test_button': 'Send test',
      'uptizm.teams.channels_email_recipients_label': 'Additional recipients',
      'uptizm.teams.channels_email_recipients_hint': 'Plus every member.',
      'uptizm.teams.channels_email_recipients_placeholder': 'oncall@acme.com',
      'uptizm.teams.channels_sms_phone_label': 'Phone number',
      'uptizm.teams.channels_slack_workspace_label': 'Workspace',
      'uptizm.teams.channels_slack_channel_label': 'Channel',
      'uptizm.teams.channels_slack_channel_placeholder': '#incidents',
      'uptizm.teams.channels_teams_webhook_label': 'Incoming webhook URL',
      'uptizm.teams.channels_teams_webhook_hint': 'Create one in connectors.',
      'uptizm.teams.channels_teams_webhook_placeholder':
          'https://outlook.office.com/webhook',
      'uptizm.teams.channels_webhook_url_label': 'Endpoint URL',
      'uptizm.teams.channels_webhook_secret_label': 'Signing secret',
      'uptizm.teams.channels_webhook_secret_hint': 'Sent as a header.',
      'uptizm.teams.channels_severity_label': 'Deliver',
      'uptizm.teams.channels_severity_hint': 'Which alerts this channel gets.',

      // Escalation policies list.
      'uptizm.teams.escalation_title': 'Escalation policies',
      'uptizm.teams.escalation_description':
          'When an alert goes unacknowledged.',
      'uptizm.teams.escalation_new_button': 'New policy',
      'uptizm.teams.escalation_oncall_reference':
          'Who answers comes from on-call.',
      'uptizm.teams.escalation_policy_edit_button': 'Edit',
      'uptizm.teams.escalation_policy_delete_button': 'Delete',
      'uptizm.teams.escalation_policy_count_word_singular': 'monitor',
      'uptizm.teams.escalation_policy_count_word_plural': 'monitors',
      'uptizm.teams.escalation_policy_default_badge': 'Default',
      'uptizm.teams.escalation_policy_repeats_last':
          'Repeats until acknowledged.',
      'uptizm.teams.escalation_policy_delete_confirm_title': 'Delete :name?',
      'uptizm.teams.escalation_policy_delete_confirm_description':
          "Can't be undone.",
      'uptizm.teams.escalation_policy_delete_confirm_label': 'Delete policy',

      // Escalation policy editor.
      'uptizm.teams.escalation_editor_title_new': 'New escalation policy',
      'uptizm.teams.escalation_editor_title_edit': 'Edit policy',
      'uptizm.teams.escalation_editor_description': 'A ladder of rungs.',
      'uptizm.teams.escalation_editor_save_button': 'Save changes',
      'uptizm.teams.escalation_editor_create_button': 'Create policy',
      'uptizm.teams.escalation_editor_name_label': 'Name',
      'uptizm.teams.escalation_editor_name_placeholder': 'Critical path',
      'uptizm.teams.escalation_editor_desc_label': 'Description',
      'uptizm.teams.escalation_editor_desc_placeholder': 'Aggressive paging.',
      'uptizm.teams.escalation_editor_ladder_header': 'Escalation ladder',
      'uptizm.teams.escalation_editor_add_rung_button': '+ Add rung',
      'uptizm.teams.escalation_editor_rung_title': 'Rung :number',
      'uptizm.teams.escalation_editor_delay_label': 'When',
      'uptizm.teams.escalation_editor_targets_label': 'Notify',
      'uptizm.teams.escalation_editor_targets_hint': "Who this rung pages.",
      'uptizm.teams.escalation_editor_repeat_label': 'Repeat the last rung',
      'uptizm.teams.escalation_editor_default_label': 'Use as default',

      // On-call schedule.
      'uptizm.teams.oncall_title': 'On-call schedule',
      'uptizm.teams.oncall_description': 'Who answers first.',
      'uptizm.teams.oncall_override_button': 'Override',
      'uptizm.teams.oncall_override_label': 'Hand the pager to',
      'uptizm.teams.oncall_current_header': 'On call now',
      'uptizm.teams.oncall_rotation_header': 'Rotation',
      'uptizm.teams.oncall_remove_button': 'Remove',
      'uptizm.teams.oncall_add_button': '+ Add to rotation',
      'uptizm.teams.oncall_escalation_reference':
          'Configure escalation policies.',
      'uptizm.teams.oncall_remove_confirm_title': 'Remove :name?',
      'uptizm.teams.oncall_remove_confirm_description': "They'll stop shifts.",
      'uptizm.teams.oncall_remove_confirm_label': 'Remove',

      // Plan & billing.
      'uptizm.teams.billing_title': 'Plan & billing',
      'uptizm.teams.billing_description': 'Your plan and usage.',
      'uptizm.teams.billing_plan_current_badge': 'Current',
      'uptizm.teams.billing_renewal_text':
          ':price/mo billed :cycle · renews :date',
      'uptizm.teams.billing_renewal_cycle_annual': 'annually',
      'uptizm.teams.billing_renewal_cycle_monthly': 'monthly',
      'uptizm.teams.billing_plans_heading': 'Plans',
      'uptizm.teams.billing_plans_monthly': 'Monthly',
      'uptizm.teams.billing_plans_annual': 'Annual',
      'uptizm.teams.billing_plan_recommended_badge': 'Recommended',
      'uptizm.teams.billing_plan_price_monthly': '/mo',
      'uptizm.teams.billing_plan_price_custom': 'Custom',
      'uptizm.teams.billing_plan_billing_custom': 'Tailored to your scale',
      'uptizm.teams.billing_plan_billing_annual': 'billed annually',
      'uptizm.teams.billing_plan_billing_free': 'free forever',
      'uptizm.teams.billing_plan_billing_monthly': 'billed monthly',
      'uptizm.teams.billing_plan_button_current': 'Current plan',
      'uptizm.teams.billing_plan_button_contact': 'Contact sales',
      'uptizm.teams.billing_plan_button_upgrade': 'Upgrade',
      'uptizm.teams.billing_plan_button_downgrade': 'Downgrade',
      'uptizm.teams.billing_payment_header': 'Payment method',
      'uptizm.teams.billing_payment_expires': 'Expires :date',
      'uptizm.teams.billing_payment_update_button': 'Update',
      'uptizm.teams.billing_invoices_header': 'Billing history',
      'uptizm.teams.billing_invoice_receipt_button': 'Receipt',
      'uptizm.teams.billing_toast_contact_title': "We'll be in touch",
      'uptizm.teams.billing_toast_contact_description': 'Sales will reach out.',
      'uptizm.teams.billing_toast_upgrade_title': 'Upgrading to :name',
      'uptizm.teams.billing_toast_switch_title': 'Switching to :name',
      'uptizm.teams.billing_toast_change_description': 'Billed :cycle.',

      // Invite accept.
      'uptizm.teams.invite_accept_heading': 'Join :name on Uptizm',
      'uptizm.teams.invite_accept_body': "You've been invited to collaborate.",
      'uptizm.teams.invite_accept_button': 'Accept invite',
      'uptizm.teams.invite_accept_decline_button': 'Decline',
      'uptizm.teams.invite_accepted': 'You have joined the team.',
    };
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card / Button / Input / Switch / Badge
    // / PageHeader resolve their themes via MagicStarter.* without a full app
    // boot (mirrors settings_views_test.dart).
    Magic.singleton('magic_starter', () => MagicStarterManager());

    Translator.instance.setLoader(_TeamsViewsLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme] under a
  /// configurable [MediaQuery] size, mirroring the harness established in
  /// `settings_views_test.dart`.
  Widget wrap(Widget widget, {Size size = const Size(1280, 6000)}) {
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

  // ---------------------------------------------------------------------------
  // TeamCreateView
  // ---------------------------------------------------------------------------

  group('TeamCreateView', () {
    testWidgets('renders the title and the Create button', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const TeamCreateView(), size: const Size(1280, 4000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.text(trans('uptizm.teams.create_title')), findsOneWidget);
      expect(find.text(trans('uptizm.teams.create_button')), findsOneWidget);
    });
  });

  // ---------------------------------------------------------------------------
  // TeamSettingsView
  // ---------------------------------------------------------------------------

  group('TeamSettingsView', () {
    testWidgets('renders the title and every AI-mode option title', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const TeamSettingsView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.text(trans('uptizm.teams.settings_title')), findsOneWidget);
      expect(
        find.text(trans('uptizm.teams.settings_ai_off_title')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.teams.settings_ai_suggest_title')),
        findsOneWidget,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // TeamMembersView
  // ---------------------------------------------------------------------------

  group('TeamMembersView', () {
    testWidgets('renders the title and a row for every fixture member', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const TeamMembersView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.text(trans('uptizm.teams.members_title')), findsOneWidget);
      for (final TeamMember member in teamMembers) {
        expect(find.text(member.name), findsOneWidget);
      }
    });
  });

  // ---------------------------------------------------------------------------
  // NotificationChannelsView
  // ---------------------------------------------------------------------------

  group('NotificationChannelsView', () {
    testWidgets('renders the title and a row for every fixture channel', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const NotificationChannelsView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.text(trans('uptizm.teams.channels_title')), findsOneWidget);
      for (final NotificationChannelConfig channel in notificationChannels) {
        expect(find.text(channel.name), findsOneWidget);
      }
    });
  });

  // ---------------------------------------------------------------------------
  // EscalationPoliciesView
  // ---------------------------------------------------------------------------

  group('EscalationPoliciesView', () {
    testWidgets('renders the title and a card for every policy', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const EscalationPoliciesView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      // The rung rail's connecting line is a Stack+Positioned bar (the
      // incident_timeline pattern), not an Expanded-in-Column, so no
      // RenderFlex overflow fires under the unbounded-height scroll view.
      expect(tester.takeException(), isNull);
      expect(find.text(trans('uptizm.teams.escalation_title')), findsOneWidget);
      for (final EscalationPolicy policy in escalationPolicies) {
        expect(find.text(policy.name), findsOneWidget);
      }
    });
  });

  // ---------------------------------------------------------------------------
  // EscalationPolicyEditorView
  // ---------------------------------------------------------------------------

  group('EscalationPolicyEditorView', () {
    testWidgets('create mode renders the new-policy title', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const EscalationPolicyEditorView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.escalation_editor_title_new')),
        findsOneWidget,
      );
    });

    testWidgets('edit mode resolves a known id and renders the edit title', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final String id = escalationPolicies.first.id;

      await tester.pumpWidget(
        wrap(EscalationPolicyEditorView(id: id), size: const Size(1280, 6000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.teams.escalation_editor_title_edit')),
        findsOneWidget,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // OnCallScheduleView
  // ---------------------------------------------------------------------------

  group('OnCallScheduleView', () {
    testWidgets('renders the title and the current-shift member name', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 6000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const OnCallScheduleView(), size: const Size(1280, 6000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.text(trans('uptizm.teams.oncall_title')), findsOneWidget);
      final OnCallShift current = onCallRotation.firstWhere(
        (OnCallShift shift) => shift.current,
      );
      expect(find.text(current.memberName), findsWidgets);
    });
  });

  // ---------------------------------------------------------------------------
  // PlanBillingView
  // ---------------------------------------------------------------------------

  group('PlanBillingView', () {
    testWidgets('renders the title and the billing-history heading', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 8000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const PlanBillingView(), size: const Size(1280, 8000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.text(trans('uptizm.teams.billing_title')), findsOneWidget);
      expect(
        find.text(trans('uptizm.teams.billing_invoices_header')),
        findsOneWidget,
      );
    });
  });

  // ---------------------------------------------------------------------------
  // InviteAcceptView
  // ---------------------------------------------------------------------------

  group('InviteAcceptView', () {
    testWidgets('renders the join heading and the Accept/Decline actions', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(const InviteAcceptView(token: 'x')));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text(
          trans('uptizm.teams.invite_accept_heading', {
            'name': teams.first.name,
          }),
        ),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.teams.invite_accept_button')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.teams.invite_accept_decline_button')),
        findsOneWidget,
      );
    });
  });
}
