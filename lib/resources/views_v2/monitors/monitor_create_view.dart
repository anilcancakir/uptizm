import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../app/enums/http_method.dart';
import '../../../app/enums/monitor_location.dart';
import '../../../app/enums/monitor_type.dart';
import '../components/common/modal_nav_bar.dart';
import '../components/common/preset_selector.dart';
import '../components/common/segmented_control.dart';
import '../components/settings/settings_section.dart';

/// Monitor creation form with modal presentation.
///
/// ## Usage
/// ```dart
/// MonitorCreateV2View.show(context);
/// ```
class MonitorCreateV2View extends StatefulWidget {
  const MonitorCreateV2View({super.key});

  /// Show the monitor create view as a pushed route.
  static void show(BuildContext context) {
    MagicRoute.push('/monitors/create');
  }

  @override
  State<MonitorCreateV2View> createState() => _MonitorCreateV2ViewState();
}

class _MonitorCreateV2ViewState extends State<MonitorCreateV2View> {
  final _nameController = TextEditingController();
  final _urlController = TextEditingController();
  final _statusCodeController = TextEditingController(text: '200');

  MonitorType _selectedType = MonitorType.http;
  HttpMethod _selectedMethod = HttpMethod.get;
  int _selectedInterval = 120;
  int _selectedTimeout = 30;
  final Set<MonitorLocation> _selectedLocations = {MonitorLocation.usEast};

