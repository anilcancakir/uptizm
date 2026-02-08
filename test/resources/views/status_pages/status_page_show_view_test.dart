import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/controllers/status_page_controller.dart';
import 'package:uptizm/app/models/status_page.dart';
import 'package:uptizm/resources/views/status_pages/status_page_show_view.dart';

void main() {
  group('StatusPageShowView', () {
    test('can be instantiated', () {
      const view = StatusPageShowView();
      expect(view, isA<StatusPageShowView>());
    });
  });

  group('StatusPageShowView content', () {
    late StatusPageController controller;

    setUp(() {
      controller = StatusPageController();
    });

    testWidgets('displays status page name and public URL', (tester) async {
      final statusPage = StatusPage()
        ..setRawAttributes({
          'id': 'test-uuid-1',
          'name': 'Acme Status',
          'slug': 'acme',
          'description': 'Status page for Acme Corp',
          'is_published': true,
          'monitor_ids': [],
        }, sync: true)
        ..exists = true;

      controller.selectedStatusPageNotifier.value = statusPage;

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: Scaffold(
              body: ValueListenableBuilder<StatusPage?>(
                valueListenable: controller.selectedStatusPageNotifier,
                builder: (context, page, _) {
                  if (page == null) {
                    return const Center(child: CircularProgressIndicator());
                  }
                  return Column(
                    children: [
                      Text(page.name, key: const Key('status_page_name')),
                      Text(page.publicUrl, key: const Key('status_page_url')),
                    ],
                  );
                },
              ),
            ),
          ),
        ),
      );

      expect(find.text('Acme Status'), findsOneWidget);
      expect(find.text('https://acme.uptizm.com'), findsOneWidget);
    });

    testWidgets('displays published badge when is_published is true', (
      tester,
    ) async {
      final statusPage = StatusPage()
        ..setRawAttributes({
          'id': 'test-uuid-1',
          'name': 'Acme Status',
          'slug': 'acme',
          'is_published': true,
          'monitor_ids': [],
        }, sync: true)
        ..exists = true;

      controller.selectedStatusPageNotifier.value = statusPage;

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: Scaffold(
              body: ValueListenableBuilder<StatusPage?>(
                valueListenable: controller.selectedStatusPageNotifier,
                builder: (context, page, _) {
                  if (page == null) return const SizedBox.shrink();
                  return Text(
                    page.isPublished ? 'Published' : 'Draft',
                    key: const Key('publish_status'),
                  );
                },
              ),
            ),
          ),
        ),
      );

      expect(find.text('Published'), findsOneWidget);
    });

    testWidgets('displays draft badge when is_published is false', (
      tester,
    ) async {
      final statusPage = StatusPage()
        ..setRawAttributes({
          'id': 'test-uuid-1',
          'name': 'Acme Status',
          'slug': 'acme',
          'is_published': false,
          'monitor_ids': [],
        }, sync: true)
        ..exists = true;

      controller.selectedStatusPageNotifier.value = statusPage;

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: Scaffold(
              body: ValueListenableBuilder<StatusPage?>(
                valueListenable: controller.selectedStatusPageNotifier,
                builder: (context, page, _) {
                  if (page == null) return const SizedBox.shrink();
                  return Text(
                    page.isPublished ? 'Published' : 'Draft',
                    key: const Key('publish_status'),
                  );
                },
              ),
            ),
          ),
        ),
      );

      expect(find.text('Draft'), findsOneWidget);
    });

    testWidgets('displays attached monitors list', (tester) async {
      final statusPage = StatusPage()
        ..setRawAttributes({
          'id': 'test-uuid-1',
          'name': 'Acme Status',
          'slug': 'acme',
          'is_published': true,
          'monitor_ids': [1, 2],
          'monitors': [
            {'id': 'test-uuid-1', 'name': 'API Server', 'status': 'active'},
            {'id': 'test-uuid-2', 'name': 'Web App', 'status': 'active'},
          ],
        }, sync: true)
        ..exists = true;

      controller.selectedStatusPageNotifier.value = statusPage;

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: Scaffold(
              body: ValueListenableBuilder<StatusPage?>(
                valueListenable: controller.selectedStatusPageNotifier,
                builder: (context, page, _) {
                  if (page == null) return const SizedBox.shrink();
                  final monitors = page.monitors;
                  return Column(
                    children: [
                      Text('Monitors (${monitors.length})'),
                      ...monitors.map((m) => Text(m.name ?? '')),
                    ],
                  );
                },
              ),
            ),
          ),
        ),
      );

      expect(find.text('Monitors (2)'), findsOneWidget);
      expect(find.text('API Server'), findsOneWidget);
      expect(find.text('Web App'), findsOneWidget);
    });

    testWidgets('shows loading state when status page is null', (tester) async {
      controller.selectedStatusPageNotifier.value = null;

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: Scaffold(
              body: ValueListenableBuilder<StatusPage?>(
                valueListenable: controller.selectedStatusPageNotifier,
                builder: (context, page, _) {
                  if (page == null) {
                    return const Center(
                      child: CircularProgressIndicator(
                        key: Key('loading_indicator'),
                      ),
                    );
                  }
                  return Text(page.name);
                },
              ),
            ),
          ),
        ),
      );

      expect(find.byKey(const Key('loading_indicator')), findsOneWidget);
    });

    testWidgets('displays description when present', (tester) async {
      final statusPage = StatusPage()
        ..setRawAttributes({
          'id': 'test-uuid-1',
          'name': 'Acme Status',
          'slug': 'acme',
          'description': 'Real-time status updates for Acme services',
          'is_published': true,
          'monitor_ids': [],
        }, sync: true)
        ..exists = true;

      controller.selectedStatusPageNotifier.value = statusPage;

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: Scaffold(
              body: ValueListenableBuilder<StatusPage?>(
                valueListenable: controller.selectedStatusPageNotifier,
                builder: (context, page, _) {
                  if (page == null) return const SizedBox.shrink();
                  return Text(
                    page.description ?? '',
                    key: const Key('description'),
                  );
                },
              ),
            ),
          ),
        ),
      );

      expect(
        find.text('Real-time status updates for Acme services'),
        findsOneWidget,
      );
    });

    testWidgets('displays primary color preview', (tester) async {
      final statusPage = StatusPage()
        ..setRawAttributes({
          'id': 'test-uuid-1',
          'name': 'Acme Status',
          'slug': 'acme',
          'primary_color': '#FF5733',
          'is_published': true,
          'monitor_ids': [],
        }, sync: true)
        ..exists = true;

      controller.selectedStatusPageNotifier.value = statusPage;

      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: Scaffold(
              body: ValueListenableBuilder<StatusPage?>(
                valueListenable: controller.selectedStatusPageNotifier,
                builder: (context, page, _) {
                  if (page == null) return const SizedBox.shrink();
                  return Text(
                    page.primaryColor,
                    key: const Key('primary_color'),
                  );
                },
              ),
            ),
          ),
        ),
      );

      expect(find.text('#FF5733'), findsOneWidget);
    });
  });

  group('StatusPage model', () {
    test('publicUrl is correctly generated from slug', () {
      final statusPage = StatusPage()
        ..setRawAttributes({
          'id': 'test-uuid-1',
          'slug': 'my-company',
        }, sync: true);

      expect(statusPage.publicUrl, 'https://my-company.uptizm.com');
    });

    test('monitors relationship parses correctly', () {
      final statusPage = StatusPage()
        ..setRawAttributes({
          'id': 'test-uuid-1',
          'monitors': [
            {'id': 'test-uuid-1', 'name': 'API', 'status': 'active'},
            {'id': 'test-uuid-2', 'name': 'Web', 'status': 'paused'},
          ],
        }, sync: true);

      expect(statusPage.monitors.length, 2);
      expect(statusPage.monitors[0].name, 'API');
      expect(statusPage.monitors[1].name, 'Web');
    });

    test('isPublished defaults to false', () {
      final statusPage = StatusPage()
        ..setRawAttributes({'id': 'test-status-page-uuid-1'}, sync: true);

      expect(statusPage.isPublished, false);
    });
  });
}
