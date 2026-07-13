import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/models/incident.dart';
import 'incident_card.dart';

/// Static variant-matrix preview for [IncidentCard].
///
/// Renders five representative incidents so the preview catalog shows the full
/// range: critical outage (down / AI-owned), degraded warning (AI-owned),
/// resolved threshold incident, maintenance window, and an auto-resolved AI
/// blip. Built from raw `IncidentResource`-shaped maps through
/// [Incident.fromMap] so the preview exercises the same decode path the live
/// list uses.
///
/// One preview class per file is the canonical atomic-component contract.
class IncidentCardPreview extends StatelessWidget {
  /// Creates the incident card variant-matrix preview.
  const IncidentCardPreview({super.key});

  /// Five fixture incidents covering the representative impact x severity x
  /// lifecycle x AI-ownership range.
  static final List<Incident> _incidents = <Incident>[
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
    }),
    Incident.fromMap(const {
      'id': 'eu-packet-loss',
      'title': 'EU region packet loss',
      'impact': 'critical',
      'severity': 'critical',
      'signal_source': 'user_threshold',
      'lifecycle': 'resolved',
      'ai_owned': false,
      'started_at': '2026-07-11T10:00:00Z',
      'resolved_at': '2026-07-11T11:08:00Z',
      'primary_monitor_id': 'm0',
      'monitors': [
        {'monitor_id': 'm0', 'name': 'Marketing site'},
        {'monitor_id': 'm1', 'name': 'Docs'},
      ],
    }),
    Incident.fromMap(const {
      'id': 'maintenance-db',
      'title': 'Scheduled database maintenance',
      'impact': 'none',
      'severity': 'info',
      'signal_source': 'manual',
      'lifecycle': 'monitoring',
      'ai_owned': false,
      'started_at': '2026-07-11T14:00:00Z',
      'primary_monitor_id': 'm0',
      'monitors': [
        {'monitor_id': 'm0', 'name': 'API gateway'},
        {'monitor_id': 'm1', 'name': 'Checkout service'},
      ],
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
    }),
  ];

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-4 p-6',
      children: [
        // Section label.
        WText(
          'IncidentCard — all severity × status combinations',
          className: 'text-sm font-semibold text-fg',
        ),

        // All five fixture incidents in a single column.
        ..._incidents.map(
          (incident) => IncidentCard(incident: incident, onTap: () {}),
        ),
      ],
    );
  }
}
