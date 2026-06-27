import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'incident_timeline.dart';

/// The design-lab seed timeline (`IncidentTimeline.preview.tsx`): a human
/// update, an AI analysis post, and a system threshold breach, newest first.
const List<TimelineEntry> _entries = [
  TimelineEntry(
    actor: TimelineActor.human,
    author: 'Ada · on-call',
    status: 'Investigating',
    message:
        'Confirmed the spike maps to checkout pods. Rolling back the latest '
        'deploy now.',
    time: '14:34',
    isPublic: true,
  ),
  TimelineEntry(
    actor: TimelineActor.ai,
    author: 'Uptizm AI',
    status: 'Analysis posted',
    message:
        'All regions return 503 with low latency: an origin-side fault, not a '
        'network issue.',
    time: '14:33',
  ),
  TimelineEntry(
    actor: TimelineActor.system,
    status: 'Threshold breach',
    message:
        '503 rate on /charge crossed the 2% critical bound for 3 consecutive '
        'checks.',
    time: '14:32',
    isPublic: true,
  ),
];

/// Static preview for [IncidentTimeline]. Actor-aware incident timeline.
class IncidentTimelinePreview extends StatelessWidget {
  /// Creates the incident-timeline preview.
  const IncidentTimelinePreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'p-6',
      child: const IncidentTimeline(entries: _entries),
    );
  }
}
