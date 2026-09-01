import 'dart:async' show unawaited;

import 'package:flutter/foundation.dart'
    show defaultTargetPlatform, kIsWeb, TargetPlatform;
import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart'
    show Notify, PushDriver, PushReachability;

import 'push_prompt.recipe.dart';

/// **The push permission soft prompt.**
///
/// A single row explaining what push notifications buy the operator and asking
/// for them BEFORE the platform's own one-shot prompt is spent. Presentational
/// on purpose: it renders the [reachability] it is handed and reports the two
/// decisions back, so its whole surface is reachable from a preview and from a
/// widget test. [PushPromptHost] is the half that reads the platform.
///
/// ### The four presentations, and why `blocked` is not a control
///
/// - **`unavailable`**: this build has no push driver (a browser without the
///   Notifications API, a platform the SDK does not cover). One muted line.
/// - **`blocked`**: the permission was denied, and every platform answers the
///   next request WITHOUT showing anything. A toggle here would be a control
///   that silently does nothing, so the row says where the switch actually
///   lives instead: the browser's site settings, or the OS Settings app.
/// - **`off`**: reachable in principle. Not yet asked ([declined] false) shows
///   the soft prompt with an explicit decline; a resolved ask ([declined] true)
///   leaves the compact enable control, because a declined soft prompt must
///   never be a dead end.
/// - **`on`**: subscribed. One confirming line.
///
/// ### Example Usage:
///
/// ```dart
/// PushPrompt(
///   reachability: PushReachability.off,
///   onEnable: () => Notify.requestPushPermission(),
///   onDecline: () => gate.recordDecline(),
/// )
/// ```
@immutable
class PushPrompt extends StatelessWidget {
  /// Identifies the blocked-state instruction row.
  ///
  /// Exported rather than private because "the blocked state renders an
  /// instruction and NOT a control" is the assertion this component exists to
  /// hold, and a test should not have to match on copy to make it.
  static const ValueKey<String> blockedInstructionKey = ValueKey<String>(
    'push-prompt-blocked-instruction',
  );

  /// The glyph for the soft prompt and the compact enable row.
  static const IconData _askIcon = Icons.notifications_active_outlined;

  /// The glyph for the blocked row.
  static const IconData _blockedIcon = Icons.notifications_off_outlined;

  /// The glyph for the subscribed row.
  static const IconData _onIcon = Icons.check_circle_outline;

  /// Whether push can reach this device right now, as the platform reports it.
  final PushReachability reachability;

  /// Whether the soft ask has already been resolved on this device.
  ///
  /// Only meaningful while [reachability] is [PushReachability.off]: it swaps
  /// the soft prompt for the compact enable control.
  final bool declined;

  /// Whether an enable request is in flight, driving the button's spinner.
  final bool busy;

  /// Invoked when the operator asks for push. The caller owns the platform
  /// request; this widget never touches the SDK.
  final Future<void> Function()? onEnable;

  /// Invoked when the operator declines the soft prompt.
  final VoidCallback? onDecline;

  /// Creates a [PushPrompt] for the given [reachability].
  const PushPrompt({
    super.key,
    required this.reachability,
    this.declined = false,
    this.busy = false,
    this.onEnable,
    this.onDecline,
  });

  /// The recipe state axis value for the current presentation.
  String get _state => switch (reachability) {
    PushReachability.unavailable => kPushPromptStateUnavailable,
    PushReachability.blocked => kPushPromptStateBlocked,
    PushReachability.on => kPushPromptStateOn,
    PushReachability.off => kPushPromptStateAsk,
  };

  /// The glyph for the current presentation.
  IconData get _icon => switch (reachability) {
    PushReachability.unavailable => _blockedIcon,
    PushReachability.blocked => _blockedIcon,
    PushReachability.on => _onIcon,
    PushReachability.off => _askIcon,
  };

  /// Where the operator has to go to unblock notifications.
  ///
  /// Three answers rather than one, because the setting lives somewhere
  /// different on each: a browser hides it behind the padlock in the address
  /// bar, iOS keeps it under Settings, Android under the app's own entry.
  String get _blockedInstruction {
    if (kIsWeb) return trans('uptizm.push_prompt.blocked_body_web');

    return switch (defaultTargetPlatform) {
      TargetPlatform.iOS ||
      TargetPlatform.macOS => trans('uptizm.push_prompt.blocked_body_ios'),
      _ => trans('uptizm.push_prompt.blocked_body_android'),
    };
  }

