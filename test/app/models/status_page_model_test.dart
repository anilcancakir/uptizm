import 'package:flutter/painting.dart' show Color;
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/enums/domain_mode.dart' show DomainMode;
import 'package:uptizm/app/enums/status_key.dart' show StatusKey;
import 'package:uptizm/app/models/status_page.dart';

void main() {
  group('StatusPage model', () {
    test('resource / table / incrementing configuration', () {
      final page = StatusPage.fromMap({'id': 'acme', 'name': 'Acme'});
      expect(page.table, 'status_pages');
      expect(page.resource, 'status-pages');
      expect(page.incrementing, isFalse);
      expect(page.fillable, contains('domain_mode'));
      expect(page.fillable, contains('brand_color'));
    });

    test('fromMap hydrates scalars and marks exists', () {
      final page = StatusPage.fromMap({
        'id': 'acme',
        'name': 'Acme Status',
        'slug': 'acme',
        'logo_text': 'A',
        'description': 'Public status',
        'is_public': true,
        'subscriptions_enabled': false,
      });
      expect(page.exists, isTrue);
      expect(page.id, 'acme');
      expect(page.name, 'Acme Status');
      expect(page.slug, 'acme');
      expect(page.logoText, 'A');
      expect(page.description, 'Public status');
      expect(page.isPublic, isTrue);
      expect(page.subscriptionsEnabled, isFalse);
    });

    test('domainMode parses subdomain / path and falls back', () {
      expect(
        StatusPage.fromMap({'domain_mode': 'subdomain'}).domainMode,
        DomainMode.subdomain,
      );
      expect(
        StatusPage.fromMap({'domain_mode': 'path'}).domainMode,
        DomainMode.path,
      );
      // Missing or unrecognized -> subdomain default.
      expect(
        StatusPage.fromMap(<String, dynamic>{}).domainMode,
        DomainMode.subdomain,
      );
      expect(
        StatusPage.fromMap({'domain_mode': 'bogus'}).domainMode,
        DomainMode.subdomain,
      );
    });

    test('domainMode setter stores the wire name', () {
      final page = StatusPage()..domainMode = DomainMode.path;
      expect(page.domainMode, DomainMode.path);
    });

    test('brandColor parses a #rrggbb hex and falls back', () {
      expect(
        StatusPage.fromMap({'brand_color': '#008560'}).brandColor,
        const Color(0xFF008560),
      );
      expect(
        StatusPage.fromMap({'brand_color': '008560'}).brandColor,
        const Color(0xFF008560),
      );
      // Missing -> opaque black fallback.
      expect(
        StatusPage.fromMap(<String, dynamic>{}).brandColor,
        const Color(0xFF000000),
      );
    });

    test('brandColor setter round-trips through a #rrggbb hex', () {
      final page = StatusPage()..brandColor = const Color(0xFF008560);
      expect(page.brandColor, const Color(0xFF008560));
    });

    test('monitorIds reads the monitors pivot array', () {
      final page = StatusPage.fromMap({
        'id': 'acme',
        'monitors': [
          {'id': 'm1', 'name': 'API'},
          {'id': 'm2', 'name': 'Web'},
        ],
      });
      expect(page.monitorIds, ['m1', 'm2']);
    });

    test('monitorIds is empty when the pivot is absent', () {
      expect(StatusPage.fromMap({'id': 'acme'}).monitorIds, isEmpty);
    });

    test('metricKeys reads the optional metric_keys array', () {
      expect(
        StatusPage.fromMap({'metric_keys': ['m1.latency', 'm2.uptime']})
            .metricKeys,
        ['m1.latency', 'm2.uptime'],
      );
      expect(StatusPage.fromMap(<String, dynamic>{}).metricKeys, isEmpty);
    });

    test('all() routes through GET /status-pages via Http.fake', () async {
      final fake = Http.fake({
        'status-pages': Http.response({
          'data': [
            {'id': 'acme', 'name': 'Acme'},
            {'id': 'ops', 'name': 'Ops'},
          ],
        }),
      });
      final pages = await StatusPage.all();
      expect(pages.length, 2);
      expect(pages[0].id, 'acme');
      expect(pages[1].name, 'Ops');
      fake.assertSent((r) => r.url.contains('status-pages'));
      Http.unfake();
    });

    test('find() routes through GET /status-pages/{id}', () async {
      final fake = Http.fake({
        'status-pages/acme': Http.response({
          'data': {
            'id': 'acme',
            'name': 'Acme',
            'domain_mode': 'path',
            'brand_color': '#008560',
            'monitors': [{'id': 'm1'}],
          },
        }),
      });
      final page = await StatusPage.find('acme');
      expect(page, isNotNull);
      expect(page!.id, 'acme');
      expect(page.domainMode, DomainMode.path);
      expect(page.brandColor, const Color(0xFF008560));
      expect(page.monitorIds, ['m1']);
      fake.assertSent((r) => r.url.contains('status-pages/acme'));
      Http.unfake();
    });

    test('fromJson round-trips the wire shape', () {
      final page = StatusPage.fromJson(
        '{"id":"acme","name":"Acme","domain_mode":"subdomain","brand_color":"#008560","is_public":true}',
      );
      expect(page.id, 'acme');
      expect(page.domainMode, DomainMode.subdomain);
      expect(page.brandColor, const Color(0xFF008560));
      expect(page.isPublic, isTrue);
    });

    group('components', () {
      /// Builds a page carrying the pivot shape `StatusPageResource` sends.
      StatusPage pageWith(List<Map<String, dynamic>> monitors) {
        return StatusPage.fromMap(<String, dynamic>{
          'id': 'acme',
          'name': 'Acme Status',
          'monitors': monitors,
        });
      }

      test('reads name, live status and order from the pivot', () {
        // These used to be resolved by looking each monitor id up in a
        // design-lab fixture list, which could never match a real uuid, so every
        // page reported zero components and (via worstStatus's old empty-list
        // default) rendered as "Operational" while its monitors were down.
        final StatusPage page = pageWith([
          <String, dynamic>{
            'id': 'm2',
            'name': 'Website',
            'display_order': 1,
            'last_status': 'down',
          },
          <String, dynamic>{
            'id': 'm1',
            'name': 'API',
            'display_order': 0,
            'last_status': 'up',
          },
        ]);

        expect(
          page.components.map((c) => c.name).toList(),
          equals(['API', 'Website']),
          reason: 'components come back in display order',
        );
        expect(page.components.last.status, StatusKey.down);
      });

      test('prefers the operator custom label over the monitor name', () {
        final StatusPage page = pageWith([
          <String, dynamic>{
            'id': 'm1',
            'name': 'checkout-prod-eu',
            'custom_label': 'Checkout',
            'display_order': 0,
            'last_status': 'up',
          },
        ]);

        expect(page.components.single.name, equals('Checkout'));
      });

      test('a monitor with no check yet is Pending, never up', () {
        final StatusPage page = pageWith([
          <String, dynamic>{'id': 'm1', 'name': 'API', 'display_order': 0},
        ]);

        expect(
          page.components.single.status,
          StatusKey.pending,
          reason: 'the absence of a measurement is not evidence of health',
        );
      });

      test('excludes a component the public page hides', () {
        // The SECOND gate: StatusPageAssembler filters public components on
        // show_on_status_page, so an attached-but-unpublished monitor must not
        // appear here either, or the in-app preview promises a component the real
        // page will not show.
        final StatusPage page = pageWith([
          <String, dynamic>{
            'id': 'm1',
            'name': 'API',
            'display_order': 0,
            'last_status': 'up',
            'show_on_status_page': true,
          },
          <String, dynamic>{
            'id': 'm2',
            'name': 'Internal probe',
            'display_order': 1,
            'last_status': 'down',
            'show_on_status_page': false,
          },
        ]);

        expect(page.components.map((c) => c.name).toList(), equals(['API']));
      });

      test('treats a row without the flag as public', () {
        // An older payload predating the flag must keep rendering rather than
        // silently emptying the page.
        final StatusPage page = pageWith([
          <String, dynamic>{
            'id': 'm1',
            'name': 'API',
            'display_order': 0,
            'last_status': 'up',
          },
        ]);

        expect(page.components, hasLength(1));
      });

      test('a page with no pivot has no components', () {
        expect(StatusPage.fromMap({'id': 'acme'}).components, isEmpty);
      });
    });
  });
}
