import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/models/assertion_rule.dart';
import '../../../../app/models/metric_mapping.dart';
import '../app_card.dart';
import '../assertion_rule_editor.dart';
import '../metric_mapping_editor.dart';
import '../response_preview.dart';

/// Shared "Validation & Parsing" section: response preview, assertion rules, metric mappings.
class MonitorValidationSection extends StatelessWidget {
  final Map<String, dynamic>? testFetchResponse;
  final bool isTestingFetch;
  final VoidCallback onTestFetch;
  final List<AssertionRule> assertionRules;
  final ValueChanged<List<AssertionRule>> onAssertionRulesChanged;
  final List<MetricMapping> metricMappings;
  final ValueChanged<List<MetricMapping>> onMetricMappingsChanged;

  const MonitorValidationSection({
    super.key,
    required this.testFetchResponse,
    required this.isTestingFetch,
    required this.onTestFetch,
    required this.assertionRules,
    required this.onAssertionRulesChanged,
    required this.metricMappings,
    required this.onMetricMappingsChanged,
  });

  @override
  Widget build(BuildContext context) {
    return AppCard(
      title: trans('monitor.validation_parsing'),
      body: WDiv(
        className: 'flex flex-col gap-4',
        children: [
          // Response Preview Section
          WDiv(
            className: 'flex flex-col gap-2',
            children: [
              WDiv(
                className: 'flex flex-row items-center justify-between',
                children: [
                  WText(
                    trans('monitor.response_preview'),
                    className:
                        'text-sm font-medium text-gray-900 dark:text-gray-200',
                  ),
                  WButton(
                    isLoading: isTestingFetch,
                    onTap: onTestFetch,
                    className: '''
                      px-3 py-2 rounded-lg
                      bg-primary hover:bg-green-600
                      text-white text-xs font-medium
                    ''',
                    child: WDiv(
                      className: 'flex flex-row items-center gap-2',
                      children: [
                        WIcon(Icons.refresh, className: 'text-sm'),
                        WText(trans('monitor.test_fetch')),
                      ],
                    ),
                  ),
                ],
              ),
              ResponsePreview(
                response: testFetchResponse,
                isLoading: isTestingFetch,
              ),
            ],
          ),

          // Assertion Rules Section
          WDiv(
            className: 'flex flex-col gap-2',
            children: [
              WText(
                trans('monitor.assertion_rules'),
                className:
                    'text-sm font-medium text-gray-900 dark:text-gray-200',
              ),
              AssertionRuleEditor(
                rules: assertionRules,
                onChanged: onAssertionRulesChanged,
              ),
            ],
          ),

          // Metric Mappings Section
          WDiv(
            className: 'flex flex-col gap-2',
            children: [
              WText(
                trans('monitor.metric_mappings'),
                className:
                    'text-sm font-medium text-gray-900 dark:text-gray-200',
              ),
              WText(
                trans('monitor.metric_mappings_hint'),
                className: 'text-sm text-gray-600 dark:text-gray-400',
              ),
              MetricMappingEditor(
                mappings: metricMappings,
                onChanged: onMetricMappingsChanged,
              ),
            ],
          ),
        ],
      ),
    );
  }
}
