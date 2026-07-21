import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'key_value_editor.recipe.dart';

/// A single editable key/value pair, e.g. one HTTP request header.
@immutable
class KeyValueRow {
  /// The key (left input).
  final String key;

  /// The value (right input).
  final String value;

  /// Creates a [KeyValueRow].
  const KeyValueRow({required this.key, required this.value});

  /// Returns a copy with [key] and/or [value] replaced.
  KeyValueRow copyWith({String? key, String? value}) =>
      KeyValueRow(key: key ?? this.key, value: value ?? this.value);
}

/// **Controlled editor for an ordered list of key/value pairs.**
///
/// Each row exposes a key input, a value input, and a remove button; a trailing
/// secondary button appends an empty row. Every mutation emits a fresh list so
/// the parent owns the canonical state. Ported from the design lab
/// `KeyValueEditor`.
///
/// ### Example:
/// ```dart
/// KeyValueEditor(
///   value: rows,
///   onChanged: (next) => setState(() => rows = next),
/// )
/// ```
@immutable
class KeyValueEditor extends StatelessWidget {
  /// Controlled list of rows.
  final List<KeyValueRow> value;

  /// Called with a fresh copy whenever a row is edited, added, or removed.
  final ValueChanged<List<KeyValueRow>> onChanged;

  /// Placeholder for the key input. Falls back to a localized default.
  final String? keyPlaceholder;

  /// Placeholder for the value input. Falls back to a localized default.
  final String? valuePlaceholder;

  /// Label of the append button. Falls back to a localized default.
  final String? addLabel;

  /// Optional extra classNames appended to the root slot.
  final String? className;

  /// Creates a [KeyValueEditor].
  const KeyValueEditor({
    super.key,
    required this.value,
    required this.onChanged,
    this.keyPlaceholder,
    this.valuePlaceholder,
    this.addLabel,
    this.className,
  });

  static const IconData _removeIcon = Icons.close;

  void _updateRow(int index, {String? key, String? value}) {
    final next = [
      for (var i = 0; i < this.value.length; i++)
        if (i == index)
          this.value[i].copyWith(key: key, value: value)
        else
          this.value[i],
    ];
    onChanged(next);
  }

  void _removeRow(int index) {
    onChanged([
      for (var i = 0; i < value.length; i++)
        if (i != index) value[i],
    ]);
  }

  void _addRow() {
    onChanged([...value, const KeyValueRow(key: '', value: '')]);
  }

  @override
  Widget build(BuildContext context) {
    final slots = keyValueEditorRecipe(variants: const <String, String>{});

    return WDiv(
      className: className == null
          ? slots['root']
          : '${slots['root']} $className',
      children: [
        for (var i = 0; i < value.length; i++) _row(i, slots),
        MSButton(
          intent: ButtonIntent.secondary,
          size: ButtonSize.sm,
          onPressed: _addRow,
          child: WText(addLabel ?? trans('uptizm.monitors.kv_add_header')),
        ),
      ],
    );
  }

  Widget _row(int index, Map<String, String> slots) {
    final entry = value[index];
    return Row(
      children: [
        Expanded(
          child: MSInput(
            value: entry.key,
            placeholder:
                keyPlaceholder ?? trans('uptizm.monitors.kv_key_placeholder'),
            onChanged: (v) => _updateRow(index, key: v),
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: MSInput(
            value: entry.value,
            placeholder:
                valuePlaceholder ??
                trans('uptizm.monitors.kv_value_placeholder'),
            onChanged: (v) => _updateRow(index, value: v),
          ),
        ),
        const SizedBox(width: 8),
        WAnchor(
          onTap: () => _removeRow(index),
          child: WDiv(
            className: slots['remove'],
            child: WIcon(_removeIcon, className: 'size-4'),
          ),
        ),
      ],
    );
  }
}
