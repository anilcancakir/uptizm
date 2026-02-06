import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/controllers/auth_controller.dart';
import '../components/auth/auth_form_card.dart';

/// Reset Password View
///
/// Form to reset password using token from email link.
/// Receives token and email from URL query parameters.
class ResetPasswordView extends MagicStatefulView<AuthController> {
  const ResetPasswordView({super.key});

  @override
  State<ResetPasswordView> createState() => _ResetPasswordViewState();
}

class _ResetPasswordViewState
    extends MagicStatefulViewState<AuthController, ResetPasswordView> {
  late final form = MagicFormData({
    'password': '',
    'password_confirmation': '',
  }, controller: controller);

  // Token and email from URL query params
  String? _token;
  String? _email;

  @override
  void onInit() {
    super.onInit();
    // Extract token and email from query parameters
    final queryParams = MagicRouter.instance.queryParameters;
    _token = queryParams['token'];
    _email = queryParams['email'];
  }

  @override
  void onClose() => form.dispose();

  Future<void> _handleSubmit() async {
    // Reset controller state to allow re-submission after errors
    controller.setEmpty();
    controller.clearErrors();

    if (!form.validate()) return;

    if (_token == null || _email == null) {
      Magic.error(
        trans('auth.invalid_reset_link_title'),
        trans('auth.invalid_reset_link_message'),
      );
      return;
    }

    await controller.doResetPassword(
      token: _token!,
      email: _email!,
      password: form.get('password'),
      passwordConfirmation: form.get('password_confirmation'),
    );
  }

  @override
  Widget build(BuildContext context) {
    // Check for missing token/email
    if (_token == null || _email == null) {
      return _buildInvalidLink();
    }

    return controller.renderState(
      (_) => _buildForm(),
      onEmpty: _buildForm(),
      onError: (msg) => _buildForm(errorMessage: msg),
    );
  }

  Widget _buildInvalidLink() {
    return AuthFormCard(
      title: trans('auth.invalid_reset_link_title'),
      subtitle: trans('auth.invalid_reset_link_message'),
      child: WDiv(
        className: 'flex flex-col items-center',
        children: [
          WDiv(
            className:
                'w-16 h-16 bg-red-500/20 rounded-full flex items-center justify-center mb-4',
            child: WIcon(
              Icons.error_outline,
              className: 'text-red-500 text-[32px]',
            ),
          ),
          const WSpacer(className: 'h-2'),
          WAnchor(
            onTap: () => MagicRoute.to('/auth/forgot-password'),
            child: WText(
              trans('auth.request_new_link'),
              className: 'text-primary hover:text-primary/80',
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildForm({String? errorMessage}) {
    final isLoading = controller.isLoading;

    return AuthFormCard(
      title: trans('auth.reset_password_title'),
      subtitle: trans('auth.reset_password_subtitle'),
      errorMessage: errorMessage,
      child: WDiv(
        className: 'flex flex-col items-stretch',
        children: [
          // Show email being reset
          WDiv(
            className:
                'p-3 bg-slate-100 dark:bg-slate-800 rounded-xl text-center mb-6',
            child: WText(
              _email ?? '',
              className:
                  'text-slate-700 dark:text-slate-300 text-sm font-medium',
            ),
          ),

          // Password Field
          WFormInput(
            label: trans('auth.new_password'),
            controller: form['password'],
            placeholder: trans('fields.password_placeholder'),
            type: InputType.password,
            validator: rules([Required(), Min(8)], field: 'password'),
            className: '''
              w-full bg-white dark:bg-slate-800
              border border-slate-200 dark:border-slate-700
              rounded-xl p-3
              text-slate-900 dark:text-white
              focus:border-primary error:border-red-500
            ''',
            placeholderClassName: 'text-slate-400 dark:text-slate-500',
            labelClassName:
                'text-sm font-medium text-slate-900 dark:text-slate-200 mb-1',
          ),
          const WSpacer(className: 'h-4'),

          // Confirm Password Field
          WFormInput(
            label: trans('auth.confirm_new_password'),
            controller: form['password_confirmation'],
            placeholder: trans('fields.password_confirmation_placeholder'),
            type: InputType.password,
            validator: rules([
              Required(),
              Same('password', valueGetter: () => form.get('password')),
            ], field: 'password_confirmation'),
            className: '''
              w-full bg-white dark:bg-slate-800
              border border-slate-200 dark:border-slate-700
              rounded-xl p-3
              text-slate-900 dark:text-white
              focus:border-primary error:border-red-500
            ''',
            placeholderClassName: 'text-slate-400 dark:text-slate-500',
            labelClassName:
                'text-sm font-medium text-slate-900 dark:text-slate-200 mb-1',
          ),
          const WSpacer(className: 'h-6'),

          // Submit Button
          WButton(
            isLoading: isLoading,
            className: '''
              w-full bg-primary hover:bg-primary-dark
              text-white text-base font-bold
              p-4 rounded-xl shadow-lg
            ''',
            onTap: () => _handleSubmit(),
            child: WText(
              trans('auth.reset_password_button'),
              className: 'text-center',
            ),
          ),
          const WSpacer(className: 'h-6'),

          WAnchor(
            onTap: () => MagicRoute.to('/auth/login'),
            child: WText(
              trans('auth.back_to_login'),
              className:
                  'text-center text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-300',
            ),
          ),
        ],
      ),
    );
  }
}