  @override
  void dispose() {
    _nameController.dispose();
    _urlController.dispose();
    _statusCodeController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: wColor(
        context,
        'gray',
        shade: 50,
        darkColorName: 'gray',
        darkShade: 900,
      ),
      body: SafeArea(
        child: WDiv(
          className: 'flex flex-col h-full',
          children: [
            ModalNavBar(
              title: trans('monitors.new_monitor'),
              actionLabel: trans('common.add'),
              onAction: _handleCreate,
            ),
            WDiv(
              className: 'flex-1 overflow-y-auto',
              scrollPrimary: true,
              child: WDiv(
                className: 'flex flex-col gap-6 p-4 pb-8',
                children: [
                  _buildTypeSelector(),
                  _buildBasicInfoSection(),
                  if (_selectedType == MonitorType.http)
                    _buildHttpOptionsSection(),
                  _buildMonitoringSettingsSection(),
                  _buildLocationsSection(),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  // -------  Type Selector  -------

  Widget _buildTypeSelector() {
    return SegmentedControl<MonitorType>(
      items: MonitorType.values,
      selected: _selectedType,
      labelOf: (t) => t.label,
      onChanged: (t) => setState(() => _selectedType = t),
    );
  }

  // -------  Basic Info  -------

  Widget _buildBasicInfoSection() {
    return SettingsSection(
      title: trans('monitors.basic_info'),
      isElevated: true,
      usePadding: true,
      child: WDiv(
        className: 'flex flex-col gap-3',
        children: [
          WFormInput(
            controller: _nameController,
            placeholder: trans('monitors.monitor_name_placeholder'),
            label: trans('monitors.monitor_name'),
            labelClassName: '''
              text-sm font-medium mb-1
              text-gray-700 dark:text-gray-300
            ''',
            className: '''
              h-12 px-4 rounded-lg
              bg-white dark:bg-gray-800
              border border-gray-300 dark:border-gray-600
              focus:border-primary dark:focus:border-primary-400
              text-base text-gray-900 dark:text-white
            ''',
            placeholderClassName: 'text-gray-400 dark:text-gray-500',
          ),
          WFormInput(
            controller: _urlController,
            placeholder: _urlPlaceholder,
            label: _urlLabel,
            type: InputType.text,
            labelClassName: '''
              text-sm font-medium mb-1
              text-gray-700 dark:text-gray-300
            ''',
            className: '''
              h-12 px-4 rounded-lg
              bg-white dark:bg-gray-800
              border border-gray-300 dark:border-gray-600
              focus:border-primary dark:focus:border-primary-400
              text-base font-mono text-gray-900 dark:text-white
            ''',
            placeholderClassName: 'text-gray-400 dark:text-gray-500',
          ),
        ],
      ),
    );
  }

  String get _urlLabel => switch (_selectedType) {
    MonitorType.http => trans('monitors.url_label_http'),
    MonitorType.ping => trans('monitors.url_label_ping'),
    MonitorType.port => trans('monitors.url_label_port'),
  };

  String get _urlPlaceholder => switch (_selectedType) {
    MonitorType.http => trans('monitors.url_placeholder_http'),
    MonitorType.ping => trans('monitors.url_placeholder_ping'),
    MonitorType.port => trans('monitors.url_placeholder_port'),
  };

  // -------  HTTP Options  -------

  Widget _buildHttpOptionsSection() {
    return SettingsSection(
      title: trans('monitors.http_options'),
      isElevated: true,
      usePadding: true,
      child: WDiv(
        className: 'flex flex-col gap-4',
        children: [
          WDiv(
            className: 'flex flex-col gap-2',
            children: [
              WText(
                trans('monitors.method'),
                className: '''
                  text-sm font-medium
                  text-gray-700 dark:text-gray-300
                ''',
              ),
              SegmentedControl<HttpMethod>(
                items: HttpMethod.values,
                selected: _selectedMethod,
                labelOf: (m) => m.label,
                onChanged: (m) => setState(() => _selectedMethod = m),
              ),
            ],
          ),
          WFormInput(
            controller: _statusCodeController,
            placeholder: '200',
            label: trans('monitors.expected_status_code'),
            type: InputType.number,
            labelClassName: '''
              text-sm font-medium mb-1
              text-gray-700 dark:text-gray-300
            ''',
            className: '''
              h-12 px-4 rounded-lg
              bg-white dark:bg-gray-800
              border border-gray-300 dark:border-gray-600
              focus:border-primary dark:focus:border-primary-400
              text-base font-mono text-gray-900 dark:text-white
            ''',
            placeholderClassName: 'text-gray-400 dark:text-gray-500',
          ),
        ],
      ),
    );
  }

  // -------  Monitoring Settings  -------

  Widget _buildMonitoringSettingsSection() {
    return SettingsSection(
      title: trans('monitors.monitoring'),
      isElevated: true,
      usePadding: true,
      child: WDiv(
        className: 'flex flex-col gap-4',
        children: [
          PresetSelector(
            label: trans('monitors.check_interval'),
            presets: _intervalPresets,
            selected: _selectedInterval,
            onChanged: (v) => setState(() => _selectedInterval = v),
          ),
          PresetSelector(
            label: trans('monitors.timeout'),
            presets: _timeoutPresets,
            selected: _selectedTimeout,
            onChanged: (v) => setState(() => _selectedTimeout = v),
          ),
        ],
      ),
    );
  }

  // -------  Locations  -------

  Widget _buildLocationsSection() {
    return SettingsSection(
      title: trans('monitors.locations'),
      isElevated: true,
      usePadding: true,
      trailing: WText(
        trans('monitors.locations_selected', {
          'count': _selectedLocations.length.toString(),
        }),
        className: 'text-xs text-gray-400 dark:text-gray-500',
      ),
      child: WDiv(
        className: 'flex flex-col gap-2',
        children: [
          for (var i = 0; i < MonitorLocation.values.length; i += 2)
            WDiv(
              className: 'flex flex-row gap-2',
              children: [
                WDiv(
                  className: 'flex-1',
                  child: _buildLocationBadge(MonitorLocation.values[i]),
                ),
                if (i + 1 < MonitorLocation.values.length)
                  WDiv(
                    className: 'flex-1',
                    child: _buildLocationBadge(MonitorLocation.values[i + 1]),
                  )
                else
                  WDiv(className: 'flex-1'),
              ],
            ),
        ],
      ),
    );
  }

  Widget _buildLocationBadge(MonitorLocation location) {
    final isSelected = _selectedLocations.contains(location);

    return WButton(
      onTap: () {
        setState(() {
          if (isSelected) {
            _selectedLocations.remove(location);
          } else {
            _selectedLocations.add(location);
          }
        });
      },
      states: {if (isSelected) 'selected'},
      className: '''
        py-3.5 px-3 rounded-xl
        bg-white dark:bg-gray-800
        border border-gray-200 dark:border-gray-700
        selected:bg-primary-50 dark:selected:bg-primary-900/30
        selected:border-primary dark:selected:border-primary-400
      ''',
      child: WDiv(
        className: 'flex flex-row items-center gap-2',
        children: [
          WDiv(
            states: {if (isSelected) 'selected'},
            className: '''
              text-[16px]
              text-gray-400 dark:text-gray-500
              selected:text-primary dark:selected:text-primary-400
            ''',
            child: WIcon(Icons.public_rounded),
          ),
          WDiv(
            className: 'flex-1 flex flex-col',
            children: [
              WText(
                location.label,
                states: {if (isSelected) 'selected'},
                className: '''
                  text-sm font-medium no-underline
                  text-gray-700 dark:text-gray-300
                  selected:text-primary-700 dark:selected:text-primary-400
                ''',
              ),
            ],
          ),
          if (isSelected)
            WDiv(
              className: 'text-[16px] text-primary dark:text-primary-400',
              child: WIcon(Icons.check_circle_rounded),
            ),
        ],
      ),
    );
  }

  // -------  Actions  -------

  void _handleCreate() {
    // TODO: Implement form validation and API call
  }
}

// -------  Preset Data  -------

const _intervalPresets = [
  (value: 60, label: '1m'),
  (value: 120, label: '2m'),
  (value: 180, label: '3m'),
  (value: 300, label: '5m'),
];

const _timeoutPresets = [
  (value: 10, label: '10s'),
  (value: 15, label: '15s'),
  (value: 30, label: '30s'),
  (value: 60, label: '60s'),
];
