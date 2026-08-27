import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/models/user.dart';
import '../../../app/services/locale_onboarding_gate.dart';

/// **The first-run locale + timezone prompt banner.**
///
/// A compact, non-blocking, dismissible banner rendered at the top of the
/// populated dashboard on the FIRST launch only. A freshly authenticated user
/// lands straight on the dashboard; this banner then surfaces the language +
/// timezone auto-detected at boot (the applied [Lang] locale and the applied
/// [DateManager] timezone) as a quiet suggestion rather than a gate.
///
/// Whether the banner has anything to say is [shouldShow], read from
/// [LocaleOnboardingGate], the same device-scoped first-run flag the removed
/// routing guard used: it speaks only while the gate is unset AND a user is
/// authenticated. Every action (Confirm, Change, Dismiss) marks the gate and
/// then calls [onResolved], so the banner never reappears on this device after
/// any interaction.
///
/// THE CALLER MUST GATE ON [shouldShow] RATHER THAN MOUNT THIS UNCONDITIONALLY.
/// A widget that renders nothing still occupies a slot in a Wind flex, whose gap
/// injection inserts a spacer between every pair of children, a zero-size one
/// included. Mounted unconditionally inside the dashboard's `gap-6` intro
/// column, this banner held 24px above the page header on every launch after
/// the first, and the dashboard title sat a notch lower than the title on every
/// other page in the product. That is why hiding is the caller's decision and
/// this widget carries no `visible` state of its own.
///
/// - **Confirm** persists the applied locale/timezone through the profile
///   update path ([MagicStarterProfileController.doUpdateProfile], the
///   canonical `locale`/`timezone` wire keys), then marks the gate.
/// - **Change** marks the gate, then routes to the EXISTING language settings
///   page (`/settings/language`); it never builds its own picker.
/// - **Dismiss** (the `✕`) marks the gate, keeping the detected defaults
///   already applied at boot.
///
/// Styled after the dashboard's "Right now" [AiInsight] banner (rounded, tinted
/// surface, an icon tile, message, and inline text-actions) using Wind semantic
/// alias tokens only, each with its `dark:` pair.
@immutable
class LocalePromptBanner extends StatefulWidget {
  /// Creates the [LocalePromptBanner].
  const LocalePromptBanner({super.key, required this.onResolved});

  /// Called after any action marks the one-time gate, so the caller can drop
  /// the banner from the tree. Re-reading [shouldShow] then returns false.
  final VoidCallback onResolved;

  /// Whether this device still owes the user the one-time locale prompt.
  ///
  /// The auth read is guarded behind the container binding (mirroring
  /// [LocaleOnboardingGate.load]'s `Magic.bound('log')` check): the running app
  /// always binds `auth`, but widget tests that mount the dashboard without an
  /// auth container must not crash on this first-run probe.
  static bool get shouldShow {
    if (!Magic.bound('auth') || !Auth.check()) return false;

    return !LocaleOnboardingGate.instance.isCompleted;
  }

  @override
  State<LocalePromptBanner> createState() => _LocalePromptBannerState();
}

class _LocalePromptBannerState extends State<LocalePromptBanner> {
  /// Guards the Confirm action against double submission while its profile
  /// update is in flight.
  bool _busy = false;

  /// Resolves the human label for the currently applied locale.
  ///
  /// Maps the `MagicStarter.manager.localeOptions` entry whose value matches
  /// [Lang.current] to its display label, falling back to the raw language
  /// code when no option matches (or none are configured).
  String _currentLanguageLabel() {
    final String code = Lang.current.languageCode;
    for (final SelectOption<String> option
        in MagicStarter.manager.localeOptions) {
      if (option.value == code) return option.label;
    }
    return code;
  }

