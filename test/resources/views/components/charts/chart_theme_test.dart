import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/resources/views/components/charts/chart_theme.dart';

void main() {
  group('UptizmChartTheme', () {
    test('has correct primary color from brand', () {
      expect(UptizmChartTheme.primary, equals(const Color(0xFF009E60)));
    });

    test('has semantic status colors', () {
      expect(UptizmChartTheme.success, equals(const Color(0xFF009E60)));
      expect(UptizmChartTheme.warning, equals(const Color(0xFFF59E0B)));
      expect(UptizmChartTheme.error, equals(const Color(0xFFEF4444)));
    });

    test('has grid and text colors for light/dark mode', () {
      expect(UptizmChartTheme.gridLight, isA<Color>());
      expect(UptizmChartTheme.gridDark, isA<Color>());
      expect(UptizmChartTheme.textLight, isA<Color>());
      expect(UptizmChartTheme.textDark, isA<Color>());
    });

    test('gradient returns valid gradient colors', () {
      final gradient = UptizmChartTheme.primaryGradient;
      expect(gradient, isA<List<Color>>());
      expect(gradient.length, equals(2));
    });

    test('getStatusColor returns correct color for status', () {
      expect(UptizmChartTheme.getStatusColor('up'), equals(UptizmChartTheme.success));
      expect(UptizmChartTheme.getStatusColor('down'), equals(UptizmChartTheme.error));
      expect(UptizmChartTheme.getStatusColor('degraded'), equals(UptizmChartTheme.warning));
      expect(UptizmChartTheme.getStatusColor('unknown'), equals(UptizmChartTheme.textLight));
    });
  });
}
