// GENERATED: do not edit by hand.
// Regenerate via: dart run magic:artisan design:sync
//
// Source of truth: DESIGN.md

import 'package:flutter/material.dart';

/// Semantic wind alias map generated from DESIGN.md.
///
/// Drop-in for `WindThemeData(aliases: designAliases)`; the
/// keys match the magic_starter token contract.
const Map<String, String> designAliases = <String, String>{
  'bg-surface': 'bg-[#FFFFFF] dark:bg-[#030712]',
  'bg-surface-container': 'bg-[#F9FAFB] dark:bg-[#111827]',
  'bg-surface-container-high': 'bg-[#F3F4F6] dark:bg-[#1F2937]',
  'text-fg': 'text-[#111827] dark:text-[#F9FAFB]',
  'text-fg-muted': 'text-[#6B7280] dark:text-[#9CA3AF]',
  'text-fg-disabled': 'text-[#D1D5DB] dark:text-[#4B5563]',
  'bg-primary': 'bg-[#7C3AED] dark:bg-[#8B5CF6]',
  'text-on-primary': 'text-[#FFFFFF] dark:text-[#FFFFFF]',
  'bg-primary-container': 'bg-[#EDE9FE] dark:bg-[#4C1D95]',
  'bg-accent': 'bg-[#4F46E5] dark:bg-[#6366F1]',
  'border-color-border': 'border-[#E5E7EB] dark:border-[#374151]',
  'border-color-border-subtle': 'border-[#F3F4F6] dark:border-[#1F2937]',
  'bg-destructive': 'bg-[#DC2626] dark:bg-[#EF4444]',
  'text-on-destructive': 'text-[#FFFFFF] dark:text-[#FFFFFF]',
  'bg-destructive-container': 'bg-[#FEE2E2] dark:bg-[#7F1D1D]',
  'bg-success': 'bg-[#15803D] dark:bg-[#16A34A]',
  'bg-warning': 'bg-[#D97706] dark:bg-[#B45309]',
};

/// The brand `primary` color with a generated 50-900 ramp.
///
/// Seeded from the DESIGN.md `primary` light hex; consumed by
/// `WindThemeData.toThemeData()` Material interop.
final Map<String, MaterialColor> designColors = <String, MaterialColor>{
  'primary': MaterialColor(0xFF7C3AED, <int, Color>{
    50: Color(0xFFF5EFFE),
    100: Color(0xFFEADFFC),
    200: Color(0xFFD2BCF9),
    300: Color(0xFFBB99F6),
    400: Color(0xFF9E6DF2),
    500: Color(0xFF7C3AED),
    600: Color(0xFF6D33D1),
    700: Color(0xFF5E2CB4),
    800: Color(0xFF4F2598),
    900: Color(0xFF401E7B),
  }),
};
