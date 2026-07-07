import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons, IconData;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

/// A single onboarding slide: a soft-toned glyph tile over a title and body.
///
/// [toneClassName] is an explicit status soft-token pair (e.g.
/// `bg-up-soft text-up-soft-foreground`) so the tile color reads from the
/// monitoring status vocabulary; [titleKey] / [bodyKey] are i18n keys resolved
/// at build time.
@immutable
class _WelcomeSlide {
  /// Soft background + foreground token pair for the glyph tile.
  final String toneClassName;

  /// The glyph shown in the tile.
  final IconData icon;

  /// i18n key for the slide headline.
  final String titleKey;

  /// i18n key for the slide body copy.
  final String bodyKey;

  const _WelcomeSlide({
    required this.toneClassName,
    required this.icon,
    required this.titleKey,
    required this.bodyKey,
  });
}

/// The three onboarding slides, in order (monitoring, AI, status pages).
const List<_WelcomeSlide> _slides = [
  _WelcomeSlide(
    toneClassName: 'bg-up-soft text-up-soft-foreground',
    icon: Icons.public,
    titleKey: 'uptizm.welcome.slide_monitoring_title',
    bodyKey: 'uptizm.welcome.slide_monitoring_body',
  ),
  _WelcomeSlide(
    toneClassName: 'bg-ai-soft text-ai-soft-foreground',
    icon: Icons.auto_awesome,
    titleKey: 'uptizm.welcome.slide_ai_title',
    bodyKey: 'uptizm.welcome.slide_ai_body',
  ),
  _WelcomeSlide(
    toneClassName: 'bg-info-soft text-info-soft-foreground',
    icon: Icons.campaign,
    titleKey: 'uptizm.welcome.slide_status_title',
    bodyKey: 'uptizm.welcome.slide_status_body',
  ),
];

/// **The first-launch onboarding carousel at `/welcome`.**
///
/// A faithful Flutter port of the React `WelcomePage`: a standalone (no app
/// shell) three-slide carousel that introduces Uptizm's value, then hands off
/// to register (or sign in). Registered OUTSIDE [AppLayout], mirroring the
/// React router placing `/welcome` outside its app shell (like `/invite/:token`).
///
/// The React track-based carousel ports to a Flutter [PageView]: slides swipe
/// horizontally or advance via the footer control. Progress dots reflect the
/// current slide; the primary button reads "Continue" until the last slide,
/// then "Get started" (routing to register). "Skip" and "Sign in" route to the
/// login screen. Auth targets are magic_starter's client routes
/// (`/auth/register`, `/auth/login`).
///
/// ### Example
/// ```dart
/// // Registered as a top-level route with no AppLayout wrapper:
/// MagicRoute.page('/welcome', () => const WelcomeView());
/// ```
@immutable
class WelcomeView extends StatefulWidget {
  /// Creates the [WelcomeView].
  const WelcomeView({super.key});

  @override
  State<WelcomeView> createState() => _WelcomeViewState();
}

class _WelcomeViewState extends State<WelcomeView> {
  final PageController _controller = PageController();
  int _index = 0;

  /// magic_starter client route for the sign-in screen.
  static const String _loginRoute = '/auth/login';

  /// magic_starter client route for the registration screen.
  static const String _registerRoute = '/auth/register';

  bool get _isLast => _index == _slides.length - 1;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  /// Animates to slide [next], clamped to the valid range.
  void _goTo(int next) {
    final int target = next.clamp(0, _slides.length - 1);
    _controller.animateToPage(
      target,
      duration: const Duration(milliseconds: 300),
      curve: Curves.easeOut,
    );
  }

  /// Advances to the next slide, or finishes onboarding on the last slide by
  /// routing to registration.
  void _onPrimaryPressed() {
    if (_isLast) {
      MagicRoute.to(_registerRoute);
    } else {
      _goTo(_index + 1);
    }
  }

