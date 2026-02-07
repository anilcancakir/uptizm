import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:magic/magic.dart';
import 'package:uptizm/app/controllers/monitor_controller.dart';
import 'package:uptizm/app/controllers/status_page_controller.dart';
import 'package:uptizm/app/models/monitor.dart';
import 'package:uptizm/app/models/status_page.dart';

class _MockMonitorController extends MonitorController {
  @override
  Future<void> loadMonitors() async {
    final m1 = Monitor()
      ..id = 'test-uuid-10'
      ..name = 'API Server'
      ..metricMappings = [
        {'label': 'CPU', 'path': 'data.cpu', 'type': 'numeric'},
        {'label': 'RAM', 'path': 'data.ram', 'type': 'numeric'},
        {'label': 'Status', 'path': 'data.status', 'type': 'status'},
      ];

    final m2 = Monitor()
      ..id = 'test-uuid-20'
      ..name = 'DB Server';

    monitorsNotifier.value = [m1, m2];
  }
}

class _MockStatusPageController extends StatusPageController {
  Map<String, dynamic>? lastUpdateArgs;
  StatusPage? _pageToReturn;

  set pageToReturn(StatusPage page) {
    _pageToReturn = page;
  }

  @override
  Future<void> loadStatusPage(String id) async {
    if (_pageToReturn != null) {
      selectedStatusPageNotifier.value = _pageToReturn;
    }
  }

  @override
  Future<void> update(
    String id, {
    String? name,
    String? slug,
    String? description,
    String? logoUrl,
    String? faviconUrl,
    String? primaryColor,
    bool? isPublished,
    List<String>? monitorIds,
    List<Map<String, dynamic>>? monitors,
  }) async {
    lastUpdateArgs = {'id': id, 'name': name, 'monitors': monitors};
  }

  @override
  Future<void> attachMonitors(
    String id,
    List<Map<String, dynamic>> monitors,
  ) async {}
}

StatusPage _buildStatusPageWithMetrics() {
  final page = StatusPage();
  page.setRawAttributes({
    'id': 'test-uuid-42',
    'name': 'Test Status Page',
    'slug': 'test-page',
    'description': 'Test description',
    'primary_color': '#009E60',
    'is_published': false,
    'monitors': [
      {
        'id': 'test-uuid-10',
        'name': 'API Server',
        'metric_mappings': [
          {'label': 'CPU', 'path': 'data.cpu', 'type': 'numeric'},
          {'label': 'RAM', 'path': 'data.ram', 'type': 'numeric'},
          {'label': 'Status', 'path': 'data.status', 'type': 'status'},
        ],
        'pivot': {
          'display_name': 'My API',
          'sort_order': 0,
          'selected_metrics': ['data.cpu', 'data.status'],
        },
      },
    ],
  }, sync: true);
  return page;
}

StatusPage _buildStatusPageWithoutMetrics() {
  final page = StatusPage();
  page.setRawAttributes({
    'id': 'test-uuid-43',
    'name': 'No Metrics Page',
    'slug': 'no-metrics',
    'description': '',
    'primary_color': '#009E60',
    'is_published': true,
    'monitors': [
      {
        'id': 'test-uuid-20',
        'name': 'DB Server',
        'pivot': {'display_name': 'Database', 'sort_order': 0},
      },
    ],
  }, sync: true);
  return page;
}

