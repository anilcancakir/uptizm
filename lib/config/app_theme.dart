import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Uptizm design system theme configuration.
///
/// Generated from wind.md. Primary: Sage Green #009E60 (hue 155).
/// HSL-based shade generation per design-principles.md.
///
/// ## Usage
/// ```dart
/// final theme = AppTheme.windThemeData;
/// MagicApplication(windTheme: theme, ...);
/// ```
class AppTheme {
  AppTheme._();

  // -------  Brand Colors  -------

  /// Primary sage green palette (hue 155, HSL-generated shades).
  static const MaterialColor primary = MaterialColor(0xFF009E60, <int, Color>{
    50: Color(0xFFEDF8F3),
    100: Color(0xFFD2EDDF),
    200: Color(0xFFA3D9BF),
    300: Color(0xFF5EBD96),
    400: Color(0xFF20AF74),
    500: Color(0xFF009E60),
    600: Color(0xFF008551),
    700: Color(0xFF006B3F),
    800: Color(0xFF00522F),
    900: Color(0xFF013820),
    950: Color(0xFF022113),
  });

  // -------  Theme Data  -------

  /// WindThemeData configured per wind.md design system.
  static WindThemeData get windThemeData =>
      WindThemeData(colors: {'primary': primary});
}
