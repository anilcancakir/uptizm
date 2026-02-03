import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

/// Key-value pair editor for headers, query params, etc.
///
/// Features:
/// - Add/remove key-value pairs
/// - Empty state with "Add Header" button
/// - Delete button on each row
class KeyValueEditor extends StatefulWidget {
  final Map<String, String> entries;
  final Function(Map<String, String>) onChanged;

  const KeyValueEditor({
    super.key,
    required this.entries,
    required this.onChanged,
  });

  @override
  State<KeyValueEditor> createState() => _KeyValueEditorState();
}

class _KeyValueEditorState extends State<KeyValueEditor> {
  late List<_Entry> _entries;

  @override
  void initState() {
    super.initState();
    _entries = widget.entries.entries
        .map(
          (e) => _Entry(
            keyController: TextEditingController(text: e.key),
            valueController: TextEditingController(text: e.value),
          ),
        )
        .toList();
  }

  @override
  void dispose() {
    for (final entry in _entries) {
      entry.keyController.dispose();
      entry.valueController.dispose();
    }
    super.dispose();
  }

  void _addEntry() {
    setState(() {
      _entries.add(
        _Entry(
          keyController: TextEditingController(),
          valueController: TextEditingController(),
        ),
      );
    });
    _notifyChanged();
  }

  void _removeEntry(int index) {
    setState(() {
      final entry = _entries.removeAt(index);
      entry.keyController.dispose();
      entry.valueController.dispose();
    });
    _notifyChanged();
  }

  void _notifyChanged() {
    final map = <String, String>{};
    for (final entry in _entries) {
      final key = entry.keyController.text;
      final value = entry.valueController.text;
      if (key.isNotEmpty) {
        map[key] = value;
      }
    }
    widget.onChanged(map);
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        // Existing entries
        ..._entries.asMap().entries.map((mapEntry) {
          final index = mapEntry.key;
          final entry = mapEntry.value;

          return WDiv(
            className: 'flex flex-row gap-2 items-center',
            children: [
              // Key field
              Expanded(
                child: WInput(
                  controller: entry.keyController,
                  placeholder: 'Key',
                  onChanged: (_) => _notifyChanged(),
                  className: '''
                    w-full px-3 py-2 rounded-lg
                    bg-white dark:bg-gray-800
                    border border-gray-200 dark:border-gray-700
                    text-gray-900 dark:text-white text-sm
                    focus:border-primary focus:ring-2 focus:ring-primary/20
                  ''',
                  placeholderClassName: 'text-gray-400 dark:text-gray-500',
                ),
              ),

              // Value field
              Expanded(
                child: WInput(
                  controller: entry.valueController,
                  placeholder: 'Value',
                  onChanged: (_) => _notifyChanged(),
                  className: '''
                    w-full px-3 py-2 rounded-lg
                    bg-white dark:bg-gray-800
                    border border-gray-200 dark:border-gray-700
                    text-gray-900 dark:text-white text-sm
                    focus:border-primary focus:ring-2 focus:ring-primary/20
                  ''',
                  placeholderClassName: 'text-gray-400 dark:text-gray-500',
                ),
              ),

              // Delete button
              WButton(
                onTap: () => _removeEntry(index),
                className:
                    'p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700',
                child: WIcon(
                  Icons.close,
                  className: 'text-gray-600 dark:text-gray-400',
                ),
              ),
            ],
          );
        }),

        // Add button
        WButton(
          onTap: _addEntry,
          className: '''
            px-3 py-2 rounded-lg
            bg-gray-100 dark:bg-gray-700
            text-gray-700 dark:text-gray-300
            hover:bg-gray-200 dark:hover:bg-gray-600
            text-sm
          ''',
          child: WText('Add Header'),
        ),
      ],
    );
  }
}

class _Entry {
  final TextEditingController keyController;
  final TextEditingController valueController;

  _Entry({required this.keyController, required this.valueController});
}
