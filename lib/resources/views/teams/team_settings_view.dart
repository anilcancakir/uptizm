import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/billing.dart';
import '../../../app/mocks/teams.dart';
import '../../../app/mocks/teams_data.dart';
import '../../../ui/components/upgrade_nudge/index.dart';
import '../../../ui/layouts/page_container.dart';

/// One selectable AI-mode radio option, mirroring the React `AI_MODES`
/// fixture (`{value, title, desc}`).
@immutable
class _AiMode {
  /// Stable machine value, e.g. `'off'`, `'suggest'`, `'auto'`.
  final String value;

  /// Display title shown on the card.
  final String title;

  /// Explanatory description shown below the title.
  final String description;

  const _AiMode({
    required this.value,
    required this.title,
    required this.description,
  });
}

/// **The Team Settings screen (`/teams/settings`).**
///
/// A faithful Flutter port of the React `TeamSettingsPage.tsx`: a Team card
/// (name/slug/avatar color, seeded from [teams].first), an AI-mode card of
/// three radio cards (off/suggest/auto, mirroring
/// `appearance_settings_view`'s radio-card pattern; Auto is disabled and
/// gated behind an [UpgradeNudge] when the current plan does not reach
/// [AiLevel.auto]) plus a weekly-digest [Switch] and Save button, and a
/// danger-zone card with a destructive Delete action.
///
/// This is a pure UI mock: Save shows a [Magic.success] toast; Delete opens a
/// [MagicStarterConfirmDialog], and on confirm (mounted-guarded) shows a
/// toast and returns to `/`. Nothing persists.
///
/// ### Example
/// ```dart
/// MagicRoute.page('/teams/settings', () => const TeamSettingsView());
/// ```
@immutable
class TeamSettingsView extends StatefulWidget {
  /// Creates the [TeamSettingsView].
  const TeamSettingsView({super.key});

  @override
  State<TeamSettingsView> createState() => _TeamSettingsViewState();
}

class _TeamSettingsViewState extends State<TeamSettingsView> {
  /// The route the breadcrumb and post-delete flow return to.
  static const String _homeRoute = '/';

  /// The AI-mode radio options, in display order.
  static final List<_AiMode> _aiModes = <_AiMode>[
    _AiMode(
      value: 'off',
      title: trans('uptizm.teams.settings_ai_off_title'),
      description: trans('uptizm.teams.settings_ai_off_desc'),
    ),
    _AiMode(
      value: 'suggest',
      title: trans('uptizm.teams.settings_ai_suggest_title'),
      description: trans('uptizm.teams.settings_ai_suggest_desc'),
    ),
    _AiMode(
      value: 'auto',
      title: trans('uptizm.teams.settings_ai_auto_title'),
      description: trans('uptizm.teams.settings_ai_auto_desc'),
    ),
  ];

  /// The current team, seeded once from the fixtures.
  late final Team _team = teams.first;

  /// The team-name field value, seeded from [_team].
  late String _name = _team.name;

  /// The URL-slug field value, seeded from [_team].
  late String _slug = _team.slug;

  /// The selected avatar color, seeded from [_team].
  late Color _color = _team.color;

  /// The selected AI mode, defaulting to `suggest` (React default).
  String _aiMode = 'suggest';

  /// Whether the weekly AI digest email is enabled.
  bool _weeklyDigest = true;

  /// Whether the Auto AI mode is locked behind the current plan.
  bool get _autoLocked => currentLimits.ai.index < AiLevel.auto.index;

  /// The cheapest plan that unlocks Auto AI mode.
  Plan get _autoPlan =>
      smallestPlanWhere((PlanLimits l) => l.ai.index >= AiLevel.auto.index);

  /// Saves the team settings (mock: nothing persists).
  void _save() {
    Magic.success(trans('uptizm.teams.settings_save_button'), _name);
  }

  /// Opens the delete-team confirm dialog; on confirm (mounted-guarded)
  /// shows a toast and returns home.
  Future<void> _confirmDelete() async {
    final bool confirmed = await MagicStarterConfirmDialog.show(
      context,
      title: trans('uptizm.teams.settings_delete_confirm_title', {
        'name': _name,
      }),
      description: trans('uptizm.teams.settings_delete_confirm_description'),
      confirmLabel: trans('uptizm.teams.settings_delete_confirm_label'),
      variant: ConfirmDialogVariant.danger,
    );
    if (!confirmed) return;
    // Guard against the async dialog gap: the view may have been popped while
    // the confirm dialog was open.
    if (!mounted) return;

    Magic.success(trans('uptizm.teams.settings_delete_button'), _name);
    MagicRoute.to(_homeRoute);
  }

