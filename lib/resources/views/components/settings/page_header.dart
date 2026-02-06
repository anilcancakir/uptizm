import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Page Header Component
///
/// Header section for settings/form pages with breadcrumbs, title, and actions.
/// Uses Wind UI widgets.
class PageHeader extends StatelessWidget {
  final String title;
  final List<String>? breadcrumbs;
  final List<Widget>? actions;

  const PageHeader({
    super.key,
    required this.title,
    this.breadcrumbs,
    this.actions,
  });

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className:
          'w-full px-6 py-4 bg-white border-b border-gray-200 flex items-center justify-between',
      children: [
        // Left: Breadcrumb + Title
        WDiv(
          className: 'flex flex-col gap-1',
          children: [
            if (breadcrumbs != null && breadcrumbs!.isNotEmpty)
              WDiv(
                className: 'flex items-center gap-2',
                children: [
                  for (int i = 0; i < breadcrumbs!.length; i++) ...[
                    WText(breadcrumbs![i], className: 'text-sm text-slate-500'),
                    if (i < breadcrumbs!.length - 1)
                      WIcon(
                        Icons.chevron_right,
                        className: 'text-sm text-slate-400',
                      ),
                  ],
                ],
              ),
            WText(title, className: 'text-xl font-bold text-slate-900'),
          ],
        ),
        // Right: Actions
        if (actions != null && actions!.isNotEmpty)
          WDiv(className: 'flex items-center gap-3', children: actions!),
      ],
    );
  }
}
