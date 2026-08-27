import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';

import 'package:uptizm/app/enums/incident_lifecycle.dart';
import 'package:uptizm/resources/views/incidents/incident_form_support.dart';
import 'package:uptizm/resources/views/monitors/monitor_metrics_support.dart'
    show MetricOption;

import '../../support/bundled_lang.dart';

/// `mitigated` has to decode without becoming a rung.
///
/// The backend enum has carried it since before the redesign and still emits it
/// for those rows. This enum did not, so `lifecycleFromWire` fell through its
/// `orElse` and answered `detected`: an incident somebody had already mitigated
/// rendered as the EARLIEST stage of the ladder, in the list badge, the detail
/// header and the timeline alike. The fallback exists for a stage a NEWER
/// backend invents, and it was firing on one this backend has always sent.
///
/// The other half is that it must not become selectable. The backend documents
/// the ladder as detected -> investigating -> identified -> monitoring ->
/// resolved, so offering `mitigated` in the composer would let an operator move
/// a live incident onto a stage the product no longer uses.
void main() {
  Future<void> useLocale(String locale) async {
    Translator.instance.setLoader(_BundledLoader(locale));
    await Translator.instance.setLocale(Locale(locale));
  }

  test('a mitigated row decodes as itself, not as the first rung', () {
    expect(lifecycleFromWire('mitigated'), IncidentLifecycle.mitigated);
  });

  test('the fallback still catches a stage this client does not know', () {
    // The `orElse` is not removed by the fix, only stopped from firing on a
    // value the backend has always emitted. A genuinely new stage still
    // degrades rather than throwing.
    expect(lifecycleFromWire('teleported'), IncidentLifecycle.detected);
    expect(lifecycleFromWire(null), IncidentLifecycle.detected);
  });

  test('it is not offered as a status an operator can pick', () {
    final List<String> offered = kIncidentStatuses
        .map((MetricOption option) => option.value)
        .toList();

    expect(offered, isNot(contains('mitigated')));
    // The five rungs the backend documents, and only those.
    expect(offered, <String>[
      'detected',
      'investigating',
      'identified',
      'monitoring',
      'resolved',
    ]);
  });

  testWidgets('it reads as a mitigated incident in both languages', (_) async {
    // Read against the SHIPPED catalogue: a label asserted from a fixture would
    // agree with whoever wrote the test rather than with the product, which is
    // how an English string reached a Turkish screen twice before.
    for (final String locale in <String>['tr', 'en']) {
      await useLocale(locale);
      final Map<String, dynamic> lang = readBundledLang(locale);

      expect(
        IncidentLifecycle.mitigated.label,
        lang['uptizm.enums.incident_lifecycle.mitigated'],
      );
      expect(
        IncidentLifecycle.mitigated.label,
        isNot(IncidentLifecycle.detected.label),
        reason: 'the whole defect was these two reading the same',
      );
    }
  });
}

/// Feeds [trans] the app's shipped catalogue for one locale.
class _BundledLoader implements TranslationLoader {
  final String locale;

  const _BundledLoader(this.locale);

  @override
  Future<Map<String, dynamic>> load(Locale _) async => readBundledLang(locale);
}
