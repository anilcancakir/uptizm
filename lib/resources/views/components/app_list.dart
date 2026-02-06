import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import 'pagination_controls.dart';

/// AppList - Reusable list component with consistent styling
///
/// A generic list widget that provides:
/// - Card container with consistent styling
/// - Optional header with title, icon, and actions
/// - Empty, loading, and error states
/// - Pagination support
/// - Custom separators
/// - Item tap handling
///
/// Example usage:
/// ```dart
/// AppList<Monitor>(
///   items: monitors,
///   itemBuilder: (context, monitor, index) => MonitorListItem(monitor),
///   title: 'Monitors',
///   headerIcon: Icons.monitor_heart,
///   emptyIcon: Icons.monitor_heart_outlined,
///   emptyText: 'No monitors found',
///   isLoading: controller.isLoading,
///   currentPage: 1,
///   totalPages: 5,
///   onPageChange: (page) => controller.loadPage(page),
/// )
/// ```
class AppList<T> extends StatelessWidget {
  /// The list of items to display
  final List<T> items;

  /// Builder function for each item
  final Widget Function(BuildContext context, T item, int index) itemBuilder;

  /// Optional title for the header section
  final String? title;

  /// Optional icon for the header (displayed with primary background)
  final IconData? headerIcon;

  /// Optional actions to display in the header
  final List<Widget>? headerActions;

  /// Whether to show the card wrapper (default: true)
  final bool showCard;

  /// Additional className for the container
  final String? className;

  /// Whether the list is loading
  final bool isLoading;

  /// Whether there's an error
  final bool hasError;

  /// Error text to display
  final String? errorText;

  /// Custom empty state widget
  final Widget? emptyState;

  /// Icon for default empty state
  final IconData? emptyIcon;

  /// Text for default empty state
  final String? emptyText;

  /// Current page for pagination
  final int? currentPage;

  /// Total pages for pagination
  final int? totalPages;

  /// Callback when page changes
  final ValueChanged<int>? onPageChange;

  /// Custom separator builder
  final Widget Function(BuildContext context, int index)? separatorBuilder;

  /// Wrapper for each item (e.g., to add padding or styling)
  final Widget Function(BuildContext context, T item, int index, Widget child)?
  itemWrapper;

  /// Callback when an item is tapped
  final void Function(T item, int index)? onItemTap;

  /// Whether pagination is loading
  final bool isPaginationLoading;

  const AppList({
    super.key,
    required this.items,
    required this.itemBuilder,
    this.title,
    this.headerIcon,
    this.headerActions,
    this.showCard = true,
    this.className,
    this.isLoading = false,
    this.hasError = false,
    this.errorText,
    this.emptyState,
    this.emptyIcon,
    this.emptyText,
    this.currentPage,
    this.totalPages,
    this.onPageChange,
    this.separatorBuilder,
    this.itemWrapper,
    this.onItemTap,
    this.isPaginationLoading = false,
  });

  @override
  Widget build(BuildContext context) {
    final content = WDiv(
      className: 'flex flex-col',
      children: [
        if (title != null) _buildHeader(),
        _buildContent(),
        if (_shouldShowPagination) _buildPagination(),
      ],
    );

    if (!showCard) return content;

    return WDiv(
      className:
          '''
        bg-white dark:bg-gray-800
        border border-gray-100 dark:border-gray-700
        rounded-2xl overflow-hidden
        $className
      ''',
      child: content,
    );
  }

  bool get _shouldShowPagination =>
      currentPage != null &&
      totalPages != null &&
      totalPages! > 1 &&
      onPageChange != null &&
      !isLoading &&
      !hasError;

  Widget _buildHeader() {
    return WDiv(
      className: 'p-5 border-b border-gray-100 dark:border-gray-700',
      child: WDiv(
        className: 'flex flex-row items-center justify-between',
        children: [
          WDiv(
            className: 'flex flex-row items-center',
            children: [
              if (headerIcon != null) ...[
                WDiv(
                  className: 'p-2 rounded-lg bg-primary/10',
                  child: WIcon(headerIcon!, className: 'text-primary text-lg'),
                ),
                const WSpacer(className: 'w-3'),
              ],
              WText(
                title!.toUpperCase(),
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400',
              ),
            ],
          ),
          if (headerActions != null && headerActions!.isNotEmpty)
            WDiv(
              className: 'flex flex-row items-center gap-2',
              children: headerActions!,
            ),
        ],
      ),
    );
  }

