import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// App Card Component
///
/// Global reusable card with header, body, and optional footer.
/// Supports expandable/collapsible content (click icon to toggle).
///
/// ```dart
/// AppCard(
///   title: 'General Information',
///   icon: Icons.info_outline,
///   body: Column(...),
///   footer: Row(...),
///   expandable: true,
///   initiallyExpanded: false,
/// )
/// ```
class AppCard extends StatefulWidget {
  const AppCard({
    super.key,
    required this.title,
    this.icon,
    required this.body,
    this.footer,
    this.expandable = false,
    this.initiallyExpanded = true,
    this.headerActions,
    this.titleClassName,
  });

  /// Card header title.
  final String title;

  /// Optional class name for title.
  final String? titleClassName;

  /// Optional header icon.
  final IconData? icon;

  /// Card body content.
  final Widget body;

  /// Optional footer (typically action buttons).
  final Widget? footer;

  /// Whether the card can be collapsed/expanded.
  final bool expandable;

  /// Initial expansion state (only used if expandable is true).
  final bool initiallyExpanded;

  /// Optional actions to show in the header.
  final List<Widget>? headerActions;

  @override
  State<AppCard> createState() => _AppCardState();
}

class _AppCardState extends State<AppCard> with SingleTickerProviderStateMixin {
  late bool _isExpanded;
  late AnimationController _controller;
  late Animation<double> _expandAnimation;

  @override
  void initState() {
    super.initState();
    _isExpanded = widget.expandable ? widget.initiallyExpanded : true;
    _controller = AnimationController(
      duration: const Duration(milliseconds: 200),
      vsync: this,
    );
    _expandAnimation = CurvedAnimation(
      parent: _controller,
      curve: Curves.easeInOut,
    );

    if (_isExpanded) {
      _controller.value = 1.0;
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _toggleExpansion() {
    setState(() {
      _isExpanded = !_isExpanded;
      if (_isExpanded) {
        _controller.forward();
      } else {
        _controller.reverse();
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        flex flex-col 
        bg-white dark:bg-gray-800 
        rounded-xl 
        text-gray-900 dark:text-white 
        w-full
        border border-gray-200 dark:border-gray-700
      ''',
      children: [
        // Header
        WDiv(
          className:
              '''
            flex flex-row items-center justify-between w-full 
            px-4 lg:px-6 py-4 
            ${_isExpanded ? 'border-b border-gray-200 dark:border-gray-700' : ''}
            rounded-t-xl 
            text-sm text-gray-600 dark:text-gray-200
          ''',
          children: [
            WText(
              widget.title,
              className:
                  'uppercase font-semibold tracking-wide ${widget.titleClassName ?? ''}',
            ),
            if (widget.icon != null ||
                widget.headerActions != null ||
                widget.expandable)
              WDiv(
                className: 'flex flex-row items-center gap-2',
                children: [
                  // Header actions
                  if (widget.headerActions != null) ...widget.headerActions!,

                  // Static icon
                  if (widget.icon != null)
                    WIcon(
                      widget.icon!,
                      className: 'text-2xl text-gray-400 dark:text-gray-500',
                    ),

                  // Expand/collapse icon (click to toggle)
                  if (widget.expandable)
                    WAnchor(
                      onTap: _toggleExpansion,
                      child: AnimatedRotation(
                        turns: _isExpanded ? 0 : -0.25,
                        duration: const Duration(milliseconds: 200),
                        child: WDiv(
                          className: '''
                            p-1 rounded-md
                            hover:bg-gray-200 dark:hover:bg-gray-600
                            duration-150
                          ''',
                          child: WIcon(
                            Icons.keyboard_arrow_down,
                            className: '''
                              text-2xl text-gray-400 dark:text-gray-500 
                              hover:text-primary
                              duration-150
                            ''',
                          ),
                        ),
                      ),
                    ),
                ],
              ),
          ],
        ),

        // Expandable Body & Footer
        SizeTransition(
          sizeFactor: _expandAnimation,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              // Body
              WDiv(className: 'p-4 lg:p-6', child: widget.body),

              // Footer (optional)
              if (widget.footer != null)
                WDiv(
                  className: '''
                    py-4 px-4 lg:px-6 
                    border-t border-gray-200 dark:border-gray-700 
                    w-full rounded-b-xl 
                    bg-gray-50 dark:bg-gray-800/50
                  ''',
                  child: widget.footer,
                ),
            ],
          ),
        ),
      ],
    );
  }
}
