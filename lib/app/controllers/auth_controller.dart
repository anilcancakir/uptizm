import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:fluttersdk_magic_social_auth/fluttersdk_magic_social_auth.dart';

import '../models/user.dart';
import '../../resources/views/auth/login_view.dart';
import '../../resources/views/auth/register_view.dart';
import '../../resources/views/auth/forgot_password_view.dart';
import '../../resources/views/auth/reset_password_view.dart';

/// Auth Controller with State Management and Validation.
///
/// Uses MagicStateMixin for async data and ValidatesRequests for
/// server-side validation error handling.
class AuthController extends MagicController
    with MagicStateMixin<bool>, ValidatesRequests {
  /// Singleton accessor with lazy registration.
  static AuthController get instance => Magic.findOrPut(AuthController.new);

  /// Currently active social login provider (for per-button loading state).
  String? socialLoginProvider;

  /// Render the register view.
  Widget register() => const RegisterView();

  /// Render the login view.
  Widget login() => const LoginView();

  /// Render the forgot password view.
  Widget forgotPassword() => const ForgotPasswordView();

  /// Render the reset password view.
  Widget resetPassword() => const ResetPasswordView();

  /// Register a new user via API.
  ///
  /// Flow:
  /// 1. POST /auth/register
  /// 2. On success: login user and navigate to dashboard
  /// 3. On fail: show validation errors
  Future<void> doRegister({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
    bool subscribeNewsletter = false,
  }) async {
    setLoading();
    clearErrors();

    try {
      final response = await Http.post(
        '/auth/register',
        data: {
          'name': name,
          'email': email,
          'password': password,
          'password_confirmation': passwordConfirmation,
          'subscribe_newsletter': subscribeNewsletter,
        },
      );

      if (response.successful) {
        final data = response['data'] as Map<String, dynamic>?;
        final token = data?['token'] as String?;
        final userData = data?['user'] as Map<String, dynamic>?;

        if (token != null && userData != null) {
          final user = User.fromMap(userData);
          await Auth.login({'token': token}, user);
          setSuccess(true);
          MagicRoute.to('/');
        } else {
          setSuccess(true);
          Magic.success('Success', 'Account created! Please login.');
          MagicRoute.to('/auth/login');
        }
      } else {
        handleApiError(response, fallback: 'Registration failed');
      }
    } catch (e) {
      setError('An unexpected error occurred');
    }
  }

  /// Login a user via API.
  ///
  /// Flow:
  /// 1. POST /auth/login
  /// 2. On success: store token, set user, navigate to dashboard
  /// 3. On fail: show validation errors
  Future<void> doLogin({
    required String email,
    required String password,
    bool rememberMe = false,
  }) async {
    setLoading();
    clearErrors();

    try {
      final response = await Http.post(
        '/auth/login',
        data: {'email': email, 'password': password, 'remember_me': rememberMe},
      );

      if (response.successful) {
        final data = response['data'] as Map<String, dynamic>?;
        final token = data?['token'] as String?;
        final userData = data?['user'] as Map<String, dynamic>?;

        if (token != null && userData != null) {
          final user = User.fromMap(userData);
          await Auth.login({'token': token}, user);
          setSuccess(true);
          MagicRoute.to('/');
        } else {
          setError('Login failed: Invalid response format');
        }
      } else {
        handleApiError(response, fallback: 'Authentication failed');
      }
    } catch (e) {
      setError('An unexpected error occurred');
    }
  }

  /// Send forgot password email.
  ///
  /// Flow:
  /// 1. POST /auth/forgot-password
  /// 2. On success: show success message
  /// 3. On fail: show validation errors
  Future<void> doForgotPassword({required String email}) async {
    setLoading();
    clearErrors();

    try {
      final response = await Http.post(
        '/auth/forgot-password',
        data: {'email': email},
      );

      if (response.successful) {
        setSuccess(true);
        Magic.success(
          trans('auth.reset_link_sent_title'),
          trans('auth.reset_link_sent_message'),
        );
      } else {
        handleApiError(response, fallback: trans('auth.reset_link_failed'));
      }
    } catch (e) {
      setError('An unexpected error occurred');
    }
  }

  /// Reset password with token.
  ///
  /// Flow:
  /// 1. POST /auth/reset-password
  /// 2. On success: navigate to login
  /// 3. On fail: show validation errors
  Future<void> doResetPassword({
    required String token,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) async {
    setLoading();
    clearErrors();

    try {
      final response = await Http.post(
        '/auth/reset-password',
        data: {
          'token': token,
          'email': email,
          'password': password,
          'password_confirmation': passwordConfirmation,
        },
      );

      if (response.successful) {
        setSuccess(true);
        Magic.success(
          trans('auth.password_reset_success_title'),
          trans('auth.password_reset_success_message'),
        );
        MagicRoute.to('/auth/login');
      } else {
        handleApiError(response, fallback: trans('auth.password_reset_failed'));
      }
    } catch (e) {
      setError('An unexpected error occurred');
    }
  }

  // ---------------------------------------------------------------------------
  // Social Login Methods
  // ---------------------------------------------------------------------------

  /// Login with any social provider.
  ///
  /// Flow:
  /// 1. Native SDK / OAuth opens
  /// 2. Token received from provider
  /// 3. Token sent to backend for verification
  /// 4. Sanctum token received, user logged in
  Future<void> doSocialLogin(String provider) async {
    if (!SocialAuth.supports(provider)) {
      setError('$provider is not supported on this platform');
      return;
    }

    setLoading();
    clearErrors();
    socialLoginProvider = provider; // Track which button is loading
    notifyListeners();

    try {
      await SocialAuth.driver(provider).authenticate();
      setSuccess(true);
      MagicRoute.to('/');
    } on SocialAuthCancelledException {
      // User cancelled - reset to initial state
      setSuccess(false);
    } on SocialAuthException catch (e) {
      setError(e.message);
    } catch (e) {
      Log.error('Social Login Error: $e');
      setError('Social login failed. Please try again.');
    } finally {
      socialLoginProvider = null; // Clear loading state
      notifyListeners();
    }
  }

  /// Login with Google.
  Future<void> doGoogleLogin() => doSocialLogin('google');

  /// Login with Microsoft.
  Future<void> doMicrosoftLogin() => doSocialLogin('microsoft');

  /// Login with GitHub.
  Future<void> doGithubLogin() => doSocialLogin('github');

  // ---------------------------------------------------------------------------
  // Logout Methods
  // ---------------------------------------------------------------------------

  /// Logout the current user.
  ///
  /// Flow:
  /// 1. Sign out from all social providers (clear cached credentials)
  /// 2. Logout from the app (clear token and user)
  /// 3. Navigate to login page
  Future<void> doLogout() async {
    try {
      // Sign out from all social providers first
      await SocialAuth.signOut();

      // Then logout from the app
      await Auth.logout();

      // Navigate to login
      MagicRoute.to('/auth/login');
    } catch (e) {
      Log.error('Logout error: $e');
      // Still try to logout even if social signout fails
      await Auth.logout();
      MagicRoute.to('/auth/login');
    }
  }
}
