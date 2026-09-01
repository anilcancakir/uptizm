import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart'
    show PushReachability;

import 'push_prompt.dart';

/// Static preview for [PushPrompt].
///
/// Renders all five presentations: the four [PushReachability] values plus the
/// compact enable row a resolved soft ask leaves behind, which is the one state
/// no reachability value names on its own. One preview class per file is the
/// canonical atomic-component contract.
class PushPromptPreview extends StatelessWidget {
  /// Creates the push-prompt preview.
  const PushPromptPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-4 p-6 items-stretch',
      children: [
        _labelled(
          'off (the soft prompt)',
          const PushPrompt(reachability: PushReachability.off),
        ),
        _labelled(
          'off + declined (the explicit enable)',
          const PushPrompt(
            reachability: PushReachability.off,
            declined: true,
          ),
        ),
        _labelled(
          'blocked (an instruction, never a control)',
          const PushPrompt(reachability: PushReachability.blocked),
        ),
        _labelled(
          'on',
          const PushPrompt(reachability: PushReachability.on),
        ),
        _labelled(
          'unavailable',
          const PushPrompt(reachability: PushReachability.unavailable),
        ),
      ],
    );
  }

  /// One captioned specimen.
  Widget _labelled(String caption, Widget specimen) {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WText(caption, className: 'text-sm font-semibold text-fg'),
        specimen,
      ],
    );
  }
}
