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
import 'package:uptizm/ui/components/empty_state/index.dart';
import 'package:uptizm/ui/components/status_page_preview/index.dart';
import 'package:uptizm/ui/layouts/page_container.dart';

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
      'uptizm.status.list_card_subdomain': 'Subdomain',
      'uptizm.status.list_card_path': 'Path',
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
      'uptizm.status.editor_form_logo_text_label': 'Logo',
      'uptizm.status.editor_form_logo_text_hint': 'Up to 2 characters.',
      'uptizm.status.editor_form_logo_label': 'Upload',
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
      expect(find.byType(PageContainer), findsOneWidget);
      for (final StatusPage page in statusPages) {
        expect(find.text(page.name!), findsOneWidget);
      }
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
      expect(find.byType(PageContainer), findsOneWidget);
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
      expect(find.byType(EmptyState), findsOneWidget);
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
      expect(find.byType(PageContainer), findsOneWidget);
      for (final Subscriber s in subs) {
        expect(find.text(s.email), findsOneWidget);
      }
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
        expect(find.byType(EmptyState), findsOneWidget);
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
      expect(find.byType(EmptyState), findsOneWidget);
      expect(find.text('Status page not found'), findsWidgets);
    });
  });
}
