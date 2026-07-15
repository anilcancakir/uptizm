import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/controllers/escalation_controller.dart';
import '../../../app/models/escalation_policy.dart';
import '../../../app/support/escalation_support.dart'
    show escalationDelayLabel, escalationTargetRegions;
import '../../../ui/components/empty_state/index.dart';
import '../../../ui/components/region_picker/region_picker.dart';
import '../../../ui/layouts/page_container.dart';

/// **The Escalation Policy editor screen (`/teams/escalation/new` + `/:id`).**
///
/// A Flutter port of the React `EscalationPolicyEditor.tsx`: one screen serving
/// both create and edit. In edit mode it resolves [id] to
/// [EscalationController.detailById] (an unknown id falls back to a graceful
/// [EmptyState]); in create mode ([id] `null`) it seeds a single blank rung.
///
/// The body is a single stacked column:
///
/// - A Branding [Card] with the policy name + description [Input]s (the
///   backend model only persists `name`; see [EscalationController]'s
///   docblock, so `description` stays a local-only field like the
///   repeat/default switches below).
/// - An escalation-ladder section: each rung is a [Card] carrying its rung
///   number, a delay [Select] over the fixed delay options
///   ([_delayOptions], labelled via [escalationDelayLabel]), a targets
///   [RegionPicker] over [escalationTargetRegions] bound to that rung's
///   targets, and a remove-rung ghost [Button] (disabled while only one rung
///   remains). An "Add rung" [Button] appends a blank rung.
/// - A settings [Card] with the repeat-last-rung and use-as-default [Switch]es
///   (local-only inputs; the backend has no matching columns yet).
///
/// State is a mutable [_RungDraft] list plus name/description text controllers.
/// Save fires [EscalationController.create] (no [id]) or
/// [EscalationController.save] (existing [id]), which persists the policy
/// `name` and reconciles the step chain against [_originalStepIds] (add/
/// remove/reorder; see the controller's docblock for why an in-place rung
/// edit clears its [_RungDraft.id] rather than calling a step-update
/// endpoint that does not exist). Save is enabled only while [_canSave] (name
/// non-empty and every rung has at least one target). [didUpdateWidget]
/// reseeds on an id change so navigating between policies never carries a
/// stale draft across (mirrors `status_page_editor_view`).
///
/// ### Example
/// ```dart
/// // Registered as the routed `/teams/escalation/new` and `/:id` (Step 11):
/// MagicRoute.page('/teams/escalation/new', () => const EscalationPolicyEditorView());
/// MagicRoute.page('/teams/escalation/:id', () => EscalationPolicyEditorView(id: id));
/// ```
@immutable
class EscalationPolicyEditorView
    extends MagicStatefulView<EscalationController> {
  /// The escalation-policy identifier resolved against
  /// [EscalationController.detailById]. `null` (or an unknown id) puts the
  /// editor in create mode.
  final String? id;

  /// Creates the [EscalationPolicyEditorView] for the given policy [id].
  const EscalationPolicyEditorView({super.key, this.id});

  @override
  State<EscalationPolicyEditorView> createState() =>
      _EscalationPolicyEditorViewState();
}

/// An editable escalation rung.
///
/// Carries the backend step [id] once persisted so
/// [EscalationController.save] can diff the draft ladder against its
/// previously loaded chain. [id] is cleared back to `null` whenever
/// [afterMinutes]/[targets] mutate in place: the backend has no step-update
/// endpoint, so an edited rung is reconciled as "remove the old row, add a
/// fresh one" rather than silently dropping the edit (see
/// [EscalationController.save]'s docblock).
class _RungDraft {
  /// The backend step id, or `null` for a new (or just-edited) rung.
  String? id;

  /// Minutes to wait after the previous rung fires. 0 means immediately.
  int afterMinutes;

  /// Notification targets this rung pages, e.g. `"Slack #incidents"`.
  List<String> targets;

  _RungDraft({this.id, required this.afterMinutes, required this.targets});
}

