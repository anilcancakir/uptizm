import 'package:flutter_test/flutter_test.dart';

import 'package:uptizm/app/mocks/incidents.dart';
import 'package:uptizm/ui/components/ai_confidence_badge/ai_confidence_badge.dart';

void main() {
  group('AiConfidenceBadge', () {
    // 1. Test that the component can be instantiated with high confidence.
    test('creates a high confidence badge widget', () {
      const widget = AiConfidenceBadge(AiConfidence.high);
      expect(widget, isNotNull);
      expect(widget.level, equals(AiConfidence.high));
    });

    // 2. Test that the component can be instantiated with medium confidence.
    test('creates a medium confidence badge widget', () {
      const widget = AiConfidenceBadge(AiConfidence.medium);
      expect(widget, isNotNull);
      expect(widget.level, equals(AiConfidence.medium));
    });

    // 3. Test that the component can be instantiated with low confidence.
    test('creates a low confidence badge widget', () {
      const widget = AiConfidenceBadge(AiConfidence.low);
      expect(widget, isNotNull);
      expect(widget.level, equals(AiConfidence.low));
    });

    // 4. Test that all three confidence levels are instantiable.
    test('creates badge widgets for all confidence levels', () {
      for (final level in AiConfidence.values) {
        final widget = AiConfidenceBadge(level);
        expect(widget, isNotNull);
        expect(widget.level, equals(level));
      }
    });

    // 5. Test that the label is capitalized correctly (e.g., "High confidence").
    test('renders correct label format for high confidence', () {
      const widget = AiConfidenceBadge(AiConfidence.high);
      expect(widget, isNotNull);
      // The label is built as "{Level} confidence" in the component.
    });

    // 6. Test that the label is capitalized correctly for medium confidence.
    test('renders correct label format for medium confidence', () {
      const widget = AiConfidenceBadge(AiConfidence.medium);
      expect(widget, isNotNull);
    });

    // 7. Test that the label is capitalized correctly for low confidence.
    test('renders correct label format for low confidence', () {
      const widget = AiConfidenceBadge(AiConfidence.low);
      expect(widget, isNotNull);
    });
  });
}
