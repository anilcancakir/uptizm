import 'package:flutter/foundation.dart';
import 'package:magic/magic.dart';

import '../mocks/oncall.dart';

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

/// A wire-shaped escalation policy detail, as returned by
/// `GET /escalation-policies/{id}`: the backend `name` plus its full,
/// id-carrying step chain.
@immutable
class EscalationPolicyDetail {
  /// Backend policy id.
  final String id;

  /// The policy's display name (the only field the backend model persists;
  /// see the class docblock on [EscalationController] for the divergence
  /// from the fixture [EscalationPolicy] shape).
  final String name;

  /// Ordered step chain, ascending by [EscalationStepWire.position].
  final List<EscalationStepWire> steps;

  /// Creates an [EscalationPolicyDetail].
  const EscalationPolicyDetail({
    required this.id,
    required this.name,
    required this.steps,
  });
}

/// Controller backing the two routed escalation-policy screens
/// ([EscalationPoliciesView], [EscalationPolicyEditorView]).
///
/// Sources the policy roster from the live `api/v1` `GET /escalation-policies`
/// (list; `name` + timestamps only) followed by a `GET /escalation-policies/{id}`
/// per policy (mirrors `dashboard_controller.dart:89-96`'s `Future.wait`
/// fan-out) to hydrate each policy's step chain, since the index endpoint
/// does not eager-load `steps` (`EscalationPolicyResource::toArray`).
/// Business actions ([create], [save], [delete]) write through to the
/// matching `EscalationPolicyController` endpoints, following
/// `monitor_controller.dart:145-221`'s try/log/toast/refresh shape.
/// [removeStep] and [reorderSteps] are exposed directly (and used internally
/// by [save]'s reconciliation) so they stay independently callable and
/// testable, mirroring `status_page_controller.dart`'s
/// `detachMonitor`/`reorderMonitors` precedent.
///
/// **Divergence from the fixture shape.** The backend `EscalationPolicy`
/// model only persists `name`; it has no `description`/`repeat_last_step`/
/// `is_default`/`monitor_count` columns. Wire reads default those fields
/// (`''`, `false`, `false`, `0`) when projecting onto the fixture
/// [EscalationPolicy] value type for [policies]' read side. Likewise
/// `EscalationStep` only carries one `target_type`/`target_id`/`channel` per
/// row (no free-text multi-target list), so every editor rung maps to
/// exactly one step whose `channel` holds the rung's targets joined with
/// `", "` and whose `target_type` is always `channel` (the editor has no
/// on-call/user target picker yet). See `### Deviations` in the step report
/// for why this diverges from the former fixture-only shape.
class EscalationController extends MagicController {
  /// Singleton accessor, registering the controller on first access.
  static EscalationController get instance =>
      Magic.findOrPut(EscalationController.new);

  /// In-memory cache of the policy roster (fixture-shaped summaries for the
  /// list view), populated by [reload].
  List<EscalationPolicy> _policies = [];

  /// In-memory cache of the id-carrying policy detail, keyed by policy id.
  /// Populated by [reload] and kept warm by [_refreshDetail].
  final Map<String, EscalationPolicyDetail> _details = {};

  /// The policy roster, sourced from `GET /escalation-policies` (+ per-policy
  /// detail hydration). Empty until the first successful [reload].
  List<EscalationPolicy> get policies => _policies;

