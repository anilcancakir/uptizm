import 'package:magic/magic.dart';
import 'package:magic_deeplink/magic_deeplink.dart';
import '../models/user.dart';
import '../policies/team_policy.dart';
import '../policies/monitor_policy.dart';

class AppServiceProvider extends ServiceProvider {
  AppServiceProvider(super.app);

  @override
  void register() {
    //
  }

  @override
  Future<void> boot() async {
    // Register User factory for Auth session restoration
    Auth.manager.setUserFactory((data) => User.fromMap(data));

    // Register policies
    TeamPolicy().register();
    MonitorPolicy().register();

    // Register deep link handler
    final configPaths = Config.get('deeplink.paths');
    final paths = (configPaths as List? ?? [])
        .map((e) => e.toString())
        .toList();
    DeeplinkManager().registerHandler(RouteDeeplinkHandler(paths: paths));
  }
}
