import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';

import '../models/escalation_policy.dart';

/// One wire-shaped escalation step, as returned by
/// `GET /escalation-policies/{id}` (`EscalationPolicyResource::toArray`).
///
/// Carries the backend [id] (unlike the fixture [EscalationStep], which has
/// none) so the editor can diff a saved ladder against a fresh draft and
/// issue exactly the add/remove/reorder calls the change requires.
@immutable
class EscalationStepWire {
  /// Backend step id.
  final String id;

  /// Ascending fire order within the policy.
  final int position;

  /// Minutes to wait after the previous step (or after incident open, for
  /// the first step) before this step fires.
  final int delayMinutes;

  /// `on_call` / `user` / `channel`, per `EscalationTargetType`.
  final String targetType;

  /// The targeted user id, present only when [targetType] is `user`.
  final String? targetId;

  /// The targeted channel name, present only when [targetType] is `channel`.
  final String? channel;

  /// Creates an [EscalationStepWire].
  const EscalationStepWire({
    required this.id,
    required this.position,
    required this.delayMinutes,
    required this.targetType,
    this.targetId,
    this.channel,
  });
}

/// Controller backing the two routed escalation-policy screens
/// ([EscalationPoliciesView], [EscalationPolicyEditorView]).
///
/// The read side is ORM-native: [reload] fetches the roster through
/// `EscalationPolicy.all()` (`GET /escalation-policies`; `name` + timestamps
/// only) followed by a `EscalationPolicy.find(id)`
/// (`GET /escalation-policies/{id}`) per policy to hydrate each policy's step
/// chain, since the index endpoint does not eager-load `steps`
/// (`EscalationPolicyResource::toArray`). The [EscalationPolicy] model
/// collapses the former list-row/detail split: a policy IS its detail, with
/// its id-carrying [EscalationPolicy.steps] chain populated, so [policies] and
/// [detailById] answer from one cache.
///
/// Business actions [create]/[save]/[delete] write the policy body through the
/// model's ORM `save()`/`delete()` (bool-checked, toast on `false`), mirroring
/// `status_page_controller.dart`'s Wave 2 precedent. The step sub-resource has
/// no ORM model, so [removeStep]/[reorderSteps]/[_addStep] stay raw `Http.*`
/// against `escalation-policies/{id}/steps`; [removeStep] and [reorderSteps]
/// are exposed directly (and used internally by [save]'s reconciliation) so
/// they stay independently callable and testable.
///
/// **Divergence from the backend shape.** The backend `EscalationPolicy`
/// model only persists `name`; it has no `description`/`repeat_last_step`/
/// `is_default`/`monitor_count` columns, so the list view renders the policy
/// name plus its step ladder and nothing else. Likewise `EscalationStep` only
/// carries one `target_type`/`target_id`/`channel` per row (no free-text
/// multi-target list), so every editor rung maps to exactly one step whose
/// `channel` holds the rung's targets joined with `", "` and whose
/// `target_type` is always `channel` (the editor has no on-call/user target
/// picker yet).
class EscalationController extends MagicController {
  /// Singleton accessor, registering the controller on first access.
  static EscalationController get instance =>
      Magic.findOrPut(EscalationController.new);

  /// In-memory cache of the id-carrying policy models, keyed by policy id.
  /// Populated by [reload]/[seedForTest] and kept warm by [_refreshDetail].
  /// A policy is its own detail, so this single map backs both [policies] and
  /// [detailById].
  final Map<String, EscalationPolicy> _details = {};

  /// The policy roster, sourced from `GET /escalation-policies` (+ per-policy
  /// detail hydration). Empty until the first successful [reload]. Preserves
  /// the insertion order of the last [reload]/[seedForTest].
  List<EscalationPolicy> get policies => _details.values.toList();

  /// Seeds the in-memory cache directly for a widget/controller test,
  /// bypassing the network. Notifies listeners so an already-mounted view
  /// rebuilds against the seeded data.
  @visibleForTesting
  void seedForTest(List<EscalationPolicy> seed) {
    _details
      ..clear()
      ..addEntries(seed.map((p) => MapEntry(p.id, p)));
    refreshUI();
  }

  /// Bootstraps the roster the first time this controller backs a view.
  @override
  void onInit() {
    super.onInit();
    reload();
  }

  // ---------------------------------------------------------------------------
  // Reads
  // ---------------------------------------------------------------------------

  /// Non-destructive roster refresh: fetches the roster through
  /// `EscalationPolicy.all()` (`GET /escalation-policies`), then hydrates every
  /// returned policy's step chain in parallel through `EscalationPolicy.find`
  /// (`GET /escalation-policies/{id}`), since the index endpoint does not
  /// eager-load `steps`.
  ///
  /// The model's `all()`/`find()` swallow transport failures (returning an
  /// empty list / `null`), so an empty roster is treated as "nothing new to
  /// publish" and leaves the last-known-good cache in place (empty before the
  /// first success). The list view therefore never flickers into an empty
  /// state between reloads.
  Future<void> reload() async {
    final List<EscalationPolicy> summaries = await EscalationPolicy.all();
    if (summaries.isEmpty) return;

    final List<EscalationPolicy?> fetched = await Future.wait(
      summaries.map((p) => EscalationPolicy.find(p.id)),
    );
    final List<EscalationPolicy> details = fetched
        .whereType<EscalationPolicy>()
        .toList();
    if (details.isEmpty) return;

    _details
      ..clear()
      ..addEntries(details.map((p) => MapEntry(p.id, p)));
    refreshUI();
  }

