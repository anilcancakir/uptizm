import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart'
    show PushPromptAction, PushReachability;

import 'push_prompt.dart';
import 'push_prompt.recipe.dart';

/// Static preview for [PushPrompt] and its shell marker.
///
/// Renders all six presentations: the soft prompt, the compact enable row a
/// resolved ask leaves behind, the two blocked rows (a control where the
/// platform can open its own setting, an instruction where it cannot), the
/// subscribed row and the no-push-at-all row. Neither the fourth
/// [PushReachability] value nor a bare [PushPromptAction] names those six on
/// its own, which is exactly why the row takes both.
///
/// [PushOffNotice] reads the live platform, so the two shell forms below are
/// drawn from their recipe rather than mounted: a preview must not depend on
/// what the reviewer's own device happens to answer.
///
/// One preview class per file is the canonical atomic-component contract.
class PushPromptPreview extends StatelessWidget {
  /// Creates the push-prompt preview.
  const PushPromptPreview({super.key});

  /// The glyph both shell forms carry, matching [PushOffNotice]'s own.
  static const IconData _noticeIcon = Icons.notifications_off_outlined;

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-4 p-6 items-stretch',
      children: [
        _labelled(
          'off (the soft prompt)',
          const PushPrompt(
            reachability: PushReachability.off,
            action: PushPromptAction.request,
          ),
        ),
        _labelled(
          'off + declined (the explicit enable)',
          const PushPrompt(
            reachability: PushReachability.off,
            action: PushPromptAction.request,
            declined: true,
          ),
        ),
        _labelled(
          'blocked, mobile (a real way back into Settings)',
          const PushPrompt(
            reachability: PushReachability.blocked,
            action: PushPromptAction.openSettings,
          ),
        ),
        _labelled(
          'blocked, web (an instruction, because nothing can open site '
          'settings)',
          const PushPrompt(
            reachability: PushReachability.blocked,
            action: PushPromptAction.instructions,
          ),
        ),
        _labelled(
          'on',
          const PushPrompt(
            reachability: PushReachability.on,
            action: PushPromptAction.none,
          ),
        ),
        _labelled(
          'unavailable',
          const PushPrompt(
            reachability: PushReachability.unavailable,
            action: PushPromptAction.none,
          ),
        ),
        _labelled('the shell notice, sidebar form', _notice(compact: false)),
        _labelled('the shell notice, mobile top-bar form', _notice(compact: true)),
      ],
    );
  }

  /// One shell-notice specimen, drawn from the component's own recipe.
  ///
  /// Not the widget itself: [PushOffNotice] renders whatever the reviewer's
  /// device reports, which is nothing at all on a machine where push happens to
  /// work. The className comes from [pushOffNoticeRecipe] and the two shared
  /// constants, so the tokens under review are the shipped ones; only the tap
  /// and the platform read are absent.
  Widget _notice({required bool compact}) {
    return WDiv(
      className: pushOffNoticeRecipe(
        variants: {
          kPushOffNoticeDensityAxis: compact
              ? kPushOffNoticeDensityCompact
              : kPushOffNoticeDensityFull,
        },
      ),
      children: [
        WIcon(_noticeIcon, className: pushOffNoticeIconClassName),
        if (!compact)
          Expanded(
            child: WText(
              trans('uptizm.push_prompt.shell_notice'),
              className: pushOffNoticeLabelClassName,
            ),
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
