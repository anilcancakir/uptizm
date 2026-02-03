import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../../app/controllers/monitor_controller.dart';
import '../../../app/enums/http_method.dart';
import '../../../app/enums/monitor_location.dart';
import '../../../app/enums/monitor_type.dart';
import '../../../app/models/assertion_rule.dart';
import '../../../app/models/metric_mapping.dart';
import '../../../app/models/monitor.dart';
import '../../../app/models/monitor_auth_config.dart';
import '../components/monitors/monitor_basic_info_section.dart';
import '../components/monitors/monitor_settings_section.dart';
import '../components/monitors/monitor_auth_section.dart';
import '../components/monitors/monitor_request_details_section.dart';
import '../components/monitors/monitor_validation_section.dart';

/// Monitor Edit View
///
/// Form for editing an existing monitor. Reuses shared section components
/// from the create view, hydrated with existing monitor data.
class MonitorEditView extends MagicStatefulView<MonitorController> {
  const MonitorEditView({super.key});

  @override
  State<MonitorEditView> createState() => _MonitorEditViewState();
}

class _MonitorEditViewState
    extends MagicStatefulViewState<MonitorController, MonitorEditView> {
  int? _monitorId;
  MagicFormData? _form;
  bool _initialized = false;

  // Local state mirrors create view, hydrated from monitor
  MonitorType _selectedType = MonitorType.http;
  List<MonitorLocation> _selectedLocations = [MonitorLocation.usEast];
  List<String> _tags = [];
  List<SelectOption<String>> _tagOptions = [];
  Map<String, String> _headers = {};
  String _body = '';
  List<AssertionRule> _assertionRules = [];
  List<MetricMapping> _metricMappings = [];
  MonitorAuthConfig _authConfig = MonitorAuthConfig.none();
  Map<String, dynamic>? _testFetchResponse;
  bool _isTestingFetch = false;

  @override
  void onInit() {
    super.onInit();
    controller.clearErrors();

    final idParam = MagicRouter.instance.pathParameter('id');
    if (idParam != null) {
      _monitorId = int.tryParse(idParam);
      if (_monitorId != null) {
        controller.loadMonitor(_monitorId!);
      }
    }
  }

  @override
  void onClose() {
    _form?.dispose();
  }

  void _hydrateFromMonitor(Monitor monitor) {
    if (_initialized) return;
    _initialized = true;

    _form = MagicFormData({
      'name': monitor.name ?? '',
      'url': monitor.url ?? '',
      'method': monitor.method?.value ?? HttpMethod.get.value,
      'expected_status_code': (monitor.expectedStatusCode ?? 200).toString(),
      'check_interval': (monitor.checkInterval ?? 60).toString(),
      'timeout': (monitor.timeout ?? 30).toString(),
    }, controller: controller);

    _selectedType = monitor.type ?? MonitorType.http;
    _selectedLocations = List<MonitorLocation>.from(
        monitor.monitoringLocations ?? [MonitorLocation.usEast]);
    _tags = List<String>.from(monitor.tags ?? []);
    _tagOptions = _tags
        .map((t) => SelectOption(value: t, label: t))
        .toList();
    _headers = Map<String, String>.from(
        (monitor.headers ?? {}).cast<String, String>());
    _body = monitor.body ?? '';
    _assertionRules = (monitor.assertionRules ?? [])
        .map((r) => AssertionRule.fromMap(r))
        .toList();
    _metricMappings = (monitor.metricMappings ?? [])
        .map((m) => MetricMapping.fromMap(m))
        .toList();
    _authConfig = monitor.authConfig;
  }

  Future<void> _handleSubmit() async {
    if (_form == null || !_form!.validate()) return;
    if (_monitorId == null) return;

    await controller.update(
      _monitorId!,
      name: _form!.get('name'),
      url: _form!.get('url'),
      method: _selectedType == MonitorType.http
          ? HttpMethod.fromValue(_form!.get('method'))
          : null,
      headers: _headers.isNotEmpty ? _headers : null,
      body: _body.isNotEmpty ? _body : null,
      expectedStatusCode: int.tryParse(_form!.get('expected_status_code')),
      checkInterval: int.tryParse(_form!.get('check_interval')) ?? 60,
      timeout: int.tryParse(_form!.get('timeout')) ?? 30,
      monitoringLocations: _selectedLocations,
      assertionRules: _assertionRules.isNotEmpty
          ? _assertionRules.map((rule) => rule.toMap()).toList()
          : null,
      metricMappings: _metricMappings.isNotEmpty
          ? _metricMappings.map((m) => m.toMap()).toList()
          : null,
      tags: _tags.isNotEmpty ? _tags : null,
      authConfig: _authConfig,
    );
  }

  Future<void> _handleTestFetch() async {
    if (_form == null) return;
    final url = _form!.get('url');
    if (url.toString().isEmpty) {
      Magic.toast(trans('monitor.test_fetch_url_required'));
      return;
    }

    setState(() => _isTestingFetch = true);

    try {
      final response = await Http.post('/monitors/test', data: {
        'url': url,
        'method': _form!.get('method'),
        'headers': _headers,
        'body': _body,
        'auth_config': _authConfig.toMap(),
      });

      if (response.successful) {
        setState(() {
          _testFetchResponse = response.data is Map &&
                  response.data.containsKey('data')
              ? response.data['data']
              : response.data;
          _isTestingFetch = false;
        });
      } else {
        Magic.toast(response.message ?? 'Test fetch failed');
        setState(() => _isTestingFetch = false);
      }
    } catch (e) {
      Log.error('Test fetch failed', e);
      Magic.toast('Test fetch failed. Please check the URL and try again.');
      setState(() => _isTestingFetch = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<Monitor?>(
      valueListenable: controller.selectedMonitorNotifier,
      builder: (context, monitor, _) {
        if (controller.isLoading && monitor == null) {
          return const Center(child: CircularProgressIndicator());
        }

        if (monitor == null) {
          return Center(
            child: WText(
              trans('monitors.not_found'),
              className: 'text-gray-600 dark:text-gray-400',
            ),
          );
        }

        _hydrateFromMonitor(monitor);

        return controller.renderState(
          (_) => _buildForm(monitor),
          onEmpty: _buildForm(monitor),
          onLoading: _buildForm(monitor, isLoading: true),
          onError: (msg) => _buildForm(monitor, errorMessage: msg),
        );
      },
    );
  }

  Widget _buildForm(Monitor monitor,
      {bool isLoading = false, String? errorMessage}) {
    final form = _form;
    if (form == null) return const SizedBox.shrink();

    return MagicForm(
      formData: form,
      child: SingleChildScrollView(
        child: WDiv(
          className: 'flex flex-col gap-6 p-4 lg:p-6',
          children: [
            // Page Header
            WDiv(
              className: 'flex flex-row items-center gap-3 mb-2',
              children: [
                WButton(
                  onTap: () => MagicRoute.to('/monitors/$_monitorId'),
                  className: '''
                    p-2 rounded-lg
                    hover:bg-gray-100 dark:hover:bg-gray-700
                  ''',
                  child: WIcon(
                    Icons.arrow_back,
                    className: 'text-xl text-gray-700 dark:text-gray-300',
                  ),
                ),
                WText(
                  '${trans('monitor.edit_title')}: ${monitor.name ?? "Monitor"}',
                  className:
                      'text-2xl font-bold text-gray-900 dark:text-white',
                ),
              ],
            ),

            // Error Message
            if (errorMessage != null)
              WDiv(
                className: '''
                  p-3 mb-2
                  bg-red-100 dark:bg-red-900
                  border border-red-300 dark:border-red-700
                  rounded-lg
                ''',
                child: WText(
                  errorMessage,
                  className: 'text-red-700 dark:text-red-200',
                ),
              ),

            // Basic Information (type not editable)
            MonitorBasicInfoSection(
              form: form,
              selectedType: _selectedType,
              onTypeChanged: (type) => setState(() => _selectedType = type),
              typeEditable: false,
              tags: _tags,
              tagOptions: _tagOptions,
              onTagsChanged: (tags) => setState(() => _tags = tags),
              onTagOptionsChanged: (options) =>
                  setState(() => _tagOptions = options),
            ),

            // Monitoring Settings
            MonitorSettingsSection(
              form: form,
              selectedLocations: _selectedLocations,
              onLocationsChanged: (locs) =>
                  setState(() => _selectedLocations = locs),
            ),

            // Authentication (only for HTTP monitors)
            if (_selectedType == MonitorType.http)
              MonitorAuthSection(
                authConfig: _authConfig,
                onChanged: (config) => setState(() => _authConfig = config),
              ),

            // HTTP Request Details (only for HTTP monitors with POST/PUT)
            if (_selectedType == MonitorType.http &&
                (form.get('method') == HttpMethod.post.value ||
                    form.get('method') == HttpMethod.put.value))
              MonitorRequestDetailsSection(
                headers: _headers,
                onHeadersChanged: (h) => setState(() => _headers = h),
                body: _body,
                onBodyChanged: (b) => setState(() => _body = b),
              ),

            // Validation & Parsing
            if (_selectedType == MonitorType.http)
              MonitorValidationSection(
                testFetchResponse: _testFetchResponse,
                isTestingFetch: _isTestingFetch,
                onTestFetch: _handleTestFetch,
                assertionRules: _assertionRules,
                onAssertionRulesChanged: (rules) =>
                    setState(() => _assertionRules = rules),
                metricMappings: _metricMappings,
                onMetricMappingsChanged: (mappings) =>
                    setState(() => _metricMappings = mappings),
              ),

            // Action Buttons
            WDiv(
              className: 'flex flex-row justify-end gap-3 px-4',
              children: [
                WButton(
                  onTap: () => MagicRoute.to('/monitors/$_monitorId'),
                  className: '''
                    px-4 py-2 rounded-lg
                    bg-gray-200 dark:bg-gray-700
                    text-gray-700 dark:text-gray-200
                    hover:bg-gray-300 dark:hover:bg-gray-600
                    text-sm font-medium
                  ''',
                  child: WText(trans('common.cancel')),
                ),
                WButton(
                  isLoading: isLoading,
                  onTap: _handleSubmit,
                  className: '''
                    px-4 py-2 rounded-lg
                    bg-primary hover:bg-green-600
                    text-white
                    text-sm font-medium
                  ''',
                  child: WText(trans('common.save_changes')),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
