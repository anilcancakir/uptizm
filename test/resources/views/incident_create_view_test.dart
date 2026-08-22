import 'package:flutter/material.dart' hide Card, Switch;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import 'package:uptizm/app/controllers/entitlement_controller.dart';
import 'package:uptizm/app/controllers/incident_controller.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/controllers/status_page_controller.dart';
import 'package:magic_payments/magic_payments.dart'
    show
        BillingEntitlement,
        BillingInvoicesPage,
        BillingService,
        PaymentMethod,
        UsageStat;
import 'package:uptizm/app/mocks/billing.dart' show plans;
import 'package:uptizm/app/mocks/teams_data.dart' show planWireRows;
import 'package:uptizm/app/support/billing_types.dart' show Plan;
import 'package:uptizm/app/models/scheduled_maintenance.dart';
import 'package:uptizm/app/models/status_page.dart';
import 'package:uptizm/resources/views/incidents/incident_create_view.dart';

import '../../support/monitor_fixtures.dart';

/// In-memory [BillingService] fake feeding [EntitlementController] a fixed
/// plan id, mirroring the fake in `entitlement_controller_test.dart`.
///
/// The READ contract only. The purchase and management calls live on their own
/// rail contracts, which this fake deliberately does not serve: this form spends
/// no money, and a fake must not carry a contract its subject never calls.
class _FakeBilling implements BillingService {
  _FakeBilling({this.entitlementPlan, List<Plan>? catalog})
    : _catalog = catalog ?? plans;

  /// The plan id `currentEntitlement` resolves to.
  final String? entitlementPlan;

  /// The catalog `getPlans` resolves to. Defaults to the shared design-lab
  /// fixture; a test that needs a specific `limits.regions` value (the shared
  /// fixture predates that field and defaults it to null/unlimited) supplies
  /// its own catalog instead.
  final List<Plan> _catalog;

  @override
  Future<BillingEntitlement> currentEntitlement() async {
    // Through the real decoder, from the wire words the producer emits:
    // `plan_status` is the only status key this wire has ever carried.
    return BillingEntitlement.fromMap(<String, dynamic>{
      'plan': entitlementPlan,
      'plan_status': 'active',
    });
  }

  @override
  Future<List<Map<String, dynamic>>> getPlans() async =>
      planWireRows(_catalog);

  @override
  Future<List<UsageStat>> getUsage() async => const [];

  @override
  Future<BillingInvoicesPage> getInvoices({String? cursor}) =>
      throw UnimplementedError();

  @override
  Future<PaymentMethod> getPaymentMethod() => throw UnimplementedError();
}

/// In-memory language loader supplying every [trans] key the create form
/// renders, mirroring `incident_views_test.dart`'s loader.
///
/// Every key a widget under test renders MUST be here: a missing key renders as
/// the ~40-character raw key and produces a false overflow failure.
class _IncidentCreateLangLoader implements TranslationLoader {
  @override
  Future<Map<String, dynamic>> load(Locale locale) async {
    return {
      'common.error_occurred': 'Something went wrong.',
      'common.done': 'Done',
      'uptizm.monitors.check_col_time': 'Time',
      'uptizm.incidents.form_prefill_title': 'Investigating :monitor',
      'uptizm.incidents.back': 'Incidents',
      'uptizm.incidents.form_title_new': 'New incident',
      'uptizm.incidents.form_title_maintenance': 'Schedule maintenance',
      'uptizm.incidents.form_description': 'File an incident manually.',
      'uptizm.incidents.form_type_label': 'Type',
      'uptizm.incidents.form_kind_incident': 'Incident',
      'uptizm.incidents.form_kind_maintenance': 'Scheduled maintenance',
      'uptizm.incidents.form_title_label': 'Title',
      'uptizm.incidents.form_title_placeholder_incident': '503s',
      'uptizm.incidents.form_title_placeholder_maintenance': 'Upgrade',
      'uptizm.incidents.form_title_error_required': 'Title is required.',
      'uptizm.incidents.form_affected_error_required': 'Select a monitor.',
      'uptizm.incidents.form_affected_label': 'Affected monitors',
      'uptizm.incidents.form_affected_empty': 'You have no monitors yet.',
      'uptizm.incidents.form_affected_loading': 'Loading your monitors...',
      'uptizm.incidents.form_affected_hint': 'Drives the status page.',
      'uptizm.incidents.form_severity_label': 'Severity',
      'uptizm.incidents.form_severity_hint': 'Operator-side priority.',
      'uptizm.incidents.form_severity_critical': 'Critical',
      'uptizm.incidents.form_severity_warning': 'Warning',
      'uptizm.incidents.form_severity_info': 'Info',
      'uptizm.incidents.form_starts_label': 'Starts',
      'uptizm.incidents.form_ends_label': 'Ends',
      'uptizm.incidents.form_impact_label': 'Status page impact',
      'uptizm.incidents.form_impact_hint': 'How this reads to customers.',
      'uptizm.incidents.form_impact_down': 'Major outage',
      'uptizm.incidents.form_impact_degraded': 'Degraded',
      'uptizm.incidents.form_impact_info': 'Maintenance',
      'uptizm.incidents.form_first_update_label': 'First update',
      'uptizm.incidents.form_first_update_hint': 'The opening post.',
      'uptizm.incidents.form_first_update_placeholder_incident': 'Investigating.',
      'uptizm.incidents.form_first_update_placeholder_maintenance': 'Planned.',
      'uptizm.incidents.form_notify_label': 'Notify subscribers',
      'uptizm.incidents.form_notify_hint': 'Email subscribers.',
      'uptizm.incidents.submit_open': 'Open incident',
      'uptizm.incidents.submit_schedule': 'Schedule maintenance',
      'uptizm.incidents.cancel': 'Cancel',
      'uptizm.incidents.ai_generic_banner': 'Uptizm AI analyzes this incident.',
      'uptizm.incidents.ai_promoted_title': 'Promoted from an AI anomaly',
      'uptizm.incidents.ai_promoted_explainer': 'Pre-filled from the anomaly.',
    };
  }
}

