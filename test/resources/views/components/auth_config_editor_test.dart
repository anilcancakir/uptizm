import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/monitor_auth_type.dart';
import 'package:uptizm/app/models/monitor_auth_config.dart';
import 'package:uptizm/resources/views/components/auth_config_editor.dart';

void main() {
  setUpAll(() {
    Magic.init();
  });

  Widget wrapWithTheme(Widget child) {
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp(
        home: Scaffold(body: SingleChildScrollView(child: child)),
      ),
    );
  }

  group('AuthConfigEditor', () {
    testWidgets('renders auth type selector', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(
          AuthConfigEditor(value: MonitorAuthConfig.none(), onChanged: (_) {}),
        ),
      );

      // Should find the auth type label text
      expect(find.text('None'), findsOneWidget);
    });

    testWidgets('shows no extra fields when type is none', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(
          AuthConfigEditor(value: MonitorAuthConfig.none(), onChanged: (_) {}),
        ),
      );

      // Should NOT find username/password/token fields (trans keys used in test mode)
      expect(find.textContaining('auth_username'), findsNothing);
      expect(find.textContaining('auth_password'), findsNothing);
      expect(find.textContaining('auth_token'), findsNothing);
    });

    testWidgets('shows username/password fields when type is basic_auth', (
      tester,
    ) async {
      await tester.pumpWidget(
        wrapWithTheme(
          AuthConfigEditor(
            value: MonitorAuthConfig(type: MonitorAuthType.basicAuth),
            onChanged: (_) {},
          ),
        ),
      );

      // Trans keys are used in test mode
      expect(find.textContaining('auth_username'), findsWidgets);
      expect(find.textContaining('auth_password'), findsWidgets);
    });

    testWidgets('shows token field when type is bearer_token', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(
          AuthConfigEditor(
            value: MonitorAuthConfig(type: MonitorAuthType.bearerToken),
            onChanged: (_) {},
          ),
        ),
      );

      // Trans key is used in test mode
      expect(find.textContaining('auth_token'), findsWidgets);
    });

    testWidgets('shows key name, value, location fields when type is api_key', (
      tester,
    ) async {
      await tester.pumpWidget(
        wrapWithTheme(
          AuthConfigEditor(
            value: MonitorAuthConfig(type: MonitorAuthType.apiKey),
            onChanged: (_) {},
          ),
        ),
      );

      // Trans keys are used in test mode
      expect(find.textContaining('auth_key_name'), findsWidgets);
      expect(find.textContaining('auth_key_value'), findsWidgets);
      expect(find.textContaining('auth_key_location'), findsWidgets);
    });

    testWidgets('shows key-value editor when type is custom_header', (
      tester,
    ) async {
      await tester.pumpWidget(
        wrapWithTheme(
          AuthConfigEditor(
            value: MonitorAuthConfig(type: MonitorAuthType.customHeader),
            onChanged: (_) {},
          ),
        ),
      );

      // KeyValueEditor renders "Add Header" button (trans key in test mode)
      expect(find.textContaining('add_header'), findsOneWidget);
    });
  });
}
