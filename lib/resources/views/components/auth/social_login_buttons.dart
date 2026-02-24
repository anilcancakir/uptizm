import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'package:magic_social_auth/magic_social_auth.dart'
    show SocialAuthButtons, SocialAuthMode;

/// @deprecated Use [SocialAuthButtons] from `magic_social_auth` plugin instead.
///
/// This widget is kept for backward compatibility only. It will be removed
/// in a future release. Migrate to:
///
/// ```dart
/// import 'package:magic_social_auth/magic_social_auth.dart';
///
/// SocialAuthButtons(
///   onAuthenticate: controller.doSocialLogin,
///   loadingProvider: controller.socialLoginProvider,
///   mode: SocialAuthMode.signIn,
/// )
/// ```
@Deprecated('Use SocialAuthButtons from magic_social_auth plugin instead.')
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

  @override
  Widget build(BuildContext context) {
    // Delegate to the new plugin widget.
    return SocialAuthButtons(
      onAuthenticate: (provider) {
        switch (provider) {
          case 'google':
            return onGoogle();
          case 'microsoft':
            return onMicrosoft();
          case 'github':
            return onGithub();
          default:
            return Future<void>.value();
        }
      },
      loadingProvider: loadingProvider,
      mode: mode,
    );
  }
}