/// The status page the maintenance window is announced on, publishing the
/// `checkout` fixture monitor as a component.
StatusPage get _publicPage => StatusPage.fromMap(<String, dynamic>{
  'id': 'page-1',
  'name': 'Public status',
  'monitors': <Map<String, dynamic>>[
    <String, dynamic>{'id': 'checkout', 'name': 'Checkout service'},
  ],
});

void main() {
  setUp(() async {
    MagicApp.reset();
    Magic.flush();
    // Card / PageHeader / Button / Input / SegmentedControl / Select / Textarea
    // / Switch resolve their themes through MagicStarter.*.
    Magic.singleton('magic_starter', () => MagicStarterManager());
    // The write paths log and toast on failure; both need their service bound.
    Magic.singleton('log', () => LogManager());
    Config.set('logging', {
      'default': 'console',
      'channels': {
        'console': {'driver': 'console', 'level': 'debug'},
      },
    });

    Translator.instance.setLoader(_IncidentCreateLangLoader());
    await Translator.instance.setLocale(const Locale('en'));

    // The view's backing controller: registered and marked initialized so its
    // own `onInit` list fetch does not race the assertions below.
    final IncidentController incidents = Magic.findOrPut(
      IncidentController.new,
    );
    incidents.onInit();
    await Future<void>.delayed(Duration.zero);
    incidents.setSuccess(const []);
  });

  tearDown(() {
    MagicApp.reset();
    Magic.flush();
  });

  /// Wraps [widget] in the standard test harness (MaterialApp + WindTheme under
  /// a fixed MediaQuery), mirroring `incident_views_test.dart`.
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

  /// Registers the routes `MaintenanceController.create` navigates to on
  /// success (the context-free `MagicRoute.to` needs an initialized router).
  void registerRoutes() {
    MagicRouter.reset();
    MagicRoute.page('/', () => const SizedBox());
    MagicRoute.page('/incidents', () => const SizedBox());
    MagicRouter.instance.routerConfig;
    addTearDown(MagicRouter.reset);
  }

  /// Pumps the create view, switches it to the maintenance kind, and fills the
  /// title, the affected monitor and the first update. Leaves the window bounds
  /// at the form's own defaults, which is the shape a real operator submits
  /// when the default slot suits them.
  Future<void> pumpFilledMaintenanceForm(WidgetTester tester) async {
    await tester.binding.setSurfaceSize(const Size(1280, 3200));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    MonitorController.instance.seedForTest(monitorFixtures);
    addTearDown(() => MonitorController.instance.seedForTest(const []));
    StatusPageController.instance.seedForTest(<StatusPage>[_publicPage]);
    addTearDown(() => StatusPageController.instance.seedForTest(const []));

    await tester.pumpWidget(wrap(const IncidentCreateView()));
    await tester.pump();

    await tester.tap(find.text(trans('uptizm.incidents.form_kind_maintenance')));
    await tester.pump();

    await tester.enterText(
      find.widgetWithText(
        MSInput,
        trans('uptizm.incidents.form_title_placeholder_maintenance'),
      ),
      'Database upgrade',
    );
    await tester.pump();

    await tester.tap(find.text('Checkout service'));
    await tester.pump();

    await tester.enterText(
      find.widgetWithText(
        MSTextarea,
        trans('uptizm.incidents.form_first_update_placeholder_maintenance'),
      ),
      'A brief read-only period is expected.',
    );
    await tester.pump();
  }

  /// Taps the maintenance submit button.
  Future<void> submit(WidgetTester tester) async {
    final Finder button = find.widgetWithText(
      MSButton,
      trans('uptizm.incidents.submit_schedule'),
    );
    await tester.ensureVisible(button);
    await tester.pump();
    await tester.tap(button);
    await tester.pump();
    await tester.pump();
  }

  group('IncidentCreateView maintenance write', () {
    testWidgets(
      'submitting the maintenance kind POSTs the window to '
      '/scheduled-maintenances with UTC ISO-8601 bounds',
      (tester) async {
        final fake = Http.fake();
        registerRoutes();

        await pumpFilledMaintenanceForm(tester);
        await submit(tester);

        expect(tester.takeException(), isNull);

        final Iterable<MagicRequest> posts = fake.recorded
            .map((entry) => entry.$1)
            .where(
              (r) => r.method == 'POST' && r.url == '/scheduled-maintenances',
            );
        expect(
          posts,
          hasLength(1),
          reason: 'The maintenance kind must post exactly one window',
        );

        final Map<String, dynamic> body =
            (posts.first.data as Map).cast<String, dynamic>();

        expect(body['title'], equals('Database upgrade'));
        expect(
          body['description'],
          equals('A brief read-only period is expected.'),
          reason: 'The first-update copy is the window public description',
        );
        expect(
          body['status_page_id'],
          equals('page-1'),
          reason: 'StoreScheduledMaintenanceRequest requires a status page, '
              'resolved from the page publishing the affected monitor',
        );
        expect(
          body['monitor_ids'],
          equals(<String>['checkout']),
          reason: 'The affected monitors ride the pivot as monitor_ids',
        );

        // The load-bearing assertion: Dart cannot store an arbitrary UTC
        // offset, and the backend's database session is pinned to UTC, so a
        // naive local string silently shifts the window.
        final String startsAt = body['starts_at'] as String;
        final String endsAt = body['ends_at'] as String;
        expect(
          startsAt,
          endsWith('Z'),
          reason: 'starts_at must cross the wire as UTC, never local time',
        );
        expect(
          endsAt,
          endsWith('Z'),
          reason: 'ends_at must cross the wire as UTC, never local time',
        );
        expect(DateTime.parse(startsAt).isUtc, isTrue);
        expect(
          DateTime.parse(endsAt).isAfter(DateTime.parse(startsAt)),
          isTrue,
          reason: 'the default window must satisfy the backend after: rule',
        );

        // The window is not an incident: nothing may reach the incident
        // endpoint on this path.
        fake.assertNotSent((r) => r.method == 'POST' && r.url == '/incidents');
      },
    );

    testWidgets(
      'a time picked on the datetime control is what reaches the wire',
      (tester) async {
        // The whole point of the datetime mode: the two raw-string inputs this
        // replaced could not express an hour at all. Stepping the Starts hour
        // must move the posted instant by exactly that hour, in UTC.
        final fake = Http.fake();
        registerRoutes();

        await pumpFilledMaintenanceForm(tester);
        // Disposed at the end of the body rather than through addTearDown: the
        // framework verifies handle disposal BEFORE tear-downs run.
        final SemanticsHandle semantics = tester.ensureSemantics();

        /// The Starts picker's live value, read off the mounted control.
        DateTime startsPickerValue() {
          return tester
              .widget<WDatePicker>(
                find.descendant(
                  of: find.byKey(
                    const ValueKey<String>('maintenance-window-starts'),
                  ),
                  matching: find.byType(WDatePicker),
                ),
              )
              .value!;
        }

        final DateTime seeded = startsPickerValue();
        expect(
          seeded.minute % 15,
          equals(0),
          reason: 'the default window opens on a clean quarter hour',
        );

        await tester.tap(
          find.descendant(
            of: find.byKey(const ValueKey<String>('maintenance-window-starts')),
            matching: find.byType(WDatePicker),
          ),
        );
        await tester.pumpAndSettle();

        // Step DOWN on the day's LAST hour, UP everywhere else. WDatePicker
        // deliberately refuses to wrap the hour (rolling 23 to 0 would move the
        // emitted instant a day BACKWARDS while the calendar still showed the same
        // day), so at hour 23 the increment control is disabled and the tap is a
        // no-op. This test used to always tap Increase and assert exactly
        // `seeded + 1h`, which made it fail for the one hour a day when the seeded
        // default landed on 23: green on a UTC+3 machine, red on a UTC runner
        // between 22:00 and 23:00.
        final bool seededOnLastHour = seeded.hour == 23;

        await tester.tap(
          find.bySemanticsLabel(
            seededOnLastHour ? 'Decrease hour' : 'Increase hour',
          ),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.text(trans('common.done')));
        await tester.pumpAndSettle();

        final DateTime picked = startsPickerValue();
        expect(
          picked,
          equals(seeded.add(Duration(hours: seededOnLastHour ? -1 : 1))),
          reason: 'the datetime control must carry the picked hour',
        );

        await submit(tester);

        final MagicRequest post = fake.recorded
            .map((entry) => entry.$1)
            .firstWhere(
              (r) => r.method == 'POST' && r.url == '/scheduled-maintenances',
            );
        final Map<String, dynamic> body = (post.data as Map)
            .cast<String, dynamic>();

        expect(
          body['starts_at'],
          equals(picked.toUtc().toIso8601String()),
          reason: 'the posted instant is the picked LOCAL time converted to '
              'UTC, never the local wall clock as-is',
        );

        semantics.dispose();
      },
    );

    testWidgets('every field the form collects survives the ORM filter', (
      tester,
    ) async {
      // A field missing from ScheduledMaintenance.fillable is stripped BEFORE
      // the request leaves the client, with no error anywhere: the exact way
      // this codebase has already lost writes twice. Asserting over the posted
      // body's own keys would be vacuous (a dropped key is simply not there),
      // so this checks the CONTRACT list against both ends.
      const List<String> wireFields = <String>[
        'status_page_id',
        'title',
        'description',
        'starts_at',
        'ends_at',
        'monitor_ids',
      ];

      final fake = Http.fake();
      registerRoutes();

      await pumpFilledMaintenanceForm(tester);
      await submit(tester);

      final MagicRequest post = fake.recorded
          .map((entry) => entry.$1)
          .firstWhere(
            (r) => r.method == 'POST' && r.url == '/scheduled-maintenances',
          );
      final Map<String, dynamic> body = (post.data as Map)
          .cast<String, dynamic>();
      final List<String> fillable = ScheduledMaintenance().fillable;

      for (final String key in wireFields) {
        expect(
          fillable,
          contains(key),
          reason: 'the form posts "$key", so ScheduledMaintenance.fillable '
              'must carry it or the ORM drops it before the request is sent',
        );
        expect(
          body.containsKey(key),
          isTrue,
          reason: '"$key" left the form but never reached the request body',
        );
      }
    });

    testWidgets('a server 422 renders under the matching window field', (
      tester,
    ) async {
      const String serverMessage = 'The ends at must be after starts at.';
      Http.fake({
        'scheduled-maintenances': Http.response({
          'message': serverMessage,
          'errors': {
            'ends_at': [serverMessage],
          },
        }, 422),
      });
      registerRoutes();

      await pumpFilledMaintenanceForm(tester);
      await submit(tester);

      expect(tester.takeException(), isNull);
      expect(
        find.text(serverMessage),
        findsOneWidget,
        reason: 'a 422 on ends_at must paint the Ends field error slot',
      );
    });

    /// The maintenance tab's empty state says "Plan a window" and used to land
    /// here on the INCIDENT form, one unnoticed switch away from declaring an
    /// outage that pages the on-call and publishes a red banner, for work the
    /// operator meant to ANNOUNCE.
    ///
    /// Driven through the router rather than by pumping the view, because the
    /// query is read from `MagicRouter.instance.queryParameters`, which is only
    /// populated by a route's own pageBuilder.
    testWidgets('the kind query param opens the maintenance form', (
      tester,
    ) async {
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      MonitorController.instance.seedForTest(monitorFixtures);
      addTearDown(() => MonitorController.instance.seedForTest(const []));
      StatusPageController.instance.seedForTest(<StatusPage>[_publicPage]);
      addTearDown(() => StatusPageController.instance.seedForTest(const []));

      MagicRouter.reset();
      MagicRoute.page('/', () => const SizedBox());
      MagicRoute.page('/incidents', () => const SizedBox());
      MagicRoute.page('/incidents/new', () => const IncidentCreateView());
      addTearDown(MagicRouter.reset);

      await tester.pumpWidget(
        MaterialApp.router(
          routerConfig: MagicRouter.instance.routerConfig,
          builder: (context, child) => MediaQuery(
            data: const MediaQueryData(size: Size(1280, 3200)),
            child: WindTheme(data: WindThemeData(), child: child!),
          ),
        ),
      );
      await tester.pumpAndSettle();

      MagicRoute.to('/incidents/new', query: const {'kind': 'maintenance'});
      await tester.pumpAndSettle();

      // The maintenance form's own title placeholder, which the incident kind
      // never renders.
      expect(
        find.widgetWithText(
          MSInput,
          trans('uptizm.incidents.form_title_placeholder_maintenance'),
        ),
        findsOneWidget,
      );
    });

    testWidgets('a blank title blocks the maintenance round trip', (
      tester,
    ) async {
      final fake = Http.fake();
      registerRoutes();

      MonitorController.instance.seedForTest(monitorFixtures);
      addTearDown(() => MonitorController.instance.seedForTest(const []));
      StatusPageController.instance.seedForTest(<StatusPage>[_publicPage]);
      addTearDown(() => StatusPageController.instance.seedForTest(const []));

      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const IncidentCreateView()));
      await tester.pump();

      await tester.tap(
        find.text(trans('uptizm.incidents.form_kind_maintenance')),
      );
      await tester.pump();

      await submit(tester);

      expect(
        find.text(trans('uptizm.incidents.form_title_error_required')),
        findsOneWidget,
        reason: 'a blank title must surface inline before any round trip',
      );
      fake.assertNotSent(
        (r) => r.method == 'POST' && r.url == '/scheduled-maintenances',
      );
    });

    testWidgets('a team with no status page is told so before it submits', (
      tester,
    ) async {
      // The defect this pins, reported from a live session: the form used to
      // RESOLVE `status_page_id` silently and omit the key when the team owned
      // no page, so a fully filled window came back as
      // "The status page id field is required" under an unexpected-error toast,
      // naming a field the form never showed. The roster is deliberately left
      // empty here; every other maintenance test seeds one page, which is why
      // none of them could see this.
      final fake = Http.fake();
      registerRoutes();

      MonitorController.instance.seedForTest(monitorFixtures);
      addTearDown(() => MonitorController.instance.seedForTest(const []));
      StatusPageController.instance.seedForTest(const <StatusPage>[]);

      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const IncidentCreateView()));
      await tester.pump();

      await tester.tap(
        find.text(trans('uptizm.incidents.form_kind_maintenance')),
      );
      await tester.pump();

      expect(
        find.text(trans('uptizm.incidents.form_status_page_empty')),
        findsOneWidget,
        reason:
            'the reason and the remedy replace the select, rather than the '
            'operator discovering the requirement from a 422',
      );

      // Fill everything ELSE, so the missing page is the only thing blocking
      // the submit. Without this the title and affected-monitor checks would
      // block it anyway and the no-POST assertion below would prove nothing.
      await tester.enterText(
        find.widgetWithText(
          MSInput,
          trans('uptizm.incidents.form_title_placeholder_maintenance'),
        ),
        'Database upgrade',
      );
      await tester.pump();

      await tester.tap(find.text(monitorFixtures.first.name ?? ''));
      await tester.pump();

      await submit(tester);

      expect(
        find.text(trans('uptizm.incidents.form_status_page_error_required')),
        findsOneWidget,
        reason: 'the missing page surfaces in its own inline slot',
      );
      fake.assertNotSent(
        (r) => r.method == 'POST' && r.url == '/scheduled-maintenances',
      );
    });

    testWidgets('the picked status page is the one the window is posted on', (
      tester,
    ) async {
      // Two pages, and the affected monitor belongs to NEITHER, so the seeded
      // default falls back to the first. Picking the second must win: the page
      // decides which public page renders the window and which confirmed
      // subscribers are mailed, so an arbitrary default may never be final.
      final StatusPage second = StatusPage.fromMap(<String, dynamic>{
        'id': 'page-2',
        'name': 'Second Status',
        'slug': 'second',
        'monitor_ids': const <String>[],
      });

      final fake = Http.fake();
      registerRoutes();

      MonitorController.instance.seedForTest(monitorFixtures);
      addTearDown(() => MonitorController.instance.seedForTest(const []));
      StatusPageController.instance.seedForTest(<StatusPage>[
        _publicPage,
        second,
      ]);
      addTearDown(() => StatusPageController.instance.seedForTest(const []));

      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(wrap(const IncidentCreateView()));
      await tester.pump();

      await tester.tap(
        find.text(trans('uptizm.incidents.form_kind_maintenance')),
      );
      await tester.pump();

      await tester.enterText(
        find.widgetWithText(
          MSInput,
          trans('uptizm.incidents.form_title_placeholder_maintenance'),
        ),
        'Database upgrade',
      );
      await tester.pump();

      await tester.tap(find.text(monitorFixtures.first.name ?? ''));
      await tester.pump();

      // Open the page select and choose the second entry.
      await tester.tap(find.text(_publicPage.name ?? ''));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Second Status').last);
      await tester.pumpAndSettle();

      await submit(tester);

      final MagicRequest post = fake.recorded
          .map((entry) => entry.$1)
          .firstWhere(
            (r) => r.method == 'POST' && r.url == '/scheduled-maintenances',
          );
      final Map<String, dynamic> body = (post.data as Map)
          .cast<String, dynamic>();

      expect(
        body['status_page_id'],
        equals('page-2'),
        reason: 'the operator pick reaches the wire, not the seeded default',
      );
    });
  });

  group('IncidentCreateView affected monitors', () {
    testWidgets('an empty roster says so instead of rendering nothing', (
      tester,
    ) async {
      // Driven for real against the running app: on a cold open of this form
      // the snapshot read label, hint, then straight on to Severity. The one
      // REQUIRED choice on the screen had no control under it and the screen
      // gave no reason, so the form could not be completed and did not say why.
      await tester.binding.setSurfaceSize(const Size(1280, 3200));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      // No `monitors` stub: the roster answers empty, which is the cold-open
      // state and also the state of a team that owns no monitors yet.
      Http.fake();

      await tester.pumpWidget(wrap(const IncidentCreateView()));
      await tester.pump();

      expect(
        find.text(trans('uptizm.incidents.form_affected_empty')),
        findsOneWidget,
        reason: 'a required field with no options has to say why',
      );
    });
  });

  // ---------------------------------------------------------------------------
  // The AI banner may not promise what the plan will refuse
  // ---------------------------------------------------------------------------

  group('IncidentCreateView AI banner is plan-gated', () {
    testWidgets('a Free plan is not promised an analysis it will not get', (
      tester,
    ) async {
      // The banner says the analysis "lands on the detail page within seconds".
      // On Free the detail page answers "AI incident analysis is available on
      // the Pro plan and up", so the form was promising something the user
      // discovers is refused only after they have opened the incident.
      // `EntitlementController` is registered before the view resolves
      // `.instance`, mirroring `monitor_write_test.dart`.
      Magic.findOrPut(
        () => EntitlementController(
          billing: _FakeBilling(entitlementPlan: 'free'),
        ),
      );
      await EntitlementController.instance.reload();

      await tester.pumpWidget(wrap(const IncidentCreateView()));
      await tester.pumpAndSettle();

      expect(find.text('Uptizm AI analyzes this incident.'), findsNothing);
    });

    testWidgets('a plan that unlocks analysis still sees the banner', (
      tester,
    ) async {
      Magic.findOrPut(
        () => EntitlementController(
          billing: _FakeBilling(entitlementPlan: 'pro'),
        ),
      );
      await EntitlementController.instance.reload();

      await tester.pumpWidget(wrap(const IncidentCreateView()));
      await tester.pumpAndSettle();

      expect(find.text('Uptizm AI analyzes this incident.'), findsOneWidget);
    });
  });
}
