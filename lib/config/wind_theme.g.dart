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
  'bg-surface': 'bg-[#F9FAFB] dark:bg-[#07090C]',
  'bg-surface-container': 'bg-[#FFFFFF] dark:bg-[#121518]',
  'bg-surface-container-high': 'bg-[#F1F3F6] dark:bg-[#202529]',
  'text-fg': 'text-[#040608] dark:text-[#F1F3F6]',
  'text-fg-muted': 'text-[#555D65] dark:text-[#AAB1B7]',
  'text-fg-disabled': 'text-[#D1D5DA] dark:text-[#3A4147]',
  'bg-primary': 'bg-[#009A6F] dark:bg-[#00C292]',
  'text-on-primary': 'text-[#FFFFFF] dark:text-[#07090C]',
  'bg-primary-container': 'bg-[#E0F9EE] dark:bg-[#003223]',
  'bg-accent': 'bg-[#007A54] dark:bg-[#98E8C9]',
  'border-color-border': 'border-[#DEE2E5] dark:border-[#2A2E33]',
  'border-color-border-subtle': 'border-[#ECEFF1] dark:border-[#1C2023]',
  'bg-destructive': 'bg-[#DF202E] dark:bg-[#FF645F]',
  'text-on-destructive': 'text-[#FFFFFF] dark:text-[#07090C]',
  'bg-destructive-container': 'bg-[#FFE3DF] dark:bg-[#4C1010]',
  'bg-success': 'bg-[#30A556] dark:bg-[#45C06A]',
  'bg-warning': 'bg-[#E69825] dark:bg-[#F5AE39]',
};

/// The brand `primary` color with a generated 50-900 ramp.
///
/// Seeded from the DESIGN.md `primary` light hex; consumed by
/// `WindThemeData.toThemeData()` Material interop.
final Map<String, MaterialColor> designColors =
    <String, MaterialColor>{
  'primary': MaterialColor(
    0xFF009A6F,
    <int, Color>{
      50: Color(0xFFEBF7F3),
      100: Color(0xFFD6EFE8),
      200: Color(0xFFA8DDCE),
      300: Color(0xFF7ACAB4),
      400: Color(0xFF42B494),
      500: Color(0xFF009A6F),
      600: Color(0xFF008862),
      700: Color(0xFF007554),
      800: Color(0xFF006347),
      900: Color(0xFF00503A),
    }),
};
