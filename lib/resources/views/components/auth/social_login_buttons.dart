import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

/// Social auth mode - determines button label text.
enum SocialAuthMode { signIn, signUp }

/// SocialLoginButtons
///
/// Shared social login buttons for Login and Register pages.
/// Provides consistent Google, Microsoft, and GitHub authentication options.
/// Supports per-button loading states.
class SocialLoginButtons extends StatelessWidget {
  final String? loadingProvider;
  final SocialAuthMode mode;
  final Future<void> Function() onGoogle;
  final Future<void> Function() onMicrosoft;
  final Future<void> Function() onGithub;

  const SocialLoginButtons({
    super.key,
    required this.loadingProvider,
    this.mode = SocialAuthMode.signIn,
    required this.onGoogle,
    required this.onMicrosoft,
    required this.onGithub,
  });

  String _label(String provider) {
    final action = mode == SocialAuthMode.signIn ? 'Sign in' : 'Sign up';
    return '$action with $provider';
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col items-stretch',
      children: [
        // Divider with "Or continue with" text
        WDiv(
          className: 'flex flex-row items-center my-4',
          children: [
            WDiv(
              className: 'flex-1 h-[1px] bg-slate-200 dark:bg-slate-700',
              child: const SizedBox.shrink(),
            ),
            WDiv(
              className: 'px-4',
              child: WText(
                trans('auth.or_continue_with'),
                className: 'text-sm text-slate-500 dark:text-slate-400',
              ),
            ),
            WDiv(
              className: 'flex-1 h-[1px] bg-slate-200 dark:bg-slate-700',
              child: const SizedBox.shrink(),
            ),
          ],
        ),
        const SizedBox(height: 16),

        // Google Button
        _buildSocialButton(
          provider: 'google',
          label: _label('Google'),
          onTap: onGoogle,
          iconWidget: WSvg(src: 'assets/svg/google.svg', className: 'w-5 h-5'),
        ),
        const SizedBox(height: 12),

        // Microsoft Button
        _buildSocialButton(
          provider: 'microsoft',
          label: _label('Microsoft'),
          onTap: onMicrosoft,
          iconWidget: WSvg(
            src: 'assets/svg/microsoft.svg',
            className: 'w-5 h-5',
          ),
        ),
        const SizedBox(height: 12),

        // GitHub Button
        _buildSocialButton(
          provider: 'github',
          label: _label('GitHub'),
          onTap: onGithub,
          iconWidget: WSvg(
            src: 'assets/svg/github.svg',
            className: 'w-5 h-5 fill-slate-900 dark:fill-white',
          ),
        ),
      ],
    );
  }

  /// Build a social login button with per-provider loading state.
  Widget _buildSocialButton({
    required String provider,
    required String label,
    required Future<void> Function() onTap,
    required Widget iconWidget,
  }) {
    final isThisButtonLoading = loadingProvider == provider;
    final isAnyButtonLoading = loadingProvider != null;
    final isDisabled = isAnyButtonLoading && !isThisButtonLoading;

    return WButton(
      onTap: isDisabled ? null : onTap,
      isLoading: isThisButtonLoading,
      className:
          '''
        w-full p-3 rounded-xl
        bg-white dark:bg-slate-800
        border border-slate-200 dark:border-slate-700
        hover:bg-slate-50 dark:hover:bg-slate-700/50
        ${isDisabled ? 'opacity-50 cursor-not-allowed' : ''}
      ''',
      child: WDiv(
        className:
            'flex flex-row items-center justify-center gap-3 text-slate-900 dark:text-white',
        children: [
          iconWidget,
          WText(label, className: 'text-base font-medium'),
        ],
      ),
    );
  }
}
