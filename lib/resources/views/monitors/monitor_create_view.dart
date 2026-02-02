import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import '../../../app/controllers/monitor_controller.dart';
import '../../../app/enums/http_method.dart';
import '../../../app/enums/monitor_location.dart';
import '../../../app/enums/monitor_type.dart';
import '../../../app/models/assertion_rule.dart';
import '../../../app/models/metric_mapping.dart';
import '../../../app/models/monitor_auth_config.dart';
import '../components/monitors/monitor_basic_info_section.dart';
import '../components/monitors/monitor_settings_section.dart';
import '../components/monitors/monitor_auth_section.dart';
import '../components/monitors/monitor_request_details_section.dart';
import '../components/monitors/monitor_validation_section.dart';

/// Monitor Create View
///
/// Form for creating a new monitor.
class MonitorCreateView extends MagicStatefulView<MonitorController> {
  const MonitorCreateView({super.key});

  @override
  State<MonitorCreateView> createState() => _MonitorCreateViewState();
}

class _MonitorCreateViewState
    extends MagicStatefulViewState<MonitorController, MonitorCreateView> {
  late final MagicFormData form;
  MonitorType _selectedType = MonitorType.http;
  final List<MonitorLocation> _selectedLocations = [MonitorLocation.usEast];
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

    form = MagicFormData({
      'name': '',
      'url': '',
      'method': HttpMethod.get.value,
      'expected_status_code': '200',
      'check_interval': '60',
      'timeout': '30',
    }, controller: controller);
  }

  @override
  void onClose() {
    form.dispose();
  }

  Future<void> _handleSubmit() async {
    if (!form.validate()) return;

    await controller.store(
      name: form.get('name'),
      type: _selectedType,
      url: form.get('url'),
      method: _selectedType == MonitorType.http
          ? HttpMethod.fromValue(form.get('method'))
          : null,
      headers: _headers.isNotEmpty ? _headers : null,
      body: _body.isNotEmpty ? _body : null,
      expectedStatusCode: int.tryParse(form.get('expected_status_code')),
      checkInterval: int.tryParse(form.get('check_interval')) ?? 60,
      timeout: int.tryParse(form.get('timeout')) ?? 30,
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
    final url = form.get('url');
    if (url.toString().isEmpty) {
      Magic.toast(trans('monitor.test_fetch_url_required'));
      return;
    }

    setState(() => _isTestingFetch = true);

    try {
      final response = await Http.post('/monitors/test', data: {
        'url': url,
        'method': form.get('method'),
        'headers': _headers,
        'body': _body,
        'auth_config': _authConfig.toMap(),
      });

      if (response.successful) {
        setState(() {
          _testFetchResponse = response.data is Map && response.data.containsKey('data')
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
    return controller.renderState(
      (_) => _buildForm(),
      onEmpty: _buildForm(),
      onLoading: _buildForm(isLoading: true),
      onError: (msg) => _buildForm(errorMessage: msg),
    );
  }

  Widget _buildForm({bool isLoading = false, String? errorMessage}) {
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
                  onTap: () => MagicRoute.back(),
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
                  trans('monitor.create_title'),
                  className: 'text-2xl font-bold text-gray-900 dark:text-white',
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

            // Basic Information
            MonitorBasicInfoSection(
              form: form,
              selectedType: _selectedType,
              onTypeChanged: (type) => setState(() => _selectedType = type),
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
                  setState(() {
                    _selectedLocations.clear();
                    _selectedLocations.addAll(locs);
                  }),
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
                  onTap: () => MagicRoute.back(),
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
                  child: WText(trans('common.create')),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