  @override
  Widget build(BuildContext context) {
    return PageContainer(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _buildHeader(),
          const SizedBox(height: 24),
          _buildTeamCard(),
          const SizedBox(height: 24),
          _buildAiModeCard(),
          const SizedBox(height: 24),
          _buildSaveRow(),
          const SizedBox(height: 24),
          _buildDangerZoneCard(),
        ],
      ),
    );
  }

  /// Builds the header: breadcrumb + title + subtitle naming the team.
  Widget _buildHeader() {
    return PageHeader(
      title: trans('uptizm.teams.settings_title'),
      subtitle: trans('uptizm.teams.settings_description', {
        'name': _team.name,
      }),
      backLabel: trans('uptizm.settings.hub_title'),
      backFallback: _homeRoute,
    );
  }

  // ---------------------------------------------------------------------------
  // Team card.
  // ---------------------------------------------------------------------------

  /// Builds the Team card: name, slug, avatar color.
  Widget _buildTeamCard() {
    return Card(
      variant: CardVariant.surface,
      title: trans('uptizm.teams.settings_team_header'),
      child: WDiv(
        className: 'flex flex-col gap-5',
        children: <Widget>[
          _buildNameField(),
          _buildSlugField(),
          _buildColorField(),
        ],
      ),
    );
  }

  /// Builds the Name input.
  Widget _buildNameField() {
    return MagicFormField(
      label: trans('uptizm.teams.settings_name_label'),
      child: Input(
        value: _name,
        onChanged: (String value) => setState(() => _name = value),
      ),
    );
  }

  /// Builds the mono slug input, hinting the public URL.
  Widget _buildSlugField() {
    return MagicFormField(
      label: trans('uptizm.teams.settings_url_label'),
      hint: 'uptizm.com/$_slug',
      child: Input(
        value: _slug,
        onChanged: (String value) => setState(() => _slug = value),
        className: 'font-mono',
      ),
    );
  }

  /// Builds the eight-swatch avatar-color grid.
  ///
  /// Each swatch is a tappable circle: a [WButton] wrapping a [WDiv] with the
  /// raw team color as its background. The selected swatch gets a
  /// `border-primary` ring (not `ring-*`, the status-editor lesson).
  /// [kTeamColors] is the ONLY raw color on this screen (content, the
  /// sanctioned exception).
  Widget _buildColorField() {
    return MagicFormField(
      label: trans('uptizm.teams.settings_color_label'),
      child: WDiv(
        className: 'flex flex-row wrap gap-2',
        children: <Widget>[
          for (final Color swatch in kTeamColors) _buildSwatch(swatch),
        ],
      ),
    );
  }

  /// Builds a single tappable avatar-color swatch circle.
  Widget _buildSwatch(Color swatch) {
    final bool selected = _color.toARGB32() == swatch.toARGB32();
    return WButton(
      onTap: () => setState(() => _color = swatch),
      child: WDiv(
        className: selected
            ? 'rounded-full border-2 border-primary p-0.5'
            : 'rounded-full border-2 border-transparent p-0.5',
        child: WDiv(backgroundColor: swatch, className: 'size-7 rounded-full'),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // AI-mode card.
  // ---------------------------------------------------------------------------

  /// Builds the AI-mode card: the three radio cards, the Auto-locked
  /// [UpgradeNudge], and the weekly-digest switch.
  Widget _buildAiModeCard() {
    return Card(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-5',
        children: <Widget>[
          _buildAiModeHeading(),
          WDiv(
            className: 'flex flex-col gap-3',
            children: <Widget>[
              for (final _AiMode mode in _aiModes) _buildAiModeCardOption(mode),
              if (_autoLocked)
                UpgradeNudge(
                  message: trans('uptizm.teams.settings_ai_auto_desc'),
                  requiredPlan: _autoPlan.name,
                ),
            ],
          ),
          _buildDigestSwitchRow(),
        ],
      ),
    );
  }

  /// Builds the AI-mode card section heading.
  Widget _buildAiModeHeading() {
    return WDiv(
      className: 'flex flex-col gap-1',
      children: <Widget>[
        WText(
          trans('uptizm.teams.settings_ai_header'),
          className: 'text-sm font-semibold text-fg dark:text-fg',
        ),
        WText(
          trans('uptizm.teams.settings_ai_description'),
          className: 'text-sm text-fg-muted dark:text-fg-muted',
        ),
      ],
    );
  }

  /// Builds one AI-mode radio card, mirroring `appearance_settings_view`'s
  /// radio-card pattern. Auto is disabled + tinted with a plan [Badge] when
  /// [_autoLocked].
  Widget _buildAiModeCardOption(_AiMode mode) {
    final bool locked = mode.value == 'auto' && _autoLocked;
    final bool selected = _aiMode == mode.value;

    final String cardClassName = locked
        ? 'flex flex-row items-start gap-3 rounded-lg border '
              'border-color-border bg-surface p-4 opacity-60 '
              'dark:border-color-border dark:bg-surface'
        : selected
        ? 'flex flex-row items-start gap-3 rounded-lg border '
              'border-primary bg-primary-container p-4 transition-colors '
              'dark:border-primary dark:bg-primary-container'
        : 'flex flex-row items-start gap-3 rounded-lg border '
              'border-color-border bg-surface p-4 transition-colors '
              'hover:bg-surface-container '
              'dark:border-color-border dark:bg-surface '
              'dark:hover:bg-surface-container';

    return WAnchor(
      isDisabled: locked,
      onTap: locked ? null : () => setState(() => _aiMode = mode.value),
      child: WDiv(
        className: cardClassName,
        children: <Widget>[
          _buildRadioIndicator(selected: selected && !locked),
          Expanded(
            child: WDiv(
              className: 'flex flex-col gap-0.5',
              children: <Widget>[
                WDiv(
                  className: 'flex flex-row items-center gap-2',
                  children: <Widget>[
                    WText(
                      mode.title,
                      className: 'text-sm font-semibold text-fg dark:text-fg',
                    ),
                    if (locked) Badge(_autoPlan.name, tone: BadgeTone.neutral),
                  ],
                ),
                WText(
                  mode.description,
                  className: 'text-xs text-fg-muted dark:text-fg-muted',
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// Builds the radio indicator (ring plus dot), mirroring
  /// `appearance_settings_view._buildRadioIndicator`.
  Widget _buildRadioIndicator({required bool selected}) {
    final String ringClassName = selected
        ? 'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center '
              'rounded-full border-2 border-primary dark:border-primary'
        : 'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center '
              'rounded-full border-2 border-color-border dark:border-color-border';

    return WDiv(
      className: ringClassName,
      child: selected
          ? WDiv(
              className: 'h-2.5 w-2.5 rounded-full bg-primary dark:bg-primary',
            )
          : const SizedBox.shrink(),
    );
  }

  /// Builds the weekly-digest switch row.
  Widget _buildDigestSwitchRow() {
    return WDiv(
      className:
          'flex flex-row items-center gap-3 border-t border-color-border '
          'dark:border-color-border pt-4',
      children: <Widget>[
        Switch(
          value: _weeklyDigest,
          onChanged: (bool value) => setState(() => _weeklyDigest = value),
          semanticLabel: trans('uptizm.teams.settings_ai_digest_label'),
        ),
        WText(
          trans('uptizm.teams.settings_ai_digest_label'),
          className: 'min-w-0 text-sm text-fg dark:text-fg',
        ),
      ],
    );
  }

  /// Builds the trailing Save action row.
  Widget _buildSaveRow() {
    return WDiv(
      className: 'flex flex-row justify-end',
      child: Button(
        onPressed: _save,
        child: WText(trans('uptizm.teams.settings_save_button')),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Danger zone.
  // ---------------------------------------------------------------------------

  /// Builds the danger-zone card: description + destructive Delete button.
  Widget _buildDangerZoneCard() {
    return Card(
      variant: CardVariant.surface,
      title: trans('uptizm.teams.settings_danger_header'),
      child: WDiv(
        className: 'flex flex-col gap-4',
        children: <Widget>[
          WText(
            trans('uptizm.teams.settings_danger_description'),
            className: 'text-sm text-fg-muted dark:text-fg-muted',
          ),
          WDiv(
            className: 'flex flex-row',
            child: Button(
              intent: ButtonIntent.destructive,
              onPressed: _confirmDelete,
              child: WText(trans('uptizm.teams.settings_delete_button')),
            ),
          ),
        ],
      ),
    );
  }
}
