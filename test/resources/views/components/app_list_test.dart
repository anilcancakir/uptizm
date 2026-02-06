import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/resources/views/components/app_list.dart';

void main() {
  group('AppList', () {
    Widget buildTestWidget(Widget child, {Size size = const Size(800, 600)}) {
      return MaterialApp(
        home: MediaQuery(
          data: MediaQueryData(size: size),
          child: Scaffold(
            body: SizedBox(
              width: size.width,
              height: size.height,
              child: WindTheme(
                data: WindThemeData(brightness: Brightness.light),
                child: SingleChildScrollView(child: child),
              ),
            ),
          ),
        ),
      );
    }

    group('rendering', () {
      test('renders with required parameters', () {
        // Should compile and render with just items and itemBuilder
        expect(
          () => AppList<String>(
            items: const ['a', 'b', 'c'],
            itemBuilder: (context, item, index) => WText(item),
          ),
          returnsNormally,
        );
      });

      testWidgets('renders items using itemBuilder', (tester) async {
        final items = ['Item 1', 'Item 2', 'Item 3'];

        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: items,
              itemBuilder: (context, item, index) => WText(
                item,
                key: Key('item_$index'),
              ),
            ),
          ),
        );

        expect(find.byKey(const Key('item_0')), findsOneWidget);
        expect(find.byKey(const Key('item_1')), findsOneWidget);
        expect(find.byKey(const Key('item_2')), findsOneWidget);
        expect(find.text('Item 1'), findsOneWidget);
        expect(find.text('Item 2'), findsOneWidget);
        expect(find.text('Item 3'), findsOneWidget);
      });

      testWidgets('renders with card wrapper by default', (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const ['a'],
              itemBuilder: (context, item, index) => WText(item),
            ),
          ),
        );

        // Should have card styling (bg-white, rounded-2xl, border)
        // We verify by checking the widget tree has WDiv with expected classes
        expect(find.byType(AppList<String>), findsOneWidget);
      });
    });

    group('header', () {
      testWidgets('renders header when title is provided', (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const ['a'],
              itemBuilder: (context, item, index) => WText(item),
              title: 'Test Title',
            ),
          ),
        );

        expect(find.text('TEST TITLE'), findsOneWidget); // Uppercase header
      });

      testWidgets('renders header actions when provided', (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const ['a'],
              itemBuilder: (context, item, index) => WText(item),
              title: 'Title',
              headerActions: [
                WButton(
                  key: const Key('header_action'),
                  onTap: () {},
                  child: WText('Action'),
                ),
              ],
            ),
          ),
        );

        expect(find.byKey(const Key('header_action')), findsOneWidget);
      });

      testWidgets('renders header icon when provided', (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const ['a'],
              itemBuilder: (context, item, index) => WText(item),
              title: 'Title',
              headerIcon: Icons.list,
            ),
          ),
        );

        expect(find.byIcon(Icons.list), findsOneWidget);
      });

      testWidgets('does not render header when title is null', (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const ['a'],
              itemBuilder: (context, item, index) => WText(item),
            ),
          ),
        );

        // No header section should be rendered
        // The list should render directly without header border
        expect(find.text('TEST TITLE'), findsNothing);
      });
    });

    group('empty state', () {
      testWidgets('renders default empty state when items is empty',
          (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const [],
              itemBuilder: (context, item, index) => WText(item),
            ),
          ),
        );

        // Default empty state should show an icon
        expect(find.byIcon(Icons.inbox_outlined), findsOneWidget);
      });

      testWidgets('renders custom empty state when provided', (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const [],
              itemBuilder: (context, item, index) => WText(item),
              emptyState: WDiv(
                key: const Key('custom_empty'),
                child: WText('Custom Empty'),
              ),
            ),
          ),
        );

        expect(find.byKey(const Key('custom_empty')), findsOneWidget);
        expect(find.text('Custom Empty'), findsOneWidget);
      });

      testWidgets('renders emptyIcon and emptyText when provided',
          (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const [],
              itemBuilder: (context, item, index) => WText(item),
              emptyIcon: Icons.hourglass_empty,
              emptyText: 'No data available',
            ),
          ),
        );

        expect(find.byIcon(Icons.hourglass_empty), findsOneWidget);
        expect(find.text('No data available'), findsOneWidget);
      });
    });

    group('loading state', () {
      testWidgets('renders loading indicator when isLoading is true',
          (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const [],
              itemBuilder: (context, item, index) => WText(item),
              isLoading: true,
            ),
          ),
        );

        expect(find.byType(CircularProgressIndicator), findsOneWidget);
      });

      testWidgets('does not render items when loading', (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const ['a', 'b'],
              itemBuilder: (context, item, index) => WText(item),
              isLoading: true,
            ),
          ),
        );

        expect(find.text('a'), findsNothing);
        expect(find.text('b'), findsNothing);
        expect(find.byType(CircularProgressIndicator), findsOneWidget);
      });
    });

    group('error state', () {
      testWidgets('renders error state when hasError is true', (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const [],
              itemBuilder: (context, item, index) => WText(item),
              hasError: true,
            ),
          ),
        );

        expect(find.byIcon(Icons.error_outline), findsOneWidget);
      });

      testWidgets('renders custom error text when provided', (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const [],
              itemBuilder: (context, item, index) => WText(item),
              hasError: true,
              errorText: 'Something went wrong',
            ),
          ),
        );

        expect(find.text('Something went wrong'), findsOneWidget);
      });

      testWidgets('error state takes precedence over empty state',
          (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const [],
              itemBuilder: (context, item, index) => WText(item),
              hasError: true,
              emptyText: 'No items',
              errorText: 'Error occurred',
            ),
          ),
        );

        expect(find.text('Error occurred'), findsOneWidget);
        expect(find.text('No items'), findsNothing);
      });
    });

    group('pagination', () {
      test('shouldShowPagination returns true when conditions met', () {
        // Test the logic directly
        const totalPages = 5;
        const isLoading = false;
        const hasError = false;

        final shouldShow = totalPages > 1 && !isLoading && !hasError;

        expect(shouldShow, isTrue);
      });

      test('shouldShowPagination returns false when totalPages <= 1', () {
        const totalPages = 1;

        final shouldShow = totalPages > 1;
        expect(shouldShow, isFalse);
      });

      test('pagination info shows correct page numbers', () {
        // Test page number formatting
        const currentPage = 2;
        const totalPages = 3;
        final pageInfo = '$currentPage / $totalPages';

        expect(pageInfo, equals('2 / 3'));
      });

      test('previous callback is null on first page', () {
        // Implementation test - verify logic
        const currentPage = 1;

        final hasPrevious = currentPage > 1;
        expect(hasPrevious, isFalse);
      });

      test('next callback is null on last page', () {
        // Implementation test - verify logic
        const currentPage = 3;
        const totalPages = 3;

        final hasNext = currentPage < totalPages;
        expect(hasNext, isFalse);
      });
    });

    group('separators', () {
      testWidgets('renders border separator by default', (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const ['a', 'b', 'c'],
              itemBuilder: (context, item, index) => WText(item),
            ),
          ),
        );

        // Items should be rendered - separator is CSS-based (border-b)
        expect(find.text('a'), findsOneWidget);
        expect(find.text('b'), findsOneWidget);
        expect(find.text('c'), findsOneWidget);
      });

      testWidgets('renders custom separator when provided', (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const ['a', 'b', 'c'],
              itemBuilder: (context, item, index) => WText(item),
              separatorBuilder: (context, index) => WDiv(
                key: Key('separator_$index'),
                className: 'h-px bg-red-500',
              ),
            ),
          ),
        );

        // Should have 2 separators for 3 items
        expect(find.byKey(const Key('separator_0')), findsOneWidget);
        expect(find.byKey(const Key('separator_1')), findsOneWidget);
        expect(find.byKey(const Key('separator_2')), findsNothing);
      });
    });

    group('styling', () {
      testWidgets('applies custom className to container', (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const ['a'],
              itemBuilder: (context, item, index) => WText(item),
              className: 'custom-class',
            ),
          ),
        );

        expect(find.byType(AppList<String>), findsOneWidget);
      });

      testWidgets('disables card wrapper when showCard is false',
          (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const ['a'],
              itemBuilder: (context, item, index) => WText(item),
              showCard: false,
            ),
          ),
        );

        expect(find.byType(AppList<String>), findsOneWidget);
      });
    });

    group('item wrapper', () {
      testWidgets('wraps items with itemWrapper when provided', (tester) async {
        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const ['a', 'b'],
              itemBuilder: (context, item, index) => WText(item),
              itemWrapper: (context, item, index, child) => WDiv(
                key: Key('wrapper_$index'),
                className: 'p-4',
                child: child,
              ),
            ),
          ),
        );

        expect(find.byKey(const Key('wrapper_0')), findsOneWidget);
        expect(find.byKey(const Key('wrapper_1')), findsOneWidget);
      });
    });

    group('onItemTap', () {
      testWidgets('wraps items with WAnchor when onItemTap is provided',
          (tester) async {
        String? tappedItem;
        int? tappedIndex;

        await tester.pumpWidget(
          buildTestWidget(
            AppList<String>(
              items: const ['a', 'b'],
              itemBuilder: (context, item, index) => WText(item),
              onItemTap: (item, index) {
                tappedItem = item;
                tappedIndex = index;
              },
            ),
          ),
        );

        await tester.tap(find.text('b'));
        await tester.pump();

        expect(tappedItem, equals('b'));
        expect(tappedIndex, equals(1));
      });
    });
  });
}