  /// Seeds the in-memory caches directly for a widget/controller test,
  /// bypassing the network. Notifies listeners so an already-mounted view
  /// rebuilds against the seeded data.
  @visibleForTesting
  void seedForTest(List<EscalationPolicyDetail> seed) {
    _details
      ..clear()
      ..addEntries(seed.map((d) => MapEntry(d.id, d)));
    _policies = seed.map(_summaryFromDetail).toList();
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

  /// Non-destructive roster refresh: fetches `GET /escalation-policies`, then
  /// hydrates every returned policy's step chain in parallel via
  /// `GET /escalation-policies/{id}` (mirrors `dashboard_controller.dart:89-96`).
  /// Preserves the previously loaded roster on any failure (network error,
  /// non-2xx, or a malformed payload) so the list view never flickers into an
  /// empty state between reloads.
  Future<void> reload() async {
    try {
      final response = await Http.get('/escalation-policies');
      if (!response.successful) return;

      final Object? raw = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (raw is! List) return;

      final List<String> ids = raw
          .whereType<Map<String, dynamic>>()
          .map((m) => m['id'] as String)
          .toList();

      final List<EscalationPolicyDetail?> fetched = await Future.wait(
        ids.map(_fetchDetail),
      );
      final List<EscalationPolicyDetail> details = fetched
          .whereType<EscalationPolicyDetail>()
          .toList();

      _details
        ..clear()
        ..addEntries(details.map((d) => MapEntry(d.id, d)));
      _policies = details.map(_summaryFromDetail).toList();
      refreshUI();
    } catch (_) {
      // Deliberate degradation: a transport failure (including an
      // unregistered `network` service in a bare test host) or a malformed
      // payload keeps the last-known-good roster (empty before the first
      // successful fetch), so `onInit`/`reload` never throws.
    }
  }

  /// Resolves a policy's id-carrying detail by [id] from the cached map, or
  /// `null` when none matches (unknown id, or the cache has not loaded yet).
  ///
  /// The editor view calls this synchronously inside `build()`; it answers
  /// from [_details] immediately and also fires a background
  /// `GET /escalation-policies/:id` refresh (mirrors
  /// `monitor_controller.dart`'s `monitorById`/`_refreshOne`).
  EscalationPolicyDetail? detailById(String? id) {
    if (id == null) return null;

    final EscalationPolicyDetail? cached = _details[id];
    _refreshDetail(id);
    return cached;
  }

  /// Background single-resource refresh for [id]: fetches
  /// `GET /escalation-policies/:id`, merges the result into [_details] and
  /// [_policies], then notifies listeners. Silently no-ops on failure so a
  /// transient error never disturbs the already-cached entry.
  Future<void> _refreshDetail(String id) async {
    final EscalationPolicyDetail? detail = await _fetchDetail(id);
    if (detail == null) return;

    _details[id] = detail;
    final int index = _policies.indexWhere((p) => p.id == id);
    final EscalationPolicy summary = _summaryFromDetail(detail);
    _policies = index == -1
        ? [..._policies, summary]
        : [for (final p in _policies) p.id == id ? summary : p];
    refreshUI();
  }

  /// Fetches and parses `GET /escalation-policies/:id`. Returns `null` on any
  /// failure (network error, non-2xx, or a malformed payload).
  Future<EscalationPolicyDetail?> _fetchDetail(String id) async {
    try {
      final response = await Http.get('/escalation-policies/$id');
      if (!response.successful) {
        Log.error(
          '[EscalationController._fetchDetail] $id: ${response.errorMessage}',
        );
        return null;
      }

      final Object? data = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      if (data is! Map<String, dynamic>) return null;

      return _detailFromWire(data);
    } catch (error) {
      Log.error('[EscalationController._fetchDetail] $id failed: $error');
      return null;
    }
  }

  // ---------------------------------------------------------------------------
  // Business actions: live writes against `api/v1/escalation-policies`.
  // ---------------------------------------------------------------------------

  /// Creates a policy named [name] via `POST /escalation-policies`, then adds
  /// [rungs] as its step chain (`position` = list index) via
  /// `POST /escalation-policies/{id}/steps`, one call per rung. On success,
  /// reloads the roster, surfaces a success toast, and returns to the list.
  /// On a failed response or a transport error at any point, logs the
  /// failure and surfaces an error toast without navigating away, so the
  /// operator can retry from the still-open editor.
  Future<void> create(String name, List<EscalationRungDraft> rungs) async {
    try {
      final response = await Http.post(
        '/escalation-policies',
        data: {'name': name},
      );
      if (!response.successful) {
        Log.error('[EscalationController.create] ${response.errorMessage}');
        _toastError(response.errorMessage);
        return;
      }

      final Object? data = response.data is Map<String, dynamic>
          ? (response.data as Map<String, dynamic>)['data']
          : null;
      final String? id = data is Map<String, dynamic>
          ? data['id'] as String?
          : null;
      if (id == null) {
        Log.error('[EscalationController.create] missing id in response');
        _toastError(null);
        return;
      }

      for (int i = 0; i < rungs.length; i++) {
        final bool ok = await _addStep(id, position: i, rung: rungs[i]);
        if (!ok) return;
      }

      await reload();
      Magic.success(trans('uptizm.teams.escalation_editor_create_button'), name);
      MagicRoute.to('/teams/escalation');
    } catch (error) {
      Log.error('[EscalationController.create] failed: $error');
      _toastError(null);
    }
  }

  /// Saves the policy [id]'s [name] via `PUT /escalation-policies/{id}`, then
  /// reconciles its step chain against [rungs]: every [originalStepIds] entry
  /// no longer present in [rungs] is removed
  /// (`DELETE /escalation-policies/{id}/steps/{stepId}`), every rung with a
  /// `null` [EscalationRungDraft.id] (new, or dirtied by an in-place edit —
  /// see [EscalationRungDraft]) is added fresh
  /// (`POST /escalation-policies/{id}/steps`), and every untouched,
  /// still-present rung is bulk-repositioned in one
  /// `PUT /escalation-policies/{id}/steps/reorder` call. On success, reloads
  /// the roster, surfaces a success toast, and returns to the list. On a
  /// failed response or a transport error at any point, logs the failure and
  /// surfaces an error toast without navigating away.
  Future<void> save(
    String id,
    String name,
    List<EscalationRungDraft> rungs,
    Set<String> originalStepIds,
  ) async {
    try {
      final response = await Http.put(
        '/escalation-policies/$id',
        data: {'name': name},
      );
      if (!response.successful) {
        Log.error('[EscalationController.save] $id: ${response.errorMessage}');
        _toastError(response.errorMessage);
        return;
      }

      final Set<String> keptIds = {
        for (final r in rungs)
          if (r.id != null) r.id!,
      };
      for (final String stepId in originalStepIds) {
        if (keptIds.contains(stepId)) continue;
        final bool ok = await removeStep(id, stepId);
        if (!ok) return;
      }

      final List<Map<String, dynamic>> reorderOrder = [];
      for (int i = 0; i < rungs.length; i++) {
        final EscalationRungDraft rung = rungs[i];
        if (rung.id == null) {
          final bool ok = await _addStep(id, position: i, rung: rung);
          if (!ok) return;
        } else {
          reorderOrder.add({'id': rung.id, 'position': i});
        }
      }

      if (reorderOrder.isNotEmpty) {
        final bool ok = await reorderSteps(id, reorderOrder);
        if (!ok) return;
      }

      await reload();
      Magic.success(trans('uptizm.teams.escalation_editor_save_button'), name);
      MagicRoute.to('/teams/escalation');
    } catch (error) {
      Log.error('[EscalationController.save] $id failed: $error');
      _toastError(null);
    }
  }

  /// Deletes the policy [id] via `DELETE /escalation-policies/{id}`, evicts
  /// it from the cache, reloads the roster, and surfaces a deleted toast. On
  /// a failed response or a transport error, logs the failure and surfaces
  /// an error toast without mutating the cache.
  Future<void> delete(String id) async {
    final EscalationPolicy? policy = _policies
        .cast<EscalationPolicy?>()
        .firstWhere((p) => p?.id == id, orElse: () => null);

    try {
      final response = await Http.delete('/escalation-policies/$id');
      if (!response.successful) {
        Log.error('[EscalationController.delete] $id: ${response.errorMessage}');
        _toastError(response.errorMessage);
        return;
      }

      _details.remove(id);
      _policies = _policies.where((p) => p.id != id).toList();
      refreshUI();
      Magic.success(
        trans('uptizm.teams.escalation_policy_delete_confirm_label'),
        policy?.name ?? id,
      );
    } catch (error) {
      Log.error('[EscalationController.delete] $id failed: $error');
      _toastError(null);
    }
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

  // ---------------------------------------------------------------------------
  // Wire helpers
  // ---------------------------------------------------------------------------

  /// Parses a `GET /escalation-policies/:id` payload into an
  /// [EscalationPolicyDetail].
  EscalationPolicyDetail _detailFromWire(Map<String, dynamic> data) {
    final Object? rawSteps = data['steps'];
    final List<EscalationStepWire> steps = rawSteps is List
        ? rawSteps.whereType<Map<String, dynamic>>().map(_stepFromWire).toList()
        : const [];

    return EscalationPolicyDetail(
      id: data['id'] as String,
      name: (data['name'] as String?) ?? '',
      steps: steps,
    );
  }

  /// Parses one wire step row into an [EscalationStepWire].
  EscalationStepWire _stepFromWire(Map<String, dynamic> m) {
    return EscalationStepWire(
      id: m['id'] as String,
      position: (m['position'] as num?)?.toInt() ?? 0,
      delayMinutes: (m['delay_minutes'] as num?)?.toInt() ?? 0,
      targetType: (m['target_type'] as String?) ?? 'channel',
      targetId: m['target_id'] as String?,
      channel: m['channel'] as String?,
    );
  }

  /// Projects a detail's `name` + step chain onto the fixture-shaped
  /// [EscalationPolicy] the list view renders, defaulting the fields the
  /// backend model does not persist (see the class docblock).
  EscalationPolicy _summaryFromDetail(EscalationPolicyDetail detail) {
    return EscalationPolicy(
      id: detail.id,
      name: detail.name,
      description: '',
      steps: [
        for (final EscalationStepWire step in detail.steps)
          EscalationStep(
            afterMinutes: step.delayMinutes,
            targets: [_targetLabel(step)],
          ),
      ],
      repeatLastStep: false,
      isDefault: false,
      monitorCount: 0,
    );
  }

  /// Renders a step's target as a single display label: the channel string
  /// for a `channel` step, `"On-call"` for `on_call`, or `"User <id>"` for a
  /// `user` step.
  String _targetLabel(EscalationStepWire step) {
    switch (step.targetType) {
      case 'on_call':
        return 'On-call';
      case 'user':
        return 'User ${step.targetId ?? ''}'.trim();
      default:
        return step.channel ?? '';
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
