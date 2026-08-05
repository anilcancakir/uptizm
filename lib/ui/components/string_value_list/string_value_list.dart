import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:flutter/services.dart'
    show KeyDownEvent, KeyEvent, LogicalKeyboardKey, TextInputAction;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'string_value_list.recipe.dart';

/// The visual tone of a [StringValueList]'s chips; maps to the `tone` axis of
/// [stringValueListRecipe].
enum StringValueListTone {
  /// Unremarkable (healthy values): `bg-surface-container-high`.
  neutral,

  /// Cautionary (warning values): the `degraded` soft status pair.
  warn,

  /// Severe (critical values): the `down` soft status pair.
  critical,
}

/// **Controlled editor for a short list of distinct strings.**
///
/// Used three times in the metric form to author the healthy, warning, and
/// critical value bands for a `string`-typed custom metric. Each committed
/// value renders as a [WBadge] chip; pressing Enter (web) or the IME "done"
/// action (mobile) commits the typed draft via [WInput.onSubmitted]. Every
/// mutation calls [onChanged] with a FRESH list, never mutating [value] in
/// place, so the parent stays the single source of truth.
///
/// A value is trimmed before it is compared or committed; an empty or
/// duplicate (case-insensitive, post-trim) value is rejected silently, matching
/// the `KeyValueEditor` house style of never surfacing an inline error for a
/// no-op edit.
///
/// Backspace-to-remove-the-last-chip IS implemented here (wind ships no
/// keyboard handling of its own): a [KeyboardListener] wraps the entry field
/// and drops the last committed chip when Backspace is pressed on an already
/// empty draft, mirroring the affordance most chip-input widgets provide.
///
/// ### Example:
/// ```dart
/// StringValueList(
///   value: warnValues,
///   onChanged: (next) => setState(() => warnValues = next),
///   tone: StringValueListTone.warn,
/// )
/// ```
@immutable
class StringValueList extends StatefulWidget {
  /// Controlled list of committed values, in insertion order.
  final List<String> value;

  /// Called with a fresh copy whenever a value is added or removed.
  final ValueChanged<List<String>> onChanged;

  /// Chip tone; selects which status token family the chips render with.
  /// Defaults to [StringValueListTone.neutral].
  final StringValueListTone tone;

  /// Placeholder for the entry field. Falls back to a localized default.
  final String? placeholder;

  /// Label of the commit button. Falls back to a localized default.
  final String? addLabel;

  /// Optional extra classNames appended to the root slot.
  final String? className;

  /// Creates a [StringValueList].
  const StringValueList({
    super.key,
    required this.value,
    required this.onChanged,
    this.tone = StringValueListTone.neutral,
    this.placeholder,
    this.addLabel,
    this.className,
  });

  @override
  State<StringValueList> createState() => _StringValueListState();
}

class _StringValueListState extends State<StringValueList> {
  static const IconData _removeIcon = Icons.close;

  final TextEditingController _controller = TextEditingController();
  final FocusNode _focusNode = FocusNode();

  @override
  void dispose() {
    _controller.dispose();
    _focusNode.dispose();
    super.dispose();
  }

  /// Trims [raw], and either appends it as a new value (emitting a fresh
  /// list) or drops it silently when it is empty or already present
  /// (post-trim). Clears the draft either way, then refocuses the entry
  /// field so the operator can keep typing without an extra tap.
  ///
  /// The duplicate check trims and folds case on BOTH sides, matching the
  /// server's match-time normalizer rather than just the write path's
  /// `distinct:ignore_case`. Comparing raw would let `ok` and `OK` both become
  /// chips and then fail the save with a 422, in a feature whose whole premise
  /// is that matching ignores case. Trimming only the new side is not enough
  /// either: `distinct:ignore_case` does not trim and Laravel's `TrimStrings`
  /// is ASCII-only, so a stored value can carry a non-breaking space and a
  /// visibly identical chip would be accepted beside it.
  void _commit(String raw) {
    final trimmed = raw.trim();
    _controller.clear();

    final folded = trimmed.toLowerCase();
    final isDuplicate =
        trimmed.isNotEmpty &&
        widget.value.any((existing) => existing.trim().toLowerCase() == folded);
    if (trimmed.isNotEmpty && !isDuplicate) {
      widget.onChanged([...widget.value, trimmed]);
    }

    _focusNode.requestFocus();
  }

  /// Removes the value at [index], emitting a fresh list.
  void _removeAt(int index) {
    widget.onChanged([
      for (var i = 0; i < widget.value.length; i++)
        if (i != index) widget.value[i],
    ]);
  }

  /// Drops the last committed value when Backspace is pressed on an empty
  /// draft. `wind` has no keyboard handling of its own, so this is a plain
  /// Flutter [KeyEvent] check around the entry field's [FocusNode].
  void _onKeyEvent(FocusNode node, KeyEvent event) {
    if (event is! KeyDownEvent) return;
    if (event.logicalKey != LogicalKeyboardKey.backspace) return;
    if (_controller.text.isNotEmpty || widget.value.isEmpty) return;

    _removeAt(widget.value.length - 1);
  }

  @override
  Widget build(BuildContext context) {
    final slots = stringValueListRecipe(
      variants: {kStringValueListToneAxis: widget.tone.name},
    );

    return WDiv(
      className: widget.className == null
          ? slots['root']
          : '${slots['root']} ${widget.className}',
      children: [
        if (widget.value.isNotEmpty)
          WDiv(
            className: slots['chips'],
            children: [
              for (var i = 0; i < widget.value.length; i++)
                _chip(i, slots),
            ],
          ),
        Row(
          children: [
            Expanded(
              child: Focus(
                onKeyEvent: (node, event) {
                  _onKeyEvent(node, event);
                  return KeyEventResult.ignored;
                },
                child: WInput(
                  controller: _controller,
                  focusNode: _focusNode,
                  placeholder: widget.placeholder ??
                      trans('uptizm.monitors.string_values_placeholder'),
                  textInputAction: TextInputAction.done,
                  onSubmitted: _commit,
                ),
              ),
            ),
            const SizedBox(width: 8),
            MSButton(
              intent: ButtonIntent.secondary,
              size: ButtonSize.sm,
              onPressed: () => _commit(_controller.text),
              child: WText(
                widget.addLabel ?? trans('uptizm.monitors.string_values_add'),
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _chip(int index, Map<String, String> slots) {
    final entry = widget.value[index];
    // `flex flex-row`, never `inline-flex`: wind lists `inline-flex` among the
    // deliberately inert compat tokens in `wind_parser.dart`'s
    // `_knownUnparsedTokens`, so it is a recognized token that sets no layout
    // axis and, by design, never produces a warning. This wrapper therefore had
    // no axis at all and stacked the remove button UNDER its chip, which only
    // shows once a value has been committed (the live mobile walk caught it).
    return WDiv(
      className: 'flex flex-row items-center gap-1',
      children: [
        WBadge(entry, className: slots['chip']),
        WAnchor(
          onTap: () => _removeAt(index),
          child: WDiv(
            className: slots['remove'],
            child: WIcon(_removeIcon, className: 'size-3'),
          ),
        ),
      ],
    );
  }
}
