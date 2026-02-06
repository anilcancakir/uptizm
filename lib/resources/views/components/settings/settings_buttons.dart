import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Primary Button
///
/// Styled button with primary background for main actions.
/// Uses Wind UI WButton.
class PrimaryButton extends StatelessWidget {
  final String text;
  final VoidCallback? onTap;
  final bool isLoading;
  final IconData? icon;

  const PrimaryButton({
    super.key,
    required this.text,
    this.onTap,
    this.isLoading = false,
    this.icon,
  });

  @override
  Widget build(BuildContext context) {
    return WButton(
      onTap: onTap,
      isLoading: isLoading,
      className:
          'bg-primary hover:bg-primary/90 px-4 py-2 rounded-lg shadow-sm duration-150',
      child: WDiv(
        className: 'flex items-center gap-2',
        children: [
          if (icon != null) WIcon(icon!, className: 'text-lg text-white'),
          WText(text, className: 'text-sm font-medium text-white'),
        ],
      ),
    );
  }
}

/// Secondary Button
///
/// Styled button with white background and gray border for secondary actions.
/// Uses Wind UI WButton.
class SecondaryButton extends StatelessWidget {
  final String text;
  final VoidCallback? onTap;
  final IconData? icon;

  const SecondaryButton({super.key, required this.text, this.onTap, this.icon});

  @override
  Widget build(BuildContext context) {
    return WButton(
      onTap: onTap,
      className:
          'bg-white hover:bg-gray-50 border border-gray-300 px-4 py-2 rounded-lg duration-150',
      child: WDiv(
        className: 'flex items-center gap-2',
        children: [
          if (icon != null) WIcon(icon!, className: 'text-lg text-slate-600'),
          WText(text, className: 'text-sm font-medium text-slate-600'),
        ],
      ),
    );
  }
}
