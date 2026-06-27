import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'assistant.dart';

/// The design-lab seed conversation (`Assistant.preview.tsx` `SEED`). Five
/// messages, so the quick-prompt chips stay hidden and the embedded surface
/// shows a representative exchange.
const List<AssistantMessage> _seed = [
  AssistantMessage(
    role: AssistantRole.assistant,
    text:
        "Hi, I'm Uptizm AI. I reason from your own checks, regions, response "
        'times, and custom metrics, and I can set things up for you. How can I '
        'help?',
  ),
  AssistantMessage(role: AssistantRole.user, text: 'Which monitors are slow?'),
  AssistantMessage(
    role: AssistantRole.assistant,
    text:
        'API gateway has the highest p95 right now at 412ms, and I trace it to '
        'ap-southeast; other regions are nominal.',
  ),
  AssistantMessage(
    role: AssistantRole.user,
    text: 'Create a monitor for api.example.com',
  ),
  AssistantMessage(
    role: AssistantRole.assistant,
    text:
        "I'll set up an HTTP monitor with your default regions and a 30s "
        "interval. Review the details and create it whenever you're ready.",
  ),
];

/// Static preview for [Assistant].
///
/// Renders the embedded chat surface (mirroring the React `AssistantPanel`
/// `embedded` preview): the open conversation with the header, the seeded
/// message exchange, and the composer. The floating FAB + backdrop are the
/// widget's runtime overlay behavior, exercised in the widget tests rather than
/// shown statically here. One preview class per file is the canonical
/// atomic-component contract.
class AssistantPreview extends StatelessWidget {
  /// Creates the assistant preview.
  const AssistantPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-4 p-6 items-start',
      children: [
        WText(
          'Assistant: embedded chat surface',
          className: 'text-sm font-semibold text-fg',
        ),
        const Assistant(embedded: true, initialMessages: _seed),
      ],
    );
  }
}
