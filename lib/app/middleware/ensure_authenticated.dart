import 'package:fluttersdk_magic/fluttersdk_magic.dart';

/// Ensure the user is authenticated.
///
/// Redirects to /login if not authenticated.
///
/// ## Usage
///
/// ```dart
/// // In Kernel
/// Kernel.register('auth', () => EnsureAuthenticated());
///
/// // In Routes
/// MagicRoute.get('/dashboard', () => controller.index())
///     .middleware(['auth']);
/// ```
class EnsureAuthenticated extends MagicMiddleware {
  @override
  Future<void> handle(void Function() next) async {
    final isLoggedIn = Auth.check();

    if (isLoggedIn) {
      next(); // Allow navigation
    } else {
      // Redirect to login
      MagicRoute.to('/auth/login');
    }
  }
}
