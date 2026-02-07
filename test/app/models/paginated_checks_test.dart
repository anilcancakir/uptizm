import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/models/paginated_checks.dart';
import 'package:uptizm/app/models/monitor_check.dart';

void main() {
  group('PaginatedChecks', () {
    test('fromResponse parses data and meta correctly', () {
      final response = {
        'data': [
          {
            'id': 'test-uuid-1',
            'status': 'up',
            'response_time_ms': 100,
            'checked_at': '2023-01-01T12:00:00Z',
          },
          {
            'id': 'test-uuid-2',
            'status': 'down',
            'response_time_ms': 500,
            'checked_at': '2023-01-01T12:01:00Z',
          },
        ],
        'meta': {
          'current_page': 2,
          'last_page': 5,
          'per_page': 10,
          'total': 50,
        },
      };

      final paginated = PaginatedChecks.fromResponse(response);

      expect(paginated.checks.length, 2);
      expect(paginated.checks.first, isA<MonitorCheck>());
      expect(paginated.checks.first.id, 'test-uuid-1');

      expect(paginated.currentPage, 2);
      expect(paginated.lastPage, 5);
      expect(paginated.perPage, 10);
      expect(paginated.total, 50);
    });

    test('hasNextPage returns true when current_page < last_page', () {
      final paginated = PaginatedChecks(
        checks: [],
        currentPage: 1,
        lastPage: 2,
        perPage: 10,
        total: 15,
      );

      expect(paginated.hasNextPage, isTrue);
    });

    test('hasNextPage returns false when current_page >= last_page', () {
      final paginated = PaginatedChecks(
        checks: [],
        currentPage: 2,
        lastPage: 2,
        perPage: 10,
        total: 15,
      );

      expect(paginated.hasNextPage, isFalse);
    });

    test('hasPreviousPage returns true when current_page > 1', () {
      final paginated = PaginatedChecks(
        checks: [],
        currentPage: 2,
        lastPage: 3,
        perPage: 10,
        total: 25,
      );

      expect(paginated.hasPreviousPage, isTrue);
    });

    test('hasPreviousPage returns false when current_page is 1', () {
      final paginated = PaginatedChecks(
        checks: [],
        currentPage: 1,
        lastPage: 3,
        perPage: 10,
        total: 25,
      );

      expect(paginated.hasPreviousPage, isFalse);
    });

    test('handles missing meta gracefully using defaults', () {
      final response = {
        'data': [],
        // missing meta
      };

      final paginated = PaginatedChecks.fromResponse(response);

      expect(paginated.currentPage, 1);
      expect(paginated.lastPage, 1);
      expect(paginated.perPage, 15);
      expect(paginated.total, 0);
    });
  });
}
