import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../models/escalation_policy.dart';
import '../support/escalation_support.dart' show EscalationTargetType;

/// One wire-shaped escalation step, as returned by
/// `GET /escalation-policies/{id}` (`EscalationPolicyResource::toArray`).
///
/// Carries the backend [id] (unlike the fixture [EscalationStep], which has
/// none) so the editor can diff a saved ladder against a fresh draft and
/// issue exactly the add/remove/reorder calls the change requires.
@immutable
class EscalationStepWire {
  /// Backend step id, or `null` when the payload carried none.
  ///
  /// Nullable so a step the backend cannot identify is representable instead of
  /// throwing: decoding it as a required `String` took the whole policy decode
  /// down on one odd step, blanking the editor rather than degrading. A null id
  /// lands in the editor's draft as a null too, which its save-diff already
  /// treats as a step to create rather than one to reorder in place.
  final String? id;

  /// Ascending fire order within the policy.
  final int position;

  /// Minutes to wait after the previous step (or after incident open, for
  /// the first step) before this step fires.
  final int delayMinutes;

  /// `on_call` / `user`, per `EscalationTargetType` (people-only).
  final String targetType;

  /// The targeted user id, present only when [targetType] is `user`.
  final String? targetId;

  /// Creates an [EscalationStepWire].
  const EscalationStepWire({
    required this.id,
    required this.position,
    required this.delayMinutes,
    required this.targetType,
    this.targetId,
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
/// name plus its step ladder and nothing else. Likewise `EscalationStep`
/// carries one `target_type`/`target_id` per row, so every editor rung maps to
/// exactly one people-only step: `target_type: on_call` (the shared rotation,
/// no `target_id`) or `target_type: user` (`target_id` = a team member id).
class EscalationController extends MagicController
    implements SessionScopedController {
  /// Singleton accessor, registering the controller on first access.
  static EscalationController get instance =>
      Magic.findOrPut(EscalationController.new);

  /// In-memory cache of the id-carrying policy models, keyed by policy id.
  /// Populated by [reload]/[seedForTest] and kept warm by [refreshDetail].
  /// A policy is its own detail, so this single map backs both [policies] and
  /// [detailById].
  final Map<String, EscalationPolicy> _details = {};

  /// Policy ids whose per-id [refreshDetail] read has answered, successfully or
  /// not. Read through [isFirstLoadFor] to tell an unanswered lookup apart from
  /// a policy that does not exist.
  final Set<String> _settledDetailIds = <String>{};

  /// The policy roster, sourced from `GET /escalation-policies` (+ per-policy
  /// detail hydration). Empty until the first successful [reload]. Preserves
  /// the insertion order of the last [reload]/[seedForTest].
  List<EscalationPolicy> get policies => _details.values.toList();

  /// Whether a [reload] has completed at least once, successfully or not.
  bool _resolvedOnce = false;

  /// Whether the FIRST roster read is still in flight.
  ///
  /// Separates "we have not asked yet" from "we asked and there are none". The
  /// list view renders a skeleton while this is true instead of rendering a
  /// bare page with no policy cards before the first answer arrives, which is
  /// what made a team with a configured ladder open the screen as if it had
  /// none until the round trip landed.
  ///
  /// Only the FIRST read counts: a later refetch (the view reloads on every
  /// route entry) leaves this false so the cards stay on screen rather than
  /// flashing a skeleton over data the operator is already reading.
  bool get isFirstLoad => !_resolvedOnce;

  /// Seeds the in-memory cache directly for a widget/controller test,
  /// bypassing the network. Notifies listeners so an already-mounted view
  /// rebuilds against the seeded data.
  @visibleForTesting
  void seedForTest(List<EscalationPolicy> seed) {
    _details
      ..clear()
      ..addEntries(seed.map((p) => MapEntry(p.id, p)));
    // Seeded state is a resolved state, so a bound view renders the cards
    // rather than a skeleton waiting for a fetch the test never makes.
    _resolvedOnce = true;
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
  ///
  /// Resolving flips [isFirstLoad] false either way (an empty roster and a
  /// failed hydration are both answers), so the view swaps its skeleton for the
  /// cards or for a page that honestly has none.
  Future<void> reload() async {
    final bool firstLoad = isFirstLoad;
    final List<EscalationPolicy> summaries = await EscalationPolicy.all();
    _resolvedOnce = true;

    if (summaries.isEmpty) {
      // The cache stands, but a first read that came back empty still has to
      // repaint: the view is showing a skeleton and needs to hear that the
      // answer arrived.
      if (firstLoad) refreshUI();
      return;
    }

    final List<EscalationPolicy?> fetched = await Future.wait(
      summaries.map((p) => EscalationPolicy.find(p.id)),
    );
    final List<EscalationPolicy> details = fetched
        .whereType<EscalationPolicy>()
        .toList();
    if (details.isEmpty) {
      if (firstLoad) refreshUI();
      return;
    }

    _details
      ..clear()
      ..addEntries(details.map((p) => MapEntry(p.id, p)));
    refreshUI();
  }

  /// Drops the previous session's policy cache (roster and hydrated step
  /// chains, which share [_details]), publishes the cleared state, then
  /// refetches for the identity that is now authenticated.
  ///
  /// Clears BEFORE refetching (see [SessionScopedController]): [reload] keeps
  /// the last-known-good cache on an empty roster or a failed detail
  /// hydration, so across an identity change a failed refetch would otherwise
  /// leave the previous team's policies listed and still openable in the editor
  /// through [detailById].
  @override
  Future<void> resetForSession() async {
    _details.clear();
    _settledDetailIds.clear();
    // Back to "not asked yet": the incoming identity must get a skeleton, not
    // the previous tenant's conclusion that there are no policies.
    _resolvedOnce = false;
    refreshUI();

    await reload();
  }

  /// Resolves a policy by [id] from the cached map, or `null` when none
  /// matches (unknown id, or the cache has not loaded yet).
  ///
  /// Resolves a policy's id-carrying detail by [id] from the cached map, or
  /// `null` when none matches (unknown id, or the cache has not loaded yet).
  ///
  /// The editor view calls this synchronously inside `build()`, so it MUST stay
  /// a pure, side-effect-free cache read: it answers from [_details] and never
  /// performs I/O or notifies listeners. A single-resource refresh is a
  /// separate, explicit [refreshDetail] call the editor issues ONCE from
  /// `initState` (or on an id change), never from `build`: firing it from
  /// `build` self loops (refresh -> `refreshUI` -> rebuild -> `build` ->
  /// refresh), flooding the backend with `GET /escalation-policies/:id`
  /// (mirrors the `monitorById`/`refreshOne` split).
  EscalationPolicy? detailById(String? id) {
    if (id == null) return null;

    return _details[id];
  }

  /// One-shot single-resource refresh for [id]: fetches the policy through
  /// `EscalationPolicy.find` (`GET /escalation-policies/:id`), gates the merge
  /// on `fresh.id == id` (defending against a bodyless-`200` empty hydration),
  /// merges the result into [_details], then notifies listeners. Silently
  /// no-ops on failure so a transient error never disturbs the cached entry.
  ///
  /// Call this ONCE from the editor's `initState` (or on an id change), NEVER
  /// from `build`: its `refreshUI()` notifies listeners, so a `build`-time call
  /// self loops and floods the backend.
  Future<void> refreshDetail(String id) async {
    final EscalationPolicy? detail = await EscalationPolicy.find(id);
    // Settle before the null check, not after: a policy that came back missing
    // has ANSWERED, and the editor needs to hear that so it can leave its
    // pending state and say so. Leaving it unsettled skeletons forever with
    // nothing in flight behind it.
    _settledDetailIds.add(id);
    if (detail == null || detail.id != id) {
      refreshUI();

      return;
    }

    _details[id] = detail;
    refreshUI();
  }

  /// Whether the per-id read behind [detailById] for [id] has yet to answer.
  ///
  /// [isFirstLoad] covers the policy LIST and cannot speak for one policy: the
  /// list read can have landed while [refreshDetail] for a deep-linked id is
  /// still in flight, and in that window a `null` [detailById] used to render
  /// the editor's not-found state for a policy that exists.
  ///
  /// A null [id] is the create form, which waits for nothing.
  bool isFirstLoadFor(String? id) {
    if (id == null) return false;

    return !_settledDetailIds.contains(id);
  }

  // ---------------------------------------------------------------------------
  // Business actions: live writes against `api/v1/escalation-policies`.
  // ---------------------------------------------------------------------------

  /// Creates a policy named [name] through the model's ORM `save()`
  /// (`POST /escalation-policies`), then adds [rungs] as its step chain
  /// (`position` = list index) via `POST /escalation-policies/{id}/steps`, one
  /// raw call per rung. On success, reloads the roster, surfaces a success
  /// toast, and returns to the list.
  ///
  /// Returns the policy's backend per-field validation errors (single message
  /// per field, keyed by the wire field name: `name`) so the editor can render
  /// a server 422 inline; an empty map means success, a missing id, or a step
  /// write failure (the latter two already toasted). A `false` policy save that
  /// carries field errors STAYS on the form with no toast; a `false` save with
  /// no field errors keeps the generic error toast and returns an empty map.
  Future<Map<String, String>> create(
    String name,
    List<EscalationRungDraft> rungs,
  ) async {
    final EscalationPolicy policy = EscalationPolicy()..name = name;

    final bool ok = await policy.save();
    if (!ok) {
      final Map<String, String>? fieldErrors = _fieldErrorsOrToast(policy);
      if (fieldErrors != null) return fieldErrors;
      return const {};
    }

    final String id = policy.id;
    if (id.isEmpty) {
      Log.error('[EscalationController.create] missing id after save()');
      _toastError(null);
      return const {};
    }

    for (int i = 0; i < rungs.length; i++) {
      final bool stepOk = await _addStep(id, position: i, rung: rungs[i]);
      if (!stepOk) return const {};
    }

    await reload();
    Magic.success(trans('uptizm.teams.escalation_editor_create_button'), name);
    MagicRoute.to('/teams/escalation');
    return const {};
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
  /// the roster, surfaces a success toast, and returns to the list.
  ///
  /// Returns the policy's backend per-field validation errors (single message
  /// per field, keyed by the wire field name: `name`) so the editor can render
  /// a server 422 inline; an empty map means success or a step write failure
  /// (already toasted). A `false` policy save that carries field errors STAYS
  /// on the form with no toast; a `false` save with no field errors keeps the
  /// generic error toast and returns an empty map.
  Future<Map<String, String>> save(
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
      final Map<String, String>? fieldErrors = _fieldErrorsOrToast(policy);
      if (fieldErrors != null) return fieldErrors;
      return const {};
    }

    final Set<String> keptIds = {
      for (final r in rungs)
        if (r.id != null) r.id!,
    };
    for (final String stepId in originalStepIds) {
      if (keptIds.contains(stepId)) continue;
      final bool stepOk = await removeStep(id, stepId);
      if (!stepOk) return const {};
    }

    final List<Map<String, dynamic>> reorderOrder = [];
    for (int i = 0; i < rungs.length; i++) {
      final EscalationRungDraft rung = rungs[i];
      if (rung.id == null) {
        final bool stepOk = await _addStep(id, position: i, rung: rung);
        if (!stepOk) return const {};
      } else {
        reorderOrder.add({'id': rung.id, 'position': i});
      }
    }

    if (reorderOrder.isNotEmpty) {
      final bool stepOk = await reorderSteps(id, reorderOrder);
      if (!stepOk) return const {};
    }

    await reload();
    Magic.success(trans('uptizm.teams.escalation_editor_save_button'), name);
    MagicRoute.to('/teams/escalation');
    return const {};
  }

  /// Resolves a failed policy [policy] save into either its per-field
  /// validation errors or a generic toast.
  ///
  /// Returns the field errors (single message per field, keyed by the wire
  /// field name) when the failed save carried the Laravel 422 shape via
  /// [EscalationPolicy.validationErrors], so the caller hands them back to the
  /// editor for inline display and stays put. Returns `null` for a non-field
  /// failure (a transport error / 500) after surfacing the generic error toast
  /// and logging the cause, so the caller falls back to its empty-map contract.
  Map<String, String>? _fieldErrorsOrToast(EscalationPolicy policy) {
    final Map<String, List<String>> errors = policy.validationErrors;
    if (errors.isNotEmpty) {
      return {
        for (final MapEntry<String, List<String>> entry in errors.entries)
          entry.key: entry.value.first,
      };
    }

    Log.error('[EscalationController] save returned false with no field errors');
    _toastError(null);
    return null;
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
  /// `POST /escalation-policies/{policyId}/steps`. Emits a people-only step:
  /// `target_type: on_call` (no `target_id`) or `target_type: user` with the
  /// rung's [EscalationRungDraft.targetUserId]. Returns whether the request
  /// succeeded; logs and toasts on failure without throwing.
  Future<bool> _addStep(
    String policyId, {
    required int position,
    required EscalationRungDraft rung,
  }) async {
    try {
      final Map<String, dynamic> data = <String, dynamic>{
        'position': position,
        'delay_minutes': rung.afterMinutes,
        'target_type': rung.targetType.wire,
      };
      if (rung.targetType == EscalationTargetType.user) {
        data['target_id'] = rung.targetUserId;
      }
      final response = await Http.post(
        '/escalation-policies/$policyId/steps',
        data: data,
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
/// persisted rung whose [afterMinutes]/[targetType]/[targetUserId] were edited
/// in place: since the backend has no step-update endpoint, an in-place edit
/// clears [id] so [EscalationController.save] treats it as "remove the old row,
/// add a fresh one" rather than silently dropping the edit.
@immutable
class EscalationRungDraft {
  /// The backend step id, or `null` when not (or no longer) persisted.
  final String? id;

  /// Minutes to wait after the previous rung fires. 0 means immediately.
  final int afterMinutes;

  /// Who this rung pages: the shared on-call rotation, or a specific member.
  final EscalationTargetType targetType;

  /// The paged member id, present only when [targetType] is
  /// [EscalationTargetType.user]; `null` for the on-call rotation.
  final String? targetUserId;

  /// Creates an [EscalationRungDraft].
  const EscalationRungDraft({
    this.id,
    required this.afterMinutes,
    required this.targetType,
    this.targetUserId,
  });
}
