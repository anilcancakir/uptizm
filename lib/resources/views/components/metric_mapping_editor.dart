import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import '../../../app/enums/metric_type.dart';
import '../../../app/models/metric_mapping.dart';

class MetricMappingEditor extends StatefulWidget {
  final List<MetricMapping> mappings;
  final Function(List<MetricMapping>) onChanged;

  const MetricMappingEditor({
    super.key,
    required this.mappings,
    required this.onChanged,
  });

  @override
  State<MetricMappingEditor> createState() => _MetricMappingEditorState();
}

class _MetricMappingEditorState extends State<MetricMappingEditor> {
  late List<_MappingState> _states;

  static List<SelectOption<String>> get _upWhenOptions => [
    SelectOption(value: 'truthy', label: trans('monitor.upwhen_truthy')),
    SelectOption(value: 'falsy', label: trans('monitor.upwhen_falsy')),
    SelectOption(value: 'not_null', label: trans('monitor.upwhen_not_null')),
    SelectOption(value: 'null', label: trans('monitor.upwhen_null')),
    SelectOption(value: 'equals', label: trans('monitor.upwhen_equals')),
  ];

  @override
  void initState() {
    super.initState();
    _states = widget.mappings.map((m) => _MappingState.fromMapping(m)).toList();
  }

  @override
  void dispose() {
    for (final state in _states) {
      state.labelController.dispose();
      state.pathController.dispose();
      state.unitController.dispose();
      state.upWhenController.dispose();
    }
    super.dispose();
  }

  void _add() {
    setState(() {
      _states.add(
        _MappingState(
          type: MetricType.numeric,
          labelController: TextEditingController(),
          pathController: TextEditingController(),
          unitController: TextEditingController(),
          upWhenController: TextEditingController(),
        ),
      );
    });
    _notifyChanged();
  }

  void _remove(int index) {
    setState(() {
      final state = _states.removeAt(index);
      state.labelController.dispose();
      state.pathController.dispose();
      state.unitController.dispose();
      state.upWhenController.dispose();
    });
    _notifyChanged();
  }

  void _updateType(int index, MetricType? type) {
    if (type == null) return;
    setState(() {
      _states[index].type = type;
    });
    _notifyChanged();
  }

  void _updateUpWhenType(int index, String? type) {
    if (type == null) return;
    setState(() {
      _states[index].upWhenType = type;
    });
    _notifyChanged();
  }

