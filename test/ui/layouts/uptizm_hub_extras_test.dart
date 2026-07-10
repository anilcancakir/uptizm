import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/ui/layouts/uptizm_hub_extras.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so MSSettingsSection / MSSettingsNavRow
    // resolve their recipes the same way `settings_views_test.dart` does.
    Magic.singleton('magic_starter', () => MagicStarterManager());
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme], mirroring
  /// the harness established in `app_layout_test.dart`.
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: SingleChildScrollView(child: widget)),
      ),
    );
  }

  group('UptizmHubExtras', () {
    testWidgets('renders the Team and About & support sections', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(const UptizmHubExtras()));
      await tester.pump();

      // trans() returns the raw key when no lang file is loaded (test env),
      // matching the convention in `app_layout_test.dart`. The section header
      // caption applies an uppercase text transform (see
      // `settingsSectionCaptionRecipe`), so the rendered text is upper-cased.
      expect(find.text('UPTIZM.SETTINGS.HUB_GROUP_TEAM'), findsOneWidget);
      expect(find.text('UPTIZM.SETTINGS.HUB_GROUP_ABOUT'), findsOneWidget);
    });

    testWidgets('renders all 4 Team nav rows and all 4 About nav rows', (
      tester,
    ) async {
      await tester.pumpWidget(wrap(const UptizmHubExtras()));
      await tester.pump();

      expect(
        find.text('uptizm.settings.hub_team_channels_title'),
        findsOneWidget,
      );
      expect(
        find.text('uptizm.settings.hub_team_escalation_title'),
        findsOneWidget,
      );
      expect(
        find.text('uptizm.settings.hub_team_oncall_title'),
        findsOneWidget,
      );
      expect(
        find.text('uptizm.settings.hub_team_billing_title'),
        findsOneWidget,
      );

      expect(find.text('uptizm.settings.hub_help_title'), findsOneWidget);
      expect(
        find.text('uptizm.settings.hub_changelog_title'),
        findsOneWidget,
      );
      expect(find.text('uptizm.settings.hub_privacy_title'), findsOneWidget);
      expect(find.text('uptizm.settings.hub_terms_title'), findsOneWidget);
    });
  });
}
