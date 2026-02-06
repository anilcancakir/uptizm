import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/enums/alert_operator.dart';
import '../../../../app/enums/alert_rule_type.dart';
import '../../../../app/enums/alert_severity.dart';
import '../../../../app/models/alert_rule.dart';
import '../app_card.dart';

class AlertRuleForm extends StatefulWidget {
  final AlertRule? initialRule;
  final AlertRuleType? initialType;
  final ValueChanged<AlertRule> onSubmit;
  final String? monitorId; // Optional: for monitor-specific rules

  const AlertRuleForm({
    required this.onSubmit,
    this.initialRule,
    this.initialType,
    this.monitorId,
    super.key,
  });

  @override
  State<AlertRuleForm> createState() => _AlertRuleFormState();
}

class _AlertRuleFormState extends State<AlertRuleForm> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _thresholdValueController = TextEditingController();
  final _thresholdMinController = TextEditingController();
  final _thresholdMaxController = TextEditingController();
  final _consecutiveChecksController = TextEditingController();
  final _metricKeyController = TextEditingController();

  late AlertRuleType _selectedType;
  late AlertSeverity _selectedSeverity;
  AlertOperator _selectedOperator = AlertOperator.greaterThan;

  @override
  void initState() {
    super.initState();

    if (widget.initialRule != null) {
      _nameController.text = widget.initialRule!.name ?? '';
      _selectedType = widget.initialRule!.type;
      _selectedSeverity = widget.initialRule!.severity;
      _metricKeyController.text = widget.initialRule!.metricKey ?? '';
      _selectedOperator =
          widget.initialRule!.operator ?? AlertOperator.greaterThan;
      _thresholdValueController.text =
          widget.initialRule!.thresholdValue?.toString() ?? '';
      _thresholdMinController.text =
          widget.initialRule!.thresholdMin?.toString() ?? '';
      _thresholdMaxController.text =
          widget.initialRule!.thresholdMax?.toString() ?? '';
      _consecutiveChecksController.text =
          widget.initialRule!.consecutiveChecks?.toString() ?? '1';
    } else {
      _selectedType = widget.initialType ?? AlertRuleType.status;
      _selectedSeverity = AlertSeverity.warning;
      _consecutiveChecksController.text = '1';
    }
  }

  @override
  void dispose() {
    _nameController.dispose();
    _thresholdValueController.dispose();
    _thresholdMinController.dispose();
    _thresholdMaxController.dispose();
    _consecutiveChecksController.dispose();
    _metricKeyController.dispose();
    super.dispose();
  }

  void _handleSubmit() {
    if (!_formKey.currentState!.validate()) {
      Magic.toast('Please fill in all required fields');
      return;
    }

    final rule = AlertRule()
      ..name = _nameController.text
      ..type = _selectedType
      ..severity = _selectedSeverity
      ..consecutiveChecks = int.tryParse(_consecutiveChecksController.text) ?? 1
      ..monitorId =
          widget.monitorId; // Set monitor ID if creating for specific monitor

    if (_selectedType == AlertRuleType.threshold ||
        _selectedType == AlertRuleType.anomaly) {
      rule.metricKey = _metricKeyController.text;
    }

    if (_selectedType == AlertRuleType.threshold) {
      rule.operator = _selectedOperator;

      if (_selectedOperator.requiresRange) {
        rule.thresholdMin = double.tryParse(_thresholdMinController.text);
        rule.thresholdMax = double.tryParse(_thresholdMaxController.text);
      } else {
        rule.thresholdValue = double.tryParse(_thresholdValueController.text);
      }
    }

    Log.debug('Submitting alert rule: ${rule.toMap()}');
    widget.onSubmit(rule);
  }

  @override
  Widget build(BuildContext context) {
    return Form(
      key: _formKey,
      child: WDiv(
        className: 'flex flex-col gap-6',
        children: [
          // Basic Information Section
          AppCard(
            title: 'Basic Information',
            body: WDiv(
              className: 'flex flex-col gap-4',
              children: [
                // Rule Name
                WFormInput(
                  controller: _nameController,
                  label: 'Rule Name',
                  placeholder: 'e.g., High Response Time',
                  validator: (value) {
                    if (value == null || value.isEmpty) {
                      return 'Rule name is required';
                    }
                    return null;
                  },
                  labelClassName: '''
                    text-gray-900 dark:text-gray-200
                    mb-2 text-sm font-medium
                  ''',
                  className: '''
                    w-full bg-white dark:bg-gray-800
                    text-gray-900 dark:text-white
                    rounded-lg
                    border border-gray-200 dark:border-gray-700
                    px-3 py-3
                    text-sm
                    focus:border-primary
                    focus:ring-2 focus:ring-primary/20
                    error:border-red-500
                  ''',
                  errorClassName: 'text-red-500 text-xs mt-1',
                ),

                // Alert Type
                WFormSelect<AlertRuleType>(
                  label: 'Alert Type',
                  value: _selectedType,
                  options: AlertRuleType.selectOptions,
                  onChange: (type) {
                    if (type != null) {
                      setState(() {
                        _selectedType = type;
                      });
                    }
                  },
                  labelClassName: '''
                    text-gray-900 dark:text-gray-200
                    mb-2 text-sm font-medium
                  ''',
                  className: '''
                    w-full bg-white dark:bg-gray-800
                    text-gray-900 dark:text-white
                    rounded-lg
                    border border-gray-200 dark:border-gray-700
                    px-3 py-3
                    text-sm
                  ''',
                  menuClassName: '''
                    bg-white dark:bg-gray-800
                    border border-gray-200 dark:border-gray-700
                    rounded-xl shadow-xl
                  ''',
                ),

                // Severity
                WFormSelect<AlertSeverity>(
                  label: 'Severity',
                  value: _selectedSeverity,
                  options: AlertSeverity.selectOptions,
                  onChange: (sev) {
                    if (sev != null) {
                      setState(() {
                        _selectedSeverity = sev;
                      });
                    }
                  },
                  labelClassName: '''
                    text-gray-900 dark:text-gray-200
                    mb-2 text-sm font-medium
                  ''',
                  className: '''
                    w-full bg-white dark:bg-gray-800
                    text-gray-900 dark:text-white
                    rounded-lg
                    border border-gray-200 dark:border-gray-700
                    px-3 py-3
                    text-sm
                  ''',
                  menuClassName: '''
                    bg-white dark:bg-gray-800
                    border border-gray-200 dark:border-gray-700
                    rounded-xl shadow-xl
                  ''',
                ),

                // Consecutive Checks
                WFormInput(
                  controller: _consecutiveChecksController,
                  label: 'Consecutive Failed Checks',
                  placeholder: '1',
                  hint:
                      'Number of consecutive failures before triggering alert',
                  labelClassName: '''
                    text-gray-900 dark:text-gray-200
                    mb-2 text-sm font-medium
                  ''',
                  className: '''
                    w-full bg-white dark:bg-gray-800
                    text-gray-900 dark:text-white
                    rounded-lg
                    border border-gray-200 dark:border-gray-700
                    px-3 py-3
                    text-sm
                    focus:border-primary
                    focus:ring-2 focus:ring-primary/20
                  ''',
                ),
              ],
            ),
          ),

          // Threshold Configuration (for threshold and anomaly types)
          if (_selectedType == AlertRuleType.threshold ||
              _selectedType == AlertRuleType.anomaly)
            AppCard(
              title: 'Threshold Configuration',
              body: WDiv(
                className: 'flex flex-col gap-4',
                children: [
                  // Metric Key
                  WFormInput(
                    controller: _metricKeyController,
                    label: 'Metric Key',
                    placeholder: 'e.g., response_time',
                    hint:
                        'The metric to monitor (e.g., response_time, uptime_percentage)',
                    labelClassName: '''
                      text-gray-900 dark:text-gray-200
                      mb-2 text-sm font-medium
                    ''',
                    className: '''
                      w-full bg-white dark:bg-gray-800
                      text-gray-900 dark:text-white
                      rounded-lg
                      border border-gray-200 dark:border-gray-700
                      px-3 py-3
                      text-sm
                      focus:border-primary
                      focus:ring-2 focus:ring-primary/20
                    ''',
                  ),

                  // Operator (for threshold only)
                  if (_selectedType == AlertRuleType.threshold)
                    WFormSelect<AlertOperator>(
                      label: 'Operator',
                      value: _selectedOperator,
                      options: AlertOperator.selectOptions,
                      onChange: (op) {
                        if (op != null) {
                          setState(() {
                            _selectedOperator = op;
                          });
                        }
                      },
                      labelClassName: '''
                        text-gray-900 dark:text-gray-200
                        mb-2 text-sm font-medium
                      ''',
                      className: '''
                        w-full bg-white dark:bg-gray-800
                        text-gray-900 dark:text-white
                        rounded-lg
                        border border-gray-200 dark:border-gray-700
                        px-3 py-3
                        text-sm
                      ''',
                      menuClassName: '''
                        bg-white dark:bg-gray-800
                        border border-gray-200 dark:border-gray-700
                        rounded-xl shadow-xl
                      ''',
                    ),

                  // Threshold Value (for threshold, non-range operators)
                  if (_selectedType == AlertRuleType.threshold &&
                      !_selectedOperator.requiresRange)
                    WFormInput(
                      controller: _thresholdValueController,
                      label: 'Threshold Value',
                      placeholder: '5000',
                      hint:
                          'Alert when metric ${_selectedOperator.value} this value',
                      labelClassName: '''
                        text-gray-900 dark:text-gray-200
                        mb-2 text-sm font-medium
                      ''',
                      className: '''
                        w-full bg-white dark:bg-gray-800
                        text-gray-900 dark:text-white
                        rounded-lg
                        border border-gray-200 dark:border-gray-700
                        px-3 py-3
                        text-sm
                        focus:border-primary
                        focus:ring-2 focus:ring-primary/20
                      ''',
                    ),

                  // Threshold Min/Max (for threshold, range operators)
                  if (_selectedType == AlertRuleType.threshold &&
                      _selectedOperator.requiresRange)
                    WDiv(
                      className: 'flex flex-row gap-4',
                      children: [
                        Expanded(
                          child: WFormInput(
                            controller: _thresholdMinController,
                            label: 'Minimum',
                            placeholder: '100',
                            labelClassName: '''
                              text-gray-900 dark:text-gray-200
                              mb-2 text-sm font-medium
                            ''',
                            className: '''
                              w-full bg-white dark:bg-gray-800
                              text-gray-900 dark:text-white
                              rounded-lg
                              border border-gray-200 dark:border-gray-700
                              px-3 py-3
                              text-sm
                              focus:border-primary
                              focus:ring-2 focus:ring-primary/20
                            ''',
                          ),
                        ),
                        Expanded(
                          child: WFormInput(
                            controller: _thresholdMaxController,
                            label: 'Maximum',
                            placeholder: '500',
                            labelClassName: '''
                              text-gray-900 dark:text-gray-200
                              mb-2 text-sm font-medium
                            ''',
                            className: '''
                              w-full bg-white dark:bg-gray-800
                              text-gray-900 dark:text-white
                              rounded-lg
                              border border-gray-200 dark:border-gray-700
                              px-3 py-3
                              text-sm
                              focus:border-primary
                              focus:ring-2 focus:ring-primary/20
                            ''',
                          ),
                        ),
                      ],
                    ),
                ],
              ),
            ),

          // Submit Button
          WDiv(
            className: 'flex flex-row justify-end gap-3',
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
                child: WText('Cancel'),
              ),
              WButton(
                onTap: _handleSubmit,
                className: '''
                  px-4 py-2 rounded-lg
                  bg-primary hover:bg-green-600
                  text-white
                  text-sm font-medium
                ''',
                child: WText('Save Alert Rule'),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