  /// Persists the applied locale/timezone, marks the gate, then hides.
  Future<void> _confirm() async {
    if (_busy) return;
    setState(() => _busy = true);

    // 1. Persist the applied values through the profile-update path (the
    //    canonical `locale`/`timezone` wire keys); name/email are carried over
    //    from the current user since `doUpdateProfile` requires them.
    final User user = User.current;
    final String? name = user.name;
    final String? email = user.email;

    // A locale confirmation has no business rewriting the account's name and
    // email, and `doUpdateProfile` requires both. Coercing a null to `''` is
    // what this did, which turns "we do not know this value" into "set it to
    // empty": the server's `required` rules then reject the write, and step 2
    // used to close the gate anyway. A guest account carries a null email, so
    // the path was reachable rather than theoretical.
    if (name == null || name.isEmpty || email == null || email.isEmpty) {
      Log.error('[LocalePromptBanner] profile incomplete; locale not persisted');
      if (mounted) setState(() => _busy = false);

      return;
    }

    final bool saved = await MagicStarterProfileController.instance
        .doUpdateProfile(
          name: name,
          email: email,
          language: Lang.current.languageCode,
          timezone: DateManager.instance.timezoneName,
        );

    // 2. Close the one-time gate ONLY when the write landed.
    //
    //    `doUpdateProfile` answers false on any non-2xx and on any thrown
    //    transport error, and its result was discarded here. So a Confirm on a
    //    flaky connection destroyed the banner forever on this device with the
    //    locale and timezone never persisted server-side: every other client the
    //    account signs into kept the old locale, and the only affordance that
    //    offered to fix it was gone. Leaving the banner mounted is what makes
    //    Confirm retryable.
    if (!saved) {
      if (mounted) setState(() => _busy = false);

      return;
    }

    await LocaleOnboardingGate.instance.markCompleted();

    // 3. Release the double-tap guard, then hand the hide decision back to the
    //    caller. Order matters: the caller drops this widget on being told, and
    //    a setState after that runs against an unmounted state.
    if (mounted) setState(() => _busy = false);
    widget.onResolved();
  }

  /// Marks the gate, hands back the hide decision, then opens the existing
  /// language settings page.
  Future<void> _change() async {
    await LocaleOnboardingGate.instance.markCompleted();
    widget.onResolved();
    MagicRoute.to('/settings/language');
  }

  /// Marks the gate and hands back the hide decision, keeping the detected
  /// defaults.
  Future<void> _dismiss() async {
    await LocaleOnboardingGate.instance.markCompleted();
    widget.onResolved();
  }

  @override
  Widget build(BuildContext context) {
    final String message = trans('uptizm.onboarding.banner_detected', {
      'language': _currentLanguageLabel(),
      'timezone': DateManager.instance.timezoneName,
    });

    return WDiv(
      key: const ValueKey('locale-prompt-banner'),
      className:
          'flex flex-row items-start gap-3 rounded-xl border '
          'border-color-border bg-surface-container p-4',
      children: [
        // Icon tile, mirroring the AiInsight banner glyph tile.
        WDiv(
          className:
              'size-8 shrink-0 flex items-center justify-center rounded-lg '
              'bg-primary-container',
          child: const WIcon(
            Icons.translate,
            className: 'text-primary text-lg',
          ),
        ),

        // Body: the detected message above the inline text-actions.
        WDiv(
          className: 'min-w-0 flex-1 flex flex-col gap-2',
          children: [
            WText(message, className: 'text-sm leading-relaxed text-fg'),
            WDiv(
              className: 'flex flex-row items-center gap-4',
              children: [_buildConfirm(), _buildChange()],
            ),
          ],
        ),

        // Dismiss control.
        _buildDismiss(),
      ],
    );
  }

  /// Builds the primary "Confirm" inline text-action.
  Widget _buildConfirm() {
    return WButton(
      key: const ValueKey('locale-banner-confirm'),
      onTap: _busy ? null : _confirm,
      isLoading: _busy,
      loadingSize: 14,
      className: 'text-sm font-medium text-primary',
      child: WText(trans('uptizm.onboarding.banner_confirm')),
    );
  }

  /// Builds the secondary "Change" inline text-action.
  Widget _buildChange() {
    return WButton(
      key: const ValueKey('locale-banner-change'),
      onTap: _busy ? null : _change,
      className: 'text-sm font-medium text-fg-muted',
      child: WText(trans('uptizm.onboarding.banner_change')),
    );
  }

  /// Builds the dismiss (`✕`) control.
  Widget _buildDismiss() {
    return WButton(
      key: const ValueKey('locale-banner-dismiss'),
      onTap: _busy ? null : _dismiss,
      className: 'shrink-0 p-1',
      child: WIcon(
        Icons.close,
        className: 'text-fg-muted text-base',
        semanticLabel: trans('uptizm.onboarding.banner_dismiss'),
      ),
    );
  }
}
