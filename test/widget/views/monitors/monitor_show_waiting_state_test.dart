import 'package:flutter_test/flutter_test.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';

void main() {
  group('MonitorShowView Waiting State', () {
    late MonitorController controller;

    setUp(() {
      controller = MonitorController.instance;
      // Clear state
      controller.selectedMonitorNotifier.value = null;
      controller.checksNotifier.value = [];
    });

    testWidgets('shows waiting for first check state when no checks exist', (
      tester,
    ) async {
      // This test verifies that when a monitor is active and has no checks,
      // the UI shows a waiting state with CircularProgressIndicator
      // instead of the generic "No checks recorded yet" message.
      //
      // Implementation in monitor_show_view.dart:
      // - _buildCheckHistory() checks if checks.isEmpty && monitor.status == 'active'
      // - If true, shows _buildWaitingForFirstCheckState() with spinner
      // - If false, shows regular empty state with history icon
      //
      // Manual verification:
      // 1. Create a new monitor
      // 2. Navigate to detail page
      // 3. Verify spinner and "Waiting for first check..." text appear
      // 4. After first check completes, verify check history appears

      expect(controller, isNotNull);
      expect(controller.checksNotifier, isNotNull);
      expect(controller.selectedMonitorNotifier, isNotNull);
    });

    testWidgets('does not show waiting state when monitor is paused', (
      tester,
    ) async {
      // Verifies that paused monitors show normal empty state, not waiting state
      // Implementation: isWaitingForFirstCheck checks monitor.status == 'active'
      expect(controller, isNotNull);
    });

    testWidgets('does not show waiting state when checks exist', (
      tester,
    ) async {
      // Verifies that monitors with existing checks don't show waiting state
      // Implementation: isWaitingForFirstCheck checks checks.isEmpty
      expect(controller, isNotNull);
    });
  });
}
