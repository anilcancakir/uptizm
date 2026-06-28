import 'dart:math' as math;

import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'usage_meter.recipe.dart';

/// Tone for a usage meter, tracking how close a resource is to its limit.
enum UsageMeterTone {
  /// Comfortable headroom.
  up,

  /// Past 80% of the limit.
  degraded,

  /// At or over the limit.
  down,
}

/// **A single resource's usage against its plan limit.**
///
/// Shows the [label], a `used / limit` readout (the limit renders as the
/// infinity glyph when [limit] is null), and a tone-coded bar: healthy with
/// headroom, degraded past 80%, down at the limit. Ported 1:1 from the design
/// lab `UsageMeter`.
///
/// ### Example:
/// ```dart
/// UsageMeter(label: 'Monitors', used: 4, limit: 50)
/// UsageMeter(label: 'Monitors', used: 420, limit: null) // unlimited
/// ```
@immutable
class UsageMeter extends StatelessWidget {
  /// Resource name shown on the left.
  final String label;

  /// Amount consumed so far.
  final int used;

  /// Plan limit; null means unlimited (no bar fill to speak of, infinity readout).
  final int? limit;

  /// Optional short suffix on the numbers, e.g. "min".
  final String? unit;

  /// Optional extra classNames appended to the root slot.
  final String? className;

  /// Creates a [UsageMeter].
  const UsageMeter({
    super.key,
    required this.label,
    required this.used,
    required this.limit,
    this.unit,
    this.className,
  });

  /// Format an integer with thousands separators: 1000 -> "1,000".
  String _formatCount(int n) {
    final digits = n.abs().toString();
    final buffer = StringBuffer();
    for (var i = 0; i < digits.length; i++) {
      if (i > 0 && (digits.length - i) % 3 == 0) buffer.write(',');
      buffer.write(digits[i]);
    }
    return n < 0 ? '-$buffer' : buffer.toString();
  }

  @override
  Widget build(BuildContext context) {
    final lim = limit;
    final unlimited = lim == null;
    final ratio = unlimited ? 0.0 : math.min(1.0, used / lim);
    final tone = unlimited
        ? UsageMeterTone.up
        : ratio >= 1
        ? UsageMeterTone.down
        : ratio >= 0.8
        ? UsageMeterTone.degraded
        : UsageMeterTone.up;
    final slots = usageMeterRecipe(variants: {kUsageMeterToneAxis: tone.name});
    final widthFactor = unlimited ? 0.04 : math.max(0.02, ratio);
    final suffix = unit != null ? ' $unit' : '';
    final limitText = unlimited ? '∞' : '${_formatCount(lim)}$suffix';

    return WDiv(
      className: className == null
          ? slots['root']
          : '${slots['root']} $className',
      children: [
        WDiv(
          className: slots['head'],
          children: [
            WText(label, className: slots['label']),
            WText(
              '${_formatCount(used)}$suffix / $limitText',
              className: slots['readout'],
            ),
          ],
        ),
        WDiv(
          className: slots['track'],
          child: FractionallySizedBox(
            alignment: Alignment.centerLeft,
            widthFactor: widthFactor,
            child: WDiv(
              className: slots['bar'],
              child: const SizedBox.expand(),
            ),
          ),
        ),
      ],
    );
  }
}
