import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

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
        className: 'flex flex-col gap-5 items-stretch',
        children: [
          // JSON Response Preview
          WDiv(
            className: 'flex flex-col gap-3 items-stretch',
            children: [
              WDiv(
                className: 'flex flex-row items-center justify-between',
                children: [
                  WText(
                    trans('monitor.response_preview'),
                    className:
                        'text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400',
                  ),
                  WButton(
                    isLoading: isTestingFetch,
                    onTap: onTestFetch,
                    className: '''
                      flex flex-row items-center gap-1
                      px-2 py-1 rounded-md
                      bg-primary/5 dark:bg-primary/10
                      text-primary
                      hover:bg-primary/10 dark:hover:bg-primary/20
                      text-xs font-bold uppercase tracking-wide
                    ''',
                    child: WDiv(
                      className: 'flex flex-row items-center gap-1',
                      children: [
                        WIcon(Icons.refresh, className: 'text-xs'),
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

          // Assertion Rules
          WDiv(
            className: 'flex flex-col gap-3',
            children: [
              WText(
                trans('monitor.assertion_rules'),
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400',
              ),
              AssertionRuleEditor(
                rules: assertionRules,
                onChanged: onAssertionRulesChanged,
              ),
            ],
          ),

          // Metric Mappings
          WDiv(
            className: 'flex flex-col gap-3',
            children: [
              WText(
                trans('monitor.metric_mappings'),
                className:
                    'text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400',
              ),
              WText(
                trans('monitor.metric_mappings_hint'),
                className: 'text-xs text-gray-600 dark:text-gray-400',
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
