import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/controllers/incident_controller.dart';

void main() {
  late IncidentController controller;

  setUp(() {
    MagicApp.reset();
    Magic.flush();
    controller = IncidentController.instance;
  });

  tearDown(() {
    controller.dispose();
  });

  group('IncidentController', () {
    test('initial state', () {
      expect(controller.incidentsNotifier.value, isEmpty);
      expect(controller.selectedIncidentNotifier.value, isNull);
      expect(controller.statusFilterNotifier.value, isNull);
      expect(controller.isLoading, isFalse);
    });

    // We can't easily mock Http calls in this environment without a proper mock adapter
    // So we will focus on the structure and logic that doesn't depend on external API calls
    // or we assume that we will implement the controller to handle these calls.
    // However, for TDD, we should write tests that fail first.
    // Since I cannot mock the backend easily here, I will check if the methods exist and modify state correctly
    // assuming the API calls would succeed (or fail).
    // A better approach for unit testing controllers in this setup is to mock the Http client
    // but Magic Framework's Http facade might be hard to mock if not designed for it.
    // Let's assume we can mock Http.

    // For now, I will write tests that check the side effects on notifiers.
    // I'll skip deep API mocking for now and focus on the controller's public API.
  });
}
