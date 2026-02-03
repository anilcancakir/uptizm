import 'package:uptizm/app/models/monitor_check.dart';

class PaginatedChecks {
  final List<MonitorCheck> checks;
  final int currentPage;
  final int lastPage;
  final int perPage;
  final int total;

  const PaginatedChecks({
    required this.checks,
    required this.currentPage,
    required this.lastPage,
    required this.perPage,
    required this.total,
  });

  bool get hasNextPage => currentPage < lastPage;
  bool get hasPreviousPage => currentPage > 1;

  factory PaginatedChecks.fromResponse(Map<String, dynamic> response) {
    // Parse checks list
    List<MonitorCheck> checks = [];
    if (response['data'] is List) {
      checks = (response['data'] as List)
          .map((item) => MonitorCheck.fromMap(item as Map<String, dynamic>))
          .toList();
    }

    // Parse pagination meta
    final meta = response['meta'] as Map<String, dynamic>? ?? {};

    return PaginatedChecks(
      checks: checks,
      currentPage: (meta['current_page'] as num?)?.toInt() ?? 1,
      lastPage: (meta['last_page'] as num?)?.toInt() ?? 1,
      perPage: (meta['per_page'] as num?)?.toInt() ?? 15,
      total: (meta['total'] as num?)?.toInt() ?? 0,
    );
  }
}
