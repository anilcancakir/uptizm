import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:uptizm/app/enums/monitor_auth_type.dart';
import 'package:uptizm/app/models/monitor_auth_config.dart';
import 'package:uptizm/resources/views/components/auth_config_editor.dart';

void main() {
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

      // Should NOT find username/password/token fields
      expect(find.text('Username'), findsNothing);
      expect(find.text('Password'), findsNothing);
      expect(find.text('Token'), findsNothing);
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

      expect(find.text('Username'), findsOneWidget);
      expect(find.text('Password'), findsOneWidget);
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

      expect(find.text('Token'), findsOneWidget);
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

      expect(find.text('Key Name'), findsOneWidget);
      expect(find.text('Key Value'), findsOneWidget);
      expect(find.text('Key Location'), findsOneWidget);
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

      // KeyValueEditor renders "Add Header" button
      expect(find.text('Add Header'), findsOneWidget);
    });
  });
}
