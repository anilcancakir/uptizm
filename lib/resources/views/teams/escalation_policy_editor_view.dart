import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import '../../../app/mocks/oncall.dart';
import '../../../app/mocks/teams_data.dart';
import '../../../ui/components/empty_state/index.dart';
import '../../../ui/components/region_picker/region_picker.dart';
import '../../../ui/layouts/page_container.dart';

/// **The Escalation Policy editor screen (`/teams/escalation/new` + `/:id`).**
///
/// A Flutter port of the React `EscalationPolicyEditor.tsx`: one screen serving
/// both create and edit. In edit mode it resolves [id] to a fixture via
/// [findEscalationPolicy] (an unknown id falls back to a graceful [EmptyState]);
/// in create mode ([id] `null`) it seeds a single blank rung.
///
/// The body is a single stacked column:
///
/// - A Branding [Card] with the policy name + description [Input]s.
/// - An escalation-ladder section: each rung is a [Card] carrying its rung
///   number, a delay [Select] over the fixed delay options
///   ([_delayOptions], labelled via [escalationDelayLabel]), a targets
///   [RegionPicker] over [escalationTargetRegions] bound to that rung's
///   targets, and a remove-rung ghost [Button] (disabled while only one rung
///   remains). An "Add rung" [Button] appends a blank rung.
/// - A settings [Card] with the repeat-last-rung and use-as-default [Switch]es.
///
/// State is a mutable [_RungDraft] list plus name/description text controllers.
/// [EscalationStep] is immutable, so each rung is held as an editable
/// [_RungDraft] and projected back to [EscalationStep] only conceptually (the
/// mock never persists). Save is enabled only while [_canSave] (name non-empty
/// and every rung has at least one target); it shows a [Magic.success] toast and
/// returns to `/teams/escalation`. [didUpdateWidget] reseeds on an id change so
/// navigating between policies never carries a stale draft across (mirrors
/// `status_page_editor_view`).
///
/// ### Example
/// ```dart
/// // Registered as the routed `/teams/escalation/new` and `/:id` (Step 11):
/// MagicRoute.page('/teams/escalation/new', () => const EscalationPolicyEditorView());
/// MagicRoute.page('/teams/escalation/:id', () => EscalationPolicyEditorView(id: id));
/// ```
@immutable
class EscalationPolicyEditorView extends StatefulWidget {
  /// The escalation-policy identifier resolved against the fixtures via
  /// [findEscalationPolicy]. `null` (or an unknown id) puts the editor in
  /// create mode.
  final String? id;

  /// Creates the [EscalationPolicyEditorView] for the given policy [id].
  const EscalationPolicyEditorView({super.key, this.id});

  @override
  State<EscalationPolicyEditorView> createState() =>
      _EscalationPolicyEditorViewState();
}

/// An editable escalation rung.
///
/// [EscalationStep] is `@immutable`, so per-rung form state lives here where
/// [afterMinutes] and [targets] can mutate in place while the user edits. The
/// mock never persists, so there is no projection back to [EscalationStep].
class _RungDraft {
  /// Minutes to wait after the previous rung fires. 0 means immediately.
  int afterMinutes;

  /// Notification targets this rung pages, e.g. `"Slack #incidents"`.
  List<String> targets;

  _RungDraft({required this.afterMinutes, required this.targets});
}

