import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../theme_toggle_button.dart';

/// AuthFormCard
///
/// Consistent card wrapper for all auth forms.
/// Provides title, subtitle, optional error banner, and content area.
class AuthFormCard extends StatelessWidget {
  final String title;
  final String subtitle;
  final String? errorMessage;
  final Widget child;

  const AuthFormCard({
    super.key,
    required this.title,
    required this.subtitle,
    required this.child,
    this.errorMessage,
  });

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        rounded-2xl bg-white dark:bg-slate-800
        border border-slate-200 dark:border-slate-700
        p-4 lg:p-8 w-full max-w-[440px]
        flex flex-col items-center
      ''',
      children: [
        // Theme Toggle Button Row
        const WDiv(
          className: 'w-full flex flex-row justify-end mb-2',
          child: ThemeToggleButton(),
        ),

        // Title
        WText(
          title,
          className: 'text-2xl font-bold text-slate-900 dark:text-white text-center',
        ),
        const SizedBox(height: 4),

        // Subtitle
        WText(
          subtitle,
          className: 'text-sm text-slate-600 dark:text-slate-400 text-center',
        ),
        const SizedBox(height: 24),

        // Error Banner (if provided)
        if (errorMessage != null) ...[
          WDiv(
            className: '''
              p-3 rounded-xl
              bg-red-50 dark:bg-red-900/30
              border border-red-200 dark:border-red-800
              text-red-700 dark:text-red-300
              text-sm text-center
            ''',
            child: WText(errorMessage!),
          ),
          const SizedBox(height: 16),
        ],

        // Form Content
        child,
      ],
    );
  }
}