void main() {
  late _MockMonitorController monitorController;
  late _MockStatusPageController statusPageController;

  setUp(() {
    Magic.flush();
    monitorController = _MockMonitorController();
    statusPageController = _MockStatusPageController();
    Magic.put<MonitorController>(monitorController);
    Magic.put<StatusPageController>(statusPageController);
  });

  Widget buildTestApp(Widget child) {
    return WindTheme(
      data: WindThemeData(),
      child: MaterialApp(home: Scaffold(body: child)),
    );
  }

  group('StatusPageEditView metric roundtrip', () {
    testWidgets('pre-selects saved metrics from API response', (tester) async {
      final page = _buildStatusPageWithMetrics();
      statusPageController.pageToReturn = page;
      statusPageController.selectedStatusPageNotifier.value = page;

      await tester.pumpWidget(
        buildTestApp(
          Builder(
            builder: (context) {
              return const StatusPageEditViewTestHarness(
                pageId: 'test-uuid-42',
              );
            },
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('CPU (data.cpu)'), findsOneWidget);
      expect(find.text('RAM (data.ram)'), findsOneWidget);
      expect(find.text('Status (data.status)'), findsOneWidget);
    });

    testWidgets('monitor without metric_mappings shows no checkboxes', (
      tester,
    ) async {
      final page = _buildStatusPageWithoutMetrics();
      statusPageController.pageToReturn = page;
      statusPageController.selectedStatusPageNotifier.value = page;

      await tester.pumpWidget(
        buildTestApp(
          Builder(
            builder: (context) {
              return const StatusPageEditViewTestHarness(
                pageId: 'test-uuid-43',
              );
            },
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('DB Server'), findsAtLeastNWidgets(1));
      expect(find.text('CPU (data.cpu)'), findsNothing);
      expect(find.text('RAM (data.ram)'), findsNothing);
    });
  });
}

class StatusPageEditViewTestHarness extends StatefulWidget {
  final String pageId;
  const StatusPageEditViewTestHarness({super.key, required this.pageId});

  @override
  State<StatusPageEditViewTestHarness> createState() =>
      _StatusPageEditViewTestHarnessState();
}

class _StatusPageEditViewTestHarnessState
    extends State<StatusPageEditViewTestHarness> {
  late final MagicFormData form;
  final List<Map<String, dynamic>> _selectedMonitors = [];
  bool _isDataLoaded = false;

  @override
  void initState() {
    super.initState();
    final controller = Magic.find<StatusPageController>();
    form = MagicFormData({
      'name': '',
      'slug': '',
      'description': '',
      'logo_url': '',
      'favicon_url': '',
      'primary_color': '#009E60',
      'is_published': false,
    }, controller: controller);

    MonitorController.instance.loadMonitors();

    final page = controller.selectedStatusPageNotifier.value;
    if (page != null) {
      _populateForm(page);
    }
  }

  void _populateForm(StatusPage page) {
    if (_isDataLoaded) return;

    form.set('name', page.name);
    form.set('slug', page.slug);

    _selectedMonitors.clear();
    for (var m in page.monitors) {
      final pivot = m.get<Map<String, dynamic>>('pivot');
      final id = m.id ?? m.get('id')?.toString();
      _selectedMonitors.add({
        'monitor_id': id,
        'name': m.name,
        'display_name': pivot?['display_name'] ?? m.name,
        'metric_mappings': m.metricMappings,
        'metric_keys':
            (pivot?['selected_metrics'] as List?)?.cast<String>() ?? [],
      });
    }

    _isDataLoaded = true;
  }

  @override
  void dispose() {
    form.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (final item in _selectedMonitors)
            Padding(
              padding: const EdgeInsets.all(8.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(item['name'] ?? 'Unknown'),
                  if (item['metric_mappings'] != null &&
                      (item['metric_mappings'] as List).isNotEmpty)
                    Column(
                      children: (item['metric_mappings'] as List).map((m) {
                        final label = m['label'] ?? '';
                        final path = m['path'] ?? '';
                        final selectedKeys =
                            (item['metric_keys'] as List?)?.cast<String>() ??
                            [];
                        final isSelected = selectedKeys.contains(path);

                        return Row(
                          children: [
                            Checkbox(
                              value: isSelected,
                              onChanged: (val) {
                                setState(() {
                                  final currentKeys =
                                      (item['metric_keys'] as List?)
                                          ?.cast<String>() ??
                                      [];
                                  if (val == true) {
                                    currentKeys.add(path);
                                  } else {
                                    currentKeys.remove(path);
                                  }
                                  item['metric_keys'] = currentKeys;
                                });
                              },
                            ),
                            Text('$label ($path)'),
                          ],
                        );
                      }).toList(),
                    ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
