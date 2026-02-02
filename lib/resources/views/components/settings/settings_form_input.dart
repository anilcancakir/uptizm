import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

/// Settings Form Input
///
/// A styled text input field for settings forms using Wind UI.
class SettingsFormInput extends StatelessWidget {
  final String label;
  final TextEditingController controller;
  final String? placeholder;
  final String? description;
  final String? Function(String?)? validator;
  final TextInputType keyboardType;
  final bool obscureText;
  final int maxLines;

  const SettingsFormInput({
    super.key,
    required this.label,
    required this.controller,
    this.placeholder,
    this.description,
    this.validator,
    this.keyboardType = TextInputType.text,
    this.obscureText = false,
    this.maxLines = 1,
  });

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-1',
      children: [
        // Label
        WText(label, className: 'text-sm font-medium text-slate-700'),
        // Input
        WFormInput(
          controller: controller,
          placeholder: placeholder,
          validator: validator,
          type: obscureText ? InputType.password : InputType.text,
          className:
              'w-full rounded-lg border-gray-300 focus:border-primary focus:ring-primary text-sm shadow-sm',
        ),
        // Description
        if (description != null)
          WText(description!, className: 'text-xs text-slate-500'),
      ],
    );
  }
}
