import 'package:flutter_test/flutter_test.dart';

import 'package:uptizm/app/mocks/monitors.dart';
import 'package:uptizm/app/enums/status_key.dart';
import 'package:uptizm/app/enums/domain_mode.dart' show DomainMode;
import 'package:uptizm/app/support/status_page_support.dart'
    show cloneStatusPage, pageUrl, worstStatus;
import 'package:uptizm/app/support/status_page_types.dart' show PublicComponent;
import 'package:uptizm/app/mocks/status_pages.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/models/status_page.dart';
import 'package:uptizm/resources/views/status/status_form_support.dart';

void main() {
  // ---------------------------------------------------------------------------
  // worstStatus
  // ---------------------------------------------------------------------------

  group('worstStatus', () {
    test('an empty component list defaults to up', () {
      expect(worstStatus(const []), StatusKey.up);
    });

    test('down outranks every other status', () {
      final components = [
        _component(StatusKey.degraded),
        _component(StatusKey.down),
        _component(StatusKey.info),
      ];
      expect(worstStatus(components), StatusKey.down);
    });

    test('degraded outranks info and paused', () {
      final components = [
        _component(StatusKey.paused),
        _component(StatusKey.info),
        _component(StatusKey.degraded),
      ];
      expect(worstStatus(components), StatusKey.degraded);
    });

    test('info outranks paused', () {
      final components = [
        _component(StatusKey.paused),
        _component(StatusKey.info),
      ];
      expect(worstStatus(components), StatusKey.info);
    });

    test('paused outranks up and ai', () {
      final components = [
        _component(StatusKey.up),
        _component(StatusKey.ai),
        _component(StatusKey.paused),
      ];
      expect(worstStatus(components), StatusKey.paused);
    });

    test('up and ai are the lowest rank and yield up when tied', () {
      final components = [_component(StatusKey.ai), _component(StatusKey.up)];
      expect(worstStatus(components), StatusKey.up);
    });
  });

  // ---------------------------------------------------------------------------
  // isConfigValid
  // ---------------------------------------------------------------------------

  group('isConfigValid', () {
    test('a fully populated config is valid', () {
      expect(isConfigValid(statusPages.first), isTrue);
    });

    test('an empty name is invalid', () {
      final config = cloneStatusPage(statusPages.first, name: '  ');
      expect(isConfigValid(config), isFalse);
    });

    test('an empty slug is invalid', () {
      final config = cloneStatusPage(statusPages.first, slug: '');
      expect(isConfigValid(config), isFalse);
    });

    test('no assigned monitors is invalid', () {
      final config = cloneStatusPage(statusPages.first, monitorIds: const []);
      expect(isConfigValid(config), isFalse);
    });
  });

  // ---------------------------------------------------------------------------
  // pageUrl
  // ---------------------------------------------------------------------------

  group('pageUrl', () {
    test('subdomain mode renders slug.uptizm.com', () {
      final config = cloneStatusPage(
        statusPages.first,
        slug: 'acme',
        domainMode: DomainMode.subdomain,
      );
      expect(pageUrl(config), 'acme.uptizm.com');
    });

    test('path mode renders uptizm.com/status/slug', () {
      final config = cloneStatusPage(
        statusPages.first,
        slug: 'acme',
        domainMode: DomainMode.path,
      );
      expect(pageUrl(config), 'uptizm.com/status/acme');
    });

    test('an empty slug falls back to your-page', () {
      final config = cloneStatusPage(
        statusPages.first,
        slug: '',
        domainMode: DomainMode.path,
      );
      expect(pageUrl(config), 'uptizm.com/status/your-page');
    });
  });

  // ---------------------------------------------------------------------------
  // aiDraftFor
  // ---------------------------------------------------------------------------

  group('aiDraftFor', () {
    test('prefills name, slug, and description for a non-empty monitor set', () {
      final List<String> ids = monitors.map((m) => m.id).toList();
      final StatusPage draft = aiDraftFor(ids);

      expect(draft.name, isNotEmpty);
      expect(draft.slug, isNotEmpty);
      expect(draft.description, isNotEmpty);
      expect(draft.monitorIds, ids);
    });

    test('metric keys resolve only to metrics of the selected monitors', () {
      final StatusPage draft = aiDraftFor(const ['marketing']);

      expect(draft.monitorIds, const ['marketing']);
      for (final String key in draft.metricKeys) {
        expect(key, startsWith('marketing.'));
      }
    });

    test('an empty monitor set yields an empty metric-key list', () {
      final StatusPage draft = aiDraftFor(const []);
      expect(draft.monitorIds, isEmpty);
      expect(draft.metricKeys, isEmpty);
    });
  });

  // ---------------------------------------------------------------------------
  // componentsFor
  // ---------------------------------------------------------------------------

  group('componentsFor', () {
    test('resolves every known monitor id to a public component', () {
      final config = statusPages.first;
      final List<PublicComponent> components = componentsFor(config);

      expect(components.length, config.monitorIds.length);
      for (var i = 0; i < config.monitorIds.length; i++) {
        final Monitor monitor = findMonitor(config.monitorIds[i])!;
        expect(components[i].name, monitor.name);
        expect(components[i].status, monitor.status);
      }
    });

    test('unknown monitor ids are dropped', () {
      final config = cloneStatusPage(
        statusPages.first,
        monitorIds: const ['marketing', 'not-a-real-monitor'],
      );
      final List<PublicComponent> components = componentsFor(config);

      expect(components.length, 1);
      expect(components.first.name, findMonitor('marketing')!.name);
    });
  });

  // ---------------------------------------------------------------------------
  // findStatusPage
  // ---------------------------------------------------------------------------

  group('findStatusPage', () {
    test('a known id resolves to its fixture', () {
      final StatusPage? page = findStatusPage('acme');
      expect(page, isNotNull);
      expect(page!.id, 'acme');
    });

    test('an unknown id resolves to null', () {
      expect(findStatusPage('nope'), isNull);
    });

    test('a null id resolves to null', () {
      expect(findStatusPage(null), isNull);
    });
  });
}

/// Builds a minimal [PublicComponent] carrying only the [status] under test.
PublicComponent _component(StatusKey status) {
  return PublicComponent(
    name: 'Component',
    status: status,
    uptime: '100% uptime',
    segments: uptime90(),
  );
}
