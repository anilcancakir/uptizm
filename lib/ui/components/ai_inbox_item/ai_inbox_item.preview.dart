import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/models/incident.dart';
import 'ai_inbox_item.dart';

/// Static variant-matrix preview for [AiInboxItem].
///
/// Renders four incidents that carry an AI analysis payload so the preview
/// catalog shows the confidence range and both verdict states: a
/// high-confidence AI-owned outage, a medium-confidence latency degradation, a
/// medium-confidence auto-resolved blip, and a low-confidence row the model
/// itself disputed (the only one drawing the caveat line). Built from raw
/// `IncidentResource`-shaped maps through [Incident.fromMap] so the preview
/// exercises the same decode path the live inbox uses.
///
/// The first three carry no `confirmed` key at all, which is the wire shape of
/// a suggestion no model answered for, and is also what keeps the caveat line a
/// visible difference in the catalog rather than something on every row.
///
/// Both rows show no-op approve/dismiss callbacks to demonstrate the
/// graduated-trust affordance without side effects.
///
/// One preview class per file is the canonical atomic-component contract.
class AiInboxItemPreview extends StatelessWidget {
  /// Creates the AI inbox item preview.
  const AiInboxItemPreview({super.key});

  /// Three fixture incidents that carry an AI analysis payload.
  static final List<Incident> _aiIncidents = <Incident>[
    Incident.fromMap(const {
      'id': 'checkout-503',
      'title': 'Checkout service returning 503s across all regions',
      'impact': 'critical',
      'severity': 'critical',
      'signal_source': 'ai_anomaly',
      'lifecycle': 'investigating',
      'ai_owned': true,
      'started_at': '2026-07-11T14:00:00Z',
      'primary_monitor_id': 'm0',
      'monitors': [
        {'monitor_id': 'm0', 'name': 'Checkout service'},
      ],
      'ai': {
        'trigger': 'AI anomaly',
        'confidence': 'high',
        'tldr':
            'Every region is getting HTTP 503 from pay.uptizm.com while '
            'response times stay low: the origin is up but rejecting requests.',
      },
    }),
    Incident.fromMap(const {
      'id': 'api-latency',
      'title': 'Elevated p95 latency on API gateway',
      'impact': 'minor',
      'severity': 'warn',
      'signal_source': 'ai_anomaly',
      'lifecycle': 'identified',
      'ai_owned': true,
      'started_at': '2026-07-11T13:00:00Z',
      'primary_monitor_id': 'm0',
      'monitors': [
        {'monitor_id': 'm0', 'name': 'API gateway'},
      ],
      'ai': {
        'trigger': 'AI anomaly',
        'confidence': 'medium',
        'tldr':
            'p95 latency on API gateway has climbed for an hour with no errors; '
            'your cpu_load metric crossed its critical bound just before.',
      },
    }),
    Incident.fromMap(const {
      'id': 'docs-blip',
      'title': 'Brief latency blip on Docs, auto-resolved',
      'impact': 'minor',
      'severity': 'info',
      'signal_source': 'ai_anomaly',
      'lifecycle': 'resolved',
      'ai_owned': true,
      'started_at': '2026-07-11T09:12:00Z',
      'resolved_at': '2026-07-11T09:18:00Z',
      'primary_monitor_id': 'm0',
      'monitors': [
        {'monitor_id': 'm0', 'name': 'Docs'},
      ],
      'ai': {
        'trigger': 'AI anomaly',
        'confidence': 'medium',
        'tldr':
            'A short latency blip on Docs from eu-central that cleared on its '
            'own within 6 minutes. No errors and no other region affected.',
      },
    }),
    Incident.fromMap(const {
      'id': 'marketing-drift',
      'title': 'Response-time drift on Marketing site',
      'impact': 'minor',
      'severity': 'info',
      'signal_source': 'ai_anomaly',
      'lifecycle': 'detected',
      'ai_owned': true,
      'started_at': '2026-07-11T08:00:00Z',
      'primary_monitor_id': 'm0',
      'monitors': [
        {'monitor_id': 'm0', 'name': 'Marketing site'},
      ],
      'ai': {
        'trigger': 'AI anomaly',
        'confidence': 'low',
        'tldr':
            'The EWMA detector flagged a drift, but the latest reading is 53ms '
            'against a 120.8ms baseline: the earlier spike has already passed.',
        // The state this row exists to show. It reached the inbox precisely
        // BECAUSE the model said no: an unconfirmed anomaly is never opened
        // autonomously, so it waits here for a person instead.
        'confirmed': false,
      },
    }),
  ];

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 p-6',
      children: [
        WText(
          'AiInboxItem — graduated-trust inbox rows',
          className: 'text-sm font-semibold text-fg',
        ),

        for (final incident in _aiIncidents)
          AiInboxItem(incident: incident, onApprove: () {}, onDismiss: () {}),
      ],
    );
  }
}