  Widget _buildContent() {
    // Loading state
    if (isLoading) {
      return WDiv(
        className: 'py-12 flex items-center justify-center',
        child: const CircularProgressIndicator(),
      );
    }

    // Error state
    if (hasError) {
      return _buildErrorState();
    }

    // Empty state
    if (items.isEmpty) {
      return emptyState ?? _buildDefaultEmptyState();
    }

    // Items list
    return _buildItemsList();
  }

  Widget _buildDefaultEmptyState() {
    return WDiv(
      className: 'p-12 flex flex-col items-center justify-center w-full',
      children: [
        WIcon(
          emptyIcon ?? Icons.inbox_outlined,
          className: 'text-4xl text-gray-400 dark:text-gray-600 mb-2',
        ),
        if (emptyText != null)
          WText(
            emptyText!,
            className: 'text-sm text-gray-600 dark:text-gray-400',
          ),
      ],
    );
  }

  Widget _buildErrorState() {
    return WDiv(
      className: 'p-12 flex flex-col items-center justify-center w-full',
      children: [
        WIcon(
          Icons.error_outline,
          className: 'text-4xl text-red-400 dark:text-red-500 mb-2',
        ),
        if (errorText != null)
          WText(
            errorText!,
            className: 'text-sm text-gray-600 dark:text-gray-400',
          ),
      ],
    );
  }

  Widget _buildItemsList() {
    return _ItemsListBuilder<T>(
      items: items,
      itemBuilder: itemBuilder,
      itemWrapper: itemWrapper,
      onItemTap: onItemTap,
      separatorBuilder: separatorBuilder,
    );
  }

  Widget _buildPagination() {
    return WDiv(
      className: 'px-5',
      child: PaginationControls(
        currentPage: currentPage!,
        totalPages: totalPages!,
        hasPrevious: currentPage! > 1,
        hasNext: currentPage! < totalPages!,
        isLoading: isPaginationLoading,
        onPrevious: currentPage! > 1
            ? () => onPageChange!(currentPage! - 1)
            : null,
        onNext: currentPage! < totalPages!
            ? () => onPageChange!(currentPage! + 1)
            : null,
      ),
    );
  }
}

/// Internal widget to build items list with proper BuildContext access
class _ItemsListBuilder<T> extends StatelessWidget {
  final List<T> items;
  final Widget Function(BuildContext context, T item, int index) itemBuilder;
  final Widget Function(BuildContext context, T item, int index, Widget child)?
  itemWrapper;
  final void Function(T item, int index)? onItemTap;
  final Widget Function(BuildContext context, int index)? separatorBuilder;

  const _ItemsListBuilder({
    required this.items,
    required this.itemBuilder,
    this.itemWrapper,
    this.onItemTap,
    this.separatorBuilder,
  });

  @override
  Widget build(BuildContext context) {
    final List<Widget> children = [];

    for (int i = 0; i < items.length; i++) {
      final item = items[i];
      Widget itemWidget = itemBuilder(context, item, i);

      // Wrap with itemWrapper if provided
      if (itemWrapper != null) {
        itemWidget = itemWrapper!(context, item, i, itemWidget);
      }

      // Wrap with WAnchor if onItemTap is provided
      if (onItemTap != null) {
        itemWidget = WAnchor(
          onTap: () => onItemTap!(item, i),
          child: itemWidget,
        );
      }

      // Add default border styling if no separator builder
      if (separatorBuilder == null) {
        itemWidget = WDiv(
          className: i < items.length - 1
              ? 'border-b border-gray-100 dark:border-gray-700'
              : '',
          child: itemWidget,
        );
      }

      children.add(itemWidget);

      // Add custom separator if provided (not after last item)
      if (separatorBuilder != null && i < items.length - 1) {
        children.add(separatorBuilder!(context, i));
      }
    }

    return WDiv(children: children);
  }
}
