import 'package:flutter/material.dart';
import 'package:fluttersdk_wind/fluttersdk_wind.dart';
import '../../../app/enums/assertion_operator.dart';
import '../../../app/enums/assertion_type.dart';
import '../../../app/models/assertion_rule.dart';

/// Editor for assertion rules (validation rules for monitor responses).
///
/// Features:
/// - Add/remove assertion rules
/// - Select type, operator, value
/// - Conditional path input for JSON path assertions
/// - Display rule summary
class AssertionRuleEditor extends StatefulWidget {
  final List<AssertionRule> rules;
  final Function(List<AssertionRule>) onChanged;

  const AssertionRuleEditor({
    Key? key,
    required this.rules,
    required this.onChanged,
  }) : super(key: key);

  @override
  State<AssertionRuleEditor> createState() => _AssertionRuleEditorState();
}

class _AssertionRuleEditorState extends State<AssertionRuleEditor> {
  late List<_RuleState> _ruleStates;

  @override
  void initState() {
    super.initState();
    _ruleStates = widget.rules.map((rule) => _RuleState.fromRule(rule)).toList();
  }

  @override
  void dispose() {
    for (final state in _ruleStates) {
      state.valueController.dispose();
      state.pathController.dispose();
    }
    super.dispose();
  }

  void _addRule() {
    setState(() {
      _ruleStates.add(_RuleState(
        type: AssertionType.statusCode,
        operator: AssertionOperator.equals,
        valueController: TextEditingController(),
        pathController: TextEditingController(),
      ));
    });
    _notifyChanged();
  }

  void _removeRule(int index) {
    setState(() {
      final state = _ruleStates.removeAt(index);
      state.valueController.dispose();
      state.pathController.dispose();
    });
    _notifyChanged();
  }

  void _updateType(int index, AssertionType? type) {
    if (type == null) return;
    setState(() {
      _ruleStates[index].type = type;
    });
    _notifyChanged();
  }

  void _updateOperator(int index, AssertionOperator? operator) {
    if (operator == null) return;
    setState(() {
      _ruleStates[index].operator = operator;
    });
    _notifyChanged();
  }

  void _notifyChanged() {
    final rules = _ruleStates.map((state) {
      final rule = AssertionRule(
        type: state.type,
        operator: state.operator,
        value: state.valueController.text,
        path: state.type == AssertionType.bodyJsonPath
            ? state.pathController.text
            : null,
      );
      return rule;
    }).toList();

    widget.onChanged(rules);
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        // Existing rules
        ..._ruleStates.asMap().entries.map((entry) {
          final index = entry.key;
          final state = entry.value;

          return _buildRuleRow(index, state);
        }),

        // Add button
        WButton(
          onTap: _addRule,
          className: '''
            px-3 py-2 rounded-lg
            bg-gray-100 dark:bg-gray-700
            text-gray-700 dark:text-gray-300
            hover:bg-gray-200 dark:hover:bg-gray-600
            text-sm
          ''',
          child: WText('Add Assertion Rule'),
        ),
      ],
    );
  }

  Widget _buildRuleRow(int index, _RuleState state) {
    final rule = AssertionRule(
      type: state.type,
      operator: state.operator,
      value: state.valueController.text,
      path: state.pathController.text,
    );

    return WDiv(
      className: 'flex flex-col gap-2 p-3 rounded-lg bg-gray-50 dark:bg-gray-800',
      children: [
        // Rule summary
        WDiv(
          className: 'flex flex-row justify-between items-center',
          children: [
            WText(
              rule.toDisplayString(),
              className: 'text-sm font-medium text-gray-700 dark:text-gray-300',
            ),
            WButton(
              onTap: () => _removeRule(index),
              className: 'p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700',
              child: WIcon(Icons.close, className: 'text-gray-600 dark:text-gray-400 text-lg'),
            ),
          ],
        ),

        // Type, Operator, Value row
        WDiv(
          className: 'flex flex-row gap-2',
          children: [
            // Type select
            Expanded(
              child: WSelect<AssertionType>(
                value: state.type,
                options: AssertionType.selectOptions,
                onChange: (type) => _updateType(index, type),
                className: '''
                  border border-gray-200 dark:border-gray-700
                  bg-white dark:bg-gray-800
                  rounded-lg px-3 py-3 text-sm
                ''',
                menuClassName: '''
                  bg-white dark:bg-gray-800
                  border border-gray-200 dark:border-gray-700
                  rounded-xl shadow-xl
                ''',
              ),
            ),

            // Operator select
            Expanded(
              child: WSelect<AssertionOperator>(
                value: state.operator,
                options: AssertionOperator.selectOptions,
                onChange: (operator) => _updateOperator(index, operator),
                className: '''
                  border border-gray-200 dark:border-gray-700
                  bg-white dark:bg-gray-800
                  rounded-lg px-3 py-3 text-sm
                ''',
                menuClassName: '''
                  bg-white dark:bg-gray-800
                  border border-gray-200 dark:border-gray-700
                  rounded-xl shadow-xl
                ''',
              ),
            ),

            // Value input
            Expanded(
              child: WInput(
                controller: state.valueController,
                placeholder: 'Value',
                onChanged: (_) => _notifyChanged(),
                className: '''
                  w-full px-3 py-3 rounded-lg
                  bg-white dark:bg-gray-800
                  border border-gray-200 dark:border-gray-700
                  text-gray-900 dark:text-white text-sm
                  focus:border-primary focus:ring-2 focus:ring-primary/20
                ''',
                placeholderClassName: 'text-gray-400 dark:text-gray-500',
              ),
            ),
          ],
        ),

        // Path input (only for bodyJsonPath)
        if (state.type == AssertionType.bodyJsonPath)
          WInput(
            controller: state.pathController,
            placeholder: 'e.g. data.status',
            onChanged: (_) => _notifyChanged(),
            className: '''
              w-full px-3 py-3 rounded-lg
              bg-white dark:bg-gray-800
              border border-gray-200 dark:border-gray-700
              text-gray-900 dark:text-white text-sm
              focus:border-primary focus:ring-2 focus:ring-primary/20
            ''',
            placeholderClassName: 'text-gray-400 dark:text-gray-500',
          ),
      ],
    );
  }
}

class _RuleState {
  AssertionType type;
  AssertionOperator operator;
  TextEditingController valueController;
  TextEditingController pathController;

  _RuleState({
    required this.type,
    required this.operator,
    required this.valueController,
    required this.pathController,
  });

  factory _RuleState.fromRule(AssertionRule rule) {
    return _RuleState(
      type: rule.type,
      operator: rule.operator,
      valueController: TextEditingController(text: rule.value),
      pathController: TextEditingController(text: rule.path ?? ''),
    );
  }
}
