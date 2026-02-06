import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

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
          'flex flex-row items-center justify-between py-4 border-t border-gray-100 dark:border-gray-700',
      children: [
        // Previous Button
        WButton(
          onTap: hasPrevious && !isLoading ? onPrevious : null,
          disabled: !hasPrevious || isLoading,
          className: '''
            px-2 sm:px-3 py-2 rounded-lg
            border border-gray-200 dark:border-gray-700
            bg-white dark:bg-gray-800
            text-sm font-medium text-gray-700 dark:text-gray-200
            hover:bg-gray-50 dark:hover:bg-gray-700
            disabled:opacity-50 disabled:cursor-not-allowed
          ''',
          child: WDiv(
            className: 'flex flex-row items-center',
            children: [
              WIcon(Icons.chevron_left, className: 'text-lg'),
              // Text hidden on mobile, visible on sm+
              WText(
                trans('pagination.previous'),
                className: 'hidden sm:block ml-1',
              ),
            ],
          ),
        ),

        // Page Info - compact on mobile
        WText(
          '$currentPage / $totalPages',
          className: 'text-sm text-gray-600 dark:text-gray-400 px-2',
        ),

        // Next Button
        WButton(
          onTap: hasNext && !isLoading ? onNext : null,
          disabled: !hasNext || isLoading,
          className: '''
            px-2 sm:px-3 py-2 rounded-lg
            border border-gray-200 dark:border-gray-700
            bg-white dark:bg-gray-800
            text-sm font-medium text-gray-700 dark:text-gray-200
            hover:bg-gray-50 dark:hover:bg-gray-700
            disabled:opacity-50 disabled:cursor-not-allowed
          ''',
          child: WDiv(
            className: 'flex flex-row items-center',
            children: [
              // Text hidden on mobile, visible on sm+
              WText(
                trans('pagination.next'),
                className: 'hidden sm:block mr-1',
              ),
              WIcon(Icons.chevron_right, className: 'text-lg'),
            ],
          ),
        ),
      ],
    );
  }
}
