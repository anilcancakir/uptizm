import 'package:flutter/material.dart';

/// Uptizm Chart Theme
///
/// Brand-compliant colors and styles for fl_chart widgets.
/// Based on brand.md color palette.
class UptizmChartTheme {
  UptizmChartTheme._();

  // Primary brand color
  static const Color primary = Color(0xFF009E60);
  static const Color primaryDark = Color(0xFF007A49);

  // Semantic status colors
  static const Color success = Color(0xFF009E60); // Up
  static const Color warning = Color(0xFFF59E0B); // Degraded
  static const Color error = Color(0xFFEF4444); // Down
  static const Color info = Color(0xFF3B82F6);

  // Grid colors
  static const Color gridLight = Color(0xFFE5E7EB); // gray-200
  static const Color gridDark = Color(0xFF374151); // gray-700

  // Text colors
  static const Color textLight = Color(0xFF6B7280); // gray-500
  static const Color textDark = Color(0xFF9CA3AF); // gray-400

  // Background colors
  static const Color bgLight = Color(0xFFF9FAFB); // gray-50
  static const Color bgDark = Color(0xFF1F2937); // gray-800

  // Gradient for line charts (primary with opacity)
  static List<Color> get primaryGradient => [
    primary.withOpacity(0.3),
    primary.withOpacity(0.05),
  ];

  // Get status color by status string
  static Color getStatusColor(String? status) {
    switch (status) {
      case 'up':
        return success;
      case 'down':
        return error;
      case 'degraded':
        return warning;
      default:
        return textLight;
    }
  }

  // Chart-specific styles
  static const double lineWidth = 2.0;
  static const double dotRadius = 3.0;
  static const double tooltipRadius = 8.0;

  // Touch response
  static const Duration tooltipDuration = Duration(milliseconds: 150);
}
