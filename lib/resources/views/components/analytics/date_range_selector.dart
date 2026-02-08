import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

class DateRangeSelector extends StatelessWidget {
  final String? selectedPreset;
  final DateTimeRange? customRange;
  final ValueChanged<String> onPresetSelected;
  final ValueChanged<DateTimeRange> onCustomRangeSelected;

  const DateRangeSelector({
    super.key,
    this.selectedPreset,
    this.customRange,
    required this.onPresetSelected,
    required this.onCustomRangeSelected,
  });

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'wrap items-center gap-2',
      children: [
        // Preset buttons
        _buildPresetButton(context, '24h', 'analytics.last_24h'),
        _buildPresetButton(context, '7d', 'analytics.last_7d'),
        _buildPresetButton(context, '30d', 'analytics.last_30d'),
        _buildPresetButton(context, '90d', 'analytics.last_90d'),

        // Custom date range picker
        _buildCustomDatePicker(context),
      ],
    );
  }

  Widget _buildPresetButton(
    BuildContext context,
    String value,
    String labelKey,
  ) {
    final isSelected = selectedPreset == value;
    return WButton(
      onTap: () => onPresetSelected(value),
      className:
          '''
        px-4 py-2 rounded-lg text-sm font-medium border transition-colors
        ${isSelected ? 'bg-primary text-white border-primary' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700'}
      ''',
      child: WText(trans(labelKey)),
    );
  }

  Widget _buildCustomDatePicker(BuildContext context) {
    // Using native Flutter date picker to avoid mouse_tracker issues with WDatePicker
    return WButton(
      onTap: () async {
        final now = DateTime.now();
        final picked = await showDateRangePicker(
          context: context,
          firstDate: now.subtract(const Duration(days: 365)),
          lastDate: now,
          initialDateRange: customRange,
          builder: (context, child) {
            return Theme(
              data: Theme.of(context).copyWith(
                colorScheme: Theme.of(
                  context,
                ).colorScheme.copyWith(primary: const Color(0xFF009E60)),
              ),
              child: child!,
            );
          },
        );
        if (picked != null) {
          onCustomRangeSelected(picked);
        }
      },
      className:
          'flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700',
      child: WDiv(
        className: 'flex items-center gap-2',
        children: [
          WIcon(Icons.calendar_today, className: 'text-gray-400 text-base'),
          WText(
            customRange != null
                ? '${_formatDate(customRange!.start)} - ${_formatDate(customRange!.end)}'
                : 'Custom Range',
            className: customRange != null
                ? 'text-sm text-gray-900 dark:text-gray-100'
                : 'text-sm text-gray-400 dark:text-gray-500',
          ),
          WIcon(
            Icons.keyboard_arrow_down,
            className: 'text-gray-400 text-base',
          ),
        ],
      ),
    );
  }

  String _formatDate(DateTime date) {
    const months = [
      'Jan',
      'Feb',
      'Mar',
      'Apr',
      'May',
      'Jun',
      'Jul',
      'Aug',
      'Sep',
      'Oct',
      'Nov',
      'Dec',
    ];
    return '${months[date.month - 1]} ${date.day}';
  }
}