  @override
  Widget build(BuildContext context) {
    final String state = _state;

    return WDiv(
      className: pushPromptRecipe(
        variants: {kPushPromptStateAxis: state},
      ),
      children: [
        WDiv(
          className: pushPromptTileRecipe(
            variants: {kPushPromptStateAxis: state},
          ),
          child: WIcon(
            _icon,
            className: pushPromptIconRecipe(
              variants: {kPushPromptStateAxis: state},
            ),
          ),
        ),
        WDiv(
          className: 'min-w-0 flex-1 flex flex-col gap-2',
          children: _buildBody(),
        ),
      ],
    );
  }

  /// The message column for the current presentation.
  List<Widget> _buildBody() {
    return switch (reachability) {
      PushReachability.unavailable => [
        _buildLine(trans('uptizm.push_prompt.unavailable_body')),
      ],
      PushReachability.on => [
        _buildLine(trans('uptizm.push_prompt.on_body')),
      ],
      PushReachability.blocked => [
        _buildTitle(trans('uptizm.push_prompt.blocked_title')),
        WDiv(
          key: blockedInstructionKey,
          child: _buildLine(_blockedInstruction),
        ),
      ],
      PushReachability.off when declined => [
        _buildLine(trans('uptizm.push_prompt.declined_body')),
        WDiv(
          className: 'flex flex-row items-center gap-4',
          children: [_buildEnable()],
        ),
      ],
      PushReachability.off => [
        _buildTitle(trans('uptizm.push_prompt.ask_title')),
        _buildLine(trans('uptizm.push_prompt.ask_body')),
        WDiv(
          className: 'flex flex-row items-center gap-4',
          children: [_buildEnable(), _buildDecline()],
        ),
      ],
    };
  }

  /// A heading line.
  Widget _buildTitle(String text) {
    return WText(text, className: 'text-sm font-semibold text-fg');
  }

  /// A body line.
  Widget _buildLine(String text) {
    return WText(text, className: 'text-sm leading-relaxed text-fg-muted');
  }

  /// The primary "turn push on" action.
  Widget _buildEnable() {
    return WButton(
      key: const ValueKey<String>('push-prompt-enable'),
      onTap: busy ? null : onEnable,
      isLoading: busy,
      loadingSize: 14,
      className: pushPromptEnableButtonClassName,
      child: WText(trans('uptizm.push_prompt.enable')),
    );
  }

  /// The decline action, which resolves the soft ask WITHOUT touching the
  /// platform's one-shot prompt.
  Widget _buildDecline() {
    return WButton(
      key: const ValueKey<String>('push-prompt-decline'),
      onTap: busy ? null : onDecline,
      className: pushPromptDeclineButtonClassName,
      child: WText(trans('uptizm.push_prompt.not_now')),
    );
  }
}

/// **The push prompt wired to the platform.**
///
/// Reads the four-state reachability from the push driver, remembers a decline
/// on THIS device, and renders [PushPrompt] with both. Mounted into the
/// package's preference screen through `Notify.view.slot`, which hands a slot
/// builder a [BuildContext] and nothing else, so every input has to be resolved
/// here rather than passed in.
///
/// The decline is persisted in [Vault] like the first-run locale gate
/// (`LocaleOnboardingGate`), and for the same reason: the flag has to outlive
/// the process, or the soft prompt returns on the next launch after the
/// operator has already said no.
class PushPromptHost extends StatefulWidget {
  /// Creates a [PushPromptHost].
  const PushPromptHost({super.key});

  /// The [Vault] key recording that the soft prompt was declined here.
  static const String declinedVaultKey = 'uptizm.push_prompt_declined';

  @override
  State<PushPromptHost> createState() => _PushPromptHostState();
}

class _PushPromptHostState extends State<PushPromptHost> {
  /// The platform's answer, or null while the first read is in flight.
  PushReachability? _reachability;

  /// Whether a previous session recorded a decline on this device.
  bool _declined = false;

  /// Whether the platform prompt has already been raised in THIS session.
  ///
  /// Separate from [_declined] because it is not persisted: a granted request
  /// whose subscription has not arrived yet still reads as `off`, and asking
  /// the same question again in the same breath is noise. Both collapse into
  /// the compact enable row.
  bool _asked = false;

