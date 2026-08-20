import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:flutter/foundation.dart' show listEquals;
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'status_form_support.dart';
import '../../../app/support/refetches_on_mount.dart';
import '../../../app/support/submits_once.dart';
import '../../../app/controllers/status_page_controller.dart';
import '../../../app/controllers/entitlement_controller.dart';
import '../../../app/controllers/monitor_controller.dart';
import '../../../app/enums/ai_level.dart' show AiLevel;
import '../../../app/enums/domain_mode.dart' show DomainMode;
import '../../../app/enums/status_page_preview_status.dart'
    show StatusPagePreviewStatus;
import '../../../app/support/billing_types.dart' show PlanLimits;
import '../../../app/support/status_page_support.dart' show pageUrl;
import '../../../app/models/monitor.dart';
import '../../../app/models/status_page.dart';
import '../../../ui/components/ai_insight/index.dart';
import '../../../ui/components/region_picker/region_picker.dart';
import '../../../ui/components/status_page_preview/index.dart';

/// **The Status Page editor screen (`/status/new` + `/status/:id`).**
///
/// A faithful Flutter port of the React `StatusPageEditor.tsx`: one screen that
/// serves both create and edit. In edit mode it resolves [id] to a fixture via
/// [StatusPageController.configById] (an unknown id falls back to a graceful
/// [MSEmptyState]); in
/// create mode ([id] `null` or unknown) it starts from the React defaults.
///
/// The body is a two-column responsive split (stacking to one column below the
/// `lg` breakpoint):
///
/// - **LEFT**: the configuration column. In create mode it leads with the
///   "Draft with AI" banner, billing-gated exactly like the incident-detail AI
///   surface: when the team's real tier unlocks [AiLevel.analysis] the banner
///   offers a Generate action ([aiDraftFor]); otherwise an [MSUpgradeNudge]
///   names the cheapest plan that does (via
///   [EntitlementController.planNameUnlocking]).
///   Below it: a Branding [Card]
///   (name, domain mode, slug, the eight-swatch brand-color grid, fallback
///   initials, description), a Components [Card] ([RegionPicker] over
///   [monitorRegions]), a Metrics [Card] (only when monitors are assigned:
///   System + Custom [RegionPicker]s over [systemMetricRegions] /
///   [customMetricRegions]), and a Subscriptions [Card] (a [Switch] plus, in
///   edit mode, the subscriber count and a "View subscribers" link).
/// - **RIGHT**: a browser-framed preview, whose SOURCE depends on whether the
///   form is dirty. With unsaved edits it is the Flutter [StatusPagePreview]
///   re-rendering live off [_draftPage] as the draft mutates, labelled as a
///   draft. Once saved it is a PNG of the REAL public page, rendered by the
///   backend in a headless browser and fetched through a signed URL, labelled as
///   what customers see and stamped with when it was rendered. The two must
///   never swap labels: the draft is an approximation of unsaved state, and only
///   the PNG is the customer view. See [_buildPreviewBody] for all six states.
///
/// State is a set of individual draft fields (name, slug, domain mode, brand
/// color, logo text, description, assigned monitor ids, metric keys, and the
/// subscriptions flag); every field edit runs `setState` on the field it
/// touches. The name field auto-slugs into the slug until the user edits the
/// slug directly ([_slugEdited]). The draft is projected into a [StatusPage]
/// through [_draftPage] for the read-side helpers ([pageUrl]) and the live
/// [StatusPagePreview]; Save / Create hands that same projection to the
/// controller and is enabled only while [_isDirty].
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
    extends MagicStatefulViewState<StatusPageController, StatusPageEditorView>
    with
        RefetchesOnMount<StatusPageController, StatusPageEditorView>,
        SubmitsOnce<StatusPageEditorView> {
  /// The route both Save/Create and the breadcrumb return to.
  static const String _listRoute = '/status';

  /// The lock icon standing in for the (mocked) logo-upload affordance.
  static const IconData _uploadIcon = Icons.image_outlined;

  /// Age past which a `completed` render's rendered-at stamp pairs with the
  /// may-be-out-of-date chip (D4/D9: "beyond roughly 15 minutes").
  static const int _previewAgeChipThresholdMinutes = 15;

  /// Height of the preview pane's waiting skeleton, shared by the `rendering`
  /// state and the still-downloading PNG so the pane does not jump between
  /// them. Explicit because a heightless skeleton lays out 0px tall, which
  /// leaves the wait invisible while `find.byType` still passes.
  static const double _previewSkeletonHeight = 220;

  // ---------------------------------------------------------------------------
  // Draft fields.
  //
  // The editable status-page draft, held as individual fields rather than a DTO
  // (the `StatusPageConfig` value object was deleted). Seeded from the resolved
  // model (edit) or the React defaults (create); each edit runs `setState` on
  // the field it touches. [_draftPage] projects them into a [StatusPage] for
  // the read helpers and the live preview.
  // ---------------------------------------------------------------------------

  /// The status-page id (`'draft'` in create mode).
  late String _draftId;

  /// The page name; auto-slugs into [_slug] until the slug is edited.
  late String _name;

  /// The URL-safe slug.
  late String _slug;

  /// How the page is served (subdomain vs. path).
  late DomainMode _domainMode;

  /// The per-page brand tint (content data, the sanctioned raw-color exception).
  late Color _brandColor;

  /// The one-to-two character logo fallback text.
  late String _logoText;

  /// The short description shown under the page name.
  late String _description;

  /// The assigned monitor ids (public components).
  late List<String> _monitorIds;

  /// Whether anyone with the URL may read this page.
  ///
  /// The field the editor never had, which is why a page created here could not be
  /// published at all: `is_public` defaults to false in the database, nothing sent it,
  /// and the public route is fail-closed, so the operator's own page answered 404 with no
  /// way in the product to change it.
  late bool _isPublic;

  /// Whether email subscriptions are enabled.
  late bool _subscriptionsEnabled;

  /// Whether the resolved id maps to a real fixture (edit mode). `false` puts
  /// the editor in create mode.
  late bool _isEdit;

  /// Whether a logo upload or removal is in flight.
  ///
  /// Its own flag rather than the form's: those writes do not go through Save,
  /// so the form's process state never covers them, and a second tap while the
  /// first request is open would race two writes at one path.
  bool _logoBusy = false;

  /// Whether the user has edited the slug directly. While `false`, typing the
  /// name auto-slugs into the slug; the first manual slug edit latches this
  /// `true` and stops the auto-fill (React `slugEdited`).
  late bool _slugEdited;

  /// Whether the AI draft has been applied, gating the post-generate note
  /// (React `aiApplied`).
  bool _aiApplied = false;

  /// Inline validation error for the Name field, or null when it is valid. Set
  /// on save when the required name is blank, and by a server 422 that rejects
  /// `name`. Cleared when the name is edited.
  String? _nameError;

  /// Inline validation error for the Slug field, or null when it is valid. Set
  /// on save when the required slug is blank (the backend requires a slug), and
  /// by a server 422 that rejects `slug`. Cleared when the name auto-fills the
  /// slug or the slug is edited.
  String? _slugError;

  /// The server's reason for refusing the visibility choice, painted under the
  /// control. The backend rejects only the PRIVATE direction, and only when the
  /// team's plan does not include private pages.
  String? _isPublicError;

  /// Inline validation error for the Components picker, or null when it is
  /// valid. Set on save when no monitor is assigned (a page needs at least one
  /// public component). Cleared when the assigned-monitor selection changes.
  String? _componentsError;

  /// The domain-mode segmented-control options, in [DomainMode] order.
  static const List<DomainMode> _domainModes = <DomainMode>[
    DomainMode.subdomain,
    DomainMode.path,
  ];

  /// The team's monitor roster, backing the Components picker.
  ///
  /// Resolved through the IoC container so the editor shares the one roster the
  /// monitor views keep warm. The picker used to be built from a design-lab
  /// fixture, which offered monitors the team did not own and left the page's
  /// real components unselectable.
  final MonitorController _monitors = Magic.findOrPut(MonitorController.new);

  @override
  void initState() {
    Magic.findOrPut(StatusPageController.new);
    super.initState();
    _seedFrom(controller.configById(widget.id));
    controller.addListener(_seedOnceResolved);

    // Load the roster explicitly and listen for it: magic only fires `onInit`
    // for a view's BACKING controller, which here is the status-page one, so the
    // monitor controller's own bootstrap never runs and the picker would render
    // no options.
    _monitors.addListener(_onMonitorsChanged);
    _monitors.reload();
  }

  @override
  void dispose() {
    controller.removeListener(_seedOnceResolved);
    _monitors.removeListener(_onMonitorsChanged);
    super.dispose();
  }

  /// Re-render the Components picker when the monitor roster lands.
  void _onMonitorsChanged() {
    if (mounted) setState(() {});
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

  /// Seeds the draft the first time the routed page resolves out of the roster.
  ///
  /// [initState] can only read what is already cached, and on a direct load of
  /// `/status/<id>` (a reload, or a link someone shared) the roster fetch is
  /// still in flight, so the editor seeded an EMPTY draft and rendered "New
  /// status page" with a Create button for a page that already exists. Saving
  /// from there would have created a duplicate. Once the roster lands this
  /// reseeds, and it stops listening after the first hit so it can never
  /// clobber edits the operator has since typed.
  void _seedOnceResolved() {
    if (!mounted || _isEdit || widget.id == null) return;

    final StatusPage? resolved = controller.configById(widget.id);
    if (resolved == null) return;

    controller.removeListener(_seedOnceResolved);
    setState(() => _seedFrom(resolved));
  }

  /// Seeds the draft fields from [existing] (edit) or the React create defaults.
  ///
  /// Runs from [initState] and [didUpdateWidget]; both schedule their own build,
  /// so state is assigned directly rather than through [setState]. The slug is
  /// treated as already-edited in edit mode so an existing slug never gets
  /// clobbered by a name edit (React `useState(!isNew)` for `slugEdited`). The
  /// monitor-id list is copied so editing the draft never mutates the
  /// controller's cached model.
  void _seedFrom(StatusPage? existing) {
    _aiApplied = false;
    _nameError = null;
    _slugError = null;
    _componentsError = null;
    if (existing == null) {
      _isEdit = false;
      _slugEdited = false;
      _draftId = 'draft';
      _name = '';
      _slug = '';
      _domainMode = DomainMode.path;
      _brandColor = kBrandColors.first;
      _logoText = '';
      _description = '';
      _monitorIds = <String>[];
      _isPublic = true;
      _subscriptionsEnabled = true;
      return;
    }
    _isEdit = true;
    _slugEdited = true;
    _draftId = existing.id;
    _name = existing.name ?? '';
    _slug = existing.slug ?? '';
    _domainMode = existing.domainMode;
    _brandColor = existing.brandColor;
    _logoText = existing.logoText ?? '';
    _description = existing.description ?? '';
    _monitorIds = List<String>.of(existing.monitorIds);
    _isPublic = existing.isPublic;
    _subscriptionsEnabled = existing.subscriptionsEnabled;
  }

  /// The current draft projected into a [StatusPage], for the read-side helpers
  /// ([pageUrl]), the live [StatusPagePreview], and the controller write
  /// actions.
  /// The live public URL of the SAVED page, but only while the draft slug still
  /// matches the saved one.
  ///
  /// The URL is the backend's fact (resolved from its own public route), so it
  /// is the address to show and to open. It is deliberately dropped the moment
  /// the operator edits the slug: the saved URL would then point at the old
  /// handle while the form shows the new one, and [pageUrl] falls back to the
  /// host-less `/s/<slug>` shape, which reads as "this is what it will be"
  /// rather than as a link that works right now.
  String? get _savedPublicUrl {
    final StatusPage? saved = controller.configById(widget.id);
    if (saved == null || saved.slug != _slug) return null;

    final String? url = saved.publicUrl;

    return (url == null || url.isEmpty) ? null : url;
  }

  /// Whether the draft differs from the saved page, or there is no saved page
  /// at all yet (create mode has nothing to be clean against).
  ///
  /// Gates D2's hybrid: while dirty, the preview pane renders the Flutter
  /// approximation fed by [_draftPage] under an explicit DRAFT label; the
  /// customer-view label (paired with the real backend-rendered PNG) never
  /// shows while dirty, so an in-progress edit can never be mistaken for what
  /// customers actually see.
  bool get _isDirty {
    if (!_isEdit) return true;

    final StatusPage? saved = controller.configById(widget.id);
    if (saved == null) return true;

    return _name != (saved.name ?? '') ||
        _slug != (saved.slug ?? '') ||
        _domainMode != saved.domainMode ||
        _brandColor.toARGB32() != saved.brandColor.toARGB32() ||
        _logoText != (saved.logoText ?? '') ||
        _description != (saved.description ?? '') ||
        !listEquals(_monitorIds, saved.monitorIds) ||
        _isPublic != saved.isPublic ||
        _subscriptionsEnabled != saved.subscriptionsEnabled;
  }

  StatusPage get _draftPage {
    return StatusPage.fromMap(<String, dynamic>{
      'id': _draftId,
      'name': _name,
      'slug': _slug,
      'public_url': _savedPublicUrl,
      'domain_mode': _domainMode.name,
      'brand_color':
          '#${_brandColor.toARGB32().toRadixString(16).substring(2)}',
      'logo_text': _logoText,
      'description': _description,
      'subscriptions_enabled': _subscriptionsEnabled,
      // Carry each selected monitor's name and live status, not just its id: the
      // live preview pane renders this draft through StatusPage.components, and
      // an id-only entry would show a nameless component with no health. The
      // pivot shape matches StatusPageResource so the draft and a saved page
      // render identically.
      'monitors': <Map<String, dynamic>>[
        for (int i = 0; i < _monitorIds.length; i++)
          () {
            final String id = _monitorIds[i];
            final Monitor? monitor = _monitors.monitorById(id);
            return <String, dynamic>{
              'id': id,
              'name': monitor?.name,
              'display_order': i,
              'last_status': monitor?.lastStatus,
            };
          }(),
      ],
      'is_public': _isPublic,
    });
  }

  /// Handles a name edit: updates the name and, until the slug is manually
  /// edited, keeps the slug auto-synced from the name (React `onName`).
  void _onNameChanged(String value) {
    setState(() {
      _name = value;
      _nameError = null;
      if (!_slugEdited) {
        _slug = _slugify(value);
        _slugError = null;
      }
    });
  }

  /// Handles a slug edit: latches [_slugEdited] and stores the slugified value
  /// (React `onChange` on the slug input).
  void _onSlugChanged(String value) {
    setState(() {
      _slugEdited = true;
      _slug = _slugify(value);
      _slugError = null;
    });
  }

  /// Runs the "Draft with AI" mock: reads the controller's AI fill (over the
  /// currently-assigned monitors) into the draft fields and shows the
  /// post-generate note. The AI draft owns the slug, so it is treated as
  /// already-edited afterwards (React `generateWithAi`).
  void _generateWithAi() {
    final StatusPage draft = controller.generateWithAi(_monitorIds);
    setState(() {
      _name = draft.name ?? '';
      _slug = draft.slug ?? '';
      _domainMode = draft.domainMode;
      _brandColor = draft.brandColor;
      _logoText = draft.logoText ?? '';
      _description = draft.description ?? '';
      _monitorIds = List<String>.of(draft.monitorIds);
      _subscriptionsEnabled = draft.subscriptionsEnabled;
      _slugEdited = true;
      _aiApplied = true;
    });
  }

  /// Commits the draft via the controller and returns to the list on success.
  ///
  /// Runs the client-side required checks first (name, slug, and at least one
  /// assigned component), painting each field's inline error without a round
  /// trip. Only when they pass does it await the matching controller write
  /// (create vs. edit); a non-empty result (a server 422) is a field-error map
  /// keyed by the posted wire field names, which [_applyServerErrors] paints
  /// under the matching fields. A returned key the editor owns no slot for is
  /// surfaced as the generic error toast.
  Future<void> _save() async {
    if (!_validateClientSide()) return;

    final Map<String, String> serverErrors = _isEdit
        ? await controller.save(_draftPage)
        : await controller.create(_draftPage);
    if (!mounted || serverErrors.isEmpty) return;

    final Map<String, String> unmapped = _applyServerErrors(serverErrors);
    if (unmapped.isNotEmpty) {
      Magic.error(
        trans('uptizm.status.list_error_load_title'),
        unmapped.values.first,
      );
    }
  }

  /// Runs every client-side required check, painting each field's inline error
  /// slot, and returns whether the draft may be saved.
  ///
  /// Checks the required name and slug (both backend-required) and that at
  /// least one component is assigned. Every slot is always written (a passing
  /// check clears its slot) so a previously shown error never lingers after a
  /// corrected resubmit.
  bool _validateClientSide() {
    final String? nameError = _name.trim().isEmpty
        ? trans('uptizm.status.form_name_error_required')
        : null;
    final String? slugError = _slug.trim().isEmpty
        ? trans('uptizm.status.form_slug_error_required')
        : null;
    final String? componentsError = _monitorIds.isEmpty
        ? trans('uptizm.status.form_components_error_required')
        : null;

    setState(() {
      _nameError = nameError;
      _slugError = slugError;
      _componentsError = componentsError;
    });

    return nameError == null && slugError == null && componentsError == null;
  }

  /// Routes a backend 422 field-error map (keyed by the wire field names the
  /// editor posts) into the inline error slots, returning the entries that map
  /// to no known field so the caller can surface them another way.
  Map<String, String> _applyServerErrors(Map<String, String> errors) {
    final Map<String, String> unmapped = {};
    setState(() {
      for (final MapEntry<String, String> entry in errors.entries) {
        switch (entry.key) {
          case 'name':
            _nameError = entry.value;
          case 'slug':
            _slugError = entry.value;
          case 'is_public':
            _isPublicError = entry.value;
          default:
            unmapped[entry.key] = entry.value;
        }
      }
    });
    return unmapped;
  }

  /// Navigates to the public preview of the saved page (edit mode only).
  /// Opens the page the public actually sees.
  ///
  /// This used to route to the in-app mockup at `/status/:id/preview`, so a
  /// button labelled "View public page" never showed the public page, and the
  /// real address was unreachable from anywhere in the app: an operator had no
  /// way to obtain the link they must hand to customers. The mockup is already
  /// rendered inline beside the form, so nothing is lost by sending this to the
  /// live page; it stays the fallback for a draft that has no URL yet.
  Future<void> _viewPublicPage() async {
    final String? url = _savedPublicUrl;
    if (url == null) {
      MagicRoute.to('/status/$_draftId/preview');

      return;
    }

    final bool opened = await Launch.url(url);
    if (opened) return;

    Log.error('[StatusPageEditorView._viewPublicPage] could not open $url');
    Magic.error(
      trans('uptizm.status.editor_form_view_public_page'),
      trans('uptizm.status.editor_open_failed_description', {'url': url}),
    );
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

  /// Refetch on every mount: the backing controller loads in `onInit`, which
  /// magic fires only once per controller instance, so opening this screen would
  /// otherwise render whatever the roster held when it was first fetched. A
  /// prefilled form is the sharp edge here, since it writes what it shows back on
  /// save. See [RefetchesOnMount].
  ///
  /// In edit mode the roster read alone is not enough, and the ORDER of these
  /// two is load-bearing. `GET /status-pages` (which [StatusPageController.reload]
  /// calls, and which overwrites the whole roster) never carries
  /// `preview_image_url`: the signed URL is a capability, so D5 keeps it out of
  /// list responses and emits it from `show` only. The show-derived read
  /// therefore has to land AFTER the roster overwrite, or the roster would wipe
  /// the preview fields this pane renders and every open would show the
  /// customer-view heading with no image beneath it.
  @override
  Future<void> refetch() async {
    // `ensureFresh` for the roster, because on the mount that CREATES the
    // controller `onInit` has already started the same read and both firing
    // sent it twice. The show read below is NOT a duplicate of anything and
    // still runs on every mount, which is why this override cannot simply be
    // skipped on the first one (an earlier attempt did exactly that and opened
    // the editor with no preview image).
    await controller.ensureFresh();

    final String? id = widget.id;
    if (id == null) return;

    await controller.reloadPage(id);
  }

  @override
  Widget build(BuildContext context) {
    // 1. Resolve the page. A supplied-but-unknown id is a broken link, so it
    //    renders a graceful not-found state (mirrors incident_detail_view). But
    //    "unknown" only holds once the roster read has answered: this editor
    //    already listens for that read to seed its draft (`_seedOnceResolved`),
    //    so firing not-found before it lands contradicted the screen's own
    //    seeding logic and told the operator their page was gone.
    if (widget.id != null && controller.configById(widget.id) == null) {
      return controller.isFirstLoad ? _buildPending() : _buildNotFound();
    }

    // 2. Compose the page body as a Wind flex column: the 24px header rhythm is
    //    carried by gap-6, not a SizedBox spacer.
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [_buildHeader(), _buildBody()],
      ),
    );
  }

  /// Builds the pending state shown while the roster read that will decide
  /// whether this page exists is still in flight.
  ///
  /// Every [MSSkeleton] carries an explicit height: it wraps a childless `WDiv`
  /// with nothing of its own to measure, so one without a height lays out 0px
  /// tall and the operator sees a blank screen instead of a placeholder.
  Widget _buildPending() {
    return MSPageContainer(
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          MSPageHeader(
            title: trans('common.loading'),
            backLabel: trans('uptizm.status.editor_breadcrumb_back'),
            backFallback: _listRoute,
          ),
          WDiv(
            className: 'flex flex-col gap-4',
            children: const [
              MSSkeleton(height: 56),
              MSSkeleton(height: 56),
              MSSkeleton(height: 160),
            ],
          ),
        ],
      ),
    );
  }

  /// Builds the graceful not-found state for a status-page id the answered
  /// roster does not carry.
  Widget _buildNotFound() {
    return MSPageContainer(
      child: MSEmptyState(
        icon: Icons.public_off_outlined,
        title: trans('uptizm.status.list_empty_title'),
        description: trans('uptizm.status.list_empty_description'),
        action: MSButton(
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
        MSPageHeader(
          title: _isEdit
              ? (_name.isNotEmpty
                    ? _name
                    : trans('uptizm.status.editor_title_edit'))
              : trans('uptizm.status.editor_title_new'),
          backLabel: trans('uptizm.status.editor_breadcrumb_back'),
          backFallback: _listRoute,
          actions: _buildHeaderActions(),
        ),
        WText(
          pageUrl(_draftPage),
          className: 'font-mono text-xs text-fg-muted',
        ),
      ],
    );
  }

  /// Builds the header action row: a "View public page" secondary button
  /// (disabled in create mode until the page is saved) and the Create/Save
  /// button. The Save button is always enabled so a blank required field
  /// surfaces its inline error on save (via [_save]) rather than silently
  /// locking the button. Auto-width: never `w-full` in the row.
  List<Widget> _buildHeaderActions() {
    return <Widget>[
      MSButton(
        intent: ButtonIntent.secondary,
        disabled: !_isEdit,
        onPressed: _isEdit ? _viewPublicPage : null,
        child: WText(trans('uptizm.status.editor_form_view_public_page')),
      ),
      MSButton(
        // `isLoading` is the guard, not just the spinner: WButton drops its
        // onTap while loading, so a double tap on Create cannot create two
        // status pages (each counting against the plan limit).
        isLoading: isSubmitting,
        onPressed: () => submitOnce(_save),
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
  /// generate note, and the Branding / Components / Subscriptions cards.
  /// Null-aware elements drop the banners that do not apply.
  ///
  /// There is no Metrics card. Its System and Custom pickers resolved fixture
  /// metrics keyed by fixture monitor ids, so they were empty or wrong for a real
  /// monitor, and nothing renders published metrics now that the preview's
  /// fabricated metrics grid is gone. A control that publishes a key no surface
  /// reads is a dead control, so it was removed rather than left in place.
  List<Widget> _buildConfigColumn() {
    return <Widget>[
      if (!_isEdit) _buildAiBanner(),
      if (_aiApplied) _buildAiAppliedBanner(),
      _buildBrandingCard(),
      _buildComponentsCard(),
      _buildVisibilityCard(),
      _buildSubscriptionsCard(),
    ];
  }

  /// Builds the create-mode "Draft with AI" surface, billing-gated.
  ///
  /// Mirrors the incident-detail AI gate exactly: when the team's real tier
  /// reaches [AiLevel.analysis] the Generate banner shows; otherwise an
  /// [MSUpgradeNudge] names the cheapest plan that unlocks AI drafting (via
  /// [EntitlementController.planNameUnlocking]). Wrapped in a [ListenableBuilder]
  /// so it re-gates the moment the real plan lands.
  Widget _buildAiBanner() {
    return ListenableBuilder(
      listenable: EntitlementController.instance,
      builder: (context, _) {
        final entitlement = EntitlementController.instance;
        if (!entitlement.aiLevelAllows(AiLevel.analysis)) {
          bool unlocksAnalysis(PlanLimits limits) =>
              limits.ai.index >= AiLevel.analysis.index;
          final String requiredPlan = entitlement.planNameUnlocking(
            unlocksAnalysis,
          );
          return MSUpgradeNudge(
            message: trans('uptizm.status.editor_ai_draft_gated', {
              'plan': requiredPlan,
            }),
            requiredPlan: requiredPlan,
            // Billing with the tier intent, so Upgrade starts the purchase for
            // the plan this nudge just named instead of dropping the user on
            // the settings hub to go find it.
            onUpgrade: () => UpgradePrompt.startUpgrade(
              entitlement.planIdUnlocking(unlocksAnalysis),
            ),
          );
        }
        return AiInsight(
          tone: 'banner',
          label: trans('uptizm.status.editor_ai_draft_banner_label'),
          action: MSButton(
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
      },
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
    return MSCard(
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
    return MSFormField(
      label: trans('uptizm.status.editor_form_name_label'),
      error: _nameError,
      child: MSInput(
        value: _name,
        onChanged: _onNameChanged,
        placeholder: trans('uptizm.status.editor_form_name_placeholder'),
      ),
    );
  }

  /// Builds the domain-mode segmented control (Subdomain / Path). Maps the
  /// tapped index back to the [DomainMode] via [_domainModes].
  Widget _buildDomainModeField() {
    return MSFormField(
      label: trans('uptizm.status.editor_form_how_served_label'),
      child: MSSegmentedControl<String>(
        options: _domainModes.map((DomainMode m) => m.label).toList(),
        selectedIndex: _domainModes.indexOf(_domainMode),
        onChanged: (int index) =>
            setState(() => _domainMode = _domainModes[index]),
      ),
    );
  }

  /// Builds the mono slug input, hinting the full public URL. Editing it latches
  /// [_slugEdited] and stops the name auto-fill.
  Widget _buildSlugField() {
    return MSFormField(
      label: trans('uptizm.status.editor_form_slug_label'),
      hint: pageUrl(_draftPage),
      error: _slugError,
      child: MSInput(
        value: _slug,
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
  /// gets a ring. The draft brand color + [kBrandColors] are the ONLY raw
  /// colors on this screen (content data, the sanctioned exception).
  Widget _buildBrandColorField() {
    return MSFormField(
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
    final bool selected = _brandColor.toARGB32() == swatch.toARGB32();
    return WButton(
      onTap: () => setState(() => _brandColor = swatch),
      child: WDiv(
        className: selected
            ? 'rounded-full border-2 border-primary p-0.5'
            : 'rounded-full border-2 border-transparent p-0.5',
        child: WDiv(backgroundColor: swatch, className: 'size-7 rounded-full'),
      ),
    );
  }

  /// Builds the brand mark: the uploaded logo or its initials fallback, the
  /// upload and remove controls, and the initials input.
  ///
  /// The logo is written IMMEDIATELY, unlike every other field on this form: it
  /// is a file on a disk rather than a column in the draft, so there is nothing
  /// for Save to carry. The hint says so, because a control that saves on its
  /// own next to eight that do not is otherwise a trap.
  ///
  /// A page that does not exist yet has nothing to attach a file to, so before
  /// the first save the affordance is disabled and says why rather than failing
  /// on a request with no id.
  Widget _buildLogoField() {
    final StatusPage? page = _isEdit ? controller.configById(_draftId) : null;
    final String? logoUrl = page?.logoUrl;

    return MSFormField(
      label: trans('uptizm.status.editor_form_logo_label'),
      hint: _isEdit
          ? trans('uptizm.status.editor_form_logo_hint')
          : trans('uptizm.status.editor_form_logo_hint_unsaved'),
      child: WDiv(
        className: 'flex flex-col gap-3',
        children: <Widget>[
          // `wrap`, not a row: this field sits in a two-column form grid on a
          // desktop and full width on a phone, so a mark plus a labelled button
          // is wider than the cell at some widths, and a Row overflowed it by
          // 87px. A Wrap flows the controls under the mark instead.
          WDiv(
            className: 'wrap items-center gap-3',
            children: <Widget>[
              _buildBrandMark(logoUrl),
              WDiv(
                className: 'wrap gap-1.5 items-center',
                children: <Widget>[
                  MSButton(
                    intent: ButtonIntent.secondary,
                    size: ButtonSize.sm,
                    disabled: !_isEdit || _logoBusy,
                    onPressed: !_isEdit || _logoBusy ? null : _onPickLogo,
                    child: WDiv(
                      className: 'flex flex-row items-center gap-1.5',
                      children: <Widget>[
                        WIcon(_uploadIcon, className: 'text-sm'),
                        WText(
                          logoUrl == null
                              ? trans('uptizm.status.editor_form_logo_upload')
                              : trans('uptizm.status.editor_form_logo_replace'),
                        ),
                      ],
                    ),
                  ),
                  if (logoUrl != null)
                    MSButton(
                      intent: ButtonIntent.ghost,
                      size: ButtonSize.sm,
                      disabled: _logoBusy,
                      onPressed: _logoBusy ? null : _onRemoveLogo,
                      child: WText(
                        trans('uptizm.status.editor_form_logo_remove'),
                        className: 'text-down',
                      ),
                    ),
                ],
              ),
            ],
          ),
          // The initials keep their own label, because with a logo set they are
          // not visibly in play and the field would otherwise read as dead.
          MSFormField(
            label: trans('uptizm.status.editor_form_logo_text_label'),
            hint: trans('uptizm.status.editor_form_logo_text_hint'),
            child: MSInput(
              value: _logoText,
              onChanged: (String value) => setState(
                () => _logoText =
                    value.length > 2 ? value.substring(0, 2) : value,
              ),
              className: 'max-w-20',
            ),
          ),
        ],
      ),
    );
  }

  /// The 48pt mark: the uploaded image when there is one, the initials over the
  /// brand colour when there is not.
  ///
  /// Mirrors `status/partials/brand-header.blade.php`, deliberately: this is a
  /// preview of a public surface, so the two have to agree on which of the two
  /// states a page is in and on `contain` rather than `cover` for the artwork.
  Widget _buildBrandMark(String? logoUrl) {
    if (logoUrl != null) {
      return WDiv(
        className: 'size-12 shrink-0 rounded-lg overflow-hidden '
            'bg-surface-container-high',
        child: Image.network(
          logoUrl,
          width: 48,
          height: 48,
          fit: BoxFit.contain,
          // A signed URL expires, and a file can go missing out of band. The
          // fallback is the same mark the public page falls back to, so a
          // failed fetch reads as "no logo" rather than as a broken editor.
          errorBuilder: (_, _, _) => _buildInitialsMark(),
        ),
      );
    }

    return _buildInitialsMark();
  }

  /// The initials tile, used both as the no-logo state and as the fallback when
  /// an image fails to load.
  Widget _buildInitialsMark() {
    final String initials = _logoText.isNotEmpty
        ? _logoText
        : (_name.isNotEmpty ? _name.substring(0, 1) : 'A');

    return WDiv(
      backgroundColor: _brandColor,
      className: 'size-12 shrink-0 rounded-lg flex items-center justify-center',
      child: WText(initials, className: 'text-base font-bold text-white'),
    );
  }

  /// Picks an image and uploads it as this page's logo.
  Future<void> _onPickLogo() async {
    final MagicFile? file = await Pick.image();
    if (file == null || !mounted) return;

    setState(() => _logoBusy = true);
    await controller.uploadLogo(_draftId, file);
    if (!mounted) return;
    setState(() => _logoBusy = false);
  }

  /// Removes this page's logo, falling the mark back to its initials.
  Future<void> _onRemoveLogo() async {
    setState(() => _logoBusy = true);
    await controller.removeLogo(_draftId);
    if (!mounted) return;
    setState(() => _logoBusy = false);
  }

  /// Builds the description textarea.
  Widget _buildDescriptionField() {
    return MSFormField(
      label: trans('uptizm.status.editor_form_description_label'),
      child: MSTextarea(
        value: _description,
        onChanged: (String value) => setState(() => _description = value),
        placeholder: trans('uptizm.status.editor_form_description_placeholder'),
      ),
    );
  }

  /// Builds the Components card: a [RegionPicker] over every monitor, bound to
  /// the draft's assigned monitor ids.
  Widget _buildComponentsCard() {
    return MSCard(
      variant: CardVariant.surface,
      child: WDiv(
        className: 'flex flex-col gap-3',
        children: <Widget>[
          _buildSectionHeading(
            trans('uptizm.status.editor_section_components'),
            hint: trans('uptizm.status.editor_section_components_hint'),
          ),
          MSFormField(
            error: _componentsError,
            child: RegionPicker(
              regions: monitorRegions(_monitors.monitors),
              value: _monitorIds,
              onChanged: (List<String> next) => setState(() {
                _monitorIds = next;
                _componentsError = null;
              }),
            ),
          ),
        ],
      ),
    );
  }

  /// Builds the Visibility card: whether anybody with the URL may read this page.
  ///
  /// A new page starts PUBLIC. A status page exists to be published, the private
  /// variant is the paid one, and the backend asks for no entitlement to be public, so
  /// defaulting to private is what left every page created here answering 404 with no
  /// control to change it.
  ///
  /// Only the PRIVATE direction is gated, mirroring `StoreStatusPageRequest`: turning
  /// the switch OFF needs `private_pages`. Wrapped in a [ListenableBuilder] so it
  /// re-gates the moment the real plan lands, the same way the AI banner does, and the
  /// refusal is a nudge naming the cheapest plan that unlocks it rather than a switch
  /// that silently snaps back.
  Widget _buildVisibilityCard() {
    return ListenableBuilder(
      listenable: EntitlementController.instance,
      builder: (context, _) {
        final entitlement = EntitlementController.instance;
        final bool mayGoPrivate = entitlement.canUsePrivatePages;

        return MSCard(
          variant: CardVariant.surface,
          child: WDiv(
            className: 'flex flex-col gap-4',
            children: <Widget>[
              _buildSectionHeading(
                trans('uptizm.status.editor_section_visibility'),
                hint: trans('uptizm.status.editor_section_visibility_hint'),
              ),
              _buildSwitchRow(
                label: trans('uptizm.status.editor_form_is_public_label'),
                value: _isPublic,
                onChanged: (bool value) {
                  // Refuse the private direction rather than accepting it and
                  // letting the save fail: the nudge below already says why.
                  if (!value && !mayGoPrivate) return;
                  setState(() {
                    _isPublic = value;
                    _isPublicError = null;
                  });
                },
              ),
              if (_isPublicError != null)
                WText(
                  _isPublicError!,
                  className: 'text-xs text-down',
                ),
              if (!mayGoPrivate)
                MSUpgradeNudge(
                  message: trans('uptizm.status.editor_private_gated', {
                    'plan': entitlement.planNameUnlocking(
                      (PlanLimits limits) => limits.privatePages,
                    ),
                  }),
                  requiredPlan: entitlement.planNameUnlocking(
                    (PlanLimits limits) => limits.privatePages,
                  ),
                  onUpgrade: () => UpgradePrompt.startUpgrade(
                    entitlement.planIdUnlocking(
                      (PlanLimits limits) => limits.privatePages,
                    ),
                  ),
                ),
            ],
          ),
        );
      },
    );
  }

  /// Builds the Subscriptions card: the allow-subscriptions [Switch] and, in
  /// edit mode, the subscriber count plus a "View subscribers" link.
  Widget _buildSubscriptionsCard() {
    return MSCard(
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
            value: _subscriptionsEnabled,
            onChanged: (bool value) =>
                setState(() => _subscriptionsEnabled = value),
          ),
          if (_isEdit) _buildSubscriberSummary(),
        ],
      ),
    );
  }

  /// Builds the edit-mode subscriber summary: the count line and a "View
  /// subscribers" secondary button routing to the subscribers screen.
  Widget _buildSubscriberSummary() {
    final int count = controller.subscribersFor(_draftId).length;
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
            WText('$count $unit', className: 'text-sm font-medium text-fg'),
            WText(
              trans('uptizm.status.editor_form_subscribers_hint'),
              className: 'text-xs text-fg-muted',
            ),
          ],
        ),
        MSButton(
          intent: ButtonIntent.secondary,
          size: ButtonSize.sm,
          onPressed: () => MagicRoute.to('/status/$_draftId/subscribers'),
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

  /// Builds the live-preview column: a heading above a browser-framed pane
  /// spanning all six D8 states.
  ///
  /// The heading itself is the honesty gate: [_isDirty] picks between the
  /// DRAFT label and the customer-view label, so a dirty form can never read
  /// as what customers see (D2). Every clean sub-state (never rendered,
  /// rendering, stale, completed, failed) shares the customer-view heading,
  /// since they are all states of the one real, backend-rendered artefact.
  List<Widget> _buildPreviewColumn() {
    final bool dirty = _isDirty;
    return <Widget>[
      WText(
        trans(
          dirty
              ? 'uptizm.status.editor_preview_draft_heading'
              : 'uptizm.status.editor_preview_rendered_heading',
        ),
        className: 'text-xs font-medium uppercase tracking-wide text-fg-muted',
      ),
      _buildBrowserFrame(dirty),
    ];
  }

  /// Builds the browser frame: a title bar (three dots + the mono live URL)
  /// over the state-dependent body from [_buildPreviewBody].
  Widget _buildBrowserFrame(bool dirty) {
    return WDiv(
      className:
          'rounded-xl border border-color-border overflow-hidden '
          'bg-surface-container',
      children: <Widget>[
        _buildBrowserBar(),
        // No padding here on purpose. The completed state's screenshot has to
        // meet the frame edges to read as a browser viewport rather than as a
        // picture sitting on a card, so each state pads ITSELF and the PNG
        // simply does not. Padding at this level would inset the screenshot on
        // all four sides and undo that.
        WDiv(className: 'bg-surface', child: _buildPreviewBody(dirty)),
      ],
    );
  }

  /// Padding the non-screenshot states use, so they keep breathing room while
  /// the screenshot goes edge to edge. See [_buildBrowserFrame].
  Widget _padPreviewState(Widget child) =>
      WDiv(className: 'p-5', child: child);

  /// Dispatches to the right D8 state builder.
  ///
  /// Dirty always wins (D2): the draft is shown regardless of what the
  /// backend last rendered. Otherwise the saved page's
  /// [StatusPage.previewRenderStatus] selects among never-rendered,
  /// rendering (or stale-rendering, treated as failed so a lost job cannot
  /// pin the pane on a skeleton forever), completed, and failed.
  Widget _buildPreviewBody(bool dirty) {
    // A page that does not resolve out of the roster has no saved render to
    // present, so it takes the draft body with the draft label. `_isDirty`
    // already reports true for that case; resolving the page here rather than
    // trusting that other method's invariant is what keeps the two in step.
    final StatusPage? saved = controller.configById(widget.id);
    if (dirty || saved == null) return _padPreviewState(_buildDraftBody());

    final StatusPagePreviewStatus? status = saved.previewRenderStatus;

    if (status == null) {
      // `POST .../preview` only enqueues, so the row's status stays null until
      // a worker starts the job. While this client has a request outstanding,
      // the honest reading of that null is "in flight", not "never rendered":
      // routing it through the rendering body also picks up the check-again row
      // for a render that never starts at all (see
      // [StatusPageController.hasRequestedPreviewRender]).
      if (controller.hasRequestedPreviewRender(saved.id)) {
        return _padPreviewState(_buildRenderingBody(saved));
      }
      // Asked for, and the server never reported anything at all. The most
      // likely cause is a `previews` queue nobody is consuming. Treating this
      // as failed rather than as in-flight is what stops the marker holding the
      // pane on a skeleton for the rest of the session, and it offers the retry
      // that a never-rendered empty state would not.
      if (controller.hasPreviewRequestExpired(saved.id)) {
        return _padPreviewState(_buildFailedBody(saved));
      }
      return _padPreviewState(_buildNeverRenderedBody(saved));
    }

    if (status == StatusPagePreviewStatus.rendering) {
      if (controller.isPreviewRenderStale(saved)) {
        return _padPreviewState(_buildFailedBody(saved));
      }
      return _padPreviewState(_buildRenderingBody(saved));
    }

    if (status == StatusPagePreviewStatus.failed) {
      return _padPreviewState(_buildFailedBody(saved));
    }

    return _buildCompletedBody(saved);
  }

  /// Builds the dirty-state body: the live Flutter approximation fed by
  /// [_draftPage], bounded and scrollable exactly as the pane always was.
  Widget _buildDraftBody() {
    return SizedBox(
      height: 600,
      child: SingleChildScrollView(child: StatusPagePreview(config: _draftPage)),
    );
  }

  /// Builds the never-rendered state: an explicit empty state with a generate
  /// action that kicks off [StatusPageController.requestPreviewRender].
  Widget _buildNeverRenderedBody(StatusPage saved) {
    return MSEmptyState(
      icon: Icons.image_outlined,
      title: trans('uptizm.status.editor_preview_never_rendered_title'),
      action: MSButton(
        intent: ButtonIntent.primary,
        size: ButtonSize.sm,
        onPressed: () => controller.requestPreviewRender(saved.id),
        child: WText(trans('uptizm.status.editor_preview_generate_action')),
      ),
    );
  }

  /// Builds the `rendering` (and not stale) state: sized skeleton bars. Also
  /// serves the client-side in-flight state, where a render has been requested
  /// and the server has not started it yet (see [_buildPreviewBody]).
  ///
  /// When [StatusPageController.hasPreviewPollCapped] is true for this page,
  /// a check-again row is appended: the render may still succeed server-side
  /// even though the client's own poll gave up watching, so this is
  /// deliberately NOT the failed affordance (see the controller's own
  /// docblock on `_previewPollCapped`).
  Widget _buildRenderingBody(StatusPage saved) {
    final bool capped = controller.hasPreviewPollCapped(saved.id);
    return WDiv(
      className: 'flex flex-col gap-3',
      children: <Widget>[
        MSSkeleton(height: _previewSkeletonHeight),
        MSSkeleton(height: 20, width: 160),
        if (capped) _buildCheckAgainRow(saved),
      ],
    );
  }

  /// Builds the check-again row for a poll-capped `rendering` state: the
  /// honest "still generating" message plus a button that re-triggers
  /// [StatusPageController.requestPreviewRender], which also clears the
  /// capped signal and starts a fresh poll.
  Widget _buildCheckAgainRow(StatusPage saved) {
    return WDiv(
      className: 'flex flex-row items-center justify-between gap-2',
      children: <Widget>[
        WText(
          trans('uptizm.status.editor_preview_check_again'),
          className: 'min-w-0 text-xs text-fg-muted',
        ),
        MSButton(
          intent: ButtonIntent.secondary,
          size: ButtonSize.sm,
          onPressed: () => controller.requestPreviewRender(saved.id),
          child: WText(trans('uptizm.status.editor_preview_refresh_action')),
        ),
      ],
    );
  }

  /// Builds the `failed` state (and the stale-`rendering` state, which is
  /// treated identically per D8): an explicit error plus a retry action. A
  /// previous PNG may still show beneath it, but only under its own visibly
  /// old rendered-at stamp, so it never reads as a currently-succeeding
  /// render.
  Widget _buildFailedBody(StatusPage saved) {
    final String? url = saved.previewImageUrl;
    final Carbon? renderedAt = saved.previewRenderedAt;

    return WDiv(
      className: 'flex flex-col gap-4',
      children: <Widget>[
        MSEmptyState(
          icon: Icons.error_outline,
          title: trans('uptizm.status.editor_preview_render_failed_title'),
          action: MSButton(
            intent: ButtonIntent.primary,
            size: ButtonSize.sm,
            onPressed: () => controller.requestPreviewRender(saved.id),
            child: WText(trans('uptizm.status.editor_preview_retry_action')),
          ),
        ),
        // Same dead-control gap as the completed body: Try again leaves the row
        // at `failed` until a worker starts, so without this the retry looks
        // like it did nothing.
        if (controller.hasRequestedPreviewRender(saved.id))
          WText(
            trans('uptizm.status.editor_preview_check_again'),
            className: 'text-xs text-fg-muted',
          ),
        if (url != null && renderedAt != null)
          WDiv(
            className: 'flex flex-col gap-2',
            children: <Widget>[
              _buildPreviewImage(url, saved.name),
              WText(
                trans('uptizm.status.editor_preview_rendered_at', {
                  'time': renderedAt.diffForHumans(),
                }),
                className: 'text-xs text-fg-muted',
              ),
              MSBadge(
                trans('uptizm.status.editor_preview_may_be_stale'),
                tone: BadgeTone.warning,
              ),
            ],
          ),
      ],
    );
  }

  /// Builds the `completed` state: the PNG, its rendered-at stamp, a refresh
  /// action, the tap-to-open-full affordance (D9), and, past
  /// [_previewAgeChipThresholdMinutes], the may-be-out-of-date chip.
  Widget _buildCompletedBody(StatusPage saved) {
    final String? url = saved.previewImageUrl;
    final Carbon? renderedAt = saved.previewRenderedAt;
    final bool aged =
        renderedAt != null &&
        Carbon.now().diffInMinutes(renderedAt) > _previewAgeChipThresholdMinutes;

    // The screenshot meets the frame edges, directly under the URL bar, so the
    // pane reads as a browser viewport showing the page rather than as a
    // picture of it sitting on a card. Everything BELOW the image is editor
    // chrome, not part of the rendered page, so that keeps its own padding.
    return WDiv(
      className: 'flex flex-col',
      children: <Widget>[
        if (url != null)
          _buildPreviewImage(url, saved.name, onTap: url, flush: true),
        WDiv(
          className: 'flex flex-row items-start justify-between gap-2 px-4 pt-4',
          children: <Widget>[
            WDiv(
              className: 'min-w-0 flex flex-col gap-1',
              children: <Widget>[
                if (renderedAt != null)
                  WText(
                    trans('uptizm.status.editor_preview_rendered_at', {
                      'time': renderedAt.diffForHumans(),
                    }),
                    className: 'text-xs text-fg-muted',
                  ),
                if (aged)
                  MSBadge(
                    trans('uptizm.status.editor_preview_may_be_stale'),
                    tone: BadgeTone.warning,
                  ),
              ],
            ),
            MSButton(
              intent: ButtonIntent.secondary,
              size: ButtonSize.sm,
              onPressed: () => controller.requestPreviewRender(saved.id),
              child: WText(
                trans('uptizm.status.editor_preview_refresh_action'),
              ),
            ),
          ],
        ),
        // Acknowledge a Refresh the server has not reported yet. Without this
        // the button is a DEAD CONTROL on the most common path there is: the
        // row already says `completed`, so a tap changed nothing observable
        // until a worker flipped it to `rendering`, which with a healthy queue
        // is a second and with an unconsumed one is never. A live pass sat on
        // this for over two minutes with no acknowledgement at all.
        //
        // The stored image and its stamp deliberately stay visible above. They
        // are still the truth about the last successful render, and hiding real
        // data to signal a pending one would trade a dead control for a lie.
        if (controller.hasRequestedPreviewRender(saved.id))
          WText(
            trans('uptizm.status.editor_preview_check_again'),
            className: 'px-4 pt-2 text-xs text-fg-muted',
          ),
        if (url != null)
          WDiv(
            // A row, so the button keeps its intrinsic width. A plain padded
            // block stretches it to the full pane, which reads as a primary
            // action rather than the secondary affordance it is.
            className: 'flex flex-row px-4 pb-4 pt-3',
            child: MSButton(
              intent: ButtonIntent.secondary,
              size: ButtonSize.sm,
              onPressed: () => _openFullPreview(url),
              child: WText(
                trans('uptizm.status.editor_preview_open_fullscreen'),
              ),
            ),
          ),
      ],
    );
  }

  /// Builds the rendered PNG via [WImage], with a loading affordance while it
  /// downloads and an error fallback for a signed URL that has since expired
  /// or failed to load. When [onTap] is given the image itself is wrapped as
  /// the tap-to-open-full affordance (D9: the PNG is legible at full size, not
  /// at ~32% in a 380px column).
  ///
  /// The loading affordance is not decoration: this is a cross-origin fetch of
  /// a full-page screenshot, so without it the pane shows blank space for as
  /// long as the download takes, and blank space is indistinguishable from
  /// having no render to show at all. It reuses [_buildRenderingBody]'s sized
  /// skeleton so waiting for the image reads the same as waiting for the
  /// render.
  /// [flush] renders the image with no rounding or border of its own, so it can
  /// meet the browser frame's edges and read as that frame's viewport. The
  /// frame clips it (`overflow-hidden rounded-xl`), so the corners still follow
  /// the frame. Off by default: the failed state shows a previous render inside
  /// padding, where a bordered card is the correct reading, since there it is a
  /// stale artefact being referred to rather than the page being displayed.
  Widget _buildPreviewImage(
    String url,
    String? alt, {
    String? onTap,
    bool flush = false,
  }) {
    final Widget image = WImage(
      src: url,
      alt: alt ?? '',
      className: flush
          ? 'w-full object-cover'
          : 'w-full rounded-lg border border-color-border object-cover',
      loadingBuilder: (context, child, progress) =>
          progress == null ? child : _buildImageLoading(),
      errorBuilder: (context, error, stackTrace) => _buildImageLoadError(),
    );
    if (onTap == null) return image;
    return WButton(onTap: () => _openFullPreview(onTap), child: image);
  }

  /// Builds the placeholder shown while the PNG is still downloading: the same
  /// sized skeleton the `rendering` state uses, so the height never collapses
  /// (a heightless skeleton lays out 0px tall and is invisible).
  Widget _buildImageLoading() => MSSkeleton(height: _previewSkeletonHeight);

  /// Builds the fallback shown in place of a PNG that failed to load (e.g. an
  /// expired signed URL).
  Widget _buildImageLoadError() {
    return WDiv(
      className:
          'w-full h-40 rounded-lg border border-color-border '
          'bg-surface-container-high flex items-center justify-center',
      child: WIcon(
        Icons.broken_image_outlined,
        className: 'text-fg-muted text-2xl',
      ),
    );
  }

  /// Opens the rendered PNG at full size in the platform's default handler
  /// (D9's tap-to-open-full affordance). Logs on failure without throwing,
  /// mirroring [_viewPublicPage]'s own open-failure handling.
  Future<void> _openFullPreview(String url) async {
    final bool opened = await Launch.url(url);
    if (opened) return;

    Log.error('[StatusPageEditorView._openFullPreview] could not open $url');
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
          child: WText(pageUrl(_draftPage)),
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
        MSSwitch(value: value, onChanged: onChanged, semanticLabel: label),
        WText(label, className: 'min-w-0 text-sm text-fg'),
      ],
    );
  }
}
