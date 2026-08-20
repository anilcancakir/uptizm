import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/controllers/status_page_controller.dart';
import 'package:uptizm/app/support/status_page_types.dart' show Subscriber;
import 'package:uptizm/app/mocks/status_pages.dart';
import 'package:uptizm/app/models/status_page.dart';
import 'package:uptizm/resources/views/status/status_page_editor_view.dart';
import 'package:uptizm/resources/views/status/status_page_preview_view.dart';
import 'package:uptizm/resources/views/status/status_page_subscribers_view.dart';
import 'package:uptizm/resources/views/status/status_pages_list_view.dart';
import 'package:uptizm/ui/components/kpi_stat_card/index.dart';
import 'package:uptizm/ui/components/status_page_preview/index.dart';

import '../../support/skeleton_matchers.dart';

/// In-memory language loader supplying every [trans] key exercised by the
/// status list/editor/subscribers/preview views, mirroring the pattern
/// established in `incident_views_test.dart`.
class _StatusViewsLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      // Status badge labels (StatusBadge trans('uptizm.status.<key>')). Missing
      // these renders the raw ~30-char key token, which overflows the badge's
      // fixed-width pill (mirrors the guard in monitor_detail_view_test.dart).
      'uptizm.status.up': 'Operational',
      'uptizm.status.down': 'Major outage',
      // `pending` included because a component whose monitor has no check yet
      // resolves to it, and a missing key renders the raw ~21-char token inside
      // a fixed-width badge, which reads as a layout overflow rather than as the
      // missing translation it actually is.
      'uptizm.status.pending': 'Pending',
      'uptizm.status.degraded': 'Degraded',
      'uptizm.status.paused': 'Paused',
      'uptizm.status.info': 'Maintenance',
      'uptizm.status.ai': 'AI',

      // Overall-status banner labels (embedded StatusPagePreview). Missing
      // keys render the long raw token and overflow the fixed banner Row.
      'uptizm.status.banner_operational': 'All systems operational',
      'uptizm.status.banner_degraded': 'Degraded performance',
      'uptizm.status.banner_outage': 'Major outage',
      'uptizm.status.banner_maintenance': 'Maintenance in progress',
      'uptizm.status.banner_pending': 'Awaiting first checks',
      'uptizm.status.banner_paused': 'Some components paused',

      // Embedded StatusPagePreview section copy.
      'uptizm.status.preview_default_name': 'Status',
      'uptizm.status.preview_updated_ago': 'updated 2m ago',
      'uptizm.status.preview_live_metrics_heading': 'Live metrics',
      'uptizm.status.preview_components_heading': 'Components',
      'uptizm.status.preview_components_empty':
          'No components yet. Assign monitors to show their status here.',
      'uptizm.status.preview_no_components_banner':
          'No components published yet',
      'uptizm.status.preview_past_incidents_heading': 'Past incidents',
      'uptizm.status.preview_subscribe_heading': 'Subscribe to updates',
      'uptizm.status.preview_subscribe_description':
          'Get notified by email when an incident is opened, updated, or resolved.',
      'uptizm.status.preview_subscribe_placeholder': 'you@example.com',
      'uptizm.status.preview_subscribe_button': 'Subscribe',
      'uptizm.status.preview_powered_by': 'powered by Uptizm',

      // Not-found state, metric-section labels, list nudge noun, subscribers
      // subtitle.
      'uptizm.status.not_found_title': 'Status page not found',
      'uptizm.status.not_found_description':
          "There's no status page at this address. Double-check the link, or ask whoever shared it for the current address.",
      'uptizm.status.editor_metrics_system_label': 'System',
      'uptizm.status.editor_metrics_custom_label': 'Custom',
      'uptizm.status.subscribers_subtitle':
          'People subscribed to :page updates.',
      'uptizm.status.noun_one': 'status page',
      'uptizm.status.noun_other': 'status pages',

      // List.
      'uptizm.status.list_title': 'Status pages',
      'uptizm.status.list_description': 'Public status pages for customers.',
      'uptizm.status.list_new_page_action': 'New status page',
      'uptizm.status.list_card_component_singular': 'component',
      'uptizm.status.list_card_component_plural': 'components',
      'uptizm.status.list_card_subscribers': 'subscribers',
      'uptizm.status.list_card_subs_off': 'Subs off',
      'uptizm.status.list_empty_title': 'No status pages yet',
      'uptizm.status.list_empty_description': 'Create your first page.',

      // Editor.
      'uptizm.status.editor_breadcrumb_back': 'Status pages',
      'uptizm.status.editor_title_edit': 'Edit status page',
      'uptizm.status.editor_title_new': 'New status page',
      'uptizm.status.editor_form_view_public_page': 'View public page',
      'uptizm.status.editor_form_save': 'Save',
      // Short values on purpose. Without a stub entry `trans()` returns the raw
      // key, which is long enough to overflow the fixed-width switch row and
      // fails these tests as a layout error rather than a missing translation.
      'uptizm.status.editor_section_visibility': 'Visibility',
      'uptizm.status.editor_section_visibility_hint': 'Who can open it.',
      'uptizm.status.editor_form_is_public_label': 'Public',
      'uptizm.status.editor_private_gated': 'Private needs :plan.',
      'uptizm.status.editor_form_create_page': 'Create page',
      'uptizm.status.editor_ai_draft_gated':
          'Upgrade to :plan to draft with AI.',
      'uptizm.status.editor_ai_draft_banner_label': 'Draft with AI',
      'uptizm.status.editor_ai_draft_button': 'Generate',
      'uptizm.status.editor_ai_draft_banner_text':
          'Generate a starter page from your monitors.',
      'uptizm.status.editor_ai_applied_banner': 'Drafted from your monitors.',
      'uptizm.status.editor_section_branding': 'Branding',
      'uptizm.status.editor_form_name_label': 'Name',
      'uptizm.status.editor_form_name_placeholder': 'Acme Status',
      'uptizm.status.form_name_error_required': 'Name is required.',
      'uptizm.status.form_slug_error_required': 'Slug is required.',
      'uptizm.status.form_components_error_required': 'Add a component.',
      'uptizm.status.editor_form_how_served_label': 'How is it served?',
      // Domain-mode segmented-control options (DomainMode.label). Missing keys
      // render the long raw token and overflow the fixed-width control Row.
      'uptizm.enums.domain_mode.subdomain': 'Subdomain',
      'uptizm.enums.domain_mode.path': 'Path',
      'uptizm.status.editor_form_slug_label': 'Slug',
      'uptizm.status.editor_form_slug_placeholder': 'acme',
      'uptizm.status.editor_form_brand_color_label': 'Brand color',
      'uptizm.status.editor_form_logo_text_label': 'Initials',
      'uptizm.status.editor_form_logo_text_hint': 'Up to 2 characters.',
      'uptizm.status.editor_form_logo_label': 'Logo',
      // The logo block's own five keys. Values match the shipped catalogue
      // rather than paraphrasing it: a stub that says something else is a test
      // asserting a string the app never renders.
      'uptizm.status.editor_form_logo_hint': 'PNG, JPG or WebP, up to 512 KB.',
      'uptizm.status.editor_form_logo_hint_unsaved': 'Save the page first.',
      'uptizm.status.editor_form_logo_upload': 'Upload logo',
      'uptizm.status.editor_form_logo_replace': 'Replace',
      'uptizm.status.editor_form_logo_remove': 'Remove logo',
      'uptizm.status.editor_form_description_label': 'Description',
      'uptizm.status.editor_form_description_placeholder':
          'Real-time status of our services.',
      'uptizm.status.editor_section_components': 'Components',
      'uptizm.status.editor_section_components_hint':
          'Monitors published on this page.',
      'uptizm.status.editor_section_metrics': 'Metrics',
      'uptizm.status.editor_section_metrics_hint':
          'Custom and system metrics shown publicly.',
      'uptizm.status.editor_form_no_system_metrics':
          'No system metrics for the assigned monitors.',
      'uptizm.status.editor_form_no_custom_metrics':
          'No custom metrics for the assigned monitors.',
      'uptizm.status.editor_section_subscriptions': 'Subscriptions',
      'uptizm.status.editor_section_subscriptions_hint':
          'Let visitors subscribe by email.',
      'uptizm.status.editor_form_allow_subscriptions_label':
          'Allow subscriptions',
      'uptizm.status.editor_form_subscribers_count_singular': 'subscriber',
      'uptizm.status.editor_form_subscribers_count': 'subscribers',
      'uptizm.status.editor_form_subscribers_hint': 'People notified by email.',
      'uptizm.status.editor_form_view_subscribers_button': 'View subscribers',
      'uptizm.status.editor_preview_live_heading': 'Live preview',
      'uptizm.status.editor_preview_draft_heading': 'Draft preview',
      'uptizm.status.editor_preview_rendered_heading': 'What customers see',
      'uptizm.status.editor_preview_rendered_at': 'Rendered :time',
      'uptizm.status.editor_preview_may_be_stale':
          'This preview may be out of date',
      'uptizm.status.editor_preview_refresh_action': 'Refresh',
      'uptizm.status.editor_preview_never_rendered_title': 'No preview yet',
      'uptizm.status.editor_preview_generate_action': 'Generate preview',
      'uptizm.status.editor_preview_render_failed_title':
          'Failed to generate preview',
      'uptizm.status.editor_preview_retry_action': 'Try again',
      'uptizm.status.editor_preview_check_again':
          'Still generating. Check again in a moment.',
      'uptizm.status.editor_preview_open_fullscreen': 'View full page',

      // Subscribers.
      'uptizm.status.subscribers_title': 'Subscribers',
      'uptizm.status.subscribers_export_csv': 'Export CSV',
      'uptizm.status.subscribers_total_label': 'Total',
      'uptizm.status.subscribers_subscriptions_label': 'Subscriptions',
      'uptizm.status.subscribers_subscriptions_on': 'On',
      'uptizm.status.subscribers_subscriptions_off': 'Off',
      'uptizm.status.subscribers_subscriptions_hint':
          'Whether visitors can subscribe.',
      'uptizm.status.subscribers_empty_subs_enabled_title':
          'No subscribers yet',
      'uptizm.status.subscribers_empty_subs_enabled_description':
          'Nobody has subscribed yet.',
      'uptizm.status.subscribers_empty_subs_disabled_title':
          'Subscriptions are off',
      'uptizm.status.subscribers_empty_subs_disabled_description':
          'Turn on subscriptions to collect subscribers.',
      'uptizm.status.subscribers_open_editor_button': 'Open editor',
      'uptizm.status.subscribers_search_placeholder': 'Search subscribers',
      'uptizm.status.subscribers_no_matches_text': 'No matches for ":query"',
      'uptizm.status.subscribers_subscribed_at': 'Subscribed :date',
      'uptizm.status.subscribers_remove_button': 'Remove',
      'uptizm.status.subscribers_remove_confirm_title': 'Remove subscriber',
      'uptizm.status.subscribers_remove_confirm_description':
          'Remove :email from :page?',
    };
  }
}

