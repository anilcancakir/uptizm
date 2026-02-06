import 'package:magic/magic.dart';

import '../app/controllers/auth_controller.dart';
import '../resources/views/layouts/guest_layout.dart';

/// Authentication routes.
///
/// Define login, register, password reset routes here.
void registerAuthRoutes() {
  MagicRoute.group(
    prefix: '/auth',
    middleware: ['guest'],
    layout: (child) => GuestLayout(child: child),
    routes: () {
      MagicRoute.page('/login', AuthController.instance.login)
          .transition(RouteTransition.none);
      MagicRoute.page('/register', AuthController.instance.register)
          .transition(RouteTransition.none);
      MagicRoute.page(
        '/forgot-password',
        AuthController.instance.forgotPassword,
      ).transition(RouteTransition.none);
      MagicRoute.page('/reset-password', AuthController.instance.resetPassword)
          .transition(RouteTransition.none);
    },
  );
}