  /// Resolves a policy by [id] from the cached map, or `null` when none
  /// matches (unknown id, or the cache has not loaded yet).
  ///
  /// The editor view calls this synchronously inside `build()`; it answers
  /// from [_details] immediately and also fires a background
  /// `EscalationPolicy.find` refresh (mirrors `monitor_controller.dart`'s
  /// `monitorById`/`_refreshOne`).
  EscalationPolicy? detailById(String? id) {
    if (id == null) return null;

    final EscalationPolicy? cached = _details[id];
    _refreshDetail(id);
    return cached;
  }

  /// Background single-resource refresh for [id]: fetches the policy through
  /// `EscalationPolicy.find` (`GET /escalation-policies/:id`), merges the
  /// result into [_details], then notifies listeners. Silently no-ops on
  /// failure so a transient error never disturbs the already-cached entry.
  Future<void> _refreshDetail(String id) async {
    final EscalationPolicy? detail = await EscalationPolicy.find(id);
    if (detail == null) return;

    _details[id] = detail;
    refreshUI();
  }

  // ---------------------------------------------------------------------------
  // Business actions: live writes against `api/v1/escalation-policies`.
  // ---------------------------------------------------------------------------

  /// Creates a policy named [name] through the model's ORM `save()`
  /// (`POST /escalation-policies`), then adds [rungs] as its step chain
  /// (`position` = list index) via `POST /escalation-policies/{id}/steps`, one
  /// raw call per rung. On success, reloads the roster, surfaces a success
  /// toast, and returns to the list. On a `false` save result, a missing id,
  /// or a failed step write, logs the failure and surfaces an error toast
  /// without navigating away, so the operator can retry from the still-open
  /// editor.
  Future<void> create(String name, List<EscalationRungDraft> rungs) async {
    final EscalationPolicy policy = EscalationPolicy()..name = name;

    final bool ok = await policy.save();
    if (!ok) {
      Log.error('[EscalationController.create] save() returned false');
      _toastError(null);
      return;
    }

    final String id = policy.id;
    if (id.isEmpty) {
      Log.error('[EscalationController.create] missing id after save()');
      _toastError(null);
      return;
    }

    for (int i = 0; i < rungs.length; i++) {
      final bool stepOk = await _addStep(id, position: i, rung: rungs[i]);
      if (!stepOk) return;
    }

    await reload();
    Magic.success(trans('uptizm.teams.escalation_editor_create_button'), name);
    MagicRoute.to('/teams/escalation');
  }

  /// Saves the policy [id]'s [name] through the model's ORM `save()`
  /// (`PUT /escalation-policies/{id}`), then reconciles its step chain against
  /// [rungs]: every [originalStepIds] entry no longer present in [rungs] is
  /// removed (`DELETE /escalation-policies/{id}/steps/{stepId}`), every rung
  /// with a `null` [EscalationRungDraft.id] (new, or dirtied by an in-place
  /// edit, see [EscalationRungDraft]) is added fresh
  /// (`POST /escalation-policies/{id}/steps`), and every untouched,
  /// still-present rung is bulk-repositioned in one
  /// `PUT /escalation-policies/{id}/steps/reorder` call. On success, reloads
  /// the roster, surfaces a success toast, and returns to the list. On a
  /// `false` save result or a failed step write, logs the failure and surfaces
  /// an error toast without navigating away.
  Future<void> save(
    String id,
    String name,
    List<EscalationRungDraft> rungs,
    Set<String> originalStepIds,
  ) async {
    final EscalationPolicy policy = EscalationPolicy()
      ..id = id
      ..name = name
      ..exists = true;

    final bool ok = await policy.save();
    if (!ok) {
      Log.error('[EscalationController.save] $id: save() returned false');
      _toastError(null);
      return;
    }

    final Set<String> keptIds = {
      for (final r in rungs)
        if (r.id != null) r.id!,
    };
    for (final String stepId in originalStepIds) {
      if (keptIds.contains(stepId)) continue;
      final bool stepOk = await removeStep(id, stepId);
      if (!stepOk) return;
    }

    final List<Map<String, dynamic>> reorderOrder = [];
    for (int i = 0; i < rungs.length; i++) {
      final EscalationRungDraft rung = rungs[i];
      if (rung.id == null) {
        final bool stepOk = await _addStep(id, position: i, rung: rung);
        if (!stepOk) return;
      } else {
        reorderOrder.add({'id': rung.id, 'position': i});
      }
    }

    if (reorderOrder.isNotEmpty) {
      final bool stepOk = await reorderSteps(id, reorderOrder);
      if (!stepOk) return;
    }

    await reload();
    Magic.success(trans('uptizm.teams.escalation_editor_save_button'), name);
    MagicRoute.to('/teams/escalation');
  }

