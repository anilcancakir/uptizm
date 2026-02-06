import 'package:magic/magic.dart';
import '../models/team.dart';

/// Team Policy for authorization.
///
/// Defines abilities for team-related actions using Gate.
///
/// ## Usage
///
/// ```dart
/// // Register in GateServiceProvider.boot()
/// TeamPolicy().register();
///
/// // Check in code
/// if (Gate.allows('manage-team-members', team)) { ... }
///
/// // Use in UI
/// MagicCan(
///   ability: 'manage-team-members',
///   arguments: team,
///   child: AddMemberButton(),
/// )
/// ```
class TeamPolicy extends Policy {
  @override
  void register() {
    Gate.define('view-team', _view);
    Gate.define('update-team', _update);
    Gate.define('delete-team', _delete);
    Gate.define('manage-team-members', _manageMembers);
    Gate.define('manage-team-invitations', _manageInvitations);
  }

  /// Any team member can view.
  bool _view(Authenticatable user, dynamic arguments) {
    final team = arguments as Team?;
    return team?.userRole != null;
  }

  /// Owner, admin, or editor can update.
  bool _update(Authenticatable user, dynamic arguments) {
    final team = arguments as Team?;
    return team?.canEdit ?? false;
  }

  /// Only owner can delete (and not personal team).
  bool _delete(Authenticatable user, dynamic arguments) {
    final team = arguments as Team?;
    return team?.isOwner == true && team?.isPersonalTeam == false;
  }

  /// Only owner or admin can manage members.
  bool _manageMembers(Authenticatable user, dynamic arguments) {
    final team = arguments as Team?;
    return team?.canManageMembers ?? false;
  }

  /// Only owner or admin can manage invitations.
  bool _manageInvitations(Authenticatable user, dynamic arguments) {
    final team = arguments as Team?;
    return team?.canManageMembers ?? false;
  }
}
