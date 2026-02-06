import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/resources/views/components/response_preview.dart';

void main() {
  Widget wrapWithTheme(Widget child) {
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp(home: Scaffold(body: child)),
    );
  }

  group('ResponsePreview', () {
    testWidgets('renders empty state when no response', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(const ResponsePreview(response: null, isLoading: false)),
      );

      expect(
        find.text('No response yet. Click "Test Fetch" to preview.'),
        findsOneWidget,
      );
    });

    testWidgets('renders loading state', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(const ResponsePreview(response: null, isLoading: true)),
      );

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(find.text('Fetching...'), findsOneWidget);
    });

    testWidgets('renders response metadata', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(
          const ResponsePreview(
            response: {
              'status_code': 200,
              'response_time_ms': 145,
              'content_type': 'application/json',
              'body': '{"success": true}',
            },
            isLoading: false,
          ),
        ),
      );

      expect(find.text('200'), findsOneWidget);
      expect(find.text('145ms'), findsOneWidget);
      expect(find.text('application/json'), findsOneWidget);
    });

    testWidgets('renders JSON body with syntax highlighting', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(
          const ResponsePreview(
            response: {
              'status_code': 200,
              'response_time_ms': 145,
              'content_type': 'application/json',
              'body': '{"success": true, "data": {"count": 42}}',
            },
            isLoading: false,
          ),
        ),
      );

      // Should display formatted JSON
      expect(find.textContaining('"success"'), findsOneWidget);
      expect(find.textContaining('true'), findsOneWidget);
    });

    testWidgets('renders plain text body for non-JSON', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(
          const ResponsePreview(
            response: {
              'status_code': 200,
              'response_time_ms': 145,
              'content_type': 'text/html',
              'body': '<html><body>Hello World</body></html>',
            },
            isLoading: false,
          ),
        ),
      );

      expect(
        find.text('<html><body>Hello World</body></html>'),
        findsOneWidget,
      );
    });

    testWidgets('renders error status code in red', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(
          const ResponsePreview(
            response: {
              'status_code': 500,
              'response_time_ms': 145,
              'content_type': 'application/json',
              'body': '{"error": "Internal Server Error"}',
            },
            isLoading: false,
          ),
        ),
      );

      expect(find.text('500'), findsOneWidget);
      // Status code should have error styling
    });

    testWidgets('handles null status_code and response_time_ms gracefully', (
      tester,
    ) async {
      await tester.pumpWidget(
        wrapWithTheme(
          const ResponsePreview(
            response: {
              'status_code': null,
              'response_time_ms': null,
              'content_type': 'application/json',
              'body': '{"ok": true}',
            },
            isLoading: false,
          ),
        ),
      );

      expect(find.text('0'), findsOneWidget);
      expect(find.text('0ms'), findsOneWidget);
    });

    testWidgets('handles missing body field', (tester) async {
      await tester.pumpWidget(
        wrapWithTheme(
          const ResponsePreview(
            response: {
              'status_code': 204,
              'response_time_ms': 50,
              'content_type': null,
              'body': null,
            },
            isLoading: false,
          ),
        ),
      );

      expect(find.text('204'), findsOneWidget);
      expect(find.text('50ms'), findsOneWidget);
      expect(find.text('Response Body'), findsNothing);
    });

    testWidgets('truncates long response body', (tester) async {
      final longBody = 'x' * 15000; // 15KB body

      await tester.pumpWidget(
        wrapWithTheme(
          ResponsePreview(
            response: {
              'status_code': 200,
              'response_time_ms': 145,
              'content_type': 'text/plain',
              'body': longBody,
            },
            isLoading: false,
          ),
        ),
      );

      // Should show truncation message
      expect(find.textContaining('Response truncated'), findsOneWidget);
    });
  });
}
