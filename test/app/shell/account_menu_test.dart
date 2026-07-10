import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/models/user.dart';
import 'package:uptizm/ui/layouts/sidebar.dart';

void main() {
  setUp(() {
    MagicApp.reset();
    Magic.flush();
  });

  tearDown(() {
    Auth.unfake();
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme], mirroring
  /// the harness established in `app_layout_test.dart`.
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: widget),
      ),
    );
  }

  group('Sidebar account menu', () {
    testWidgets("renders Auth.user's name once a user is set", (
      tester,
    ) async {
      // The `preview_mock_harness.dart` real-user shape (id/name/email/...).
      final user = User.fromMap(const <String, dynamic>{
        'id': 1,
        'name': 'Ada Lovelace',
        'email': 'ada@example.com',
      });
      Auth.fake(user: user);

      await tester.pumpWidget(
        wrap(const Sidebar(currentPath: '/')),
      );
      await tester.pump();

      expect(find.text('Ada Lovelace'), findsOneWidget);
      expect(find.text('ada@example.com'), findsOneWidget);
    });

    testWidgets('degrades to a blank name when no user is authenticated', (
      tester,
    ) async {
      Auth.fake();

      await tester.pumpWidget(
        wrap(const Sidebar(currentPath: '/')),
      );
      await tester.pump();

      expect(find.text('Ada Lovelace'), findsNothing);
    });
  });
}
