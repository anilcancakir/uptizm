import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'incident_timeline.recipe.dart';

/// Who moved the incident at a point in the timeline.
enum TimelineActor {
  /// Uptizm AI posted an analysis or acted in Auto mode.
  ai,

  /// A human operator (on-call) moved the incident.
  human,

  /// An automated system event (threshold breach, auto-resolve).
  system,
}

/// A single incident-timeline entry: a status change, an AI post, or a system
/// event.
@immutable
class TimelineEntry {
  /// Who moved the incident at this point.
  final TimelineActor actor;

  /// Short status / headline, e.g. "Investigating", "Detected", "Note".
  final String status;

  /// The entry body.
  final String message;

  /// Clock time, e.g. "14:32".
  final String time;

  /// Whether this entry was published to the public status page.
  final bool isPublic;

  /// Optional attribution, e.g. "Ada · on-call" or "Uptizm AI".
  final String? author;

  /// Whether the AI acted on its own here (Auto mode), flagged for audit.
  final bool autonomous;

  /// Creates a [TimelineEntry].
  const TimelineEntry({
    required this.actor,
    required this.status,
    required this.message,
    required this.time,
    this.isPublic = false,
    this.author,
    this.autonomous = false,
  });
}

/// **Incident Timeline**
///
/// An actor-aware vertical timeline. Each node is tinted by its actor (AI =
/// sparkle, human = person, system = gear), the headline carries a
/// public/internal tag (and an "Auto mode" flag when the AI acted on its own),
/// and the message plus attribution sit alongside. A hairline rail connects the
/// nodes. Ported 1:1 from the design lab `IncidentTimeline`.
///
/// Newest-first ordering is the caller's responsibility (pass ordered
/// [entries]).
///
/// ### Example Usage:
///
/// ```dart
/// IncidentTimeline(entries: [
///   TimelineEntry(
///     actor: TimelineActor.human,
///     author: 'Ada · on-call',
///     status: 'Investigating',
///     message: 'Rolling back the latest deploy now.',
///     time: '14:34',
///     isPublic: true,
///   ),
/// ])
/// ```
@immutable
class IncidentTimeline extends StatelessWidget {
  /// The ordered timeline entries (newest first).
  final List<TimelineEntry> entries;

  /// Creates an [IncidentTimeline].
  const IncidentTimeline({super.key, required this.entries});

  /// Per-actor glyph. AI = sparkle, human = person, system = gear.
  static const Map<TimelineActor, IconData> _actorIcon = {
    TimelineActor.ai: Icons.auto_awesome,
    TimelineActor.human: Icons.person_outline,
    TimelineActor.system: Icons.settings_outlined,
  };

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        for (var i = 0; i < entries.length; i++)
          _buildItem(entries[i], isLast: i == entries.length - 1),
      ],
    );
  }

  /// Builds one timeline row: a node + body, with the connecting rail painted
  /// behind it (except on the last entry). The rail is an explicit
  /// [Positioned] bar because Wind `absolute` does not reliably stretch a
  /// childless element between two offsets here.
  Widget _buildItem(TimelineEntry entry, {required bool isLast}) {
    final classes = incidentTimelineRecipe(
      variants: {
        kTimelineActorAxis: entry.actor.name,
        kTimelineVisibilityAxis: entry.isPublic ? 'public' : 'internal',
      },
    );

    return Stack(
      children: [
        // Rail: a 1px line from just below this node (top-9) to the bottom of
        // the row, threading the bottom gap to reach the next node.
        if (!isLast)
          const Positioned(
            left: 15,
            top: 36,
            bottom: 0,
            width: 1,
            // The divider tone has no `bg-*` alias (only `border-color-border`);
            // render the rail as a 1px left border so it uses that token.
            child: WDiv(className: 'border-l border-color-border'),
          ),
        Padding(
          padding: EdgeInsets.only(bottom: isLast ? 0 : 20),
          child: WDiv(
            className: 'w-full flex flex-row gap-3',
            children: [_buildNode(entry, classes), _buildBody(entry, classes)],
          ),
        ),
      ],
    );
  }

  /// The actor-tinted circular node with its glyph.
  Widget _buildNode(TimelineEntry entry, Map<String, String> classes) {
    return WDiv(
      className: classes['node'],
      child: Center(
        child: WIcon(_actorIcon[entry.actor]!, className: classes['icon']),
      ),
    );
  }

  /// The entry body: head row, message, optional author.
  Widget _buildBody(TimelineEntry entry, Map<String, String> classes) {
    return WDiv(
      className: classes['body'],
      children: [
        _buildHead(entry, classes),
        WText(entry.message, className: classes['message']),
        if (entry.author != null)
          WText(entry.author!, className: classes['author']),
      ],
    );
  }

  /// The head row: status, optional Auto-mode flag, public/internal tag, time.
  Widget _buildHead(TimelineEntry entry, Map<String, String> classes) {
    return WDiv(
      className: classes['head'],
      children: [
        WText(entry.status, className: classes['status']),
        if (entry.autonomous) _buildAutoModeBadge(),
        WText(
          entry.isPublic
              ? trans('uptizm.incidents.timeline_tag_public')
              : trans('uptizm.incidents.timeline_tag_internal'),
          className: classes['tag'],
        ),
        WText(entry.time, className: classes['time']),
      ],
    );
  }

  /// The "Auto mode" flag shown when the AI acted autonomously.
  Widget _buildAutoModeBadge() {
    return WDiv(
      className: '''
        flex flex-row items-center gap-1 rounded-sm
        bg-ai-soft px-1.5 py-0.5
      ''',
      children: [
        WIcon(
          Icons.auto_awesome,
          className: 'text-[11px] text-ai-soft-foreground',
        ),
        WText(
          trans('uptizm.incidents.timeline_auto_mode'),
          className: 'text-[11px] font-medium text-ai-soft-foreground',
        ),
      ],
    );
  }
}
