import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../models/user.dart';
import '../../resources/views/auth/login_view.dart';
import '../../resources/views/auth/register_view.dart';

/// Auth Controller with State Management and Validation.
///
/// Uses MagicStateMixin for async data and ValidatesRequests for
/// server-side validation error handling.
class AuthController extends MagicController
    with MagicStateMixin<bool>, ValidatesRequests {
  /// Singleton accessor with lazy registration.
  static AuthController get instance => Magic.findOrPut(AuthController.new);

  /// Render the register view.
  Widget register() => const RegisterView();

  /// Render the login view.
  Widget login() => const LoginView();

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
          MagicRoute.to('/dashboard');
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
          MagicRoute.to('/dashboard');
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
}
