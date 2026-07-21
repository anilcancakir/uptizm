import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/models/user.dart';
import '../../../app/services/locale_onboarding_gate.dart';

/// **The one-time post-register locale + timezone onboarding at
/// `/onboarding/locale`.**
///
/// A standalone (no app shell) screen shown once after register and before the
/// dashboard. It offers a language selector (from
/// [MagicStarterManager.localeOptions]) and a timezone selector (the reused,
/// API-backed [MagicStarterTimezoneSelect]), both pre-filled from the values
/// auto-detected at boot: the applied [Lang] locale and the applied
/// [DateManager] timezone.
///
/// On confirm it applies the choice immediately ([Lang.setLocale] +
/// [DateManager.setTimezone]) so the UI updates before leaving, persists it via
/// the profile-update path (`doUpdateProfile`, which posts the canonical
/// `locale`/`timezone` wire keys), marks the [LocaleOnboardingGate] so it never
/// shows again on this device, then continues to the dashboard. Skip keeps the
/// already-applied detected defaults and only marks the gate.
///
/// The screen is reachable exactly once per device: the routing middleware
/// ([RedirectToLocaleOnboarding]) sends a freshly authenticated user here while
/// the gate is unset, and a later login of an onboarded user routes straight to
/// the dashboard.
@immutable
class LocaleOnboardingView extends StatefulWidget {
  /// Creates the [LocaleOnboardingView].
  const LocaleOnboardingView({super.key});

  @override
  State<LocaleOnboardingView> createState() => _LocaleOnboardingViewState();
}

class _LocaleOnboardingViewState extends State<LocaleOnboardingView> {
  /// The selected locale code, seeded from the applied (detected) locale.
  ///
  /// On a fresh-device login of an existing user the post-login locale sync
  /// (`_syncLocale`) is unawaited while the router redirects here, so this may
  /// capture the device-detected locale rather than the user's restored backend
  /// locale. The user can still adjust it before confirming.
  late String _locale = Lang.current.languageCode;

  /// The selected IANA timezone, seeded from the applied (detected) timezone.
  late String _timezone = DateManager.instance.timezoneName;

  /// Guards the confirm/skip actions against double submission.
  bool _busy = false;

  /// Resolves the configured locale options, falling back to en/tr.
  ///
  /// Mirrors [MagicStarterLanguageView]: the app registers its options via
  /// `MagicStarter.useLocaleOptions`, but a bare install still offers the two
  /// shipped locales.
  List<SelectOption<String>> _localeOptions() {
    final options = MagicStarter.manager.localeOptions;
    if (options.isNotEmpty) return options;
    return [
      SelectOption<String>(value: 'en', label: 'English'),
      SelectOption<String>(value: 'tr', label: 'Türkçe'),
    ];
  }

  /// Persists the chosen locale/timezone, then applies + continues home.
  Future<void> _confirm() async {
    if (_busy) return;
    setState(() => _busy = true);

    bool persisted = false;
    try {
      // Persist to the backend profile FIRST (canonical `locale`/`timezone`
      // keys). Only a confirmed save applies the choice and closes the one-time
      // gate: applying locally before a failed save would diverge from the
      // backend (and be reverted by the next Auth.restore via `_syncLocale`),
      // and marking the gate would burn the one-shot onboarding on a save that
      // never landed.
      final User user = User.current;
      persisted = await MagicStarterProfileController.instance.doUpdateProfile(
        name: user.name ?? '',
        email: user.email ?? '',
        language: _locale,
        timezone: _timezone,
      );
    } finally {
      if (mounted) setState(() => _busy = false);
    }

    if (!persisted) {
      // `doUpdateProfile` records its error state but shows no toast; surface
      // the failure here and leave the gate open so the user can retry.
      MagicFeedback.error(
        trans('uptizm.onboarding.error_title'),
        trans('profile.update_failed'),
      );
      return;
    }

    // Apply immediately so the app renders in the chosen language/timezone,
    // then mark the one-shot gate and continue to the dashboard. reload: false
    // because the root MaterialApp.locale tracks the runtime Translator (see
    // main.dart), so notifying rebuilds the app without a full remount.
    await Lang.setLocale(Locale(_locale), reload: false);
    if (_timezone.isNotEmpty) {
      DateManager.instance.setTimezone(_timezone);
    }
    await LocaleOnboardingGate.instance.markCompleted();

    _continueHome();
  }

