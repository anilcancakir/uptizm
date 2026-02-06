import 'package:magic/magic.dart';

/// Team Invitation Model
class TeamInvitation extends Model
    with HasTimestamps, InteractsWithPersistence {
  @override
  String get table => 'team_invitations';

  @override
  String get resource => 'teams_invitations'; // Not direct resource usually?
  // Actually the API is nested: /teams/{team}/invitations
  // So standard resource path might not work for 'all()'.
  // But for 'delete', it is /teams/{team}/invitations/{invitation}
  // OR usually Laravel might support /team-invitations/{id}?
  // The doc says: DELETE /api/v1/teams/{team}/invitations/{invitation}
  // So we probably won't use InteractsWithPersistence.delete() directly
  // without custom endpoint logic in Controller.

  @override
  List<String> get fillable => ['email', 'role', 'team_id'];

  @override
  bool get incrementing => false;

  // Typed Accessors
  @override
  String? get id => getAttribute('id')?.toString();
  String? get email => getAttribute('email') as String?;
  String? get role => getAttribute('role') as String?;
  String? get teamId => getAttribute('team_id')?.toString();

  // Factory
  static TeamInvitation fromMap(Map<String, dynamic> map) {
    return TeamInvitation()
      ..setRawAttributes(map, sync: true)
      ..exists = map.containsKey('id');
  }
}
