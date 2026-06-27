import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'assistant.dart';

/// Static preview for [Assistant].
///
/// Renders the collapsed `ai`-toned FAB in the catalog. The opened surface is
/// an overlay the widget manages internally (tap the FAB to expand), so the
/// catalog shows the resting affordance in light and dark. One preview class
/// per file is the canonical atomic-component contract.
class AssistantPreview extends StatelessWidget {
  /// Creates the assistant preview.
  const AssistantPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-4 p-6 items-start',
      children: [
        WText(
          'Assistant: AI FAB (tap to open the surface)',
          className: 'text-sm font-semibold text-fg',
        ),
        SizedBox(height: 80, width: 80, child: const Assistant()),
      ],
    );
  }
}