/// The status-page id every preview fixture below carries, so a test can name
/// the routed editor id as a const.
const String _previewPageId = 'preview-acme';

/// The signed PNG URL the show endpoint answers with, for the fixtures that
/// stand for a page whose render completed.
const String _previewImageUrl =
    'https://api.uptizm.test/api/v1/status-pages/preview-acme/preview.png'
    '?signature=abc';

/// The wire payload of a status page carrying the given preview-render fields,
/// on top of the same branding shape as the `acme` fixture, for the editor's
/// hybrid-pane tests (D8).
///
/// The two read endpoints do NOT answer with the same keys, and the difference
/// is exactly one field: `GET /status-pages` (index) never carries
/// `preview_image_url`, because the signed URL is a capability and D5 keeps it
/// out of list responses (the backend pins that omission in
/// `StatusPagePreviewControllerTest::test_index_omits_the_signed_image_url`),
/// while `GET /status-pages/{id}` (show) does carry it. Every fixture here is
/// built from this one payload so a test always states which of the two shapes
/// it stands in for: the missing-PNG defect survived a full pane-state suite
/// precisely because every fixture handed the roster a URL only `show` can
/// produce.
///
/// [updatedAtOverride] stands in for the render job's own `save()` timestamp
/// bump, which [StatusPageController.isPreviewRenderStale] reads to judge a
/// `rendering` row as stuck.
Map<String, dynamic> _previewPayload({
  String id = _previewPageId,
  String? previewRenderStatus,
  String? previewImageUrl,
  DateTime? previewRenderedAt,
  DateTime? updatedAtOverride,
  String? logoUrl,
}) {
  return <String, dynamic>{
    'id': id,
    'name': 'Acme Status',
    'slug': 'acme',
    'domain_mode': 'path',
    'brand_color': '#16A34A',
    'logo_text': 'A',
    'logo_url': ?logoUrl,
    'description': "Real-time status of Acme's services.",
    'subscriptions_enabled': true,
    'monitors': const <Map<String, dynamic>>[],
    'preview_render_status': ?previewRenderStatus,
    'preview_image_url': ?previewImageUrl,
    'preview_rendered_at': ?previewRenderedAt?.toIso8601String(),
    'updated_at': (updatedAtOverride ?? DateTime.now()).toIso8601String(),
  };
}

