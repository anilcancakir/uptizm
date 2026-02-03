import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
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

  void _notifyChanged() {
    final mappings = _states.map((state) {
      final unit = state.unitController.text.isEmpty
          ? null
          : state.unitController.text;
      return MetricMapping(
        label: state.labelController.text,
        path: state.pathController.text,
        type: state.type,
        unit: unit,
      );
    }).toList();

    widget.onChanged(mappings);
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-3',
      children: [
        ..._states.asMap().entries.map((entry) {
          return _buildRow(entry.key, entry.value);
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
          child: WText('Add Metric Mapping'),
        ),
      ],
    );
  }

  Widget _buildRow(int index, _MappingState state) {
    final unit = state.unitController.text.isEmpty
        ? null
        : state.unitController.text;
    final mapping = MetricMapping(
      label: state.labelController.text,
      path: state.pathController.text,
      type: state.type,
      unit: unit,
    );

    const inputClassName = '''
      w-full px-3 py-3 rounded-lg
      bg-white dark:bg-gray-800
      border border-gray-200 dark:border-gray-700
      text-gray-900 dark:text-white text-sm
      focus:border-primary focus:ring-2 focus:ring-primary/20
    ''';

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

        // Label + Path row
        WDiv(
          className: 'flex flex-row gap-2',
          children: [
            Expanded(
              child: WInput(
                controller: state.labelController,
                placeholder: 'Label (e.g. DB Connections)',
                onChanged: (_) => _notifyChanged(),
                className: inputClassName,
                placeholderClassName: 'text-gray-400 dark:text-gray-500',
              ),
            ),
            Expanded(
              child: WInput(
                controller: state.pathController,
                placeholder: 'Path (e.g. data.database.active_connections)',
                onChanged: (_) => _notifyChanged(),
                className: inputClassName,
                placeholderClassName: 'text-gray-400 dark:text-gray-500',
              ),
            ),
          ],
        ),

        // Type + Unit row
        WDiv(
          className: 'flex flex-row gap-2',
          children: [
            Expanded(
              child: WSelect<MetricType>(
                value: state.type,
                options: MetricType.selectOptions,
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
            Expanded(
              child: WInput(
                controller: state.unitController,
                placeholder: 'Unit (e.g. MB, ms, conn)',
                onChanged: (_) => _notifyChanged(),
                className: inputClassName,
                placeholderClassName: 'text-gray-400 dark:text-gray-500',
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

  _MappingState({
    required this.type,
    required this.labelController,
    required this.pathController,
    required this.unitController,
  });

  factory _MappingState.fromMapping(MetricMapping mapping) {
    return _MappingState(
      type: mapping.type,
      labelController: TextEditingController(text: mapping.label),
      pathController: TextEditingController(text: mapping.path),
      unitController: TextEditingController(text: mapping.unit ?? ''),
    );
  }
}
