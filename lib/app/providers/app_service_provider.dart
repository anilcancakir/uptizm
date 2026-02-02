import 'package:fluttersdk_magic/fluttersdk_magic.dart';
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
  }
}
