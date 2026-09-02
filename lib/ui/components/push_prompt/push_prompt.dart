import 'dart:async' show StreamSubscription, unawaited;

import 'package:flutter/foundation.dart'
    show defaultTargetPlatform, kIsWeb, TargetPlatform;
import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_notifications/magic_notifications.dart'
    show
        Notify,
        PushDriver,
        PushPromptAction,
        PushPromptAdvice,
        PushReachability;
import 'package:magic_starter/magic_starter.dart' show MagicStarterConfig;

import 'push_prompt.recipe.dart';

/// **The push permission soft prompt.**
///
/// A single row explaining what push notifications buy the operator and asking
/// for them BEFORE the platform's own one-shot prompt is spent. Presentational
/// on purpose: it renders the [reachability] and [action] it is handed and
/// reports the two decisions back, so its whole surface is reachable from a
/// preview and from a widget test. [PushPromptHost] is the half that asks
/// `magic_notifications` what this device's state actually is.
///
/// ### Why it takes an [action] as well as a [reachability]
///
/// Reachability alone cannot name a presentation. A `blocked` device on mobile
/// still has a route back (the SDK's `fallbackToSettings` lands a request on
/// the app's settings page), and a `blocked` browser has none, because no web
/// API opens the site settings panel from a page. That split is a property of
/// the PLATFORM, not of the reading, and the package answers it in
/// [PushPromptAction] rather than leaving each consumer to guess.
///
/// ### The four presentations
///
/// - **`unavailable`** ([PushPromptAction.none]): this build has no push driver
///   at all. One muted line.
/// - **`blocked`**: the OS prompt is spent. With
///   [PushPromptAction.openSettings] the row keeps a real control that opens
///   the platform setting; with [PushPromptAction.instructions] there is
///   nowhere to send a tap, so it says where the switch lives instead of
///   offering a control that silently does nothing.
/// - **`off`** ([PushPromptAction.request]): a real dialog will appear. Not yet
///   resolved ([declined] false) shows the soft prompt with an explicit
///   decline; a resolved ask ([declined] true) leaves the compact enable
///   control, because a declined soft prompt must never be a dead end.
/// - **`on`** ([PushPromptAction.none]): subscribed. One confirming line.
///
/// ### Example Usage:
///
/// ```dart
/// PushPrompt(
///   reachability: advice.reachability,
///   action: advice.action,
///   onEnable: () => Notify.requestPushPermission(),
///   onDecline: () => gate.recordDecline(),
/// )
/// ```
@immutable
class PushPrompt extends StatelessWidget {
  /// Identifies the instruction row a blocked device with no route back gets.
  ///
  /// Exported rather than private because "a platform that cannot open its own
  /// setting renders an instruction and NOT a control" is the assertion this
  /// component exists to hold, and a test should not have to match on copy to
  /// make it. It is deliberately absent from the [PushPromptAction.openSettings]
  /// arm, which is a control.
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

  /// What this row's control can actually accomplish here, as
  /// `Notify.manager.pushPromptAdvice()` resolved it.
  final PushPromptAction action;

  /// Whether the soft ask has already been resolved on this device.
  ///
  /// Only meaningful while [action] is [PushPromptAction.request]: it swaps the
  /// soft prompt for the compact enable control.
  final bool declined;

  /// Whether an enable request is in flight, driving the button's spinner.
  final bool busy;

  /// Invoked when the operator asks for push. The caller owns the platform
  /// request; this widget never touches the SDK.
  final Future<void> Function()? onEnable;

  /// Invoked when the operator declines the soft prompt.
  final VoidCallback? onDecline;

  /// Creates a [PushPrompt] for the given [reachability] and [action].
  const PushPrompt({
    super.key,
    required this.reachability,
    required this.action,
    this.declined = false,
    this.busy = false,
    this.onEnable,
    this.onDecline,
  });

