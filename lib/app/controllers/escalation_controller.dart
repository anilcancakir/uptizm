import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../models/escalation_policy.dart';
import '../support/field_errors.dart';
import '../support/roster_page.dart';
import '../support/escalation_support.dart'
    show EscalationRungDraft, EscalationTargetType;

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
/// model persists `name` plus the two paging flags (`repeat_last_step`,
/// `is_default`); it still has no `description` or `monitor_count` column, so
/// the list view renders the policy name plus its step ladder and nothing
/// else. Likewise `EscalationStep`
/// carries one `target_type`/`target_id` per row, so every editor rung maps to
/// exactly one people-only step: `target_type: on_call` (the shared rotation,
/// no `target_id`) or `target_type: user` (`target_id` = a team member id).
class EscalationController extends MagicController
    with ValidatesRequests
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

  /// The token for the next page, or null when the roster has been walked.
  String? _nextCursor;

  /// Whether a [loadMore] is in flight.
  bool _loadingMore = false;

  /// Whether the last read failed to reach an answer.
  bool _loadFailed = false;

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
    _initialLoad = reload().whenComplete(() => _initialLoad = null);
  }

  /// The initial load of the escalation policies while it is still in flight, so a second reader
  /// can JOIN it instead of issuing the same request again. Null before it
  /// starts and once it settles.
  Future<void>? _initialLoad;

  /// The read a newly mounted view should ask for.
  ///
  /// Joins the initial load while it is in flight and refetches once it has
  /// settled. [RefetchesOnMount] calls this rather than [reload] because on the
  /// mount that CREATES this controller `onInit` has already started the same
  /// request, and both firing sent it twice. Every later mount finds nothing in
  /// flight and refetches, which is the staleness the mixin exists to prevent.
  ///
  /// Deliberately NOT a change to [reload]: coalescing there would also join a
  /// refresh issued right after a mutation to a request that started before it,
  /// and hand back a snapshot without the row the operator just created.
  Future<void> ensureFresh() => _initialLoad ?? reload();

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
  Future<void> reload() => _load(reset: true);

  /// Appends the next page of the roster. A no-op at the end of it.
  Future<void> loadMore() async {
    if (_nextCursor == null || _loadingMore) return;

    _loadingMore = true;
    refreshUI();
    await _load(reset: false);
    _loadingMore = false;
    refreshUI();
  }

  /// Whether a page after the current one exists.
  bool get hasMore => _nextCursor != null;

  /// Whether the next page is being fetched right now.
  bool get isLoadingMore => _loadingMore;

  /// Whether there is nothing to show AND the reason is a failed read.
  bool get loadFailed => _loadFailed && _details.isEmpty;

  Future<void> _load({required bool reset}) async {
    final RosterPage<EscalationPolicy> page =
        await readRosterPage<EscalationPolicy>(
          resource: 'escalation-policies',
          fromMap: EscalationPolicy.fromMap,
          logTag: 'EscalationController.reload',
          cursor: reset ? null : _nextCursor,
        );

    _resolvedOnce = true;

    if (page.failed) {
      _loadFailed = true;
      refreshUI();

      return;
    }

    _loadFailed = false;
    _nextCursor = page.nextCursor;

    // The index carries no step chain, so each policy on THIS PAGE is hydrated
    // individually. Scoping the hydration to the page is the point of paging:
    // it used to fan out one request per policy across the whole roster.
    final List<EscalationPolicy?> hydrated = await Future.wait(
      page.rows!.map((EscalationPolicy p) => EscalationPolicy.find(p.id)),
    );

    if (reset) _details.clear();

    for (int i = 0; i < page.rows!.length; i++) {
      final EscalationPolicy row = hydrated[i] ?? page.rows![i];
      _details[row.id] = row;
    }

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
    // A cursor names a row in the OUTGOING team's ordering, and the reset's own
    // refetch only overwrites it when that refetch succeeds.
    _nextCursor = null;
    _loadingMore = false;
    _details.clear();
    _settledDetailIds.clear();
    // Back to "not asked yet": the incoming identity must get a skeleton, not
    // the previous tenant's conclusion that there are no policies.
    _resolvedOnce = false;
    clearErrors();
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
  // The write path's client-side rules.
  // ---------------------------------------------------------------------------

  /// The client-side mirror of `StoreEscalationPolicyRequest::rules()`
  /// (`POST /escalation-policies`).
  ///
  /// Only `name` is expressible. The other two backend fields are
  /// deliberately absent:
  ///
  ///  - `repeat_last_step` / `is_default` (`sometimes|boolean`): magic ships no
  ///    `boolean` rule, so there is nothing here that could measure the type
  ///    without refusing an absent key the server explicitly allows.
  ///
  /// A fresh map per call rather than a shared constant, because [Max] and
  /// [Min] remember the value type they last measured and `message()` reads it
  /// back; one shared instance would let one submit's type pick another's
  /// message (mirrors `monitor_controller.dart`'s `_createRules`).
  Map<String, List<Rule>> get _createRules => <String, List<Rule>>{
    'name': [Required(), Max(200)],
  };

  /// The client-side mirror of `UpdateEscalationPolicyRequest::rules()`
  /// (`PUT /escalation-policies/{id}`).
  ///
  /// `name` there is `sometimes|required`, and magic has no `sometimes`, so the
  /// `required` half is dropped, mirroring `monitor_controller.dart`'s
  /// `_updateRules`: a bare [Required] would refuse a partial payload the
  /// server accepts. What is left is the bound, and [Max] passes on null,
  /// which is exactly `sometimes` semantics for a rule that measures only a
  /// value it was given. The two booleans are omitted for the same reason as
  /// [_createRules].
  Map<String, List<Rule>> get _updateRules => <String, List<Rule>>{
    'name': [Max(200)],
  };

  // ---------------------------------------------------------------------------
  // Business actions: live writes against `api/v1/escalation-policies`.
  // ---------------------------------------------------------------------------

  /// Creates a policy named [name] through the model's ORM `save()`
  /// (`POST /escalation-policies`), then adds [rungs] as its step chain
  /// (`position` = list index) via `POST /escalation-policies/{id}/steps`, one
  /// raw call per rung. On success, reloads the roster, surfaces a success
  /// toast, and returns to the list.
  ///
  /// Answers whether the policy was written. The per-field detail does NOT
  /// travel in the return value: it is published in [validationErrors] and the
  /// editor reads it back through [getError]. So `false` with a populated
  /// [validationErrors] means "stay on the form and correct the flagged
  /// fields", and `false` with an EMPTY one means the generic save-failed toast
  /// has already fired (a missing id after save, or a step write failure, both
  /// of which already toasted their own cause).
  ///
  /// [name] is checked against [_createRules] BEFORE anything is sent, so a
  /// blank name never becomes a request. [EscalationPolicy.save] absorbs
  /// transport failures internally and returns `false` rather than throwing; a
  /// `false` that carries the Laravel 422 shape on
  /// [EscalationPolicy.validationErrors] is republished here.
  Future<bool> create(
    String name,
    List<EscalationRungDraft> rungs, {
    bool repeatLastStep = false,
    bool isDefault = false,
  }) async {
    try {
      validate(<String, dynamic>{'name': name}, _createRules);
    } on ValidationException {
      return false;
    }

    final EscalationPolicy policy = EscalationPolicy()
      ..name = name
      ..repeatLastStep = repeatLastStep
      ..isDefault = isDefault;

    final bool ok = await policy.save();
    if (!ok) return _publishFieldErrors(policy);

    final String id = policy.id;
    if (id.isEmpty) {
      Log.error('[EscalationController.create] missing id after save()');
      _toastError(null);
      return false;
    }

    for (int i = 0; i < rungs.length; i++) {
      final bool stepOk = await _addStep(id, position: i, rung: rungs[i]);
      if (!stepOk) return false;
    }

    await reload();
    Magic.success(trans('uptizm.teams.escalation_editor_create_button'), name);
    MagicRoute.to('/teams/escalation');
    return true;
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
  /// Answers whether the policy was written, on the same contract [create]
  /// documents: the per-field detail lives in [validationErrors], not in the
  /// return value.
  ///
  /// [name] is checked against [_updateRules] BEFORE the policy is even built,
  /// so a payload whose answer is already known costs no request at all.
  Future<bool> save(
    String id,
    String name,
    List<EscalationRungDraft> rungs,
    Set<String> originalStepIds, {
    bool repeatLastStep = false,
    bool isDefault = false,
  }) async {
    try {
      validate(<String, dynamic>{'name': name}, _updateRules);
    } on ValidationException {
      return false;
    }

    final EscalationPolicy policy = EscalationPolicy()
      ..id = id
      ..name = name
      ..repeatLastStep = repeatLastStep
      ..isDefault = isDefault
      ..exists = true;

    final bool ok = await policy.save();
    if (!ok) return _publishFieldErrors(policy);

    final Set<String> keptIds = {
      for (final r in rungs)
        if (r.id != null) r.id!,
    };
    for (final String stepId in originalStepIds) {
      if (keptIds.contains(stepId)) continue;
      final bool stepOk = await removeStep(id, stepId);
      if (!stepOk) return false;
    }

    final List<Map<String, dynamic>> reorderOrder = [];
    for (int i = 0; i < rungs.length; i++) {
      final EscalationRungDraft rung = rungs[i];
      if (rung.id == null) {
        final bool stepOk = await _addStep(id, position: i, rung: rung);
        if (!stepOk) return false;
      } else {
        reorderOrder.add({'id': rung.id, 'position': i});
      }
    }

    if (reorderOrder.isNotEmpty) {
      final bool stepOk = await reorderSteps(id, reorderOrder);
      if (!stepOk) return false;
    }

    await reload();
    Magic.success(trans('uptizm.teams.escalation_editor_save_button'), name);
    MagicRoute.to('/teams/escalation');
    return true;
  }

  /// Publishes a failed [policy] save as either per-field validation errors or
  /// a generic toast, and answers `false` either way.
  ///
  /// The 422 is read off [EscalationPolicy.validationErrors] rather than a
  /// [MagicResponse], because there is no response object in scope to read:
  /// `Model.save()` consumes its own response internally and hands back a bare
  /// bool. Assigning [validationErrors] is what puts the messages where the
  /// editor's `getError` reads them.
  ///
  /// A failure carrying NO field errors is a transport error or a 500, so it
  /// gets the generic toast and leaves [validationErrors] empty, which is the
  /// signal the editor uses to tell "stay and correct" apart from "already
  /// told".
  bool _publishFieldErrors(EscalationPolicy policy) {
    final Map<String, String> fieldErrors = fieldErrorsFromModel(policy);
    if (fieldErrors.isNotEmpty) {
      validationErrors = fieldErrors;
      refreshUI();

      return false;
    }

    Log.error('[EscalationController] save returned false with no field errors');
    _toastError(null);

    return false;
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
