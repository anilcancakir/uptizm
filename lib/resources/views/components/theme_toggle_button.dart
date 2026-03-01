import 'package:flutter/material.dart';
import 'package:magic/magic.dart';


/// Theme Toggle Button
///
/// Shared component for toggling between light and dark themes.
/// Preference is automatically persisted by MagicApplication.
///
/// Usage:
/// ```dart
/// ThemeToggleButton()
/// ```
class ThemeToggleButton extends StatelessWidget {
  const ThemeToggleButton({super.key});

  @override
  Widget build(BuildContext context) {
    return WAnchor(
      onTap: () {
        context.windTheme.toggleTheme();
      },
      child: WDiv(
        className: '''
          p-2 rounded-lg duration-150
          bg-transparent hover:bg-gray-100 dark:hover:bg-gray-800
          flex items-center justify-center
        ''',
        child: WIcon(
          Icons.brightness_6_outlined,
          className: 'text-2xl text-gray-500 dark:text-gray-400',
        ),
      ),
    );
  }
}