class _EscalationPolicyEditorViewState
    extends State<EscalationPolicyEditorView> {
  /// The route both Save and the breadcrumb return to.
  static const String _listRoute = '/teams/escalation';

  /// The remove-rung glyph (a trash can, matching the React source).
  static const IconData _removeIcon = Icons.delete_outline;

  /// The fixed rung-delay options, in ascending order. Mirrors the React
  /// `DELAY_OPTIONS`; each value is labelled through [escalationDelayLabel].
  static const List<int> _delayOptions = <int>[0, 3, 5, 10, 15, 30];

  /// The policy-name field controller.
  late TextEditingController _nameController;

  /// The policy-description field controller.
  late TextEditingController _descriptionController;

  /// The mutable escalation ladder. Seeded from the resolved fixture (edit) or
  /// a single blank rung (create).
  late List<_RungDraft> _rungs;

  /// Whether the last rung keeps firing until the alert is acknowledged.
  late bool _repeatLastStep;

  /// Whether this policy applies to any monitor that does not pick one.
  late bool _isDefault;

  /// Whether the resolved id maps to a real fixture (edit mode). `false` puts
  /// the editor in create mode.
  late bool _isEdit;

  @override
  void initState() {
    super.initState();
    _nameController = TextEditingController();
    _descriptionController = TextEditingController();
    _seedFrom(findEscalationPolicy(widget.id));
  }

  @override
  void didUpdateWidget(covariant EscalationPolicyEditorView oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Reseed whenever the resolved id changes so navigating between policies
    // does not carry a stale draft across (mirrors status_page_editor_view).
    if (oldWidget.id != widget.id) {
      _seedFrom(findEscalationPolicy(widget.id));
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
  /// build, so state is assigned directly rather than through [setState]. Edit
  /// mode copies each fixture rung into a fresh [_RungDraft] (with a copied
  /// targets list) so mutating the draft never touches the const fixture.
  void _seedFrom(EscalationPolicy? existing) {
    if (existing == null) {
      _isEdit = false;
      _nameController.text = '';
      _descriptionController.text = '';
      _rungs = <_RungDraft>[_RungDraft(afterMinutes: 0, targets: <String>[])];
      _repeatLastStep = true;
      _isDefault = false;
      return;
    }
    _isEdit = true;
    _nameController.text = existing.name;
    _descriptionController.text = existing.description;
    _rungs = <_RungDraft>[
      for (final EscalationStep step in existing.steps)
        _RungDraft(
          afterMinutes: step.afterMinutes,
          targets: List<String>.from(step.targets),
        ),
    ];
    _repeatLastStep = existing.repeatLastStep;
    _isDefault = existing.isDefault;
  }

  /// Whether the draft satisfies the Save-enabled rule: a non-empty name and at
  /// least one target on every rung (React `canSave`).
  bool get _canSave =>
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

  /// Sets the delay of the rung at [index] (React `setDelay`).
  void _setDelay(int index, int minutes) {
    setState(() {
      _rungs[index].afterMinutes = minutes;
    });
  }

  /// Sets the notification targets of the rung at [index] (React `setTargets`).
  void _setTargets(int index, List<String> targets) {
    setState(() {
      _rungs[index].targets = targets;
    });
  }

  /// Saves the draft and returns to the list (mock: nothing persists).
  void _save() {
    Magic.success(
      trans(
        _isEdit
            ? 'uptizm.teams.escalation_editor_save_button'
            : 'uptizm.teams.escalation_editor_create_button',
      ),
      _nameController.text.trim(),
    );
    MagicRoute.to(_listRoute);
  }

  @override
  Widget build(BuildContext context) {
    // 1. A supplied-but-unknown id is a broken link, so it renders a graceful
    //    not-found state (mirrors status_page_editor_view).
    if (widget.id != null && findEscalationPolicy(widget.id) == null) {
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
        action: Button(
          intent: ButtonIntent.primary,
          onPressed: () => MagicRoute.to(_listRoute),
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
    return PageHeader(
      title: _isEdit
          ? trans('uptizm.teams.escalation_editor_title_edit')
          : trans('uptizm.teams.escalation_editor_title_new'),
      subtitle: trans('uptizm.teams.escalation_editor_description'),
      backLabel: trans('uptizm.team_menu.escalation'),
      backFallback: _listRoute,
      actions: <Widget>[
        Button(
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
    return Card(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-5',
        children: <Widget>[
          MagicFormField(
            label: trans('uptizm.teams.escalation_editor_name_label'),
            child: Input(
              controller: _nameController,
              placeholder: trans(
                'uptizm.teams.escalation_editor_name_placeholder',
              ),
              // Re-evaluate _canSave on every keystroke so the Save button
              // enables/disables live with the name field.
              onChanged: (String _) => setState(() {}),
            ),
          ),
          MagicFormField(
            label: trans('uptizm.teams.escalation_editor_desc_label'),
            child: Input(
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
          child: Button(
            intent: ButtonIntent.secondary,
            size: ButtonSize.sm,
            onPressed: _addRung,
            child: WText(trans('uptizm.teams.escalation_editor_add_rung_button')),
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
    return Card(
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
              Button(
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
          MagicFormField(
            label: trans('uptizm.teams.escalation_editor_delay_label'),
            child: Select<int>(
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
          MagicFormField(
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
    return Card(
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
        Switch(value: value, onChanged: onChanged, semanticLabel: label),
        WText(label, className: 'min-w-0 text-sm text-fg'),
      ],
    );
  }
}
