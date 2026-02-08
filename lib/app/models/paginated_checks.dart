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

  static int? _toInt(dynamic value) {
    if (value == null) return null;
    if (value is num) return value.toInt();
    if (value is String) return int.tryParse(value);
    return null;
  }

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
      currentPage: _toInt(meta['current_page']) ?? 1,
      lastPage: _toInt(meta['last_page']) ?? 1,
      perPage: _toInt(meta['per_page']) ?? 15,
      total: _toInt(meta['total']) ?? 0,
    );
  }
}