  @override
  Widget build(BuildContext context) {
    // A full-height surface (`h-screen`) so the middle slide track can take the
    // slack between the fixed header and footer via [Expanded]. The vertical
    // outer rhythm (`py-6`) lives on the header (`pt-6`) and footer (`pb-6`)
    // rather than a wrapper, so the raw [Column] receives the SafeArea's bounded
    // height directly and [Expanded] has a definite extent to divide.
    return WDiv(
      className: 'h-screen bg-surface',
      child: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _buildHeader(),
            Expanded(child: _buildTrack()),
            _buildFooter(),
          ],
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Header
  // ---------------------------------------------------------------------------

  /// Builds the top bar: the Uptizm wordmark on the left, a "Skip" link on the
  /// right that jumps straight to sign-in.
  Widget _buildHeader() {
    return WDiv(
      className: 'flex flex-row items-center justify-between px-6 pt-6',
      children: [
        _buildWordmark(),
        WAnchor(
          onTap: () => MagicRoute.to(_loginRoute),
          child: WText(
            trans('uptizm.welcome.skip'),
            className: 'px-1 text-sm font-medium text-fg-muted',
          ),
        ),
      ],
    );
  }

  /// Builds the "Uptizm" wordmark: a brand-tinted glyph tile next to the
  /// product name (the canonical [InviteAcceptView] pattern).
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

  // ---------------------------------------------------------------------------
  // Slide track
  // ---------------------------------------------------------------------------

  /// Builds the swipeable [PageView] of onboarding slides.
  Widget _buildTrack() {
    return PageView(
      controller: _controller,
      onPageChanged: (int page) => setState(() => _index = page),
      children: [
        for (final slide in _slides) _buildSlide(slide),
      ],
    );
  }

  /// Builds one centered slide: a soft-toned glyph tile, a headline, and body.
  Widget _buildSlide(_WelcomeSlide slide) {
    return WDiv(
      className: 'flex flex-col items-center justify-center px-6 text-center',
      children: [
        WDiv(
          className:
              'size-20 rounded-3xl flex items-center justify-center ${slide.toneClassName}',
          child: WIcon(slide.icon, className: 'text-4xl'),
        ),
        WText(
          trans(slide.titleKey),
          className:
              'mt-7 text-2xl font-semibold tracking-tight text-fg text-center',
        ),
        WText(
          trans(slide.bodyKey),
          className: 'mt-3 text-sm leading-relaxed text-fg-muted text-center',
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Footer
  // ---------------------------------------------------------------------------

  /// Builds the footer: progress dots, the primary CTA, and the sign-in switch.
  Widget _buildFooter() {
    return WDiv(
      className: 'flex flex-col items-center gap-6 px-6 pb-6',
      children: [
        _buildDots(),
        WDiv(
          // The primary button is content-width and centered, matching the
          // magic_starter Button design (a default Button is `inline-flex`, and
          // full-width would require shipping a `w-full` className that bypasses
          // the recipe and drops the primary fill/padding). This mirrors the
          // content-width button convention in [InviteAcceptView].
          className: 'w-full max-w-xs flex flex-col items-center gap-3',
          children: [
            Button(
              onPressed: _onPrimaryPressed,
              child: WText(
                _isLast
                    ? trans('uptizm.welcome.get_started')
                    : trans('uptizm.welcome.continue_label'),
              ),
            ),
            _buildSignInSwitch(),
          ],
        ),
      ],
    );
  }

  /// Builds the progress dots: the active slide widens into a primary pill.
  Widget _buildDots() {
    return WDiv(
      className: 'flex flex-row items-center gap-2',
      children: [
        for (int i = 0; i < _slides.length; i++)
          WAnchor(
            onTap: () => _goTo(i),
            child: WDiv(
              className: i == _index
                  ? 'h-2 w-6 rounded-full bg-primary'
                  : 'h-2 w-2 rounded-full bg-surface-container-high',
            ),
          ),
      ],
    );
  }

  /// Builds the "Already have an account? Sign in" switch line.
  ///
  /// Uses Wind's `wrap` (flex-wrap) so the prompt and the link flow onto a
  /// second line on very narrow viewports instead of overflowing the `max-w-xs`
  /// footer column.
  Widget _buildSignInSwitch() {
    return WDiv(
      className: 'wrap items-center justify-center gap-1',
      children: [
        WText(
          trans('uptizm.welcome.have_account'),
          className: 'text-sm text-fg-muted',
        ),
        WAnchor(
          onTap: () => MagicRoute.to(_loginRoute),
          child: WText(
            trans('uptizm.welcome.sign_in'),
            className: 'text-sm font-medium text-primary',
          ),
        ),
      ],
    );
  }
}