  /// Keeps the detected defaults (already applied at boot) and continues home.
  Future<void> _skip() async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      await LocaleOnboardingGate.instance.markCompleted();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
    _continueHome();
  }

  /// Leaves onboarding for the shared home route.
  void _continueHome() {
    MagicRoute.to(MagicStarterConfig.homeRoute());
  }

  @override
  Widget build(BuildContext context) {
    final MagicStarterFormTheme formTheme = MagicStarter.formTheme;

    return WDiv(
      className: 'h-screen bg-surface',
      child: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _buildHeader(),
            Expanded(child: _buildBody(formTheme)),
            _buildFooter(),
          ],
        ),
      ),
    );
  }

  /// Builds the top bar: the Uptizm wordmark and a "Skip" affordance.
  Widget _buildHeader() {
    return WDiv(
      className: 'flex flex-row items-center justify-between px-6 pt-6',
      children: [
        _buildWordmark(),
        WAnchor(
          onTap: _busy ? null : _skip,
          child: WText(
            trans('uptizm.onboarding.skip'),
            className: 'px-1 text-sm font-medium text-fg-muted',
          ),
        ),
      ],
    );
  }

  /// Builds the brand wordmark (matching [WelcomeView]).
  Widget _buildWordmark() {
    return WDiv(
      className: 'flex flex-row items-center gap-2',
      children: [
        WDiv(
          className:
              'size-7 rounded-lg bg-primary flex items-center justify-center',
          child: const WIcon(
            Icons.show_chart,
            className: 'text-on-primary text-base',
          ),
        ),
        WText(
          'Uptizm',
          className: 'text-base font-semibold tracking-tight text-fg',
        ),
      ],
    );
  }

  /// Builds the scrollable body: heading + the two preference selectors.
  Widget _buildBody(MagicStarterFormTheme formTheme) {
    return SingleChildScrollView(
      child: WDiv(
        className: 'flex flex-col gap-6 px-6 pt-8',
        children: [
          WDiv(
            className: 'flex flex-col gap-2',
            children: [
              WText(
                trans('uptizm.onboarding.title'),
                className: 'text-2xl font-semibold tracking-tight text-fg',
              ),
              WText(
                trans('uptizm.onboarding.subtitle'),
                className: 'text-sm leading-relaxed text-fg-muted',
              ),
            ],
          ),
          WDiv(
            className:
                'flex flex-col gap-4 bg-surface-container border '
                'border-color-border rounded-lg p-5',
            children: [
              WFormSelect<String>(
                value: _locale,
                onChange: (v) => setState(() => _locale = v ?? _locale),
                label: trans('uptizm.onboarding.language_label'),
                options: _localeOptions(),
                labelClassName: formTheme.labelClassName,
                className: formTheme.inputClassName,
                menuClassName:
                    'bg-surface-container border border-color-border '
                    'rounded-xl shadow-xl',
              ),
              MagicStarterTimezoneSelect(
                value: _timezone,
                onChanged: (v) => setState(() => _timezone = v ?? _timezone),
                label: trans('uptizm.onboarding.timezone_label'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Builds the footer: the primary "Continue" action.
  Widget _buildFooter() {
    return WDiv(
      className: 'flex flex-col px-6 pb-6 pt-4',
      children: [
        WButton(
          key: const ValueKey('onboarding-confirm'),
          onTap: _busy ? null : _confirm,
          isLoading: _busy,
          className:
              'w-full px-4 py-2.5 rounded-lg bg-primary hover:bg-primary/80 '
              'text-on-primary text-sm font-medium',
          child: WText(trans('uptizm.onboarding.confirm')),
        ),
      ],
    );
  }
}
