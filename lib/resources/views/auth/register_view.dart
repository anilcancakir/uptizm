import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../app/controllers/auth_controller.dart';

/// Register View
///
/// Simple registration page with clean form design.
/// Validation is handled here, registration logic is in AuthController.
class RegisterView extends MagicStatefulView<AuthController> {
  const RegisterView({super.key});

  @override
  State<RegisterView> createState() => _RegisterViewState();
}

class _RegisterViewState
    extends MagicStatefulViewState<AuthController, RegisterView> {
  // Centralized form data management - types inferred from initial values
  late final form = MagicFormData({
    'name': 'John Doe',
    'email': 'john.doe@example.com',
    'password': 'password123',
    'password_confirmation': 'password123',
    'accept_terms': false,
    'subscribe_newsletter': true,
  }, controller: controller);

  @override
  void onClose() => form.dispose();

  Future<void> _handleRegister() async {
    if (!form.validate()) return;

    await controller.doRegister(
      name: form.get('name'),
      email: form.get('email'),
      password: form.get('password'),
      passwordConfirmation: form.get('password_confirmation'),
      subscribeNewsletter: form.value<bool>('subscribe_newsletter'),
    );
  }

  @override
  Widget build(BuildContext context) {
    return controller.renderState(
      (_) => _buildForm(),
      onEmpty: _buildForm(),
      onError: (msg) => _buildForm(errorMessage: msg),
    );
  }

  Widget _buildForm({String? errorMessage}) {
    return ListenableBuilder(
      listenable: controller,
      builder: (context, _) {
        final isLoading = controller.isLoading;

        return WDiv(
          className:
              'max-w-[400px] p-6 bg-slate-900 border border-gray-700 rounded-lg',
          child: MagicForm(
            formData: form,
            child: WDiv(
              className: 'flex flex-col items-stretch',
              children: [
                WText(
                  trans('auth.create_account'),
                  className: 'text-2xl font-semibold text-white text-center',
                ),
                const SizedBox(height: 4),
                WText(
                  trans('auth.register_subtitle'),
                  className: 'text-sm text-gray-400 text-center',
                ),
                const SizedBox(height: 24),

                // Error Message
                if (errorMessage != null) ...[
                  WDiv(
                    className:
                        'p-2 bg-red-500 border border-red-500 rounded-lg text-center text-white mb-6',
                    child: WText(errorMessage),
                  ),
                  const SizedBox(height: 16),
                ],

                // Name Field
                WFormInput(
                  label: trans('attributes.name'),
                  controller: form['name'],
                  placeholder: trans('fields.full_name_placeholder'),
                  validator: rules([Required(), Min(2)], field: 'name'),
                  className:
                      'w-full bg-slate-900 border border-gray-700 rounded-lg p-3 text-white focus:border-primary error:border-red-500',
                  placeholderClassName: 'text-gray-400',
                  labelClassName: 'text-sm font-medium text-gray-300 mb-1',
                ),
                const SizedBox(height: 16),

                // Email Field
                WFormInput(
                  label: trans('attributes.email'),
                  controller: form['email'],
                  placeholder: trans('fields.email_placeholder'),
                  type: InputType.email,
                  validator: rules([Required(), Email()], field: 'email'),
                  className:
                      'w-full bg-slate-900 border border-gray-700 rounded-lg p-3 text-white focus:border-primary error:border-red-500',
                  placeholderClassName: 'text-gray-400',
                  labelClassName: 'text-sm font-medium text-gray-300 mb-1',
                ),
                const SizedBox(height: 16),

                // Password Field
                WFormInput(
                  label: trans('attributes.password'),
                  controller: form['password'],
                  placeholder: trans('fields.password_placeholder'),
                  type: InputType.password,
                  validator: rules([Required(), Min(8)], field: 'password'),
                  className:
                      'w-full bg-slate-900 border border-gray-700 rounded-lg p-3 text-white focus:border-primary error:border-red-500',
                  placeholderClassName: 'text-gray-400',
                  labelClassName: 'text-sm font-medium text-gray-300 mb-1',
                ),
                const SizedBox(height: 16),

                // Password Confirmation Field
                WFormInput(
                  label: trans('attributes.password_confirmation'),
                  controller: form['password_confirmation'],
                  placeholder: trans(
                    'fields.password_confirmation_placeholder',
                  ),
                  type: InputType.password,
                  validator: rules([
                    Required(),
                    Same('password', valueGetter: () => form['password'].text),
                  ], field: 'password_confirmation'),
                  className:
                      'w-full bg-slate-900 border border-gray-700 rounded-lg p-3 text-white focus:border-primary error:border-red-500',
                  placeholderClassName: 'text-gray-400',
                  labelClassName: 'text-sm font-medium text-gray-300 mb-1',
                ),
                const SizedBox(height: 20),

                // Terms Checkbox
                WFormCheckbox(
                  value: form.value<bool>('accept_terms'),
                  onChanged: (value) => form.setValue('accept_terms', value),
                  label: WDiv(
                    className:
                        'flex items-center text-gray-300 hover:text-gray-400 ml-1',
                    children: [
                      WText(trans('fields.term_agree_start')),
                      WAnchor(
                        onTap: () => _handleTermService(),
                        child: WText(
                          trans('fields.term_service'),
                          className: 'text-primary hover:text-primary/80',
                        ),
                      ),
                    ],
                  ),
                  validator: FormValidator.rules([
                    Required(),
                  ], field: 'accept_terms'),
                ),
                const SizedBox(height: 12),

                // Newsletter Checkbox
                WFormCheckbox(
                  value: form.value<bool>('subscribe_newsletter'),
                  onChanged: (value) =>
                      form.setValue('subscribe_newsletter', value),
                  label: WText(
                    trans('fields.newsletter_subscribe'),
                    className: 'text-gray-300 hover:text-gray-400 ml-1',
                  ),
                ),
                const SizedBox(height: 24),

                // Submit Button
                WButton(
                  isLoading: isLoading,
                  className:
                      'bg-primary text-white p-4 rounded-lg font-medium text-base hover:bg-primary/80',
                  onTap: () => _handleRegister(),
                  child: WText(
                    trans('auth.create_account'),
                    className: 'text-center',
                  ),
                ),
                const SizedBox(height: 24),

                WAnchor(
                  onTap: () => MagicRoute.to('/auth/login'),
                  child: WText(
                    trans('auth.login_link'),
                    className: 'text-center text-gray-400 hover:text-gray-300',
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  void _handleTermService() async {
    final url = Uri.parse('https://uptizm.com/terms');
    await launchUrl(url, mode: LaunchMode.externalApplication);
  }
}
