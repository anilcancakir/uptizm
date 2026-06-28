import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'key_value_editor.dart';

/// Static variant-matrix preview for [KeyValueEditor].
///
/// Mirrors the design lab `KeyValueEditor.preview.tsx`: a small controlled set
/// of header rows; editing/adding/removing updates the state. One preview class
/// per file; discovered by `previews:refresh`.
class KeyValueEditorPreview extends StatefulWidget {
  /// Creates the KeyValueEditor preview.
  const KeyValueEditorPreview({super.key});

  @override
  State<KeyValueEditorPreview> createState() => _KeyValueEditorPreviewState();
}

class _KeyValueEditorPreviewState extends State<KeyValueEditorPreview> {
  List<KeyValueRow> _rows = const [
    KeyValueRow(key: 'Authorization', value: 'Bearer ...'),
    KeyValueRow(key: 'X-Region', value: 'us-east'),
  ];

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 p-6 max-w-lg',
      child: KeyValueEditor(
        value: _rows,
        onChanged: (next) => setState(() => _rows = next),
      ),
    );
  }
}
