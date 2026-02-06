import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import '../../../lib/resources/views/components/app_page_header.dart';

/// Helper to wrap widget with WindTheme for testing
Widget wrapWithTheme(Widget child) {
  return WindTheme(
    data: WindThemeData(),
    child: MaterialApp(home: Scaffold(body: child)),
  );
}

void main() {
  group('AppPageHeader', () {
    group('Basic Rendering', () {
      testWidgets('renders title only', (tester) async {
        await tester.pumpWidget(
          wrapWithTheme(AppPageHeader(title: 'Test Title')),
        );

        expect(find.text('Test Title'), findsOneWidget);
      });

      testWidgets('renders title and subtitle', (tester) async {
        await tester.pumpWidget(
          wrapWithTheme(
            AppPageHeader(title: 'Test Title', subtitle: 'Test Subtitle'),
          ),
        );

        expect(find.text('Test Title'), findsOneWidget);
        expect(find.text('Test Subtitle'), findsOneWidget);
      });

      testWidgets('renders with leading widget', (tester) async {
        await tester.pumpWidget(
          wrapWithTheme(
            AppPageHeader(
              title: 'Test Title',
              leading: Icon(Icons.arrow_back, key: Key('back-button')),
            ),
          ),
        );

        expect(find.byKey(Key('back-button')), findsOneWidget);
        expect(find.byIcon(Icons.arrow_back), findsOneWidget);
      });

      testWidgets('renders with trailing action', (tester) async {
        await tester.pumpWidget(
          wrapWithTheme(
            AppPageHeader(
              title: 'Test Title',
              actions: [
                WButton(
                  onTap: () {},
                  child: WText('Add'),
                  key: Key('add-button'),
                ),
              ],
            ),
          ),
        );

        expect(find.byKey(Key('add-button')), findsOneWidget);
        expect(find.text('Add'), findsOneWidget);
      });

      testWidgets('renders with multiple trailing actions', (tester) async {
        await tester.pumpWidget(
          wrapWithTheme(
            AppPageHeader(
              title: 'Test Title',
              actions: [
                WButton(
                  onTap: () {},
                  child: WIcon(Icons.search, key: Key('search-action')),
                ),
                WButton(
                  onTap: () {},
                  child: WIcon(Icons.add, key: Key('add-action')),
                ),
              ],
            ),
          ),
        );

        expect(find.byKey(Key('search-action')), findsOneWidget);
        expect(find.byKey(Key('add-action')), findsOneWidget);
      });
    });

    group('Responsive Layout', () {
      testWidgets('uses column layout on narrow screens', (tester) async {
        tester.view.physicalSize = Size(375, 667); // iPhone SE
        tester.view.devicePixelRatio = 2.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        await tester.pumpWidget(
          wrapWithTheme(
            AppPageHeader(
              title: 'Test Title',
              subtitle: 'Test Subtitle',
              actions: [WButton(onTap: () {}, child: WText('Add'))],
            ),
          ),
        );

        // On mobile, title and actions should stack vertically
        final header = tester.widget<WDiv>(find.byType(WDiv).first);

        // Verify className contains flex-col (mobile-first)
        expect(header.className?.contains('flex-col'), isTrue);
      });

      testWidgets('uses row layout on wide screens', (tester) async {
        tester.view.physicalSize = Size(1024, 768); // Tablet
        tester.view.devicePixelRatio = 2.0;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);

        await tester.pumpWidget(
          wrapWithTheme(
            AppPageHeader(
              title: 'Test Title',
              subtitle: 'Test Subtitle',
              actions: [WButton(onTap: () {}, child: WText('Add'))],
            ),
          ),
        );

        // On desktop, should use sm:flex-row for horizontal layout
        final header = tester.widget<WDiv>(find.byType(WDiv).first);

        expect(header.className?.contains('sm:flex-row'), isTrue);
      });
    });

    group('Styling', () {
      testWidgets('has proper border styling', (tester) async {
        await tester.pumpWidget(
          wrapWithTheme(AppPageHeader(title: 'Test Title')),
        );

        final header = tester.widget<WDiv>(find.byType(WDiv).first);

        // Should have bottom border
        expect(header.className?.contains('border-b'), isTrue);
        expect(header.className?.contains('border-gray-200'), isTrue);
        expect(header.className?.contains('dark:border-gray-700'), isTrue);
      });

      testWidgets('has proper padding', (tester) async {
        await tester.pumpWidget(
          wrapWithTheme(AppPageHeader(title: 'Test Title')),
        );

        final header = tester.widget<WDiv>(find.byType(WDiv).first);

        // Should have responsive padding
        expect(header.className?.contains('p-4'), isTrue);
        expect(header.className?.contains('lg:p-6'), isTrue);
      });

      testWidgets('title has proper text styling', (tester) async {
        await tester.pumpWidget(
          wrapWithTheme(AppPageHeader(title: 'Test Title')),
        );

        final titleTexts = tester.widgetList<WText>(find.byType(WText));

        final titleWidget = titleTexts.firstWhere(
          (w) => w.data == 'Test Title',
        );

        expect(titleWidget.className?.contains('text-2xl'), isTrue);
        expect(titleWidget.className?.contains('font-bold'), isTrue);
        expect(titleWidget.className?.contains('text-gray-900'), isTrue);
        expect(titleWidget.className?.contains('dark:text-white'), isTrue);
      });

      testWidgets('subtitle has proper text styling', (tester) async {
        await tester.pumpWidget(
          wrapWithTheme(
            AppPageHeader(title: 'Test Title', subtitle: 'Test Subtitle'),
          ),
        );

        final subtitleTexts = tester.widgetList<WText>(find.byType(WText));

        final subtitleWidget = subtitleTexts.firstWhere(
          (w) => w.data == 'Test Subtitle',
        );

        expect(subtitleWidget.className?.contains('text-sm'), isTrue);
        expect(subtitleWidget.className?.contains('text-gray-600'), isTrue);
        expect(
          subtitleWidget.className?.contains('dark:text-gray-400'),
          isTrue,
        );
      });
    });

    group('Null States', () {
      testWidgets('works without subtitle', (tester) async {
        await tester.pumpWidget(
          wrapWithTheme(AppPageHeader(title: 'Test Title')),
        );

        expect(find.text('Test Title'), findsOneWidget);
        expect(find.byType(WText), findsOneWidget); // Only title
      });

      testWidgets('works without leading', (tester) async {
        await tester.pumpWidget(
          wrapWithTheme(AppPageHeader(title: 'Test Title')),
        );

        expect(find.text('Test Title'), findsOneWidget);
        // Should not crash
      });

      testWidgets('works without actions', (tester) async {
        await tester.pumpWidget(
          wrapWithTheme(AppPageHeader(title: 'Test Title')),
        );

        expect(find.text('Test Title'), findsOneWidget);
        // Should not crash
      });

      testWidgets('works with empty actions list', (tester) async {
        await tester.pumpWidget(
          wrapWithTheme(AppPageHeader(title: 'Test Title', actions: [])),
        );

        expect(find.text('Test Title'), findsOneWidget);
        // Should not crash
      });
    });

    group('Interaction', () {
      testWidgets('leading widget is interactive', (tester) async {
        bool tapped = false;

        await tester.pumpWidget(
          wrapWithTheme(
            AppPageHeader(
              title: 'Test Title',
              leading: WButton(
                onTap: () => tapped = true,
                child: WIcon(Icons.arrow_back),
                key: Key('back-button'),
              ),
            ),
          ),
        );

        await tester.tap(find.byKey(Key('back-button')));
        await tester.pumpAndSettle();

        expect(tapped, isTrue);
      });

      testWidgets('action buttons are interactive', (tester) async {
        bool addTapped = false;
        bool searchTapped = false;

        await tester.pumpWidget(
          wrapWithTheme(
            AppPageHeader(
              title: 'Test Title',
              actions: [
                WButton(
                  onTap: () => searchTapped = true,
                  child: WIcon(Icons.search),
                  key: Key('search-button'),
                ),
                WButton(
                  onTap: () => addTapped = true,
                  child: WIcon(Icons.add),
                  key: Key('add-button'),
                ),
              ],
            ),
          ),
        );

        await tester.tap(find.byKey(Key('add-button')));
        await tester.pumpAndSettle();
        expect(addTapped, isTrue);

        await tester.tap(find.byKey(Key('search-button')));
        await tester.pumpAndSettle();
        expect(searchTapped, isTrue);
      });
    });

    group('Full Width', () {
      testWidgets('spans full width', (tester) async {
        await tester.pumpWidget(
          wrapWithTheme(AppPageHeader(title: 'Test Title')),
        );

        final header = tester.widget<WDiv>(find.byType(WDiv).first);

        expect(header.className?.contains('w-full'), isTrue);
      });
    });
  });
}
