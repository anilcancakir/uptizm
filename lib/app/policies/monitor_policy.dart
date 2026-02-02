import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/models/team.dart';
import 'package:uptizm/app/models/user.dart';

class MonitorPolicy extends Policy {
  @override
  void register() {
    Gate.define('view-monitor', _view);
    Gate.define('create-monitor', _create);
    Gate.define('update-monitor', _update);
    Gate.define('delete-monitor', _delete);
  }

  /// Check if user can view the monitor
  bool _view(Authenticatable user, dynamic arguments) {
    final monitor = arguments as Monitor?;
    final currentUser = user as User;
    // User must be member of the monitor's team
    return currentUser.allTeams.any((team) => team.id == monitor?.teamId);
  }

  /// Check if user can create monitors on a team
  bool _create(Authenticatable user, dynamic arguments) {
    final team = arguments as Team?;
    // User must be owner, admin, or editor on the team
    return team?.canEdit ?? false;
  }

  /// Check if user can update the monitor
  bool _update(Authenticatable user, dynamic arguments) {
    final monitor = arguments as Monitor?;
    final currentUser = user as User;
    // User must be owner, admin, or editor on the monitor's team
    final team = currentUser.allTeams.firstWhere(
      (t) => t.id == monitor?.teamId,
      orElse: () => Team(),
    );

    return team.canEdit;
  }

  /// Check if user can delete the monitor
  bool _delete(Authenticatable user, dynamic arguments) {
    final monitor = arguments as Monitor?;
    final currentUser = user as User;
    // User must be owner or admin on the monitor's team
    final team = currentUser.allTeams.firstWhere(
      (t) => t.id == monitor?.teamId,
      orElse: () => Team(),
    );

    return team.canManageMembers;
  }
}
