import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../models/team.dart';
import '../models/team_invitation.dart';
import '../models/user.dart';
import '../../resources/views/teams/team_create_view.dart';
import '../../resources/views/teams/team_settings_view.dart';
import '../../resources/views/teams/team_members_view.dart';

/// Team Controller
///
/// Handles team-related actions like creating new teams.
class TeamController extends MagicController
    with MagicStateMixin<Team>, ValidatesRequests {
  /// Singleton accessor.
  static TeamController get instance => Magic.findOrPut(TeamController.new);

  /// Render create team view.
  Widget create() => const TeamCreateView();

  /// Get current team from authenticated user.
  Team? get currentTeam => User.current.currentTeam;

  /// Edit team settings view.
  Widget edit() => const TeamSettingsView();

  /// Team members management view.
  Widget membersPage() => const TeamMembersView();

  /// Update team settings.
  ///
  /// Handles both JSON update and Multipart upload if photo is selected.
  /// Returns true if update was successful.
  Future<bool> doUpdate({required String name, MagicFile? photo}) async {
    final team = currentTeam;
    if (team == null) {
      setError(trans('teams.no_current_team'));
      return false;
    }

    setLoading();
    clearErrors();

    try {
      bool success = false;

      // If photo is selected, use Multipart upload
      if (photo != null) {
        // Use MagicFile's upload method or Http.upload
        // Since we need custom data (name, _method), Http.upload is better
        // or MagicFile.upload supports data map too.
        final response = await Http.upload(
          '/teams/${team.id}',
          data: {
            'name': name,
            '_method': 'PUT', // Simulate PUT for Laravel Multipart
          },
          files: {'photo': photo},
        );
        success = response.successful;

        // Update local model if successful
        if (success && response.data != null) {
          // Check if response contains nested 'data' key or root
          final responseData = response.data['data'] ?? response.data;
          if (responseData is Map<String, dynamic>) {
            team.setRawAttributes(responseData, sync: true);
          }
        }
      } else {
        // Standard JSON update via Model
        team.name = name;
        success = await team.save();
      }

      if (success) {
        setSuccess(team);

        // Refresh auth state to get updated team data (especially photo URL)
        await Auth.restore();

        Magic.success(trans('common.success'), trans('team_settings.saved'));
        return true;
      } else {
        setError(trans('team_settings.save_failed'));
        return false;
      }
    } catch (e) {
      Log.error('Update team error: $e');
      setError(trans('common.unexpected_error'));
      return false;
    }
  }

  // ---------------------------------------------------------------------------
  // Members & Invitations State
  // ---------------------------------------------------------------------------

  /// List of current team members.
  final ValueNotifier<List<User>> members = ValueNotifier([]);

  /// List of pending invitations.
  final ValueNotifier<List<TeamInvitation>> invitations = ValueNotifier([]);

  /// Load members and invitations for the current team.
  Future<void> loadSettingsData() async {
    await Future.wait([fetchMembers(), fetchInvitations()]);
  }

  /// Fetch team members.
  Future<void> fetchMembers() async {
    final team = currentTeam;
    if (team == null) return;

    final response = await Http.get('/teams/${team.id}/members');
    if (response.successful) {
      final List data = response.data['data'] ?? [];
      members.value = data
          .map((e) => User.fromMap(e as Map<String, dynamic>))
          .toList();
    }
  }

  /// Fetch pending invitations.
  Future<void> fetchInvitations() async {
    final team = currentTeam;
    if (team == null) return;

    final response = await Http.get('/teams/${team.id}/invitations');
    if (response.successful) {
      final List data = response.data['data'] ?? [];
      invitations.value = data
          .map((e) => TeamInvitation.fromMap(e as Map<String, dynamic>))
          .toList();
    }
  }

  /// Invite a new member.
  Future<bool> doInvite({required String email, required String role}) async {
    final team = currentTeam;
    if (team == null) return false;

    final response = await Http.post(
      '/teams/${team.id}/invitations',
      data: {'email': email, 'role': role},
    );

    if (response.successful) {
      Magic.success(trans('common.success'), trans('teams.invitation_sent'));
      fetchInvitations(); // Refresh list
      return true;
    } else {
      handleApiError(response, fallback: trans('teams.invite_failed'));
      return false;
    }
  }

  /// Update a member's role.
  Future<bool> updateRole(User user, String role) async {
    final team = currentTeam;
    if (team == null) return false;

    // Optimistic update? No, safer to wait.
    final response = await Http.put(
      '/teams/${team.id}/members/${user.id}',
      data: {'role': role},
    );

    if (response.successful) {
      Magic.success(trans('common.success'), trans('teams.role_updated'));
      fetchMembers();
      return true;
    } else {
      handleApiError(response, fallback: trans('teams.update_failed'));
      return false;
    }
  }

  /// Remove a member from the team.
  Future<bool> removeMember(User user) async {
    final team = currentTeam;
    if (team == null) return false;

    final response = await Http.delete('/teams/${team.id}/members/${user.id}');

    if (response.successful) {
      Magic.success(trans('common.success'), trans('teams.member_removed'));
      fetchMembers();
      return true;
    } else {
      handleApiError(response, fallback: trans('teams.remove_failed'));
      return false;
    }
  }

  /// Cancel an invitation.
  Future<bool> cancelInvitation(TeamInvitation invitation) async {
    final team = currentTeam;
    if (team == null) return false;

    final response = await Http.delete(
      '/teams/${team.id}/invitations/${invitation.id}',
    );

    if (response.successful) {
      Magic.success(
        trans('common.success'),
        trans('teams.invitation_canceled'),
      );
      fetchInvitations();
      return true;
    } else {
      handleApiError(response, fallback: trans('teams.cancel_failed'));
      return false;
    }
  }

  /// Create a new team via Magic ORM.
  ///
  /// Uses Team model's save() which calls POST /teams internally.
  Future<void> doCreate({required String name}) async {
    setLoading();
    clearErrors();

    try {
      final team = Team()..name = name;
      final success = await team.save();

      if (success) {
        setSuccess(team);

        // Refresh auth state to get updated current_team
        await Auth.restore();

        Magic.success(
          trans('common.success'),
          trans('teams.created_successfully'),
        );
        MagicRoute.to('/');
      } else {
        setError(trans('teams.create_failed'));
      }
    } catch (e) {
      Log.error('Create team error: $e');
      setError(trans('common.unexpected_error'));
    }
  }

  /// Switch to a different team.
  ///
  /// Calls PUT /api/v1/user/current-team with team_id.
  Future<bool> switchTeam(Team team) async {
    if (team.id == currentTeam?.id) {
      // Already on this team
      return true;
    }

    Magic.loading(message: trans('teams.switching'));

    try {
      final response = await Http.put(
        '/user/current-team',
        data: {'team_id': team.id},
      );

      Magic.closeLoading();

      if (response.successful) {
        // Refresh auth state to get updated current_team
        await Auth.restore();

        Magic.success(
          trans('common.success'),
          trans('teams.switched_successfully'),
        );

        // Navigate to dashboard to refresh with new team context
        MagicRoute.to('/');
        return true;
      } else {
        handleApiError(response, fallback: trans('teams.switch_failed'));
        return false;
      }
    } catch (e) {
      Magic.closeLoading();
      Log.error('Switch team error: $e');
      Magic.error(trans('common.error'), trans('common.unexpected_error'));
      return false;
    }
  }

  /// Delete a team.
  ///
  /// Calls DELETE /api/v1/teams/{id}.
  Future<bool> deleteTeam(Team team) async {
    Magic.loading(message: trans('teams.deleting'));

    try {
      final response = await Http.delete('/teams/${team.id}');

      Magic.closeLoading();

      if (response.successful) {
        // Refresh auth state to detect new current team (if API switches automatically)
        // or to just update the list of teams.
        await Auth.restore();

        Magic.success(
          trans('common.success'),
          trans('teams.deleted_successfully'),
        );

        // Navigate home (which should handle redirection to valid team)
        MagicRoute.to('/');
        return true;
      } else {
        handleApiError(response, fallback: trans('teams.delete_failed'));
        return false;
      }
    } catch (e) {
      Magic.closeLoading();
      Log.error('Delete team error: $e');
      Magic.error(trans('common.error'), trans('common.unexpected_error'));
      return false;
    }
  }

  /// Leave the current team (non-owner only).
  ///
  /// Calls DELETE /api/v1/teams/{id}/leave.
  Future<bool> leaveTeam(Team team) async {
    Magic.loading(message: trans('teams.leaving'));

    try {
      final response = await Http.delete('/teams/${team.id}/leave');

      Magic.closeLoading();

      if (response.successful) {
        await Auth.restore();

        Magic.success(
          trans('common.success'),
          trans('teams.left_successfully'),
        );

        MagicRoute.to('/');
        return true;
      } else {
        handleApiError(response, fallback: trans('teams.leave_failed'));
        return false;
      }
    } catch (e) {
      Magic.closeLoading();
      Log.error('Leave team error: $e');
      Magic.error(trans('common.error'), trans('common.unexpected_error'));
      return false;
    }
  }
}
