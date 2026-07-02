import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import 'status_form_support.dart';
import '../../../app/controllers/status_page_controller.dart';
import '../../../app/mocks/billing.dart';
import '../../../app/mocks/status_pages.dart';
import '../../../ui/components/ai_insight/index.dart';
import '../../../ui/components/empty_state/index.dart';
import '../../../ui/components/region_picker/region_picker.dart';
import '../../../ui/components/status_page_preview/index.dart';
import '../../../ui/components/upgrade_nudge/index.dart';
import '../../../ui/layouts/page_container.dart';

/// **The Status Page editor screen (`/status/new` + `/status/:id`).**
///
/// A faithful Flutter port of the React `StatusPageEditor.tsx`: one screen that
/// serves both create and edit. In edit mode it resolves [id] to a fixture via
/// [StatusPageController.configById] (an unknown id falls back to a graceful
/// [EmptyState]); in
/// create mode ([id] `null` or unknown) it starts from the React defaults.
///
/// The body is a two-column responsive split (stacking to one column below the
/// `lg` breakpoint):
///
/// - **LEFT** — the configuration column. In create mode it leads with the
///   "Draft with AI" banner, billing-gated exactly like the incident-detail AI
///   surface: when the current tier unlocks [AiLevel.analysis] the banner offers
///   a Generate action ([aiDraftFor]); otherwise an [UpgradeNudge] names the
///   cheapest plan that does ([planForAiDraft]). Below it: a Branding [Card]
///   (name, domain mode, slug, the eight-swatch brand-color grid, fallback
///   initials, description), a Components [Card] ([RegionPicker] over
///   [monitorRegions]), a Metrics [Card] (only when monitors are assigned:
///   System + Custom [RegionPicker]s over [systemMetricRegions] /
///   [customMetricRegions]), and a Subscriptions [Card] (a [Switch] plus, in
///   edit mode, the subscriber count and a "View subscribers" link).
/// - **RIGHT** — a browser-framed live preview: a dotted title bar with the mono
///   [pageUrl] and a bounded, scrollable [StatusPagePreview] that re-renders
///   live as the draft mutates.
///
/// State is a single mutable [StatusPageConfig] draft; every field edit does
/// `setState(() => _draft = _draft.copyWith(...))`. The name field auto-slugs
/// into the slug until the user edits the slug directly ([_slugEdited]). Save /
/// Create is enabled only while [isConfigValid]; the action shows a
/// [Magic.success] toast and returns to `/status` (mock: nothing persists).
///
/// Logo file-upload is a deliberate mock affordance (initials + brand color
/// only); no file picker is wired (Risk Accepted in the plan).
///
/// ### Example
/// ```dart
/// // Registered as the routed `/status/new` and `/status/:id` content (Step 8):
/// MagicRoute.page('/status/new', () => const StatusPageEditorView());
/// MagicRoute.page('/status/:id', () => StatusPageEditorView(id: id));
/// ```
@immutable
class StatusPageEditorView extends MagicStatefulView<StatusPageController> {
  /// The status-page identifier resolved against the fixtures via
  /// [StatusPageController.configById]. `null` (or an unknown id) puts the
  /// editor in create mode.
  final String? id;

  /// Creates the [StatusPageEditorView] for the given status-page [id].
  const StatusPageEditorView({super.key, this.id});

  @override
  State<StatusPageEditorView> createState() => _StatusPageEditorViewState();
}

