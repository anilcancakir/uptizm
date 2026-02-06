import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

/// Searchable timezone select using Wind UI's WFormSelect with API integration.
class SearchableTimezoneSelect extends StatefulWidget {
  final String? value;
  final Function(String?) onChanged;
  final String? label;
  final String? placeholder;

  const SearchableTimezoneSelect({
    super.key,
    this.value,
    required this.onChanged,
    this.label,
    this.placeholder,
  });

  @override
  State<SearchableTimezoneSelect> createState() =>
      _SearchableTimezoneSelectState();
}

class _SearchableTimezoneSelectState extends State<SearchableTimezoneSelect> {
  List<SelectOption<String>> _allOptions = [];
  bool _isInitializing = true;

  @override
  void initState() {
    super.initState();
    _initialize();
  }

  Future<void> _initialize() async {
    // Load default timezones
    final defaultOptions = await _fetchTimezones('');

    // If there's a value, make sure it's loaded
    if (widget.value != null && widget.value!.isNotEmpty) {
      final selectedExists = defaultOptions.any(
        (opt) => opt.value == widget.value,
      );
      if (!selectedExists) {
        final selectedOption = await _fetchTimezones(widget.value!);
        if (selectedOption.isNotEmpty) {
          defaultOptions.insert(0, selectedOption.first);
        }
      }
    }

    if (mounted) {
      setState(() {
        _allOptions = defaultOptions;
        _isInitializing = false;
      });
    }
  }

  Future<List<SelectOption<String>>> _fetchTimezones(String query) async {
    try {
      final response = await Http.get('/timezones?q=$query&limit=20');
      if (response.statusCode == 200) {
        final data = response.data['data'] as List;
        return data.map((tz) {
          return SelectOption<String>(
            value: tz['value'] as String,
            label: tz['label'] as String,
          );
        }).toList();
      }
    } catch (e) {
      Log.error('Failed to fetch timezones: $e');
    }
    return [];
  }

  Future<List<SelectOption<String>>> _handleSearch(String query) async {
    final results = await _fetchTimezones(query);

    // Always include the currently selected value in results
    if (widget.value != null && widget.value!.isNotEmpty) {
      final selectedExists = results.any((opt) => opt.value == widget.value);
      if (!selectedExists) {
        final selectedInAll = _allOptions
            .where((opt) => opt.value == widget.value)
            .toList();
        if (selectedInAll.isNotEmpty) {
          results.insert(0, selectedInAll.first);
        }
      }
    }

    return results;
  }

  void _handleChange(String? value) {
    widget.onChanged(value);

    // Update _allOptions to include the new selection
    if (value != null && value.isNotEmpty) {
      final exists = _allOptions.any((opt) => opt.value == value);
      if (!exists) {
        // This should not happen since we selected from search results,
        // but just in case, we'll add a placeholder that will be replaced on next search
        _fetchTimezones(value).then((options) {
          if (mounted && options.isNotEmpty) {
            setState(() {
              _allOptions = [options.first, ..._allOptions];
            });
          }
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isInitializing) {
      return WDiv(
        children: [
          if (widget.label != null)
            WText(
              widget.label!,
              className:
                  'text-xs font-bold uppercase tracking-wide mb-2 text-gray-600 dark:text-gray-400',
            ),
          WDiv(
            className: '''
              w-full px-4 py-3 rounded-xl border
              bg-white dark:bg-gray-800
              border-gray-300 dark:border-gray-700
              flex items-center justify-center
            ''',
            child: const SizedBox(
              width: 20,
              height: 20,
              child: CircularProgressIndicator(strokeWidth: 2),
            ),
          ),
        ],
      );
    }

    return WFormSelect<String>(
      value: widget.value,
      options: _allOptions,
      onChange: _handleChange,
      searchable: true,
      onSearch: _handleSearch,
      label: widget.label,
      labelClassName:
          'text-xs font-bold uppercase tracking-wide mb-2 text-gray-600 dark:text-gray-400',
      searchPlaceholder: widget.placeholder ?? 'Search timezones...',
      placeholder: widget.placeholder ?? 'Select timezone',
      className: '''
        w-full px-4 py-3 rounded-xl border transition-colors
        bg-white dark:bg-gray-800
        text-gray-900 dark:text-white
        border-gray-300 dark:border-gray-700
        focus:ring-2 focus:ring-primary
      ''',
      menuClassName: '''
        bg-white dark:bg-gray-800
        border border-gray-200 dark:border-gray-700
        shadow-xl rounded-xl
      ''',
    );
  }
}