  void _notifyChanged() {
    final mappings = _states.map((state) {
      final unit = state.unitController.text.isEmpty
          ? null
          : state.unitController.text;

      String? upWhen;
      if (state.type == MetricType.status) {
        if (state.upWhenType == 'equals') {
          upWhen = 'equals:${state.upWhenController.text}';
        } else {
          upWhen = state.upWhenType;
        }
      }

      return MetricMapping(
        label: state.labelController.text,
        path: state.pathController.text,
        type: state.type,
        unit: unit,
        upWhen: upWhen,
      );
    }).toList();

    widget.onChanged(mappings);
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        ..._states.asMap().entries.map((entry) {
          return _buildRow(entry.key, entry.value, isDark);
        }),
        WButton(
          onTap: _add,
          className: '''
            px-3 py-2 rounded-lg
            bg-gray-100 dark:bg-gray-700
            text-gray-700 dark:text-gray-300
            hover:bg-gray-200 dark:hover:bg-gray-600
            text-sm
          ''',
          child: WText(trans('monitor.add_metric_mapping')),
        ),
      ],
    );
  }

  Widget _buildRow(int index, _MappingState state, bool isDark) {
    final unit = state.unitController.text.isEmpty
        ? null
        : state.unitController.text;
    final mapping = MetricMapping(
      label: state.labelController.text,
      path: state.pathController.text,
      type: state.type,
      unit: unit,
    );

    return WDiv(
      className:
          'flex flex-col gap-2 p-3 rounded-lg bg-gray-50 dark:bg-gray-800',
      children: [
        // Summary + delete
        WDiv(
          className: 'flex flex-row justify-between items-center',
          children: [
            WText(
              mapping.toDisplayString(),
              className: 'text-sm font-medium text-gray-700 dark:text-gray-300',
            ),
            WButton(
              onTap: () => _remove(index),
              className:
                  'p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700',
              child: WIcon(
                Icons.close,
                className: 'text-gray-600 dark:text-gray-400 text-lg',
              ),
            ),
          ],
        ),

        // Label + Path row - native Flutter Row
        Row(
          children: [
            Expanded(
              child: WInput(
                controller: state.labelController,
                onChanged: (_) => _notifyChanged(),
                placeholder: trans('monitor.label_placeholder'),
                className: '''
                  w-full px-3 py-3 rounded-lg text-sm
                  bg-white dark:bg-gray-800
                  text-gray-900 dark:text-white
                  border border-gray-200 dark:border-gray-700
                  focus:border-primary focus:ring-2 focus:ring-primary/20
                ''',
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: WInput(
                controller: state.pathController,
                onChanged: (_) => _notifyChanged(),
                placeholder: trans('monitor.path_placeholder'),
                className: '''
                  w-full px-3 py-3 rounded-lg text-sm
                  bg-white dark:bg-gray-800
                  text-gray-900 dark:text-white
                  border border-gray-200 dark:border-gray-700
                  focus:border-primary focus:ring-2 focus:ring-primary/20
                ''',
              ),
            ),
          ],
        ),

        // Type + Unit/UpWhen row - native Flutter Row
        Row(
          children: [
            Expanded(
              child: WSelect<MetricType>(
                value: state.type,
                options: MetricType.selectOptions,
                onChange: (type) => _updateType(index, type),
                className: '''
                  w-full border border-gray-200 dark:border-gray-700
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
            const SizedBox(width: 8),
            if (state.type == MetricType.status) ...[
              Expanded(
                child: WSelect<String>(
                  value: state.upWhenType,
                  options: _upWhenOptions,
                  onChange: (v) => _updateUpWhenType(index, v),
                  className: '''
                    w-full border border-gray-200 dark:border-gray-700
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
              if (state.upWhenType == 'equals') ...[
                const SizedBox(width: 8),
                Expanded(
                  child: WInput(
                    controller: state.upWhenController,
                    onChanged: (_) => _notifyChanged(),
                    placeholder: trans('monitor.value_placeholder'),
                    className: '''
                      w-full px-3 py-3 rounded-lg text-sm
                      bg-white dark:bg-gray-800
                      text-gray-900 dark:text-white
                      border border-gray-200 dark:border-gray-700
                      focus:border-primary focus:ring-2 focus:ring-primary/20
                    ''',
                  ),
                ),
              ],
            ] else
              Expanded(
                child: WInput(
                  controller: state.unitController,
                  onChanged: (_) => _notifyChanged(),
                  placeholder: trans('monitor.unit_placeholder'),
                  className: '''
                    w-full px-3 py-3 rounded-lg text-sm
                    bg-white dark:bg-gray-800
                    text-gray-900 dark:text-white
                    border border-gray-200 dark:border-gray-700
                    focus:border-primary focus:ring-2 focus:ring-primary/20
                  ''',
                ),
              ),
          ],
        ),
      ],
    );
  }
}

class _MappingState {
  MetricType type;
  TextEditingController labelController;
  TextEditingController pathController;
  TextEditingController unitController;
  TextEditingController upWhenController;
  String upWhenType; // 'truthy', 'falsy', 'equals', etc.

  _MappingState({
    required this.type,
    required this.labelController,
    required this.pathController,
    required this.unitController,
    required this.upWhenController,
    this.upWhenType = 'truthy',
  });

  factory _MappingState.fromMapping(MetricMapping mapping) {
    String upWhenType = 'truthy';
    String upWhenValue = '';

    if (mapping.upWhen != null) {
      if (mapping.upWhen!.startsWith('equals:')) {
        upWhenType = 'equals';
        upWhenValue = mapping.upWhen!.substring(7);
      } else {
        upWhenType = mapping.upWhen!;
      }
    }

    return _MappingState(
      type: mapping.type,
      labelController: TextEditingController(text: mapping.label),
      pathController: TextEditingController(text: mapping.path),
      unitController: TextEditingController(text: mapping.unit ?? ''),
      upWhenController: TextEditingController(text: upWhenValue),
      upWhenType: upWhenType,
    );
  }
}
