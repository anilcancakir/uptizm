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
    50: Color(0xFFECFDF5),
    100: Color(0xFFD1FAE5),
    200: Color(0xFFA7F3D0),
    300: Color(0xFF6EE7B7),
    400: Color(0xFF34D399),
    500: Color(0xFF009E60),
    600: Color(0xFF008750),
    700: Color(0xFF006D40),
    800: Color(0xFF005430),
    900: Color(0xFF003D23),
    950: Color(0xFF002414),
  });

  // -------  Theme Data  -------

  /// WindThemeData configured per wind.md design system.
  static WindThemeData get windThemeData =>
      WindThemeData(colors: {'primary': primary});
}
