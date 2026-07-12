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

  /// Mass-assignable attributes. The backend persists only `name`; the step
  /// chain is authored through the `steps` sub-resource, not the policy body.
  @override
  List<String> get fillable => ['name'];

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

  /// The policy display name (the only field the backend model persists).
  String? get name => getAttribute('name') as String?;

  /// Set the policy display name.
  set name(String? value) => setAttribute('name', value);

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

  /// Decodes one wire step map into an [EscalationStepWire], mirroring
  /// [EscalationController]'s `_stepFromWire` field mapping so the model and
  /// the controller agree on the wire contract.
  static EscalationStepWire _stepFromWire(Map<String, dynamic> m) {
    return EscalationStepWire(
      id: m['id'] as String,
      position: (m['position'] as num?)?.toInt() ?? 0,
      delayMinutes: (m['delay_minutes'] as num?)?.toInt() ?? 0,
      targetType: (m['target_type'] as String?) ?? 'channel',
      targetId: m['target_id'] as String?,
      channel: m['channel'] as String?,
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
