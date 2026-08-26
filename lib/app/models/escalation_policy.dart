import 'dart:convert';

import 'package:magic/magic.dart';

import '../controllers/escalation_controller.dart' show EscalationStepWire;

/// **An escalation policy.**
///
/// A magic Eloquent model backing the `escalation-policies` JSON resource. It
/// collapses the former list-row (`EscalationPolicy` fixture) and detail
/// (`EscalationPolicyDetail`) shapes into one model: a policy IS its detail,
/// with its [steps] chain populated.
///
/// The backend persists only [name]; the ordered step chain is managed through
/// the `escalation-policies/{id}/steps` sub-resource (see
/// [EscalationController]). [steps] returns the wire-shaped
/// [EscalationStepWire] list (with ids) so the editor's save-diff logic keeps
/// working unchanged.
///
/// ```dart
/// final policy = await EscalationPolicy.find(id);
/// print(policy.name);
/// for (final step in policy.steps) {
///   print('${step.delayMinutes}m -> ${step.targetType}');
/// }
/// ```
class EscalationPolicy extends Model
    with HasTimestamps, InteractsWithPersistence {
  /// Creates an empty [EscalationPolicy] (hydrate via [fromMap] or persistence).
  EscalationPolicy();

  /// The table associated with the model.
  @override
  String get table => 'escalation_policies';

  /// The API resource for remote operations.
  @override
  String get resource => 'escalation-policies';

  /// UUID primary key; never auto-incrementing.
  @override
  bool get incrementing => false;

  /// Mass-assignable attributes. The step chain is authored through the `steps`
  /// sub-resource rather than the policy body, so it is deliberately absent.
  @override
  List<String> get fillable => ['name', 'repeat_last_step', 'is_default'];

  /// Attribute casts. `name` is a plain string; timestamps are handled by
  /// [HasTimestamps]. The `steps` array is decoded by the [steps] accessor.
  @override
  Map<String, dynamic> get casts => {};

  // ---------------------------------------------------------------------------
  // Typed Accessors
  // ---------------------------------------------------------------------------

  /// The policy id.
  @override
  String get id => getAttribute('id')?.toString() ?? '';

  /// The policy display name.
  String? get name => getAttribute('name') as String?;

  /// Set the policy display name.
  set name(String? value) => setAttribute('name', value);

  /// Whether the ladder keeps re-paging its last rung until somebody
  /// acknowledges the incident, instead of stopping after one pass.
  ///
  /// Defaults to false for a wire payload that omits it, which is what a
  /// backend older than the column returns.
  bool get repeatLastStep => getAttribute('repeat_last_step') == true;

  /// Set whether the last rung repeats until acknowledged.
  set repeatLastStep(bool value) => setAttribute('repeat_last_step', value);

  /// Whether this is the ladder a monitor pages when it pins no policy of its
  /// own. At most one policy per team carries it; marking a second moves it.
  ///
  /// Defaults to false for a wire payload that omits it. A team with none
  /// marked keeps the older fallback, the earliest-created policy.
  bool get isDefault => getAttribute('is_default') == true;

  /// Set whether this policy is the team's fallback ladder.
  set isDefault(bool value) => setAttribute('is_default', value);

  /// The ordered escalation step chain, decoded from the wire `steps` array
  /// into [EscalationStepWire]s (each carrying its backend `id` so the editor
  /// save-diff can reconcile add/remove/reorder). Empty when the wire omits
  /// `steps` (e.g. an index-row payload).
  List<EscalationStepWire> get steps {
    final Object? raw = getAttribute('steps');
    if (raw is! List) return const [];
    return raw
        .whereType<Map<String, dynamic>>()
        .map(_stepFromWire)
        .toList();
  }

  /// Decodes one wire step map into an [EscalationStepWire] (the id-carrying
  /// wire step type the editor's save-diff reconciliation consumes), owning the
  /// `position`/`delay_minutes`/`target_*` field mapping for the whole domain.
  static EscalationStepWire _stepFromWire(Map<String, dynamic> m) {
    return EscalationStepWire(
      // The one field here that used to be an unguarded cast, while
      // `position`/`delay_minutes`/`target_type`/`target_id` all carried a
      // fallback. A backend that omits it (or sends null) threw and took the
      // whole policy decode with it, so one odd step blanked the editor rather
      // than degrading. Null, not `''`: the editor's save-diff branches on a
      // null id to mean "create this step", while an empty string would look
      // like an existing step and send a reorder naming a step that is not
      // there.
      id: m['id'] as String?,
      position: (m['position'] as num?)?.toInt() ?? 0,
      delayMinutes: (m['delay_minutes'] as num?)?.toInt() ?? 0,
      targetType: (m['target_type'] as String?) ?? 'on_call',
      targetId: m['target_id'] as String?,
    );
  }

  // ---------------------------------------------------------------------------
  // Static retrieval + hydration
  // ---------------------------------------------------------------------------

  /// Find a policy by [id] via `GET /escalation-policies/{id}`.
  static Future<EscalationPolicy?> find(dynamic id) =>
      InteractsWithPersistence.findById<EscalationPolicy>(id, EscalationPolicy.new);

  /// All policies via `GET /escalation-policies`.
  static Future<List<EscalationPolicy>> all() =>
      InteractsWithPersistence.allModels<EscalationPolicy>(EscalationPolicy.new);

  /// Hydrate a policy from a raw wire map (e.g. an `EscalationPolicyResource`
  /// payload), bypassing mass-assignment protection.
  factory EscalationPolicy.fromMap(Map<String, dynamic> map) {
    return EscalationPolicy()
      ..setRawAttributes(map, sync: true)
      ..exists = map.containsKey('id');
  }

  /// Hydrate a policy from a JSON string.
  factory EscalationPolicy.fromJson(String json) =>
      EscalationPolicy.fromMap(jsonDecode(json) as Map<String, dynamic>);
}
