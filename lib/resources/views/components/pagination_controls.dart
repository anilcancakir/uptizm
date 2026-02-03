import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

class PaginationControls extends StatelessWidget {
  final int currentPage;
  final int totalPages;
  final bool hasPrevious;
  final bool hasNext;
  final VoidCallback? onPrevious;
  final VoidCallback? onNext;
  final bool isLoading;

  const PaginationControls({
    super.key,
    required this.currentPage,
    required this.totalPages,
    required this.hasPrevious,
    required this.hasNext,
    this.onPrevious,
    this.onNext,
    this.isLoading = false,
  });

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className:
          'flex items-center justify-between py-4 border-t border-gray-100 dark:border-gray-700',
      children: [
        // Previous Button
        WButton(
          onTap: hasPrevious && !isLoading ? onPrevious : null,
          disabled: !hasPrevious || isLoading,
          className: '''
            px-3 py-2 rounded-lg
            border border-gray-200 dark:border-gray-700
            bg-white dark:bg-gray-800
            text-sm font-medium text-gray-700 dark:text-gray-200
            hover:bg-gray-50 dark:hover:bg-gray-700
            disabled:opacity-50 disabled:cursor-not-allowed
          ''',
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              WIcon(Icons.chevron_left, className: 'text-lg'),
              const SizedBox(width: 8),
              WText(trans('pagination.previous')),
            ],
          ),
        ),

        // Page Info
        WText(
          trans('pagination.page_of', {
            'current': currentPage,
            'total': totalPages,
          }),
          className: 'text-sm text-gray-600 dark:text-gray-400',
        ),

        // Next Button
        WButton(
          onTap: hasNext && !isLoading ? onNext : null,
          disabled: !hasNext || isLoading,
          className: '''
            px-3 py-2 rounded-lg
            border border-gray-200 dark:border-gray-700
            bg-white dark:bg-gray-800
            text-sm font-medium text-gray-700 dark:text-gray-200
            hover:bg-gray-50 dark:hover:bg-gray-700
            disabled:opacity-50 disabled:cursor-not-allowed
          ''',
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              WText(trans('pagination.next')),
              const SizedBox(width: 8),
              WIcon(Icons.chevron_right, className: 'text-lg'),
            ],
          ),
        ),
      ],
    );
  }
}