  /// The recipe state axis value for the current presentation.
  ///
  /// Keyed on [reachability] rather than [action]: the tokens carry what the
  /// device's STATE is (a blocked device gets the warning tint whether or not
  /// this platform can route the tap back), while [action] decides what the
  /// body offers.
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
  ///
  /// Driven by [action], because that is the only input that knows what a tap
  /// could accomplish. [reachability] appears once, to tell the two states with
  /// nothing to offer apart: a subscribed device and a build with no push.
  List<Widget> _buildBody() {
    return switch (action) {
      PushPromptAction.none when reachability == PushReachability.on => [
        _buildLine(trans('uptizm.push_prompt.on_body')),
      ],
      PushPromptAction.none => [
        _buildLine(trans('uptizm.push_prompt.unavailable_body')),
      ],
      // The OS prompt is spent, but this platform routes the same request to
      // the app's settings page, so the row is a control again.
      PushPromptAction.openSettings => [
        _buildTitle(trans('uptizm.push_prompt.blocked_title')),
        _buildLine(trans('uptizm.push_prompt.blocked_body_settings')),
        WDiv(
          className: 'flex flex-row items-center gap-4',
          children: [_buildEnable()],
        ),
      ],
      // Nowhere to send a tap. A control here would silently do nothing, so the
      // row says where the switch actually lives instead.
      PushPromptAction.instructions => [
        _buildTitle(trans('uptizm.push_prompt.blocked_title')),
        WDiv(
          key: blockedInstructionKey,
          child: _buildLine(_blockedInstruction),
        ),
      ],
      PushPromptAction.request when declined => [
        _buildLine(trans('uptizm.push_prompt.declined_body')),
        WDiv(
          className: 'flex flex-row items-center gap-4',
          children: [_buildEnable()],
        ),
      ],
      PushPromptAction.request => [
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

  /// The primary action, labelled for what the tap will actually do.
  ///
  /// One control and one callback for both arms, because the platform call is
  /// the same one: `requestPermission()` raises the dialog on a device that has
  /// never been asked, and opens the app's settings page on one that has. Only
  /// the promise made to the operator changes, and promising "turn on push"
  /// where the tap opens Settings is the kind of small lie that costs the next
  /// tap.
  Widget _buildEnable() {
    final String label = action == PushPromptAction.openSettings
        ? trans('uptizm.push_prompt.open_settings')
        : trans('uptizm.push_prompt.enable');

    return WButton(
      key: const ValueKey<String>('push-prompt-enable'),
      onTap: busy ? null : onEnable,
      isLoading: busy,
      loadingSize: 14,
      className: pushPromptEnableButtonClassName,
      child: WText(label),
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

/// The reading both platform-wired widgets below fall back to when the platform
/// read throws: nothing known about this device, and nothing to offer.
///
/// Deliberately the same answer a build with no push driver gets. A failed read
/// is not evidence that push works, and the two are indistinguishable from
/// here; what neither of them is, is a reason to raise an error out of a
/// lifecycle path.
const PushPromptAdvice _unreadableDevice = PushPromptAdvice(
  show: false,
  reachability: PushReachability.unavailable,
  action: PushPromptAction.none,
);

/// **The push prompt wired to the platform.**
///
/// Owns the one fact `magic_notifications` refuses to own, the moment the
/// operator last turned the reminder down on THIS device, hands it to
/// `Notify.manager.pushPromptAdvice()`, and renders [PushPrompt] from the
/// answer. Mounted into the package's preference screen through
/// `Notify.view.slot`, which hands a slot builder a [BuildContext] and nothing
/// else, so every input has to be resolved here rather than passed in.
///
/// ### Why the host stores a timestamp and the package stores nothing
///
/// The POLICY (is a reminder due, and what can its control do) is the part two
/// consumers would each get wrong in their own way, so it lives in the package.
/// The decline is this app's own UI event, and a second copy inside the package
/// would be a second answer to drift out of sync with this one. The two meet at
/// `pushPromptAdvice(declinedAt:)`.
///
/// It is persisted in [Vault] like the first-run locale gate
/// (`LocaleOnboardingGate`), and for the same reason: it has to outlive the
/// process, or the soft prompt returns on the next launch after the operator
/// has already said no.
class PushPromptHost extends StatefulWidget {
  /// Creates a [PushPromptHost].
  const PushPromptHost({super.key});

  /// The [Vault] key recording WHEN the reminder was last turned down here.
  ///
  /// The value is an ISO-8601 instant in UTC. UTC rather than local because an
  /// on-call engineer travels: a local wall-clock string parsed back in another
  /// zone names an instant up to fourteen hours away from the one it recorded,
  /// which is most of the interval it is measured against.
  ///
  /// The KEY string is deliberately unchanged from the build that stored a bare
  /// `'1'` here. A new key would leave that flag behind and read a device that
  /// already declined as one that never has, so the reminder would return
  /// instantly for exactly the people who said no; [_readDeclinedAt] migrates
  /// the old value in place instead.
  static const String declinedVaultKey = 'uptizm.push_prompt_declined';

  @override
  State<PushPromptHost> createState() => _PushPromptHostState();
}

class _PushPromptHostState extends State<PushPromptHost> {
  /// The package's answer, or null while the first read is in flight.
  PushPromptAdvice? _advice;

  /// When the reminder was last turned down on this device, or null.
  DateTime? _declinedAt;

  /// Whether the platform prompt has already been raised in THIS session.
  ///
  /// Not persisted, and separate from [_declinedAt] because it answers a
  /// different question: a granted request whose subscription has not arrived
  /// yet still reads as `off`, and asking again in the same breath is noise.
  /// Both collapse into the compact enable row.
  bool _asked = false;

  /// Whether an enable request is in flight.
  bool _busy = false;

  /// The driver reports this widget listens to while it is mounted.
  ///
  /// The same pair, and for the same reason, as [PushOffNotice]: either stream
  /// can end the state this prompt is about. It matters MORE here, because this
  /// is the surface an operator is sent to in order to fix push, and the fix
  /// almost always lands out of band. `requestPermission` on an already-denied
  /// device opens the platform settings page rather than a dialog, so the grant
  /// arrives while this widget is backgrounded and unchanged; and a granted
  /// request whose subscription has not landed yet reads as `off` until the
  /// identity stream carries it (see [_asked]). Without these two, the one
  /// screen that exists to turn push on is the only one that never notices push
  /// was turned on.
  final List<StreamSubscription<Object?>> _watching =
      <StreamSubscription<Object?>>[];

  @override
  void initState() {
    super.initState();
    unawaited(_read());
    _watch();
  }

  @override
  void dispose() {
    for (final StreamSubscription<Object?> subscription in _watching) {
      unawaited(subscription.cancel());
    }
    _watching.clear();
    super.dispose();
  }

  /// Follows everything the driver reports about this device's state.
  ///
  /// Re-reads through [_read] rather than [_apply] so the stored decline is
  /// re-fetched too: a grant arriving after a decline has to clear the compact
  /// row, not re-render it against a stale timestamp.
  void _watch() {
    final PushDriver? driver = Notify.manager.pushDriverOrNull;
    if (driver == null) return;

    _watching.add(
      driver.onPermissionChanged.listen(
        (_) => unawaited(_read()),
        onError: (Object error) =>
            Log.error('[PushPromptHost] permission stream failed: $error'),
      ),
    );
    _watching.add(
      driver.onIdentityChanged.listen(
        (_) => unawaited(_read()),
        onError: (Object error) =>
            Log.error('[PushPromptHost] identity stream failed: $error'),
      ),
    );
  }

  /// Reads the decline this device carries, then what the package makes of it.
  Future<void> _read() async {
    await _apply(await _readDeclinedAt());
  }

  /// Re-derives the advice for [declinedAt] and puts both on screen.
  ///
  /// The one place this widget's state moves, so the timestamp it asked with
  /// and the answer it got can never be a frame apart.
  Future<void> _apply(DateTime? declinedAt) async {
    final PushPromptAdvice advice = await _readAdvice(declinedAt);

    if (!mounted) return;

    setState(() {
      _declinedAt = declinedAt;
      _advice = advice;
    });
  }

  /// Asks the package what to do, answering "nothing to offer" when the
  /// platform read throws.
  ///
  /// `pushPromptAdvice` reaches `permissionState()` through `reachability()`,
  /// a platform-channel call that can throw, and it does not guard that read
  /// itself the way `pushDeliverySnapshot()` does. Left unhandled the throw
  /// escapes as an unhandled async error and [_advice] stays null, which
  /// renders nothing at all rather than a state the operator can act on.
  Future<PushPromptAdvice> _readAdvice(DateTime? declinedAt) async {
    try {
      return await Notify.manager.pushPromptAdvice(declinedAt: declinedAt);
    } catch (error) {
      if (Magic.bound('log')) {
        Log.warning('[PushPromptHost] push prompt advice failed: $error');
      }

      return _unreadableDevice;
    }
  }

  /// Reads the persisted decline, migrating one an older build wrote.
  ///
  /// Three answers, and the middle one is the whole reason this is not a
  /// `DateTime.tryParse` one-liner:
  ///
  ///  1. nothing stored: this device never declined, so it is due now;
  ///  2. an unparseable value: the bare `'1'` older builds wrote, which records
  ///     a decline with NO time. Reading it as "never" would ask again
  ///     instantly, undoing a decision the operator already made, and reading
  ///     it as the epoch would do the same thing by a different route, since
  ///     every interval has elapsed since then. It is stamped as NOW instead,
  ///     and written back, so the next launch can age it like any other;
  ///  3. a timestamp: used as it stands.
  ///
  /// A vault failure answers null, because a broken read must not take the
  /// preference screen down and the safe default is to ask: the reminder is a
  /// question, not an action.
  Future<DateTime?> _readDeclinedAt() async {
    final String? stored = await _readVault();
    if (stored == null) return null;

    final DateTime? recorded = DateTime.tryParse(stored);
    if (recorded != null) return recorded;

    // The stamp is returned whether or not the write landed: this session's
    // answer is right either way, and a vault that refused the write leaves the
    // legacy flag to be migrated again on the next launch rather than losing
    // the decline.
    final DateTime migrated = DateTime.now().toUtc();
    await _persistDeclinedAt(migrated);

    return migrated;
  }

  /// The raw stored value, or null when there is none or the vault is
  /// unreachable.
  Future<String?> _readVault() async {
    try {
      return await Vault.get(PushPromptHost.declinedVaultKey);
    } catch (error) {
      if (Magic.bound('log')) {
        Log.warning('[PushPromptHost] vault read failed: $error');
      }

      return null;
    }
  }

  /// Writes [at] to this device, answering whether it landed.
  Future<bool> _persistDeclinedAt(DateTime at) async {
    try {
      await Vault.put(
        PushPromptHost.declinedVaultKey,
        at.toUtc().toIso8601String(),
      );

      return true;
    } catch (error) {
      if (Magic.bound('log')) {
        Log.warning('[PushPromptHost] vault write failed: $error');
      }

      return false;
    }
  }

  /// Records the decline on this device, then re-reads the advice.
  ///
  /// The write comes FIRST and the row only changes when it landed. A decline
  /// that failed to persist will not survive the next launch, so reporting it
  /// as resolved would leave the operator looking at a row that says their
  /// answer was taken when it was not; the reminder (decline control and all)
  /// stays on screen instead.
  Future<void> _decline() async {
    final DateTime at = DateTime.now().toUtc();
    if (!await _persistDeclinedAt(at)) return;

    await _apply(at);
  }

  /// Raises the platform request, then re-reads what the platform now says.
  ///
  /// One handler for both live actions, because both are the same call: on a
  /// device that has never been asked it raises the dialog, and on a denied one
  /// the driver's `fallbackToSettings` turns it into the app's settings page.
  ///
  /// The request is guarded the same way the vault reads are: a throw is logged
  /// rather than left to escape as an unhandled async error, and control still
  /// falls through to [_apply] afterward so the row never gets stuck on the
  /// spinner with a stale reading. The re-read keeps the decline this device
  /// already carries rather than going back to the vault for it.
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

    await _apply(_declinedAt);
  }

  /// Whether the host app has left the reminder turned on at all.
  ///
  /// Read here as well as inside the package, and the duplication is
  /// deliberate: `pushPromptAdvice` folds this switch into `advice.show`, but a
  /// false `show` cannot say WHICH of the two reasons produced it, and the two
  /// render differently. "Not due yet" still owes the operator a status line on
  /// a screen they opened on purpose; "switched off" owes them nothing at all.
  bool get _softPromptEnabled =>
      Config.get<bool>('notifications.soft_prompt.enabled', true) ?? true;

  @override
  Widget build(BuildContext context) {
    if (!_softPromptEnabled) return const SizedBox.shrink();

    final PushPromptAdvice? advice = _advice;

    // Nothing is known yet. One frame, and only ever the first: every other
    // state below renders a row, so this cannot become a permanently empty
    // child costing a gap slot in the preference screen's flex column.
    if (advice == null) return const SizedBox.shrink();

    return PushPrompt(
      reachability: advice.reachability,
      action: advice.action,
      // `show` is the package's answer to "may I interrupt", and a screen the
      // operator opened deliberately still states the device's status when the
      // answer is no. That is exactly the compact row: no title, no decline,
      // and the way back left in place.
      declined: !advice.show || _asked,
      busy: _busy,
      onEnable: _enable,
      onDecline: _decline,
    );
  }
}

/// **The shell's admission that this device cannot be paged.**
///
/// A quiet, tappable marker for the app shell: a glyph and one line in the
/// desktop sidebar, the glyph alone in the mobile top bar. Tapping it opens the
/// notification preferences screen, where [PushPromptHost] carries the controls
/// that can actually fix it.
///
/// ### Why it exists at all, and why it is not louder
///
/// The soft prompt lives on a settings screen an on-call engineer opens roughly
/// never, so on the two surfaces they DO look at, a device that cannot ring was
/// indistinguishable from one that can. That is the failure this product cannot
/// afford quietly: an incident opens, nothing rings, and nobody learns why until
/// the postmortem.
///
/// It is still not an alarm. It sits with the shell's other secondary controls,
/// carries no colour beyond the `degraded` glyph, and never blocks anything: an
/// engineer who has decided email is enough should be able to read past it all
/// day without being nagged, which is also why the reminder's own cadence lives
/// in `notifications.push.reprompt_after_hours` rather than here.
///
/// ### When it says nothing
///
/// Exactly when there is nothing to do about it, which is
/// [PushPromptAction.none]: a device that is already subscribed, and a build
/// with no push driver at all (a platform the SDK does not cover, and a
/// platform read that failed). A permanent marker nobody can resolve is the
/// fastest way to train people to ignore the one that matters.
///
/// ### Example Usage:
///
/// ```dart
/// const PushOffNotice()                // the sidebar row
/// const PushOffNotice(compact: true)   // the mobile top bar
/// ```
class PushOffNotice extends StatefulWidget {
  /// Creates the shell notice.
  const PushOffNotice({super.key, this.compact = false});

  /// Whether to render the glyph alone, for a bar with no room for a label.
  final bool compact;

  @override
  State<PushOffNotice> createState() => _PushOffNoticeState();
}

class _PushOffNoticeState extends State<PushOffNotice> {
  /// The glyph. Extracted rather than written inline so the icon tree-shakes.
  static const IconData _icon = Icons.notifications_off_outlined;

  /// What the package makes of this device, or null while the first read is in
  /// flight.
  PushPromptAdvice? _advice;

  /// The driver reports this widget listens to while it is mounted.
  ///
  /// Both of them, because either can end the state this marker is about: the
  /// permission stream carries a grant, and the identity stream carries the
  /// subscription landing afterwards, which is the half that turns an `off`
  /// device into an `on` one. Without them the shell keeps claiming push is off
  /// for as long as this widget stays mounted, which in a persistent app shell
  /// is the whole session.
  final List<StreamSubscription<Object?>> _watching =
      <StreamSubscription<Object?>>[];

  @override
  void initState() {
    super.initState();
    unawaited(_read());
    _watch();
  }

  @override
  void dispose() {
    for (final StreamSubscription<Object?> subscription in _watching) {
      unawaited(subscription.cancel());
    }
    _watching.clear();
    super.dispose();
  }

  /// Follows everything the driver reports about this device's state.
  void _watch() {
    final PushDriver? driver = Notify.manager.pushDriverOrNull;
    if (driver == null) return;

    // Both carry an `onError`: the driver pipes a failed platform read into
    // these streams instead of swallowing it, and a subscription without a
    // handler would hand that to the zone as an unhandled async error.
    _watching.add(
      driver.onPermissionChanged.listen(
        (_) => unawaited(_read()),
        onError: (Object error) =>
            Log.error('[PushOffNotice] permission stream failed: $error'),
      ),
    );
    _watching.add(
      driver.onIdentityChanged.listen(
        (_) => unawaited(_read()),
        onError: (Object error) =>
            Log.error('[PushOffNotice] identity stream failed: $error'),
      ),
    );
  }

  /// Asks the package where this device stands.
  ///
  /// No decline timestamp is passed, and that is deliberate: `declinedAt` only
  /// moves `advice.show`, which is the answer to "may I interrupt". This marker
  /// never interrupts, so it reads [PushPromptAdvice.action] instead, and a
  /// device stays marked whether or not the operator turned the reminder down.
  ///
  /// A platform read that throws answers "nothing to offer" rather than raising
  /// on a lifecycle path. That hides the marker, which is the quieter of the two
  /// wrong answers and matches the no-driver case it cannot be told apart from:
  /// the preferences screen still states the device's status honestly.
  Future<void> _read() async {
    PushPromptAdvice advice;

    try {
      advice = await Notify.manager.pushPromptAdvice();
    } catch (error) {
      if (Magic.bound('log')) {
        Log.warning('[PushOffNotice] push prompt advice failed: $error');
      }

      advice = _unreadableDevice;
    }

    if (!mounted) return;

    setState(() => _advice = advice);
  }

  @override
  Widget build(BuildContext context) {
    final PushPromptAdvice? advice = _advice;
    if (advice == null || advice.action == PushPromptAction.none) {
      return const SizedBox.shrink();
    }

    final String label = trans('uptizm.push_prompt.shell_notice');

    // Named for assistive technology on both forms, not just the compact one:
    // the row's own label would otherwise be read as loose text with an
    // unnamed tap target beside it, which is the defect ShellControlSemantics
    // exists to prevent for the shell's other controls.
    return MergeSemantics(
      child: Semantics(
        label: trans('uptizm.a11y.push_off'),
        button: true,
        child: WAnchor(
          onTap: () =>
              MagicRoute.to(MagicStarterConfig.notificationPreferencesRoute()),
          child: WDiv(
            className: pushOffNoticeRecipe(
              variants: {
                kPushOffNoticeDensityAxis: widget.compact
                    ? kPushOffNoticeDensityCompact
                    : kPushOffNoticeDensityFull,
              },
            ),
            children: [
              WIcon(_icon, className: pushOffNoticeIconClassName),
              if (!widget.compact)
                Expanded(
                  child: WText(label, className: pushOffNoticeLabelClassName),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