  /// Deletes the policy [id] through the model's ORM `delete()`
  /// (`DELETE /escalation-policies/{id}`), evicts it from the cache, and
  /// surfaces a deleted toast. On a `false` delete result, logs the failure
  /// and surfaces an error toast without mutating the cache.
  Future<void> delete(String id) async {
    final EscalationPolicy? cached = _details[id];
    final EscalationPolicy model =
        cached ??
        (EscalationPolicy()
          ..id = id
          ..exists = true);

    final bool ok = await model.delete();
    if (!ok) {
      Log.error('[EscalationController.delete] $id: delete() returned false');
      _toastError(null);
      return;
    }

    _details.remove(id);
    refreshUI();
    Magic.success(
      trans('uptizm.teams.escalation_policy_delete_confirm_label'),
      cached?.name ?? id,
    );
  }

  /// Removes the step [stepId] from policy [policyId] via
  /// `DELETE /escalation-policies/{policyId}/steps/{stepId}`. Returns whether
  /// the request succeeded; logs and toasts on failure without throwing.
  Future<bool> removeStep(String policyId, String stepId) async {
    try {
      final response = await Http.delete(
        '/escalation-policies/$policyId/steps/$stepId',
      );
      if (!response.successful) {
        Log.error(
          '[EscalationController.removeStep] $policyId/$stepId: '
          '${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        return false;
      }
      return true;
    } catch (error) {
      Log.error(
        '[EscalationController.removeStep] $policyId/$stepId failed: $error',
      );
      _toastError(null);
      return false;
    }
  }

  /// Bulk-repositions policy [policyId]'s steps via
  /// `PUT /escalation-policies/{policyId}/steps/reorder`. [order] is the full
  /// set of `{id, position}` rows in their new order (mirrors the backend's
  /// `reorderSteps` contract). Returns whether the request succeeded; logs
  /// and toasts on failure without throwing.
  Future<bool> reorderSteps(
    String policyId,
    List<Map<String, dynamic>> order,
  ) async {
    try {
      final response = await Http.put(
        '/escalation-policies/$policyId/steps/reorder',
        data: {'order': order},
      );
      if (!response.successful) {
        Log.error(
          '[EscalationController.reorderSteps] $policyId: '
          '${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        return false;
      }
      return true;
    } catch (error) {
      Log.error('[EscalationController.reorderSteps] $policyId failed: $error');
      _toastError(null);
      return false;
    }
  }

  /// Adds [rung] to policy [policyId] at [position] via
  /// `POST /escalation-policies/{policyId}/steps`. Every rung is sent as a
  /// `channel`-typed step (see the class docblock's divergence note). Returns
  /// whether the request succeeded; logs and toasts on failure without
  /// throwing.
  Future<bool> _addStep(
    String policyId, {
    required int position,
    required EscalationRungDraft rung,
  }) async {
    try {
      final response = await Http.post(
        '/escalation-policies/$policyId/steps',
        data: {
          'position': position,
          'delay_minutes': rung.afterMinutes,
          'target_type': 'channel',
          'channel': rung.targets.join(', '),
        },
      );
      if (!response.successful) {
        Log.error(
          '[EscalationController._addStep] $policyId: ${response.errorMessage}',
        );
        _toastError(response.errorMessage);
        return false;
      }
      return true;
    } catch (error) {
      Log.error('[EscalationController._addStep] $policyId failed: $error');
      _toastError(null);
      return false;
    }
  }

  /// Surfaces a generic write-failure toast.
  ///
  /// Reuses the existing `escalation_policy_delete_confirm_*` copy family
  /// has no dedicated save/create/step failure strings yet, and this step's
  /// file scope does not extend to the lang assets that would add a
  /// dedicated one; see `### Deviations`.
  void _toastError(String? detail) {
    Magic.error(
      trans('uptizm.teams.escalation_toast_error_title'),
      detail ?? trans('uptizm.teams.escalation_toast_error_description'),
    );
  }
}

/// An editable escalation rung, carrying the backend step [id] once
/// persisted so [EscalationController.save] can diff a draft ladder against
/// its previously loaded chain.
///
/// [id] is `null` for a brand-new rung (never persisted) OR a previously
/// persisted rung whose [afterMinutes]/[targets] were edited in place: since
/// the backend has no step-update endpoint, an in-place edit clears [id] so
/// [EscalationController.save] treats it as "remove the old row, add a fresh
/// one" rather than silently dropping the edit.
@immutable
class EscalationRungDraft {
  /// The backend step id, or `null` when not (or no longer) persisted.
  final String? id;

  /// Minutes to wait after the previous rung fires. 0 means immediately.
  final int afterMinutes;

  /// Notification targets this rung pages, e.g. `"Slack #incidents"`.
  final List<String> targets;

  /// Creates an [EscalationRungDraft].
  const EscalationRungDraft({
    this.id,
    required this.afterMinutes,
    required this.targets,
  });
}
