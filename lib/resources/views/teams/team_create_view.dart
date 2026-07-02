import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/mocks/teams_data.dart';
import '../../../ui/layouts/page_container.dart';

/// **The Team Create screen (`/teams/new`).**
///
/// A faithful Flutter port of the React `TeamCreatePage.tsx`: a single
/// [Card] with the team name, URL slug, an eight-swatch avatar-color grid,
/// and an optional bulk-invite [Textarea]. The name input auto-slugs into
/// the slug field until the user edits the slug directly, mirroring
/// `status_page_editor_view`'s `_slugEdited` latch.
///
/// This is a pure UI mock: Create is enabled once name and slug are both
/// non-empty, and it only shows a [Magic.success] toast and returns to `/`;
/// nothing persists.
///
/// ### Example
/// ```dart
/// MagicRoute.page('/teams/new', () => const TeamCreateView());
/// ```
@immutable
class TeamCreateView extends StatefulWidget {
  /// Creates the [TeamCreateView].
  const TeamCreateView({super.key});

  @override
  State<TeamCreateView> createState() => _TeamCreateViewState();
}

class _TeamCreateViewState extends State<TeamCreateView> {
  /// The route Create/Cancel returns to.
  static const String _homeRoute = '/';

  /// The team name field value.
  String _name = '';

  /// The URL slug field value.
  String _slug = '';

  /// Whether the user has edited the slug directly. While `false`, typing the
  /// name auto-slugs into the slug; the first manual slug edit latches this
  /// `true` and stops the auto-fill (React `slugEdited`).
  bool _slugEdited = false;

  /// The selected avatar color, defaulting to the first swatch.
  Color _color = kTeamColors.first;

  /// The bulk-invite textarea value.
  String _invites = '';

  /// Whether Create is enabled: both name and slug must be non-empty.
  bool get _canCreate => _name.trim().isNotEmpty && _slug.trim().isNotEmpty;

  /// Handles a name edit: updates the name and, until the slug is manually
  /// edited, keeps the slug auto-synced from the name (React `onName`).
  void _onNameChanged(String value) {
    setState(() {
      _name = value;
      if (!_slugEdited) {
        _slug = _slugify(value);
      }
    });
  }

  /// Handles a slug edit: latches [_slugEdited] and stores the slugified
  /// value (React `onChange` on the slug input).
  void _onSlugChanged(String value) {
    setState(() {
      _slugEdited = true;
      _slug = _slugify(value);
    });
  }

  /// Slugifies [value] to a URL-safe, hyphen-separated handle capped at 40
  /// characters. Mirrors the React `slugify` helper.
  String _slugify(String value) {
    final String s = value
        .toLowerCase()
        .replaceAll(RegExp(r'[^a-z0-9]+'), '-')
        .replaceAll(RegExp(r'^-+'), '')
        .replaceAll(RegExp(r'-+$'), '');
    return s.substring(0, s.length < 40 ? s.length : 40);
  }

  /// Creates the team (mock: nothing persists) and returns home.
  void _create() {
    Magic.success(trans('uptizm.teams.create_button'), _name);
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
          _buildFormCard(),
          const SizedBox(height: 24),
          _buildActionRow(),
        ],
      ),
    );
  }

  /// Builds the header: breadcrumb + title + subtitle.
  Widget _buildHeader() {
    return PageHeader(
      title: trans('uptizm.teams.create_title'),
      subtitle: trans('uptizm.teams.create_subtitle'),
      backLabel: trans('uptizm.settings.hub_title'),
      backFallback: _homeRoute,
    );
  }

  /// Builds the form card: name, slug, avatar color, bulk invites.
  Widget _buildFormCard() {
    return Card(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-5',
        children: [
          _buildNameField(),
          _buildSlugField(),
          _buildColorField(),
          _buildInvitesField(),
        ],
      ),
    );
  }

  /// Builds the Name input, which auto-slugs into the slug until the slug is
  /// edited directly.
  Widget _buildNameField() {
    return MagicFormField(
      label: trans('uptizm.teams.create_name_label'),
      child: Input(
        value: _name,
        onChanged: _onNameChanged,
        placeholder: trans('uptizm.teams.create_name_placeholder'),
      ),
    );
  }

  /// Builds the mono slug input, hinting the full public URL. Editing it
  /// latches [_slugEdited] and stops the name auto-fill.
  Widget _buildSlugField() {
    return MagicFormField(
      label: trans('uptizm.teams.create_url_label'),
      hint:
          'uptizm.com/${_slug.isEmpty ? trans('uptizm.teams.create_url_placeholder') : _slug}',
      child: Input(
        value: _slug,
        onChanged: _onSlugChanged,
        placeholder: trans('uptizm.teams.create_url_placeholder'),
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
      label: trans('uptizm.teams.create_color_label'),
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

  /// Builds the optional bulk-invite textarea.
  Widget _buildInvitesField() {
    return MagicFormField(
      label: trans('uptizm.teams.create_invites_label'),
      hint: trans('uptizm.teams.create_invites_hint'),
      child: Textarea(
        value: _invites,
        onChanged: (String value) => setState(() => _invites = value),
        placeholder: trans('uptizm.teams.create_invites_placeholder'),
        minLines: 2,
      ),
    );
  }

  /// Builds the trailing action row: Cancel + Create. Neither is `w-full`
  /// inside the flex row.
  Widget _buildActionRow() {
    return WDiv(
      className: 'flex flex-row justify-end gap-3',
      children: <Widget>[
        Button(
          intent: ButtonIntent.secondary,
          onPressed: () => MagicRoute.to(_homeRoute),
          child: WText(trans('common.cancel')),
        ),
        Button(
          disabled: !_canCreate,
          onPressed: _canCreate ? _create : null,
          child: WText(trans('uptizm.teams.create_button')),
        ),
      ],
    );
  }
}
