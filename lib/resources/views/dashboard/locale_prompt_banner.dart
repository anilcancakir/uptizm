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
/// Visibility is decided once in [initState] from [LocaleOnboardingGate], the
/// same device-scoped first-run flag the removed routing guard used: the banner
/// shows only while the gate is unset AND a user is authenticated. Every action
/// (Confirm, Change, Dismiss) marks the gate, so the banner never reappears on
/// this device after any interaction.
///
/// - **Confirm** persists the applied locale/timezone through the profile
///   update path ([MagicStarterProfileController.doUpdateProfile], the
///   canonical `locale`/`timezone` wire keys), then marks the gate and hides.
/// - **Change** marks the gate, hides, then routes to the EXISTING language
///   settings page (`/settings/language`); it never builds its own picker.
/// - **Dismiss** (the `✕`) marks the gate and hides, keeping the detected
///   defaults already applied at boot.
///
/// Styled after the dashboard's "Right now" [AiInsight] banner (rounded, tinted
/// surface, an icon tile, message, and inline text-actions) using Wind semantic
/// alias tokens only, each with its `dark:` pair.
@immutable
class LocalePromptBanner extends StatefulWidget {
  /// Creates the [LocalePromptBanner].
  const LocalePromptBanner({super.key});

  @override
  State<LocalePromptBanner> createState() => _LocalePromptBannerState();
}

class _LocalePromptBannerState extends State<LocalePromptBanner> {
  /// Whether the banner is currently shown.
  ///
  /// Resolved once at mount: the banner appears only for an authenticated user
  /// whose device has not yet completed the one-time locale prompt. Any action
  /// flips this to `false` for the rest of the session.
  late bool _visible;

  /// Guards the Confirm action against double submission while its profile
  /// update is in flight.
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    // Guard the auth read behind the container binding (mirroring
    // [LocaleOnboardingGate.load]'s `Magic.bound('log')` check): the running
    // app always binds `auth`, but widget tests that mount the dashboard
    // without an auth container must not crash on this first-run probe.
    final bool authed = Magic.bound('auth') && Auth.check();
    _visible = authed && !LocaleOnboardingGate.instance.isCompleted;
  }

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
    await MagicStarterProfileController.instance.doUpdateProfile(
      name: user.name ?? '',
      email: user.email ?? '',
      language: Lang.current.languageCode,
      timezone: DateManager.instance.timezoneName,
    );

    // 2. Close the one-time gate so the banner never reappears on this device.
    await LocaleOnboardingGate.instance.markCompleted();

    // 3. Hide the banner and release the double-tap guard.
    if (mounted) {
      setState(() {
        _busy = false;
        _visible = false;
      });
    }
  }

  /// Marks the gate, hides, then opens the existing language settings page.
  Future<void> _change() async {
    await LocaleOnboardingGate.instance.markCompleted();
    if (mounted) setState(() => _visible = false);
    MagicRoute.to('/settings/language');
  }

  /// Marks the gate and hides, keeping the detected defaults.
  Future<void> _dismiss() async {
    await LocaleOnboardingGate.instance.markCompleted();
    if (mounted) setState(() => _visible = false);
  }

  @override
  Widget build(BuildContext context) {
    if (!_visible) return const SizedBox.shrink();

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
