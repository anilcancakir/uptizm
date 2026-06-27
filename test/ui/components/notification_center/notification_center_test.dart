import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/mocks/status.dart';
import 'package:uptizm/ui/components/notification_center/index.dart';
import 'package:uptizm/ui/components/notification_center/notification_center.preview.dart';
import 'package:uptizm/ui/components/status_dot/index.dart';

/// In-memory loader feeding the panel's fixed labels so [trans] resolves the
/// real English strings instead of falling back to the raw key. This mirrors
/// production, where the bundled `assets/lang/en.json` is loaded.
class _NotificationLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    // The Translator caches whatever the loader returns verbatim; flattening
    // is the loader's job (see JsonAssetLoader), so the keys are pre-flattened.
    return {
      'notifications.title': 'Notifications',
      'notifications.mark_all_read': 'Mark all as read',
      'notifications.settings': 'Notification Settings',
      'notifications.empty': 'No notifications',
    };
  }
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    Magic.singleton('magic_starter', () => MagicStarterManager());

    Translator.instance.setLoader(_NotificationLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme].
  Widget wrap(Widget widget) {
    return MaterialApp(
      home: WindTheme(
        data: WindThemeData(),
        child: Scaffold(body: SingleChildScrollView(child: widget)),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Recipe assertions
  // ---------------------------------------------------------------------------

  group('notificationCenterRecipe', () {
    test('base emits a contained surface panel', () {
      final cls = notificationCenterRecipe();
      expect(cls, contains('bg-surface'));
      expect(cls, contains('border-color-border'));
      expect(cls, contains('rounded-lg'));
    });

    test('base emits flex flex-col layout', () {
      final cls = notificationCenterRecipe();
      expect(cls, contains('flex'));
      expect(cls, contains('flex-col'));
    });
  });

  // ---------------------------------------------------------------------------
  // Model assertions
  // ---------------------------------------------------------------------------

  group('AppNotificationKind.status', () {
    test('maps each kind to the expected monitoring status', () {
      expect(AppNotificationKind.down.status, StatusKey.down);
      expect(AppNotificationKind.up.status, StatusKey.up);
      expect(AppNotificationKind.degraded.status, StatusKey.degraded);
      expect(AppNotificationKind.incident.status, StatusKey.down);
      expect(AppNotificationKind.resolved.status, StatusKey.up);
      expect(AppNotificationKind.ai.status, StatusKey.ai);
    });
  });

  // ---------------------------------------------------------------------------
  // Widget tests
  // ---------------------------------------------------------------------------

  testWidgets('renders the localized panel header label', (tester) async {
    await tester.pumpWidget(wrap(const NotificationCenter()));

    final label = trans('notifications.title');
    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    expect(
      texts.any((w) => w.data == label),
      isTrue,
      reason: 'header label not found',
    );
  });

  testWidgets('renders every sample item title', (tester) async {
    await tester.pumpWidget(wrap(const NotificationCenter()));

    final texts = tester.widgetList<WText>(find.byType(WText)).toList();
    for (final item in kSampleNotifications) {
      expect(
        texts.any((w) => w.data == item.title),
        isTrue,
        reason: 'title "${item.title}" not found',
      );
    }
  });

  testWidgets('renders a StatusDot per item', (tester) async {
    await tester.pumpWidget(wrap(const NotificationCenter()));

    expect(find.byType(StatusDot), findsNWidgets(kSampleNotifications.length));
  });

  testWidgets('shows the mark-all-read action while unread items remain', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const NotificationCenter()));

    expect(find.text(trans('notifications.mark_all_read')), findsOneWidget);
  });

  testWidgets('mark-all-read clears the unread action', (tester) async {
    await tester.pumpWidget(wrap(const NotificationCenter()));

    await tester.tap(find.text(trans('notifications.mark_all_read')));
    await tester.pump();

    expect(find.text(trans('notifications.mark_all_read')), findsNothing);
  });

  testWidgets('tapping a row fires onItemTap then onClose', (tester) async {
    NotificationItem? tapped;
    var closed = false;

    await tester.pumpWidget(
      wrap(
        NotificationCenter(
          onItemTap: (item) => tapped = item,
          onClose: () => closed = true,
        ),
      ),
    );

    await tester.tap(find.text(kSampleNotifications.first.title));
    await tester.pump();

    expect(tapped?.id, kSampleNotifications.first.id);
    expect(closed, isTrue);
  });

  testWidgets('settings row fires onSettings then onClose', (tester) async {
    var settings = false;
    var closed = false;

    await tester.pumpWidget(
      wrap(
        NotificationCenter(
          onSettings: () => settings = true,
          onClose: () => closed = true,
        ),
      ),
    );

    await tester.tap(find.text(trans('notifications.settings')));
    await tester.pump();

    expect(settings, isTrue);
    expect(closed, isTrue);
  });

  testWidgets('renders the empty state when the feed has no items', (
    tester,
  ) async {
    await tester.pumpWidget(wrap(const NotificationCenter(items: [])));

    expect(find.text(trans('notifications.empty')), findsOneWidget);
    expect(find.byType(StatusDot), findsNothing);
  });

  testWidgets('every sample item carries a route target', (tester) async {
    for (final item in kSampleNotifications) {
      expect(item.to, isNotEmpty, reason: '"${item.title}" is missing a route');
    }
  });

  testWidgets('preview renders without error', (tester) async {
    await tester.pumpWidget(wrap(const NotificationCenterPreview()));
    await tester.pump();

    expect(find.byType(NotificationCenter), findsOneWidget);
  });
}
