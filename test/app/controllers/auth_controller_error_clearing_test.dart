import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/controllers/auth_controller.dart';

void main() {
  group('AuthController error clearing', () {
    late AuthController controller;

    setUp(() {
      Magic.flush();
      controller = AuthController();
      Magic.put<AuthController>(controller);
    });

    tearDown(() {
      Magic.flush();
    });

    test('error state is cleared when view initializes', () {
      // Simulate a failed login
      controller.setError('Invalid credentials');
      expect(controller.isError, isTrue);
      expect(controller.rxStatus.message, equals('Invalid credentials'));

      // When a new MagicStatefulView initializes, it should clear error state
      // This is now handled by MagicStatefulViewState._clearValidationErrors()
      // Since we're testing the controller state directly, we verify the mechanism

      // The actual clearing happens in MagicStatefulViewState.initState
      // This test verifies the controller can have its error cleared
      controller.setEmpty();
      expect(controller.isError, isFalse);
      expect(controller.isEmpty, isTrue);
    });

    test('validation errors are cleared when view initializes', () {
      // Simulate server-side validation errors
      controller.validationErrors = {'email': 'Email is required'};
      expect(controller.hasErrors, isTrue);
      expect(controller.hasError('email'), isTrue);

      // When a new MagicStatefulView initializes, validation errors should clear
      controller.clearErrors();
      expect(controller.hasErrors, isFalse);
      expect(controller.hasError('email'), isFalse);
    });

    test('both error types can be set and cleared independently', () {
      // Set both types of errors
      controller.setError('Server error');
      controller.validationErrors = {'password': 'Password too short'};

      expect(controller.isError, isTrue);
      expect(controller.hasErrors, isTrue);

      // Clear just validation errors
      controller.clearErrors();
      expect(controller.isError, isTrue); // RxStatus error persists
      expect(controller.hasErrors, isFalse);

      // Clear RxStatus error
      controller.setEmpty();
      expect(controller.isError, isFalse);
      expect(controller.isEmpty, isTrue);
    });

    test('error state does not affect success state', () {
      // Start in success state
      controller.setSuccess(true);
      expect(controller.isSuccess, isTrue);

      // Clear errors should not affect success state
      controller.clearErrors();
      expect(controller.isSuccess, isTrue);

      // But setEmpty should reset to empty
      controller.setEmpty();
      expect(controller.isEmpty, isTrue);
      expect(controller.isSuccess, isFalse);
    });

    test('handleApiError sets both validation and RxStatus errors', () {
      // Create a mock validation error response (422)
      final mockResponse = MagicResponse(
        statusCode: 422,
        data: {
          'errors': {
            'email': ['Email format is invalid'],
            'password': ['Password must be at least 8 characters'],
          },
          'message': 'Validation failed',
        },
      );

      // Handle the error
      controller.handleApiError(mockResponse);

      // Should have both types of errors
      expect(controller.hasError('email'), isTrue);
      expect(controller.hasError('password'), isTrue);
      // 422 with message sets RxStatus error
      expect(controller.isError, isTrue);
    });

    testWidgets(
        'MagicStatefulViewState clears RxStatus error on init (integration)',
        (tester) async {
      // Set error state before building view
      controller.setError('Previous error');
      expect(controller.isError, isTrue);

      // Build a minimal test view that extends MagicStatefulView
      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: const _TestAuthView(),
          ),
        ),
      );

      // After view init, error should be cleared
      expect(controller.isError, isFalse);
      expect(controller.isEmpty, isTrue);
    });

    testWidgets(
        'MagicStatefulViewState clears validation errors on init (integration)',
        (tester) async {
      // Set validation errors before building view
      controller.validationErrors = {'email': 'Required'};
      expect(controller.hasErrors, isTrue);

      // Build a minimal test view
      await tester.pumpWidget(
        MaterialApp(
          home: WindTheme(
            data: WindThemeData(),
            child: const _TestAuthView(),
          ),
        ),
      );

      // After view init, validation errors should be cleared
      expect(controller.hasErrors, isFalse);
    });
  });
}

/// Test view that uses AuthController
class _TestAuthView extends MagicStatefulView<AuthController> {
  const _TestAuthView();

  @override
  State<_TestAuthView> createState() => _TestAuthViewState();
}

class _TestAuthViewState
    extends MagicStatefulViewState<AuthController, _TestAuthView> {
  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Text('Error: ${controller.isError}'),
        Text('HasErrors: ${controller.hasErrors}'),
      ],
    );
  }
}