/// A page in the shape `GET /status-pages` (index) answers with: every branding
/// field plus the render state and its timestamp, and NEVER a signed
/// `preview_image_url`.
///
/// This is the only shape a roster read can put in the cache, so it is what a
/// warm editor open actually starts from.
StatusPage _indexShapedPage({
  String? previewRenderStatus,
  DateTime? previewRenderedAt,
  DateTime? updatedAtOverride,
}) {
  return StatusPage.fromMap(
    _previewPayload(
      previewRenderStatus: previewRenderStatus,
      previewRenderedAt: previewRenderedAt,
      updatedAtOverride: updatedAtOverride,
    ),
  );
}

/// A page in the shape `GET /status-pages/{id}` (show) answers with: the index
/// shape plus the signed `preview_image_url`.
///
/// Seeding this into the roster stands for a cache the editor's own show read
/// has already published into (see [StatusPageController.reloadPage]), which is
/// the one way a roster entry ever carries the URL. It is deliberately named
/// for that, because no list response could have produced it.
StatusPage _showShapedPage({
  String previewImageUrl = _previewImageUrl,
  String? previewRenderStatus,
  DateTime? previewRenderedAt,
  DateTime? updatedAtOverride,
  String? logoUrl,
}) {
  return StatusPage.fromMap(
    _previewPayload(
      previewRenderStatus: previewRenderStatus,
      previewImageUrl: previewImageUrl,
      previewRenderedAt: previewRenderedAt,
      updatedAtOverride: updatedAtOverride,
      logoUrl: logoUrl,
    ),
  );
}

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Bind the MagicStarter manager so Card / PageHeader / Button / Input /
    // SegmentedControl / Select / Textarea / Switch resolve their themes via
    // MagicStarter.* without a full app boot.
    Magic.singleton('magic_starter', () => MagicStarterManager());

    // Register the controller up front (idempotent alongside each view's own
    // initState registration), matching the plan's canonical harness, and seed
    // its roster cache from the design-lab fixtures. The views now read the
    // ORM-native reload cache (`StatusPage.all()`), which a bare test host
    // cannot serve, so seeding stands in for a successful reload; the
    // background `onInit` reload degrades to empty and leaves the seed intact.
    Magic.findOrPut(StatusPageController.new);
    StatusPageController.instance.seedForTest(
      List<StatusPage>.of(statusPages),
    );

    // Bind LogManager so Log.error resolves (the live `subscribersFor`
    // background fetch logs on a non-2xx/failed response) and fake the
    // network with a default baseline (200 + empty data for every request) so
    // that background fetch resolves and degrades cleanly instead of
    // throwing "Service [network] is not registered". Individual tests
    // override this with a keyed `Http.fake({...})` to seed a specific
    // subscriber roster.
    Magic.singleton('log', () => LogManager());
    Http.fake();

    Translator.instance.setLoader(_StatusViewsLangLoader());
    await Translator.instance.setLocale(const Locale('en'));
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in a [MaterialApp] with a default [WindTheme] under a
  /// configurable [MediaQuery] size, mirroring the harness established in
  /// `incident_views_test.dart`.
  Widget wrap(Widget widget, {Size size = const Size(1280, 3200)}) {
    return MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(size: size),
        child: WindTheme(
          data: WindThemeData(),
          child: Scaffold(body: SingleChildScrollView(child: widget)),
        ),
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // StatusPagesListView
  // ---------------------------------------------------------------------------

  group('StatusPagesListView', () {
    testWidgets('renders a card for every fixture status page', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const StatusPagesListView()));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byType(MSPageContainer), findsOneWidget);
      for (final StatusPage page in statusPages) {
        expect(find.text(page.name!), findsOneWidget);
      }
    });

    testWidgets('shows a skeleton before the first read resolves, not the '
        'empty state', (tester) async {
      // The regression this pins: loading was indistinguishable from emptiness,
      // so a populated account opened the page on "No status pages yet" and only
      // swapped to its rows once the fetch landed.
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // A controller that has never resolved a read: no seedForTest, so the
      // mount's own fetch is still in flight on the first frame.
      MagicApp.reset();
      Magic.flush();
      Magic.singleton('magic_starter', () => MagicStarterManager());
      Magic.singleton('log', () => LogManager());
      Http.fake();

      // Deliberately NOT pumped again: the first frame is painted before the
      // mount's async fetch resolves, which is exactly the moment the operator
      // used to be told they had no status pages.
      await tester.pumpWidget(wrap(const StatusPagesListView()));

      expect(find.byType(MSSkeleton), findsWidgets);
      expectVisibleSkeletons(tester);
      expect(
        find.text(trans('uptizm.status.list_empty_title')),
        findsNothing,
        reason: 'a pending read must never assert that there are none',
      );

      // Once it resolves (the fake answers nothing), the skeleton gives way to
      // the honest empty state.
      await tester.pump();
      expect(find.byType(MSSkeleton), findsNothing);
      expect(find.text(trans('uptizm.status.list_empty_title')), findsOneWidget);
    });

    testWidgets('a resolved empty roster shows the empty state, not a '
        'skeleton', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // Seeding is a resolved state, so an empty seed is a known-empty roster.
      StatusPageController.instance.seedForTest(const []);

      await tester.pumpWidget(wrap(const StatusPagesListView()));
      await tester.pump();

      expect(find.byType(MSSkeleton), findsNothing);
      expect(find.text(trans('uptizm.status.list_empty_title')), findsOneWidget);
    });
  });

  // ---------------------------------------------------------------------------
  // StatusPageEditorView
  // ---------------------------------------------------------------------------

  group('StatusPageEditorView', () {
    testWidgets('create mode renders the new-page title and branding card', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const StatusPageEditorView(), size: const Size(1280, 4000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byType(MSPageContainer), findsOneWidget);
      expect(
        find.text(trans('uptizm.status.editor_title_new')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.status.editor_section_branding')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.status.editor_form_create_page')),
        findsOneWidget,
      );
    });

    testWidgets('a direct load reseeds into edit mode once the roster lands', (
      tester,
    ) async {
      // Regression: on a direct load of `/status/<id>` (a reload, or a shared
      // link) the roster fetch is still in flight when initState runs, so the
      // editor seeded an empty draft and rendered "New status page" with a
      // Create button for a page that already exists. Saving from there would
      // have created a duplicate.
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final StatusPage page = findStatusPage('acme')!;
      // Start with a cold cache, exactly as a fresh page load does.
      StatusPageController.instance.seedForTest(const <StatusPage>[]);

      await tester.pumpWidget(
        wrap(
          const StatusPageEditorView(id: 'acme'),
          size: const Size(1280, 4000),
        ),
      );
      await tester.pump();

      // Nothing resolved yet: the id is treated as a broken link, so neither
      // form is offered.
      expect(
        find.text(trans('uptizm.status.editor_form_save')),
        findsNothing,
      );

      // The roster lands.
      StatusPageController.instance.seedForTest(<StatusPage>[page]);
      await tester.pump();

      expect(
        find.text(trans('uptizm.status.editor_form_save')),
        findsOneWidget,
        reason: 'the resolved page must flip the editor into edit mode',
      );
      expect(
        find.text(trans('uptizm.status.editor_form_create_page')),
        findsNothing,
        reason: 'offering Create for an existing page invites a duplicate',
      );
      expect(
        find.text(page.name!),
        findsWidgets,
        reason: 'the resolved page must prefill the form',
      );
    });

    testWidgets('edit mode prefills the header with the fixture name', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final StatusPage page = findStatusPage('acme')!;

      await tester.pumpWidget(
        wrap(
          const StatusPageEditorView(id: 'acme'),
          size: const Size(1280, 4000),
        ),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.text(page.name!), findsWidgets);
      expect(
        find.text(trans('uptizm.status.editor_form_save')),
        findsOneWidget,
      );
      // Edit mode surfaces the subscriber summary, absent from create mode.
      expect(
        find.text(trans('uptizm.status.editor_form_view_subscribers_button')),
        findsOneWidget,
      );
    });

    testWidgets('an unknown id renders the graceful not-found state', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const StatusPageEditorView(id: 'nope')));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byType(MSEmptyState), findsOneWidget);
      expect(find.text(trans('uptizm.status.list_empty_title')), findsWidgets);
    });

    testWidgets(
      'create mode: saving with a blank name shows an inline error and skips '
      'the write',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final FakeNetworkDriver fake = Http.fake();

        await tester.pumpWidget(
          wrap(const StatusPageEditorView(), size: const Size(1280, 4000)),
        );
        await tester.pump();

        // Tap Create with the blank create defaults (empty name/slug/monitors):
        // the client-side required check must block before any round trip.
        await tester.tap(
          find.text(trans('uptizm.status.editor_form_create_page')),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(
          find.text(trans('uptizm.status.form_name_error_required')),
          findsOneWidget,
          reason: 'A blank name must surface its inline required error',
        );
        // No round trip: the blank submit never reached the write endpoint.
        fake.assertNotSent(
          (r) =>
              (r.method == 'POST' || r.method == 'PUT') &&
              r.url.contains('status-pages'),
        );
      },
    );

    // -------------------------------------------------------------------------
    // The D2/D8 hybrid preview pane.
    // -------------------------------------------------------------------------

    testWidgets(
      'no render yet: shows the empty state and a generate action, and '
      'never the customer-view heading',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final StatusPage page = _indexShapedPage();
        StatusPageController.instance.seedForTest(<StatusPage>[page]);
        // The show route answers the way the server really would: no render
        // state at all until the trigger has been POSTed, `completed` after.
        // A flat `completed` stub would be answered by the editor's own mount
        // read (which is what makes the PNG show at all, see the regression
        // test below), so the pane would never sit on the never-rendered
        // state this test is about. The post-tap `completed` also stops the
        // poll on its first tick, leaving no pending Timer at teardown.
        bool renderRequested = false;
        final FakeNetworkDriver fake = Http.fake((r) {
          if (r.method == 'POST' &&
              r.url.contains('status-pages/${page.id}/preview')) {
            renderRequested = true;
            return Http.response(null, 202);
          }
          if (r.method == 'GET' && r.url.endsWith('status-pages/${page.id}')) {
            return Http.response({
              'data': _previewPayload(
                previewRenderStatus: renderRequested ? 'completed' : null,
                previewImageUrl: renderRequested ? _previewImageUrl : null,
              ),
            }, 200);
          }
          return Http.response(<String, dynamic>{}, 200);
        });

        await tester.pumpWidget(
          wrap(
            StatusPageEditorView(id: page.id),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(
          find.text(trans('uptizm.status.editor_preview_never_rendered_title')),
          findsOneWidget,
        );
        expect(
          find.text(trans('uptizm.status.editor_preview_generate_action')),
          findsOneWidget,
        );

        await tester.tap(
          find.text(trans('uptizm.status.editor_preview_generate_action')),
        );
        // Advance the fake clock past the poll's 2s interval so the loop's
        // single tick fires and resolves (to `completed`) before the test
        // tears down; otherwise its Timer is still pending at disposal.
        await tester.pump(const Duration(seconds: 3));
        await tester.pump();

        fake.assertSent(
          (r) =>
              r.method == 'POST' &&
              r.url.contains('status-pages/${page.id}/preview'),
        );
      },
    );

    testWidgets(
      'no render yet: tapping Generate shows the in-flight skeleton at once, '
      'and the check-again copy once the poll gives up',
      (tester) async {
        // The regression this pins: the row's `preview_render_status` stays
        // NULL until a worker picks the job up, so the pane kept rendering
        // "No preview yet" under the same Generate button with zero feedback.
        // If nothing consumes the `previews` queue (the failure the live pass
        // found present on this machine) the status stays null forever, the
        // poll silently caps, and the check-again copy that exists for exactly
        // this case was only reachable from the `rendering` body, so the
        // operator never saw it.
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final StatusPage page = _indexShapedPage();
        StatusPageController.instance.seedForTest(<StatusPage>[page]);
        // The trigger is accepted, and every show read afterwards still
        // reports no render state: a queue nobody is consuming.
        Http.fake((r) {
          if (r.method == 'POST' &&
              r.url.contains('status-pages/${page.id}/preview')) {
            return Http.response(null, 202);
          }
          if (r.method == 'GET' && r.url.endsWith('status-pages/${page.id}')) {
            return Http.response({'data': _previewPayload()}, 200);
          }
          return Http.response(<String, dynamic>{}, 200);
        });

        await tester.pumpWidget(
          wrap(
            StatusPageEditorView(id: page.id),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();

        await tester.tap(
          find.text(trans('uptizm.status.editor_preview_generate_action')),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(
          find.byType(MSSkeleton),
          findsWidgets,
          reason:
              'a tapped Generate must show it is in flight before the server '
              'has any render state to report',
        );
        expectVisibleSkeletons(tester);
        expect(
          find.text(trans('uptizm.status.editor_preview_never_rendered_title')),
          findsNothing,
          reason: 'the requested render must not still read as never asked',
        );

        // Drain the poll: 45 attempts at 2s is the production cap, and the
        // server never leaves `null`, so it runs to the end and marks the page
        // capped. This also leaves no pending Timer at teardown.
        await tester.pump(const Duration(seconds: 95));
        await tester.pump();

        expect(
          find.text(trans('uptizm.status.editor_preview_check_again')),
          findsOneWidget,
          reason:
              'a poll that gave up on a never-started render must say so, not '
              'sit on a silent skeleton',
        );
        expect(
          find.text(trans('uptizm.status.editor_preview_render_failed_title')),
          findsNothing,
          reason: 'the render may still succeed; a poll cap is not a failure',
        );
      },
    );

    testWidgets('rendering: shows visible skeleton bars', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final StatusPage page = _indexShapedPage(
        previewRenderStatus: 'rendering',
      );
      StatusPageController.instance.seedForTest(<StatusPage>[page]);

      await tester.pumpWidget(
        wrap(StatusPageEditorView(id: page.id), size: const Size(1280, 4000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byType(MSSkeleton), findsWidgets);
      expectVisibleSkeletons(tester);
      expect(
        find.text(trans('uptizm.status.editor_preview_render_failed_title')),
        findsNothing,
        reason: 'a fresh rendering row must not read as failed',
      );
    });

    testWidgets(
      'rendering but stale: shows the failed affordance, not a skeleton',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final StatusPage page = _indexShapedPage(
          previewRenderStatus: 'rendering',
          updatedAtOverride: DateTime.now().subtract(
            const Duration(minutes: 10),
          ),
        );
        StatusPageController.instance.seedForTest(<StatusPage>[page]);

        await tester.pumpWidget(
          wrap(
            StatusPageEditorView(id: page.id),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(
          find.text(trans('uptizm.status.editor_preview_render_failed_title')),
          findsOneWidget,
        );
        expect(
          find.text(trans('uptizm.status.editor_preview_retry_action')),
          findsOneWidget,
        );
        expect(
          find.byType(MSSkeleton),
          findsNothing,
          reason: 'a lost job must not pin the pane on a skeleton forever',
        );
      },
    );

    testWidgets(
      'rendering, poll capped: shows the check-again affordance, not failed',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final StatusPage page = _indexShapedPage(
          previewRenderStatus: 'rendering',
        );
        StatusPageController.instance.seedForTest(<StatusPage>[page]);
        Http.fake({
          'status-pages/${page.id}/preview': Http.response(null, 202),
          'status-pages/${page.id}': Http.response({
            'data': {'id': page.id, 'preview_render_status': 'rendering'},
          }, 200),
        });

        // Force the poll cap in a single tick so the controller marks this
        // page's poll as capped without a real render ever landing. Run
        // through `runAsync` so the real `Future.delayed` inside the poll
        // loop actually elapses: `testWidgets`'s fake async zone never lets a
        // real timer fire on its own.
        await tester.runAsync(
          () => StatusPageController.instance.requestPreviewRender(
            page.id,
            pollInterval: const Duration(milliseconds: 1),
            maxAttempts: 1,
          ),
        );
        expect(
          StatusPageController.instance.hasPreviewPollCapped(page.id),
          isTrue,
        );

        await tester.pumpWidget(
          wrap(
            StatusPageEditorView(id: page.id),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(
          find.text(trans('uptizm.status.editor_preview_check_again')),
          findsOneWidget,
        );
        expect(
          find.text(trans('uptizm.status.editor_preview_render_failed_title')),
          findsNothing,
          reason: 'the render may still succeed; a poll cap is not a failure',
        );
      },
    );

    testWidgets(
      'completed: a plain editor open shows the PNG, even though the roster '
      'it reloads from carries no image URL',
      (tester) async {
        // The regression this pins, and the reason the pane-state tests below
        // could not catch it. `index` deliberately omits `preview_image_url`
        // (D5: the signed URL is a capability and is not multiplied across
        // list responses), and the editor's mount refetch overwrites the whole
        // roster from exactly that response. Reading the pane out of the
        // roster alone therefore left EVERY normal editor open on the
        // customer-view heading with a rendered-at stamp, a Refresh button and
        // no image at all; the PNG only ever appeared inside the window of an
        // explicit Generate/Refresh poll and was wiped again on the next mount.
        //
        // Both endpoints below answer exactly as the backend does, so the test
        // also pins the ORDER: the show read has to land after the index
        // overwrite, or the roster refresh would wipe the URL again.
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final DateTime renderedAt = DateTime.now().subtract(
          const Duration(minutes: 2),
        );

        // The cache as a warm open leaves it: index-shaped, so no image URL.
        StatusPageController.instance.seedForTest(<StatusPage>[
          _indexShapedPage(
            previewRenderStatus: 'completed',
            previewRenderedAt: renderedAt,
          ),
        ]);

        Http.fake({
          'status-pages': Http.response({
            'data': <Map<String, dynamic>>[
              _previewPayload(
                previewRenderStatus: 'completed',
                previewRenderedAt: renderedAt,
              ),
            ],
          }, 200),
          'status-pages/$_previewPageId': Http.response({
            'data': _previewPayload(
              previewRenderStatus: 'completed',
              previewImageUrl: _previewImageUrl,
              previewRenderedAt: renderedAt,
            ),
          }, 200),
        });

        await tester.pumpWidget(
          wrap(
            const StatusPageEditorView(id: _previewPageId),
            size: const Size(1280, 4000),
          ),
        );
        // Two frames: the mount's roster reload lands on the first, the show
        // read that carries the signed URL on the second.
        await tester.pump();
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(
          find.byType(WImage),
          findsOneWidget,
          reason:
              'the customer-view pane must show the rendered PNG on a plain '
              'open, not only during a Generate/Refresh poll',
        );
        expect(
          find.text(trans('uptizm.status.editor_preview_open_fullscreen')),
          findsOneWidget,
          reason: 'a PNG on screen must come with its open-full affordance',
        );
      },
    );

    testWidgets(
      'completed: a Refresh the server has not reported yet is acknowledged, '
      'without hiding the render it already has',
      (tester) async {
        // The dead control a live pass caught. On an already-rendered page the
        // row says `completed`, so tapping Refresh changed NOTHING observable
        // until a worker flipped it to `rendering`. With a healthy queue that is
        // about a second; with an unconsumed `previews` queue it never happens,
        // and the pass sat on it for over two minutes with no acknowledgement.
        // Refresh on an existing preview is the most common action this feature
        // has, so a silent one is the worst place for it.
        //
        // The old image and its stamp must SURVIVE the acknowledgement: they are
        // still the truth about the last successful render, and hiding real data
        // to signal a pending one trades a dead control for a lie.
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final DateTime renderedAt = DateTime.now().subtract(
          const Duration(minutes: 2),
        );
        StatusPageController.instance.seedForTest(<StatusPage>[
          _showShapedPage(
            previewRenderStatus: 'completed',
            previewRenderedAt: renderedAt,
          ),
        ]);
        StatusPageController.instance.seedPreviewRequestForTest(
          _previewPageId,
          DateTime.now(),
        );

        await tester.pumpWidget(
          wrap(
            const StatusPageEditorView(id: _previewPageId),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(
          find.text(trans('uptizm.status.editor_preview_check_again')),
          findsOneWidget,
          reason:
              'an outstanding render request must be acknowledged, or Refresh '
              'is a dead control whenever the queue is slow or unconsumed',
        );
        expect(
          find.byType(WImage),
          findsOneWidget,
          reason:
              'the previous render stays visible: it is still true, and hiding '
              'it to signal a pending render would be a lie',
        );
        expect(
          find.textContaining(
            trans('uptizm.status.editor_preview_rendered_at', {'time': ''}).trim(),
          ),
          findsWidgets,
          reason: 'and it keeps its own honest timestamp',
        );
      },
    );

    testWidgets(
      'completed, fresh: shows the PNG, the rendered-at stamp, a refresh '
      'action, and no age chip',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final StatusPage page = _showShapedPage(
          previewRenderStatus: 'completed',
          previewRenderedAt: DateTime.now().subtract(
            const Duration(minutes: 2),
          ),
        );
        StatusPageController.instance.seedForTest(<StatusPage>[page]);

        await tester.pumpWidget(
          wrap(StatusPageEditorView(id: page.id), size: const Size(1280, 4000)),
        );
        await tester.pump();

        expect(find.byType(WImage), findsOneWidget);
        expect(
          find.text(trans('uptizm.status.editor_preview_refresh_action')),
          findsOneWidget,
        );
        expect(
          find.text(trans('uptizm.status.editor_preview_may_be_stale')),
          findsNothing,
          reason: 'a two-minute-old render is not out of date yet',
        );
      },
    );

    testWidgets(
      'completed: the PNG downloads behind a sized skeleton, not blank space',
      (tester) async {
        // The signed PNG is a cross-origin fetch of a full-page screenshot, so
        // with only an errorBuilder wired the pane showed blank space for the
        // whole download, and that blank was indistinguishable from having no
        // render to show.
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        StatusPageController.instance.seedForTest(<StatusPage>[
          _showShapedPage(
            previewRenderStatus: 'completed',
            previewRenderedAt: DateTime.now().subtract(
              const Duration(minutes: 2),
            ),
          ),
        ]);

        await tester.pumpWidget(
          wrap(
            const StatusPageEditorView(id: _previewPageId),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();

        final WImage image = tester.widget<WImage>(find.byType(WImage));
        final BuildContext context = tester.element(find.byType(WImage));
        const Widget loaded = SizedBox.shrink();

        expect(
          image.loadingBuilder,
          isNotNull,
          reason: 'a downloading screenshot must not read as a missing one',
        );

        // Mid-download: a sized placeholder holds the pane's height.
        final Widget waiting = image.loadingBuilder!(
          context,
          loaded,
          const ImageChunkEvent(
            cumulativeBytesLoaded: 1,
            expectedTotalBytes: 100,
          ),
        );
        expect(waiting, isA<MSSkeleton>());
        expect(
          (waiting as MSSkeleton).height,
          isNotNull,
          reason: 'a heightless skeleton lays out 0px tall and is invisible',
        );

        // Downloaded: the builder must hand the image itself back, or the PNG
        // would sit behind its own placeholder forever.
        expect(image.loadingBuilder!(context, loaded, null), same(loaded));
      },
    );

    testWidgets(
      'completed, old: shows the may-be-out-of-date chip',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final StatusPage page = _showShapedPage(
          previewRenderStatus: 'completed',
          previewRenderedAt: DateTime.now().subtract(
            const Duration(minutes: 20),
          ),
        );
        StatusPageController.instance.seedForTest(<StatusPage>[page]);

        await tester.pumpWidget(
          wrap(StatusPageEditorView(id: page.id), size: const Size(1280, 4000)),
        );
        await tester.pump();

        expect(
          find.text(trans('uptizm.status.editor_preview_may_be_stale')),
          findsOneWidget,
        );
      },
    );

    testWidgets('failed: shows the error and a retry action', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      final StatusPage page = _indexShapedPage(previewRenderStatus: 'failed');
      StatusPageController.instance.seedForTest(<StatusPage>[page]);

      await tester.pumpWidget(
        wrap(StatusPageEditorView(id: page.id), size: const Size(1280, 4000)),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(
        find.text(trans('uptizm.status.editor_preview_render_failed_title')),
        findsOneWidget,
      );
      expect(
        find.text(trans('uptizm.status.editor_preview_retry_action')),
        findsOneWidget,
      );
    });

    testWidgets('a page with no logo offers an upload and no remove', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      StatusPageController.instance.seedForTest(<StatusPage>[
        _showShapedPage(),
      ]);

      await tester.pumpWidget(
        wrap(
          const StatusPageEditorView(id: _previewPageId),
          size: const Size(1280, 4000),
        ),
      );
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.text('Upload logo'), findsOneWidget);
      // Nothing to remove, so offering it would be a control that answers with
      // a no-op, and the mark falls back to the initials instead.
      expect(find.text('Remove logo'), findsNothing);
      expect(find.text('Replace'), findsNothing);
      expect(find.byType(Image), findsNothing);
    });

    testWidgets('an uploaded logo renders as the mark and can be replaced', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      StatusPageController.instance.seedForTest(<StatusPage>[
        _showShapedPage(logoUrl: 'https://example.test/logo.png?signature=x'),
      ]);

      await tester.pumpWidget(
        wrap(
          const StatusPageEditorView(id: _previewPageId),
          size: const Size(1280, 4000),
        ),
      );
      await tester.pump();

      // The brand mark is the image itself, not the initials tile: this field
      // is a preview of a public surface, and the public header makes the same
      // either-or choice.
      expect(find.byType(Image), findsWidgets);
      expect(find.text('Replace'), findsOneWidget);
      expect(find.text('Remove logo'), findsOneWidget);
      expect(find.text('Upload logo'), findsNothing);
    });

    testWidgets('an unsaved page cannot upload a logo yet', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(
        wrap(const StatusPageEditorView(), size: const Size(1280, 4000)),
      );
      await tester.pump();

      // There is no page to attach a file to before the first save, so the
      // control says so rather than failing on a request with no id.
      expect(find.text('Save the page first.'), findsOneWidget);
      final MSButton upload = tester.widget<MSButton>(
        find.ancestor(
          of: find.text('Upload logo'),
          matching: find.byType(MSButton),
        ),
      );
      expect(upload.onPressed, isNull);
    });

    testWidgets(
      'a dirty draft cannot show the customer-view heading',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final StatusPage page = _showShapedPage(
          previewRenderStatus: 'completed',
          previewRenderedAt: DateTime.now().subtract(
            const Duration(minutes: 2),
          ),
        );
        StatusPageController.instance.seedForTest(<StatusPage>[page]);

        await tester.pumpWidget(
          wrap(StatusPageEditorView(id: page.id), size: const Size(1280, 4000)),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);

        // The heading className applies an `uppercase` transform, so the
        // rendered `Text` carries the upper-cased string, not the raw trans()
        // value.
        final String renderedHeadingText = trans(
          'uptizm.status.editor_preview_rendered_heading',
        ).toUpperCase();
        final String draftHeadingText = trans(
          'uptizm.status.editor_preview_draft_heading',
        ).toUpperCase();

        // Clean: the saved PNG's label is the customer-view one.
        expect(find.text(renderedHeadingText), findsOneWidget);
        expect(find.text(draftHeadingText), findsNothing);

        // Edit the name: the draft now differs from the saved page.
        await tester.enterText(
          find.byType(MSInput).first,
          'Acme Status Renamed',
        );
        await tester.pump();

        expect(
          find.text(draftHeadingText),
          findsOneWidget,
          reason: 'an unsaved edit must render under the draft label',
        );
        expect(
          find.text(renderedHeadingText),
          findsNothing,
          reason:
              'the customer-view label must never show while the form is dirty',
        );
      },
    );
  });

  // ---------------------------------------------------------------------------
  // StatusPageSubscribersView
  // ---------------------------------------------------------------------------

  group('StatusPageSubscribersView', () {
    testWidgets('a page with subscribers lists every subscriber email', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // Fakes the live `GET status-pages/acme/subscribers` roster the view's
      // controller fetches; the view now renders this live roster, not the
      // mocks fixture (see StatusPageController.subscribersFor).
      final List<Map<String, dynamic>> roster = [
        {
          'id': 'sub-1',
          'email': 'devops@northwind.io',
          'subscribed_at': DateTime.now()
              .subtract(const Duration(days: 3))
              .toIso8601String(),
          'confirmed': true,
          'newsletter_opt_in': true,
        },
        {
          'id': 'sub-2',
          'email': 'sre-team@globex.com',
          'subscribed_at': DateTime.now()
              .subtract(const Duration(days: 7))
              .toIso8601String(),
          'confirmed': true,
          'newsletter_opt_in': false,
        },
      ];
      final List<Subscriber> subs = roster.map(Subscriber.fromMap).toList();
      Http.fake({
        'status-pages/acme/subscribers': Http.response({'data': roster}, 200),
      });

      await tester.pumpWidget(
        wrap(
          const StatusPageSubscribersView(id: 'acme'),
          size: const Size(1280, 4000),
        ),
      );
      // The roster fetch fires from the controller's `subscribersFor` getter
      // on the first build and lands asynchronously; settle so the view
      // rebuilds against the decoded roster before asserting on it.
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.byType(MSPageContainer), findsOneWidget);
      for (final Subscriber s in subs) {
        expect(find.text(s.email), findsOneWidget);
      }
    });

    testWidgets('shows a skeleton before the first read resolves, not the '
        'empty state', (tester) async {
      // The regression this pins: loading was indistinguishable from emptiness,
      // so a page WITH subscribers opened on "No subscribers yet" and a Total of
      // 0 until its roster fetch landed. The roster is a lazy per-page cache, so
      // the resolution this waits on is this page's own.
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // Deliberately NOT pumped again: the first frame is painted before the
      // roster fetch (fired from the first `subscribersFor` read) resolves.
      await tester.pumpWidget(
        wrap(
          const StatusPageSubscribersView(id: 'acme'),
          size: const Size(1280, 4000),
        ),
      );

      expect(find.byType(MSSkeleton), findsWidgets);
      expectVisibleSkeletons(tester);
      expect(
        find.text(trans('uptizm.status.subscribers_empty_subs_enabled_title')),
        findsNothing,
        reason: 'a pending read must never assert that there are none',
      );
      // An unanswered roster has no total either; the pre-fetch 0 stated as fact
      // that nobody had subscribed.
      expect(
        tester
            .widgetList<KpiStatCard>(find.byType(KpiStatCard))
            .first
            .value,
        equals('—'),
      );

      // Once it resolves (the fake answers nothing), the skeleton gives way to
      // the honest empty state and a real zero.
      await tester.pumpAndSettle();
      expect(find.byType(MSSkeleton), findsNothing);
      expect(
        find.text(trans('uptizm.status.subscribers_empty_subs_enabled_title')),
        findsOneWidget,
      );
      expect(
        tester
            .widgetList<KpiStatCard>(find.byType(KpiStatCard))
            .first
            .value,
        equals('0'),
      );
    });

    testWidgets('a resolved empty roster shows the empty state, not a '
        'skeleton', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 4000));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // Resolve this page's roster first (the fake answers nothing), so the view
      // mounts against a known-empty roster rather than a pending one.
      StatusPageController.instance.subscribersFor('acme');
      await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 10)),
      );
      expect(
        StatusPageController.instance.hasResolvedSubscribers('acme'),
        isTrue,
      );

      await tester.pumpWidget(
        wrap(
          const StatusPageSubscribersView(id: 'acme'),
          size: const Size(1280, 4000),
        ),
      );
      await tester.pump();

      expect(find.byType(MSSkeleton), findsNothing);
      expect(
        find.text(trans('uptizm.status.subscribers_empty_subs_enabled_title')),
        findsOneWidget,
      );
    });

    testWidgets(
      'a page with subscriptions off renders the disabled empty state',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 3200));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final StatusPage page = findStatusPage('internal')!;
        expect(page.subscriptionsEnabled, isFalse);

        await tester.pumpWidget(
          wrap(const StatusPageSubscribersView(id: 'internal')),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(find.byType(MSEmptyState), findsOneWidget);
        expect(
          find.text(
            trans('uptizm.status.subscribers_empty_subs_disabled_title'),
          ),
          findsOneWidget,
        );
      },
    );
  });

  // ---------------------------------------------------------------------------
  // StatusPagePreviewView
  // ---------------------------------------------------------------------------

  group('StatusPagePreviewView', () {
    testWidgets(
      'a known id renders the header title and the embedded preview',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(1280, 4000));
        addTearDown(() => tester.binding.setSurfaceSize(null));

        final StatusPage page = findStatusPage('acme')!;

        await tester.pumpWidget(
          wrap(
            const StatusPagePreviewView(id: 'acme'),
            size: const Size(1280, 4000),
          ),
        );
        await tester.pump();

        expect(tester.takeException(), isNull);
        expect(find.text(page.name!), findsWidgets);
        expect(find.byType(StatusPagePreview), findsOneWidget);
      },
    );

    testWidgets('an unknown id renders the not-found state', (tester) async {
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const StatusPagePreviewView(id: 'nope')));
      await tester.pump();

      expect(tester.takeException(), isNull);
      expect(find.byType(MSEmptyState), findsOneWidget);
      expect(find.text('Status page not found'), findsWidgets);
    });
  });
}