  /// Whether an enable request is in flight.
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    unawaited(_read());
  }

  /// Reads the platform state and the persisted decline into the widget.
  Future<void> _read() async {
    final PushDriver? driver = Notify.manager.pushDriverOrNull;
    final PushReachability reachability = driver == null
        ? PushReachability.unavailable
        : await _readReachability(driver);
    final bool declined = await _readDeclined();

    if (!mounted) return;

    setState(() {
      _reachability = reachability;
      _declined = declined;
    });
  }

  /// Reads [driver]'s reachability, answering [PushReachability.unavailable]
  /// when the platform channel throws.
  ///
  /// `reachability()` reaches `permissionState()`, a platform-channel call
  /// that can throw, and this was the one read in [_read] left unguarded
  /// while [_readDeclined] next to it already guards its own read. Left
  /// unhandled, the throw escapes as an unhandled async error and
  /// [_reachability] is stuck on its initial `null`, which renders nothing at
  /// all rather than a state the operator can act on.
  Future<PushReachability> _readReachability(PushDriver driver) async {
    try {
      return await driver.reachability();
    } catch (error) {
      if (Magic.bound('log')) {
        Log.warning('[PushPromptHost] reachability read failed: $error');
      }

      return PushReachability.unavailable;
    }
  }

  /// Reads the persisted decline flag, answering false when the vault is
  /// unreachable.
  ///
  /// A vault failure must not take the preference screen down, and the safe
  /// default is to ask: the soft prompt is a question, not an action.
  Future<bool> _readDeclined() async {
    try {
      return (await Vault.get(PushPromptHost.declinedVaultKey)) != null;
    } catch (error) {
      if (Magic.bound('log')) {
        Log.warning('[PushPromptHost] vault read failed: $error');
      }

      return false;
    }
  }

  /// Records the decline: in memory at once, then on this device.
  ///
  /// A vault failure must not be reported as a success: the flag will not
  /// survive the next launch, so the in-memory flip is rolled back and the
  /// soft prompt (with its decline control) is left on screen rather than
  /// the resolved compact row a working decline would leave. Guarded the
  /// same way [_readDeclined] guards its read.
  Future<void> _decline() async {
    setState(() => _declined = true);

    try {
      await Vault.put(PushPromptHost.declinedVaultKey, '1');
    } catch (error) {
      if (Magic.bound('log')) {
        Log.warning('[PushPromptHost] vault write failed: $error');
      }

      if (mounted) setState(() => _declined = false);
    }
  }

  /// Raises the platform prompt, then re-reads what the platform now says.
  ///
  /// The request is guarded the same way [_readDeclined] guards its read: a
  /// throw is logged rather than left to escape as an unhandled async error,
  /// and control still falls through to [_read] afterward so the row never
  /// gets stuck on the spinner with stale reachability.
  Future<void> _enable() async {
    if (_busy) return;
    if (Notify.manager.pushDriverOrNull == null) return;

    setState(() {
      _busy = true;
      _asked = true;
    });

    try {
      await Notify.requestPushPermission();
    } catch (error) {
      if (Magic.bound('log')) {
        Log.warning('[PushPromptHost] push permission request failed: $error');
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }

    await _read();
  }

  /// Whether the host app has left the soft prompt turned on.
  ///
  /// `notifications.soft_prompt.enabled` is `magic_notifications`' own
  /// config key; a consumer that sets it to `false` gets no prompt at all,
  /// not a half-read row.
  bool get _softPromptEnabled =>
      Config.get<bool>('notifications.soft_prompt.enabled', true) ?? true;

  @override
  Widget build(BuildContext context) {
    if (!_softPromptEnabled) return const SizedBox.shrink();

    final PushReachability? reachability = _reachability;

    // Nothing is known yet. One frame, and only ever the first: every other
    // state below renders a row, so this cannot become a permanently empty
    // child costing a gap slot in the preference screen's flex column.
    if (reachability == null) return const SizedBox.shrink();

    return PushPrompt(
      reachability: reachability,
      declined: _declined || _asked,
      busy: _busy,
      onEnable: _enable,
      onDecline: _decline,
    );
  }
}