class _StatusPageEditorViewState
    extends MagicStatefulViewState<StatusPageController, StatusPageEditorView> {
  /// The route both Save/Create and the breadcrumb return to.
  static const String _listRoute = '/status';

  /// The lock icon standing in for the (mocked) logo-upload affordance.
  static const IconData _uploadIcon = Icons.image_outlined;

  /// The live, mutable draft. Seeded from the resolved fixture (edit) or from
  /// the React defaults (create); every field edit round-trips through
  /// [StatusPageConfig.copyWith].
  late StatusPageConfig _draft;

  /// Whether the resolved id maps to a real fixture (edit mode). `false` puts
  /// the editor in create mode.
  late bool _isEdit;

  /// Whether the user has edited the slug directly. While `false`, typing the
  /// name auto-slugs into the slug; the first manual slug edit latches this
  /// `true` and stops the auto-fill (React `slugEdited`).
  late bool _slugEdited;

  /// Whether the AI draft has been applied, gating the post-generate note
  /// (React `aiApplied`).
  bool _aiApplied = false;

  /// The domain-mode segmented-control options, in [DomainMode] order.
  static const List<DomainMode> _domainModes = <DomainMode>[
    DomainMode.subdomain,
    DomainMode.path,
  ];

  @override
  void initState() {
    Magic.findOrPut(StatusPageController.new);
    super.initState();
    _seedFrom(controller.configById(widget.id));
  }

  @override
  void didUpdateWidget(covariant StatusPageEditorView oldWidget) {
    super.didUpdateWidget(oldWidget);
    // Reseed the draft whenever the resolved id changes so navigating between
    // status pages does not carry a stale draft across (mirrors
    // incident_detail_view's didUpdateWidget reseed).
    if (oldWidget.id != widget.id) {
      _seedFrom(controller.configById(widget.id));
    }
  }

  /// Seeds the draft from [existing] (edit) or the React create defaults.
  ///
  /// Runs from [initState] and [didUpdateWidget]; both schedule their own build,
  /// so state is assigned directly rather than through [setState]. The slug is
  /// treated as already-edited in edit mode so an existing slug never gets
  /// clobbered by a name edit (React `useState(!isNew)` for `slugEdited`).
  void _seedFrom(StatusPageConfig? existing) {
    _aiApplied = false;
    if (existing == null) {
      _isEdit = false;
      _slugEdited = false;
      _draft = StatusPageConfig(
        id: 'draft',
        name: '',
        slug: '',
        domainMode: DomainMode.path,
        brandColor: kBrandColors.first,
        logoText: '',
        description: '',
        monitorIds: const <String>[],
        metricKeys: const <String>[],
        subscriptionsEnabled: true,
      );
      return;
    }
    _isEdit = true;
    _slugEdited = true;
    _draft = existing;
  }

  /// Whether the draft satisfies the Save-enabled rule (name + slug + at least
  /// one assigned monitor).
  bool get _canSave => isConfigValid(_draft);

  /// Applies a copy of the draft, wrapped in [setState].
  void _update(StatusPageConfig next) {
    setState(() => _draft = next);
  }

  /// Handles a name edit: updates the name and, until the slug is manually
  /// edited, keeps the slug auto-synced from the name (React `onName`).
  void _onNameChanged(String value) {
    _update(
      _slugEdited
          ? _draft.copyWith(name: value)
          : _draft.copyWith(name: value, slug: _slugify(value)),
    );
  }

  /// Handles a slug edit: latches [_slugEdited] and stores the slugified value
  /// (React `onChange` on the slug input).
  void _onSlugChanged(String value) {
    setState(() {
      _slugEdited = true;
      _draft = _draft.copyWith(slug: _slugify(value));
    });
  }

  /// Runs the "Draft with AI" mock: replaces the draft with the controller's
  /// AI fill over the currently-assigned monitors and shows the post-generate
  /// note. The draft and its slug latch stay ephemeral here; only the fill
  /// itself is a controller action. The AI draft owns the slug, so it is
  /// treated as already-edited afterwards (React `generateWithAi`).
  void _generateWithAi() {
    setState(() {
      _draft = controller.generateWithAi(_draft.monitorIds);
      _slugEdited = true;
      _aiApplied = true;
    });
  }

  /// Commits the draft via the controller and returns to the list (mock:
  /// nothing persists). Create vs. edit picks the matching toast copy in the
  /// controller.
  void _save() {
    if (_isEdit) {
      controller.save(_draft);
    } else {
      controller.create(_draft);
    }
  }

  /// Navigates to the public preview of the saved page (edit mode only).
  void _viewPublicPage() {
    MagicRoute.to('/status/${_draft.id}/preview');
  }

  /// Slugifies [value] to a URL-safe, hyphen-separated handle capped at 40
  /// characters. Mirrors the React `slugify` (the status-page slug uses `-`
  /// separators, unlike the metric-key `slugify` in monitor_metrics_support).
  String _slugify(String value) {
    final String s = value
        .toLowerCase()
        .replaceAll(RegExp(r'[^a-z0-9]+'), '-')
        .replaceAll(RegExp(r'^-+'), '')
        .replaceAll(RegExp(r'-+$'), '');
    return s.substring(0, s.length < 40 ? s.length : 40);
  }

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the fixture. A supplied-but-unknown id is a broken link, so it
    //    renders a graceful not-found state (mirrors incident_detail_view).
    if (widget.id != null && controller.configById(widget.id) == null) {
      return _buildNotFound();
    }

    // 2. Compose the page body as a Wind flex column: the 24px header rhythm is
    //    carried by gap-6, not a SizedBox spacer.
    return PageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          _buildHeader(),
          _buildBody(),
        ],
      ),
    );
  }

  /// Builds the graceful not-found state for an unknown status-page id.
  Widget _buildNotFound() {
    return PageContainer(
      child: EmptyState(
        icon: Icons.public_off_outlined,
        title: trans('uptizm.status.list_empty_title'),
        description: trans('uptizm.status.list_empty_description'),
        action: Button(
          intent: ButtonIntent.primary,
          onPressed: () => MagicRoute.to(_listRoute),
          child: WText(trans('uptizm.status.editor_breadcrumb_back')),
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Header.
  // ---------------------------------------------------------------------------

  /// Builds the header: a "← Status pages" breadcrumb, the page title, a mono
  /// live [pageUrl] line, and the action row (View public page + Create/Save).
  ///
  /// The mono URL cannot ride PageHeader's `subtitle` (that slot is not mono),
  /// so it renders as a separate leaf line beneath the header, the way
  /// incident_detail_view renders its chip row below the PageHeader.
  Widget _buildHeader() {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        PageHeader(
          title: _isEdit
              ? (_draft.name.isNotEmpty
                    ? _draft.name
                    : trans('uptizm.status.editor_title_edit'))
              : trans('uptizm.status.editor_title_new'),
          backLabel: trans('uptizm.status.editor_breadcrumb_back'),
          backFallback: _listRoute,
          actions: _buildHeaderActions(),
        ),
        WText(
          pageUrl(_draft),
          className: 'font-mono text-xs text-fg-muted',
        ),
      ],
    );
  }

  /// Builds the header action row: a "View public page" secondary button
  /// (disabled in create mode until the page is saved) and the Create/Save
  /// button (disabled until [_canSave]). Auto-width: never `w-full` in the row.
  List<Widget> _buildHeaderActions() {
    return <Widget>[
      Button(
        intent: ButtonIntent.secondary,
        disabled: !_isEdit,
        onPressed: _isEdit ? _viewPublicPage : null,
        child: WText(trans('uptizm.status.editor_form_view_public_page')),
      ),
      Button(
        disabled: !_canSave,
        onPressed: _canSave ? _save : null,
        child: WText(
          _isEdit
              ? trans('uptizm.status.editor_form_save')
              : trans('uptizm.status.editor_form_create_page'),
        ),
      ),
    ];
  }

  // ---------------------------------------------------------------------------
  // Body: the responsive two-column split.
  // ---------------------------------------------------------------------------

  /// Builds the two-column body: the configuration column (LEFT) and the live
  /// preview column (RIGHT), stacking to one column below `lg`.
  Widget _buildBody() {
    return WDiv(
      className: 'flex flex-col lg:flex-row gap-6 items-start',
      children: [
        WDiv(
          className: 'lg:flex-1 min-w-0 w-full flex flex-col gap-6',
          children: _buildConfigColumn(),
        ),
        WDiv(
          className: 'lg:w-[380px] w-full flex flex-col gap-2',
          children: _buildPreviewColumn(),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // LEFT: configuration column.
  // ---------------------------------------------------------------------------

  /// Builds the configuration column: the create-mode AI banner, the post-
  /// generate note, and the Branding / Components / Metrics / Subscriptions
  /// cards. Null-aware elements drop the banners that do not apply.
  List<Widget> _buildConfigColumn() {
    return <Widget>[
      if (!_isEdit) _buildAiBanner(),
      if (_aiApplied) _buildAiAppliedBanner(),
      _buildBrandingCard(),
      _buildComponentsCard(),
      if (_draft.monitorIds.isNotEmpty) _buildMetricsCard(),
      _buildSubscriptionsCard(),
    ];
  }

  /// Builds the create-mode "Draft with AI" surface, billing-gated.
  ///
  /// Mirrors the incident-detail AI gate exactly: when the current tier reaches
  /// [AiLevel.analysis] the Generate banner shows; otherwise an [UpgradeNudge]
  /// names the cheapest plan that unlocks AI drafting ([planForAiDraft]).
  Widget _buildAiBanner() {
    final bool unlocked = currentLimits.ai.index >= AiLevel.analysis.index;
    if (!unlocked) {
      return UpgradeNudge(
        message: trans(
          'uptizm.status.editor_ai_draft_gated',
          {'plan': planForAiDraft().name},
        ),
        requiredPlan: planForAiDraft().name,
        onUpgrade: () => MagicRoute.to('/settings'),
      );
    }
    return AiInsight(
      tone: 'banner',
      label: trans('uptizm.status.editor_ai_draft_banner_label'),
      action: Button(
        intent: ButtonIntent.secondary,
        size: ButtonSize.sm,
        onPressed: _generateWithAi,
        child: WText(trans('uptizm.status.editor_ai_draft_button')),
      ),
      child: WText(
        trans('uptizm.status.editor_ai_draft_banner_text'),
        className: 'text-sm leading-relaxed text-fg',
      ),
    );
  }

  /// Builds the post-generate "Drafted from your monitors" note.
  Widget _buildAiAppliedBanner() {
    return AiInsight(
      child: WText(
        trans('uptizm.status.editor_ai_applied_banner'),
        className: 'text-sm leading-relaxed text-fg',
      ),
    );
  }

  /// Builds the Branding card: name, domain mode, slug, brand-color grid,
  /// fallback initials (with the mocked upload affordance), and description.
  Widget _buildBrandingCard() {
    return Card(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-5',
        children: [
          _buildSectionHeading(trans('uptizm.status.editor_section_branding')),
          _buildNameField(),
          _buildDomainModeField(),
          _buildSlugField(),
          _buildBrandColorField(),
          _buildLogoField(),
          _buildDescriptionField(),
        ],
      ),
    );
  }

  /// Builds the Name input, which auto-slugs into the slug until the slug is
  /// edited.
  Widget _buildNameField() {
    return MagicFormField(
      label: trans('uptizm.status.editor_form_name_label'),
      child: Input(
        value: _draft.name,
        onChanged: _onNameChanged,
        placeholder: trans('uptizm.status.editor_form_name_placeholder'),
      ),
    );
  }

  /// Builds the domain-mode segmented control (Subdomain / Path). Maps the
  /// tapped index back to the [DomainMode] via [_domainModes].
  Widget _buildDomainModeField() {
    return MagicFormField(
      label: trans('uptizm.status.editor_form_how_served_label'),
      child: SegmentedControl<String>(
        options: _domainModes.map((DomainMode m) => m.label).toList(),
        selectedIndex: _domainModes.indexOf(_draft.domainMode),
        onChanged: (int index) =>
            _update(_draft.copyWith(domainMode: _domainModes[index])),
      ),
    );
  }

  /// Builds the mono slug input, hinting the full public URL. Editing it latches
  /// [_slugEdited] and stops the name auto-fill.
  Widget _buildSlugField() {
    return MagicFormField(
      label: trans('uptizm.status.editor_form_slug_label'),
      hint: pageUrl(_draft),
      child: Input(
        value: _draft.slug,
        onChanged: _onSlugChanged,
        placeholder: trans('uptizm.status.editor_form_slug_placeholder'),
        className: 'font-mono',
      ),
    );
  }

  /// Builds the eight-swatch brand-color grid.
  ///
  /// Each swatch is a tappable circle: a [WButton] wrapping a
  /// [WDiv] with the raw brand color as its background. The selected swatch
  /// gets a ring. [StatusPageConfig.brandColor] + [kBrandColors] are the ONLY
  /// raw colors on this screen (content data, the sanctioned exception).
  Widget _buildBrandColorField() {
    return MagicFormField(
      label: trans('uptizm.status.editor_form_brand_color_label'),
      child: WDiv(
        className: 'flex flex-row wrap gap-2',
        children: <Widget>[
          for (final Color swatch in kBrandColors) _buildSwatch(swatch),
        ],
      ),
    );
  }

  /// Builds a single tappable brand-color swatch circle.
  ///
  /// The React source draws the selected state with `ring-2 ring-fg
  /// ring-offset-2`; Wind's ring parser resolves palette/hex colors, not the
  /// semantic `fg` alias, so the selection ring is expressed as a token-clean
  /// bordered wrapper (`border-2 border-primary` + padding gap) around the
  /// raw-brand-color swatch circle instead. Only the swatch fill is a raw color.
  Widget _buildSwatch(Color swatch) {
    final bool selected = _draft.brandColor.toARGB32() == swatch.toARGB32();
    return WButton(
      onTap: () => _update(_draft.copyWith(brandColor: swatch)),
      child: WDiv(
        className: selected
            ? 'rounded-full border-2 border-primary p-0.5'
            : 'rounded-full border-2 border-transparent p-0.5',
        child: WDiv(
          backgroundColor: swatch,
          className: 'size-7 rounded-full',
        ),
      ),
    );
  }

  /// Builds the fallback-initials field plus the mocked logo-upload affordance.
  ///
  /// The upload button is a disabled mock (initials + color only; no file
  /// picker is wired, Risk Accepted). The initials input caps at 2 characters.
  Widget _buildLogoField() {
    final String initials = _draft.logoText.isNotEmpty
        ? _draft.logoText
        : (_draft.name.isNotEmpty ? _draft.name.substring(0, 1) : 'A');
    return MagicFormField(
      label: trans('uptizm.status.editor_form_logo_text_label'),
      hint: trans('uptizm.status.editor_form_logo_text_hint'),
      child: WDiv(
        className: 'flex flex-col gap-3',
        children: <Widget>[
          WDiv(
            className: 'flex flex-row items-center gap-3',
            children: <Widget>[
              WDiv(
                backgroundColor: _draft.brandColor,
                className:
                    'size-12 shrink-0 rounded-lg flex items-center justify-center',
                child: WText(
                  initials,
                  className: 'text-base font-bold text-white',
                ),
              ),
              Button(
                intent: ButtonIntent.secondary,
                size: ButtonSize.sm,
                disabled: true,
                onPressed: null,
                child: WDiv(
                  className: 'flex flex-row items-center gap-1.5',
                  children: <Widget>[
                    WIcon(_uploadIcon, className: 'text-sm'),
                    WText(trans('uptizm.status.editor_form_logo_label')),
                  ],
                ),
              ),
            ],
          ),
          Input(
            value: _draft.logoText,
            onChanged: (String value) => _update(
              _draft.copyWith(
                logoText: value.length > 2 ? value.substring(0, 2) : value,
              ),
            ),
            className: 'max-w-20',
          ),
        ],
      ),
    );
  }

  /// Builds the description textarea.
  Widget _buildDescriptionField() {
    return MagicFormField(
      label: trans('uptizm.status.editor_form_description_label'),
      child: Textarea(
        value: _draft.description,
        onChanged: (String value) =>
            _update(_draft.copyWith(description: value)),
        placeholder: trans('uptizm.status.editor_form_description_placeholder'),
      ),
    );
  }

  /// Builds the Components card: a [RegionPicker] over every monitor, bound to
  /// the draft's [StatusPageConfig.monitorIds].
  Widget _buildComponentsCard() {
    return Card(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-3',
        children: <Widget>[
          _buildSectionHeading(
            trans('uptizm.status.editor_section_components'),
            hint: trans('uptizm.status.editor_section_components_hint'),
          ),
          RegionPicker(
            regions: monitorRegions(),
            value: _draft.monitorIds,
            onChanged: (List<String> next) =>
                _update(_draft.copyWith(monitorIds: next)),
          ),
        ],
      ),
    );
  }

  /// Builds the Metrics card (rendered only when monitors are assigned): the
  /// System and Custom metric pickers, each bound to the draft's
  /// [StatusPageConfig.metricKeys].
  ///
  /// Each picker resolves its options from the assigned monitors; when a monitor
  /// set exposes no system (or no custom) metric, the picker is replaced with an
  /// explanatory line (React's `systemOptions.length > 0 ? … : <p>`).
  Widget _buildMetricsCard() {
    final List<Region> systemOptions = systemMetricRegions(_draft.monitorIds);
    final List<Region> customOptions = customMetricRegions(_draft.monitorIds);
    return Card(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-5',
        children: <Widget>[
          _buildSectionHeading(
            trans('uptizm.status.editor_section_metrics'),
            hint: trans('uptizm.status.editor_section_metrics_hint'),
          ),
          MagicFormField(
            label: 'System',
            child: systemOptions.isNotEmpty
                ? RegionPicker(
                    regions: systemOptions,
                    value: _draft.metricKeys,
                    onChanged: (List<String> next) =>
                        _update(_draft.copyWith(metricKeys: next)),
                  )
                : WText(
                    trans('uptizm.status.editor_form_no_system_metrics'),
                    className: 'text-sm text-fg-muted',
                  ),
          ),
          MagicFormField(
            label: 'Custom',
            child: customOptions.isNotEmpty
                ? RegionPicker(
                    regions: customOptions,
                    value: _draft.metricKeys,
                    onChanged: (List<String> next) =>
                        _update(_draft.copyWith(metricKeys: next)),
                  )
                : WText(
                    trans('uptizm.status.editor_form_no_custom_metrics'),
                    className: 'text-sm text-fg-muted',
                  ),
          ),
        ],
      ),
    );
  }

  /// Builds the Subscriptions card: the allow-subscriptions [Switch] and, in
  /// edit mode, the subscriber count plus a "View subscribers" link.
  Widget _buildSubscriptionsCard() {
    return Card(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-4',
        children: <Widget>[
          _buildSectionHeading(
            trans('uptizm.status.editor_section_subscriptions'),
            hint: trans('uptizm.status.editor_section_subscriptions_hint'),
          ),
          _buildSwitchRow(
            label: trans('uptizm.status.editor_form_allow_subscriptions_label'),
            value: _draft.subscriptionsEnabled,
            onChanged: (bool value) =>
                _update(_draft.copyWith(subscriptionsEnabled: value)),
          ),
          if (_isEdit) _buildSubscriberSummary(),
        ],
      ),
    );
  }

  /// Builds the edit-mode subscriber summary: the count line and a "View
  /// subscribers" secondary button routing to the subscribers screen.
  Widget _buildSubscriberSummary() {
    final int count = controller.subscribersFor(_draft.id).length;
    final String unit = count == 1
        ? trans('uptizm.status.editor_form_subscribers_count_singular')
        : trans('uptizm.status.editor_form_subscribers_count');
    return WDiv(
      className:
          'flex flex-row items-center justify-between gap-3 '
          'border-t border-color-border pt-4',
      children: <Widget>[
        WDiv(
          className: 'min-w-0 flex flex-col gap-0.5',
          children: <Widget>[
            WText(
              '$count $unit',
              className: 'text-sm font-medium text-fg',
            ),
            WText(
              trans('uptizm.status.editor_form_subscribers_hint'),
              className: 'text-xs text-fg-muted',
            ),
          ],
        ),
        Button(
          intent: ButtonIntent.secondary,
          size: ButtonSize.sm,
          onPressed: () => MagicRoute.to('/status/${_draft.id}/subscribers'),
          child: WText(
            trans('uptizm.status.editor_form_view_subscribers_button'),
          ),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // RIGHT: live preview column.
  // ---------------------------------------------------------------------------

  /// Builds the live-preview column: a "Live preview" label above a browser-
  /// framed [StatusPagePreview] that re-renders as the draft mutates.
  List<Widget> _buildPreviewColumn() {
    return <Widget>[
      WText(
        trans('uptizm.status.editor_preview_live_heading'),
        className: 'text-xs font-medium uppercase tracking-wide text-fg-muted',
      ),
      _buildBrowserFrame(),
    ];
  }

  /// Builds the browser frame: a title bar (three dots + the mono live URL) over
  /// a bounded, scrollable preview surface.
  Widget _buildBrowserFrame() {
    return WDiv(
      className:
          'rounded-xl border border-color-border overflow-hidden '
          'bg-surface-container',
      children: <Widget>[
        _buildBrowserBar(),
        WDiv(
          className: 'bg-surface p-5',
          child: SizedBox(
            height: 600,
            child: SingleChildScrollView(
              child: StatusPagePreview(config: _draft),
            ),
          ),
        ),
      ],
    );
  }

  /// Builds the browser title bar: three muted dots and the mono live URL pill.
  Widget _buildBrowserBar() {
    return WDiv(
      className:
          'flex flex-row items-center gap-2 border-b border-color-border '
          'bg-surface-container-high px-3 py-2',
      children: <Widget>[
        WDiv(
          className: 'flex flex-row gap-1',
          children: <Widget>[
            WDiv(className: 'size-2.5 rounded-full bg-surface-container-high'),
            WDiv(className: 'size-2.5 rounded-full bg-surface-container-high'),
            WDiv(className: 'size-2.5 rounded-full bg-surface-container-high'),
          ],
        ),
        WDiv(
          className:
              'min-w-0 flex-1 rounded bg-surface px-2 py-0.5 text-center '
              'font-mono text-xs text-fg-muted',
          child: WText(pageUrl(_draft)),
        ),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Small helpers.
  // ---------------------------------------------------------------------------

  /// Builds a card section heading: a bold title plus an optional muted hint.
  Widget _buildSectionHeading(String title, {String? hint}) {
    return WDiv(
      className: 'flex flex-col gap-1',
      children: <Widget>[
        WText(title, className: 'text-sm font-semibold text-fg'),
        if (hint != null) WText(hint, className: 'text-sm text-fg-muted'),
      ],
    );
  }

  /// Builds a labelled switch row: the [Switch] toggle followed by its text
  /// label (the Dart [Switch] is toggle-only, so the label renders beside it,
  /// mirroring the monitor_form / incident_create switch-row helpers).
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