class _EscalationPolicyEditorViewState
    extends
        MagicStatefulViewState<
          EscalationController,
          EscalationPolicyEditorView
        > {
  /// The remove-rung glyph (a trash can, matching the React source).
  static const IconData _removeIcon = Icons.delete_outline;

  /// The fixed rung-delay options, in ascending order. Mirrors the React
  /// `DELAY_OPTIONS`; each value is labelled through [escalationDelayLabel].
  static const List<int> _delayOptions = <int>[0, 3, 5, 10, 15, 30];

  /// The policy-name field controller.
  late TextEditingController _nameController;

  /// The policy-description field controller. Local-only: the backend model
  /// does not persist a description column (see the class docblock).
  late TextEditingController _descriptionController;

  /// The mutable escalation ladder. Seeded from the resolved detail (edit) or
  /// a single blank rung (create).
  late List<_RungDraft> _rungs;

  /// Whether the last rung keeps firing until the alert is acknowledged.
  /// Local-only (see the class docblock).
  late bool _repeatLastStep;

  /// Whether this policy applies to any monitor that does not pick one.
  /// Local-only (see the class docblock).
  late bool _isDefault;

  /// Whether the resolved id maps to a real, loaded policy (edit mode).
  /// `false` puts the editor in create mode.
  late bool _isEdit;

  /// The step ids present on the policy at the moment it was seeded, used by
  /// [_save] to diff the current [_rungs] draft and issue exactly the
  /// remove/add/reorder calls the change requires.
  late Set<String> _originalStepIds;

  /// Whether a save/create request is currently in flight, disabling the
  /// Save/Create action so a double-tap cannot fire two writes.
  bool _saving = false;

  @override
  void initState() {
    // Register the controller before the base state resolves it via
    // Magic.find<T>() (which throws when unregistered). Idempotent.
    Magic.findOrPut(EscalationController.new);
    super.initState();
    _nameController = TextEditingController();
    _descriptionController = TextEditingController();
    _seedFrom(controller.detailById(widget.id));
    // One-shot single-resource refresh for the prefill (never from build; see
    // [EscalationController.refreshDetail], which notifies listeners on
    // completion so the seeded draft picks up the freshly fetched steps).
    final String? id = widget.id;
    if (id != null) {
      controller.refreshDetail(id);
    }
  }

  @override
  void didUpdateWidget(covariant EscalationPolicyEditorView oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Reseed whenever the resolved id changes so navigating between policies
    // does not carry a stale draft across (mirrors status_page_editor_view).
    if (oldWidget.id != widget.id) {
      _seedFrom(controller.detailById(widget.id));
      final String? id = widget.id;
      if (id != null) {
        controller.refreshDetail(id);
      }
    }
  }

  @override
  void dispose() {
    _nameController.dispose();
    _descriptionController.dispose();
    super.dispose();
  }

  /// Seeds the draft from [existing] (edit) or the create defaults.
  ///
  /// Runs from [initState] and [didUpdateWidget]; both schedule their own
  /// build, so state is assigned directly rather than through [setState].
  /// Edit mode copies each wire step into a fresh [_RungDraft] (carrying its
  /// backend id) so mutating the draft never touches the cached policy model.
  void _seedFrom(EscalationPolicy? existing) {
    if (existing == null) {
      _isEdit = false;
      _nameController.text = '';
      _descriptionController.text = '';
      _rungs = <_RungDraft>[_RungDraft(afterMinutes: 0, targets: <String>[])];
      _repeatLastStep = true;
      _isDefault = false;
      _originalStepIds = <String>{};
      return;
    }
    _isEdit = true;
    _nameController.text = existing.name ?? '';
    _descriptionController.text = '';
    _rungs = <_RungDraft>[
      for (final EscalationStepWire step in existing.steps)
        _RungDraft(
          id: step.id,
          afterMinutes: step.delayMinutes,
          targets: <String>[step.channel ?? ''],
        ),
    ];
    _repeatLastStep = true;
    _isDefault = false;
    _originalStepIds = existing.steps.map((s) => s.id).toSet();
  }

  /// Whether the draft satisfies the Save-enabled rule: a non-empty name and at
  /// least one target on every rung (React `canSave`), and no save already in
  /// flight.
  bool get _canSave =>
      !_saving &&
      _nameController.text.trim().isNotEmpty &&
      _rungs.every((_RungDraft rung) => rung.targets.isNotEmpty);

  /// Appends a fresh blank rung to the ladder (React `addRung`).
  void _addRung() {
    setState(() {
      _rungs.add(_RungDraft(afterMinutes: 0, targets: <String>[]));
    });
  }

  /// Removes the rung at [index]. The last rung is never removable, so callers
  /// gate this on `_rungs.length > 1` (React `removeRung` + disabled button).
  void _removeRung(int index) {
    if (_rungs.length <= 1) return;
    setState(() {
      _rungs.removeAt(index);
    });
  }

  /// Sets the delay of the rung at [index] (React `setDelay`). Clears the
  /// rung's persisted id: the backend has no step-update endpoint, so an
  /// in-place edit is reconciled as remove-then-add at save time (see the
  /// class docblock).
  void _setDelay(int index, int minutes) {
    setState(() {
      _rungs[index].afterMinutes = minutes;
      _rungs[index].id = null;
    });
  }

  /// Sets the notification targets of the rung at [index] (React
  /// `setTargets`). Clears the rung's persisted id for the same reason as
  /// [_setDelay].
  void _setTargets(int index, List<String> targets) {
    setState(() {
      _rungs[index].targets = targets;
      _rungs[index].id = null;
    });
  }

  /// Saves the draft: [EscalationController.create] in create mode,
  /// [EscalationController.save] in edit mode. Both persist through to
  /// `api/v1/escalation-policies` and navigate back to the list on success,
  /// surfacing an error toast and leaving the editor open on failure (see the
  /// controller's docblock).
  Future<void> _save() async {
    setState(() => _saving = true);
    final String name = _nameController.text.trim();
    final List<EscalationRungDraft> rungs = [
      for (final _RungDraft rung in _rungs)
        EscalationRungDraft(
          id: rung.id,
          afterMinutes: rung.afterMinutes,
          targets: rung.targets,
        ),
    ];

    if (_isEdit) {
      await controller.save(widget.id!, name, rungs, _originalStepIds);
    } else {
      await controller.create(name, rungs);
    }

    if (mounted) setState(() => _saving = false);
  }

  @override
  Widget build(BuildContext context) {
    // 1. A supplied-but-unknown id is a broken link, so it renders a graceful
    //    not-found state (mirrors status_page_editor_view).
    if (widget.id != null && controller.detailById(widget.id) == null) {
      return _buildNotFound();
    }

    // 2. A plain Flutter Column scaffolds the page body so each leaf receives a
    //    bounded width from PageContainer; Wind utilities appear only on the
    //    leaf containers below.
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          _buildHeader(),
          const SizedBox(height: 24),
          _buildBody(),
        ],
      ),
    );
  }

  /// Builds the graceful not-found state for an unknown policy id.
  Widget _buildNotFound() {
    return PageContainer(
      child: EmptyState(
        icon: Icons.route_outlined,
        title: trans('uptizm.teams.escalation_editor_title_edit'),
        description: trans('uptizm.teams.escalation_editor_description'),
        action: MSButton(
          intent: ButtonIntent.primary,
          onPressed: () => MagicRoute.to('/teams/escalation'),
          child: WText(trans('uptizm.team_menu.escalation')),
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Header.
  // ---------------------------------------------------------------------------

  /// Builds the header: a "← Escalation policies" breadcrumb, the page title
  /// and description, and the Save/Create action.
  Widget _buildHeader() {
    return MSPageHeader(
      title: _isEdit
          ? trans('uptizm.teams.escalation_editor_title_edit')
          : trans('uptizm.teams.escalation_editor_title_new'),
      subtitle: trans('uptizm.teams.escalation_editor_description'),
      backLabel: trans('uptizm.team_menu.escalation'),
      backFallback: '/teams/escalation',
      actions: <Widget>[
        MSButton(
          disabled: !_canSave,
          onPressed: _canSave ? _save : null,
          child: WText(
            _isEdit
                ? trans('uptizm.teams.escalation_editor_save_button')
                : trans('uptizm.teams.escalation_editor_create_button'),
          ),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Body.
  // ---------------------------------------------------------------------------

  /// Builds the stacked body: the name/description card, the escalation-ladder
  /// section, and the repeat/default settings card.
  Widget _buildBody() {
    return WDiv(
      className: 'flex flex-col gap-6',
      children: <Widget>[
        _buildDetailsCard(),
        _buildLadderSection(),
        _buildSettingsCard(),
      ],
    );
  }

  /// Builds the name + description card.
  Widget _buildDetailsCard() {
    return MSCard(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-5',
        children: <Widget>[
          MSFormField(
            label: trans('uptizm.teams.escalation_editor_name_label'),
            child: MSInput(
              controller: _nameController,
              placeholder: trans(
                'uptizm.teams.escalation_editor_name_placeholder',
              ),
              // Re-evaluate _canSave on every keystroke so the Save button
              // enables/disables live with the name field.
              onChanged: (String _) => setState(() {}),
            ),
          ),
          MSFormField(
            label: trans('uptizm.teams.escalation_editor_desc_label'),
            child: MSInput(
              controller: _descriptionController,
              placeholder: trans(
                'uptizm.teams.escalation_editor_desc_placeholder',
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Builds the escalation-ladder section: a small heading, one [Card] per
  /// rung, and an "Add rung" button beneath.
  Widget _buildLadderSection() {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: <Widget>[
        WText(
          trans('uptizm.teams.escalation_editor_ladder_header'),
          className:
              'px-1 text-xs font-medium uppercase tracking-wide text-fg-muted',
        ),
        WDiv(
          className: 'flex flex-col gap-3',
          children: <Widget>[
            for (int i = 0; i < _rungs.length; i++) _buildRungCard(i),
          ],
        ),
        WDiv(
          className: 'flex flex-row',
          child: MSButton(
            intent: ButtonIntent.secondary,
            size: ButtonSize.sm,
            onPressed: _addRung,
            child: WText(
              trans('uptizm.teams.escalation_editor_add_rung_button'),
            ),
          ),
        ),
      ],
    );
  }

  /// Builds a single rung card: the rung number + remove control, the delay
  /// select, and the targets picker.
  Widget _buildRungCard(int index) {
    final _RungDraft rung = _rungs[index];
    final bool canRemove = _rungs.length > 1;
    return MSCard(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-4',
        children: <Widget>[
          WDiv(
            className: 'flex flex-row items-center justify-between gap-3',
            children: <Widget>[
              WText(
                trans('uptizm.teams.escalation_editor_rung_title', {
                  'number': index + 1,
                }),
                className: 'text-sm font-semibold text-fg',
              ),
              MSButton(
                intent: ButtonIntent.ghost,
                size: ButtonSize.sm,
                disabled: !canRemove,
                onPressed: canRemove ? () => _removeRung(index) : null,
                semanticLabel: trans(
                  'uptizm.teams.escalation_editor_rung_title',
                  {'number': index + 1},
                ),
                child: WIcon(_removeIcon, className: 'text-sm'),
              ),
            ],
          ),
          MSFormField(
            label: trans('uptizm.teams.escalation_editor_delay_label'),
            child: MSSelect<int>(
              value: rung.afterMinutes,
              options: <SelectOption<int>>[
                for (final int minutes in _delayOptions)
                  SelectOption<int>(
                    value: minutes,
                    label: escalationDelayLabel(minutes),
                  ),
              ],
              onChange: (int? value) {
                if (value != null) _setDelay(index, value);
              },
            ),
          ),
          MSFormField(
            label: trans('uptizm.teams.escalation_editor_targets_label'),
            hint: trans('uptizm.teams.escalation_editor_targets_hint'),
            child: RegionPicker(
              regions: escalationTargetRegions(),
              value: rung.targets,
              onChanged: (List<String> next) => _setTargets(index, next),
            ),
          ),
        ],
      ),
    );
  }

  /// Builds the repeat/default settings card.
  Widget _buildSettingsCard() {
    return MSCard(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-3',
        children: <Widget>[
          _buildSwitchRow(
            label: trans('uptizm.teams.escalation_editor_repeat_label'),
            value: _repeatLastStep,
            onChanged: (bool value) => setState(() => _repeatLastStep = value),
          ),
          _buildSwitchRow(
            label: trans('uptizm.teams.escalation_editor_default_label'),
            value: _isDefault,
            onChanged: (bool value) => setState(() => _isDefault = value),
          ),
        ],
      ),
    );
  }

  /// Builds a labelled switch row: the [Switch] toggle followed by its text
  /// label (the Dart [Switch] is toggle-only, so the label renders beside it,
  /// mirroring status_page_editor_view's switch-row helper).
  Widget _buildSwitchRow({
    required String label,
    required bool value,
    required ValueChanged<bool> onChanged,
  }) {
    return WDiv(
      className: 'flex flex-row items-center gap-3',
      children: <Widget>[
        MSSwitch(value: value, onChanged: onChanged, semanticLabel: label),
        WText(label, className: 'min-w-0 text-sm text-fg'),
      ],
    );
  }
}
