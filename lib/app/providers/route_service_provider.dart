import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';
import '../../routes/app.dart';
import '../kernel.dart';

class RouteServiceProvider extends ServiceProvider {
  RouteServiceProvider(super.app);

  @override
  void register() {
    // Register middleware kernel (logic moved from onInit)
    registerKernel();
  }

  @override
  Future<void> boot() async {
    // Starter plugin routes (auth, profile, teams)
    registerMagicStarterAuthRoutes();
    registerMagicStarterProfileRoutes();
    registerMagicStarterTeamRoutes();

    // App-specific routes
    registerAppRoutes();
  }
}
