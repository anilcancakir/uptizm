import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/ui/components/notification_center/index.dart';
import 'package:uptizm/ui/components/notification_center/notification_center.preview.dart';
import 'package:uptizm/ui/components/status_dot/index.dart';

import '../../../support/bundled_lang.dart';

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

  group('notificationItemFromDatabaseNotification', () {
    DatabaseNotification make(String title, String body) =>
        DatabaseNotification(
          id: 'n-1',
          type: 'App\\Notifications\\IncidentOpened',
          title: title,
          body: body,
          data: const {'type': 'incident_opened', 'incident_id': 'inc-1'},
          createdAt: DateTime.utc(2026, 8, 19, 16),
        );

    test('a body that only repeats the title is dropped', () {
      // `IncidentOpened` builds `title` from the copy catalogue and `body` from
      // `IncidentTitle::render`, and for a monitor-down incident both resolve to
      // the same sentence. The row printed it twice, one line under the other.
      final NotificationItem item = notificationItemFromDatabaseNotification(
        make('QA Manual Monitor kesintide', 'QA Manual Monitor kesintide'),
      );

      expect(item.title, equals('QA Manual Monitor kesintide'));
      expect(item.detail, isNull);
    });

    test('a body that adds something is kept', () {
      final NotificationItem item = notificationItemFromDatabaseNotification(
        make(
          'Checkout is down',
          'All regions failed for 3 consecutive checks.',
        ),
      );

      expect(
        item.detail,
        equals('All regions failed for 3 consecutive checks.'),
      );
    });
  });

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

  // ---------------------------------------------------------------------------
  // The header holds at the shipped copy, in both locales
  // ---------------------------------------------------------------------------

  for (final String locale in <String>['en', 'tr']) {
    testWidgets('the header holds at the shipped $locale copy, and at 2x text', (
      tester,
    ) async {
      // The loader above hands out English LITERALS, so every assertion in this
      // file passes by construction and no locale-length problem can surface.
      // These read the shipped catalogue instead.
      //
      // Deliberately an overflow-ABSENCE assertion and nothing more. A fit
      // assertion here (rendered width == intrinsic width) is invalid in a
      // widget test: the test font is roughly one em per glyph, so it inflates
      // the 29-character Turkish label to about 355 logical pixels against a
      // 294px content row and reports a clipping that the app does not have.
      // Measured in the running Chrome build at the real Geist metrics: the
      // Turkish pair needs 69 + 165 and fits. What does NOT fit is the same
      // pair at an accessibility text scale (312 at 1.3x, 476 at 2x), which is
      // what the Wrap is actually for, and what the second case below pins.
      Translator.instance.setLoader(_BundledLangLoader(locale));
      await Translator.instance.setLocale(Locale(locale));

      await tester.pumpWidget(
        wrap(NotificationCenter(items: kSampleNotifications)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);

      // The same header at 2x, where the two labels cannot share a line in
      // either locale. A Row overflowed here; the Wrap drops the action to its
      // own run instead.
      await tester.pumpWidget(
        MediaQuery(
          data: const MediaQueryData(textScaler: TextScaler.linear(2.0)),
          child: wrap(NotificationCenter(items: kSampleNotifications)),
        ),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
    });
  }
}

/// Feeds [trans] the app's shipped catalogue for one locale, so a layout
/// assertion is made against the words an operator actually reads.
class _BundledLangLoader implements TranslationLoader {
  /// The locale whose shipped catalogue to serve.
  final String locale;

  const _BundledLangLoader(this.locale);

  @override
  Future<Map<String, dynamic>> load(Locale requested) async =>
      readBundledLang(locale);
}
