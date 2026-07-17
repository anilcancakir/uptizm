import 'package:flutter/foundation.dart' show immutable;

import '../enums/ai_confidence.dart';

/// A team's weekly AI digest, as returned by `GET /incidents/digest`.
///
/// The backend [TeamDigest] row: the trusted aggregate numbers
/// ([uptimePercent], [incidentCount]) plus the allowlist-cleaned AI narrative
/// ([summary], [highlights]) and its [confidence]. Read-only on the client; the
/// digest is composed and persisted server-side by the weekly job, never
/// generated here.
@immutable
class WeeklyDigest {
  /// ISO date (`YYYY-MM-DD`) of the covered week's Monday, or null.
  final String? weekStart;

  /// ISO date (`YYYY-MM-DD`) of the covered week's Sunday, or null.
  final String? weekEnd;

  /// Aggregate uptime across the week, as a percentage (e.g. `99.44`).
  final double uptimePercent;

  /// Number of incidents opened during the week.
  final int incidentCount;

  /// The AI narrative's self-reported confidence.
  final AiConfidence confidence;

  /// The AI-composed prose summary of the week (allowlist-cleaned server-side).
  final String summary;

  /// Key bullet points the AI surfaced, in order.
  final List<String> highlights;

  /// ISO-8601 timestamp the digest was generated, or null.
  final String? generatedAt;

  /// Creates a [WeeklyDigest].
  const WeeklyDigest({
    this.weekStart,
    this.weekEnd,
    required this.uptimePercent,
    required this.incidentCount,
    required this.confidence,
    required this.summary,
    required this.highlights,
    this.generatedAt,
  });

  /// Decodes a [WeeklyDigest] from the unwrapped `data` object of the
  /// `GET /incidents/digest` response.
  factory WeeklyDigest.fromMap(Map<String, dynamic> map) {
    return WeeklyDigest(
      weekStart: map['week_start'] as String?,
      weekEnd: map['week_end'] as String?,
      uptimePercent: (map['uptime_percent'] as num?)?.toDouble() ?? 0,
      incidentCount: (map['incident_count'] as num?)?.toInt() ?? 0,
      confidence: aiConfidenceFromWire(map['confidence'] as String?),
      summary: (map['summary'] as String?) ?? '',
      highlights: ((map['highlights'] as List<dynamic>?) ?? const <dynamic>[])
          .map((dynamic e) => e.toString())
          .toList(),
      generatedAt: map['generated_at'] as String?,
    );
  }
}
