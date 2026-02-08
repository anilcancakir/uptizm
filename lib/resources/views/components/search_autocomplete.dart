import 'dart:async';

import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import '../../../app/models/incident.dart';
import '../../../app/models/monitor.dart';

/// Search result item model
class SearchResult {
  final String id;
  final String title;
  final String subtitle;
  final SearchResultType type;
  final String path;
  final IconData icon;

  const SearchResult({
    required this.id,
    required this.title,
    required this.subtitle,
    required this.type,
    required this.path,
    required this.icon,
  });
}

enum SearchResultType { monitor, incident, log, statusPage, setting }

/// Search Autocomplete
///
/// Global search input with autocomplete dropdown.
/// Shows monitors, incidents, activity logs, status pages, etc.
class SearchAutocomplete extends StatefulWidget {
  final void Function(SearchResult)? onResultTap;

  const SearchAutocomplete({super.key, this.onResultTap});

  @override
  State<SearchAutocomplete> createState() => _SearchAutocompleteState();
}

class _SearchAutocompleteState extends State<SearchAutocomplete> {
  final TextEditingController _controller = TextEditingController();
  final PopoverController _popoverController = PopoverController();
  final FocusNode _focusNode = FocusNode();

  String _query = '';
  List<SearchResult> _searchResults = [];
  Timer? _debounceTimer;

  @override
  void initState() {
    super.initState();
    _controller.addListener(_onTextChanged);
    _focusNode.addListener(_onFocusChange);
  }

  @override
  void dispose() {
    _debounceTimer?.cancel();
    _focusNode.removeListener(_onFocusChange);
    _focusNode.dispose();
    _controller.removeListener(_onTextChanged);
    _controller.dispose();
    _popoverController.dispose();
    super.dispose();
  }

  void _onFocusChange() {
    // Optional: Close popover on lost focus if logic requires
    // The WPopover's default tap outside behavior usually handles this
  }

  void _onTextChanged() {
    final newQuery = _controller.text;
    if (newQuery != _query) {
      setState(() {
        _query = newQuery;
      });

      _debounceTimer?.cancel();
      _debounceTimer = Timer(const Duration(milliseconds: 300), () {
        _performSearch(newQuery);
      });
    }
  }

  Future<void> _performSearch(String query) async {
    if (query.isEmpty) {
      if (mounted) {
        setState(() {
          _searchResults = [];
        });
        _popoverController.hide();
      }
      return;
    }

    try {
      final results = <SearchResult>[];

      // Monitors
      final monitors = await Monitor.all();
      if (monitors.isNotEmpty) {
        final filtered = monitors
            .where(
              (m) =>
                  (m.name?.toLowerCase().contains(query.toLowerCase()) ??
                      false) ||
                  (m.url?.toLowerCase().contains(query.toLowerCase()) ?? false),
            )
            .take(3);

        results.addAll(
          filtered.map(
            (m) => SearchResult(
              id: m.id ?? '',
              title: m.name ?? 'Monitor',
              subtitle: '${m.method?.label ?? 'GET'} ${m.url ?? ''}',
              type: SearchResultType.monitor,
              path: '/monitors/${m.id ?? ''}',
              icon: Icons.dns_outlined,
            ),
          ),
        );
      }

      // Incidents
      final incidents = await Incident.all();
      if (incidents.isNotEmpty) {
        final filtered = incidents
            .where(
              (i) =>
                  (i.title?.toLowerCase() ?? '').contains(query.toLowerCase()),
            )
            .take(3);

        results.addAll(
          filtered.map(
            (i) => SearchResult(
              id: i.id ?? '',
              title: i.title ?? 'Incident',
              subtitle: i.impact?.label ?? 'Incident',
              type: SearchResultType.incident,
              path: '/incidents/${i.id ?? ''}',
              icon: Icons.warning_amber_rounded,
            ),
          ),
        );
      }

      if (mounted) {
        setState(() {
          _searchResults = results;
        });

        if (results.isNotEmpty && !_popoverController.isOpen) {
          _popoverController.show();
        } else if (results.isEmpty && _popoverController.isOpen) {
          _popoverController.hide();
        }
      }
    } catch (e) {
      Log.error('Search error', e);
    }
  }

  void _selectResult(SearchResult result) {
    _controller.clear();
    _popoverController.hide();
    widget.onResultTap?.call(result);
    MagicRoute.to(result.path);
  }

  @override
  Widget build(BuildContext context) {
    // -----------------------------------------------------------
    // REFACTOR: Use WPopover directly instead of manual OverlayPortal
    // -----------------------------------------------------------
    return WPopover(
      controller: _popoverController,
      // Disable default trigger tap so TextField handles focus/typing
      enableTriggerOnTap: false,
      alignment: PopoverAlignment.bottomLeft,
      offset: const Offset(0, 4),
      // Styling for the popover container
      className: '''
        bg-white dark:bg-gray-800 
        border border-gray-200 dark:border-gray-700 
        rounded-xl shadow-xl
      ''',
      // Trigger: Search Input
      triggerBuilder: (context, isOpen, isHovering) =>
          _buildInput(context, isOpen),
      // Content: Filtered Results
      contentBuilder: (context, close) => ConstrainedBox(
        // Manually constrain width similar to previous implementation
        constraints: const BoxConstraints(
          minWidth: 256,
          maxWidth: 384, // 256 * 1.5
        ),
        child: _buildContent(),
      ),
    );
  }

  Widget _buildInput(BuildContext context, bool isOpen) {
    return WDiv(
      states: isOpen ? {'focused'} : {},
      className: '''
        w-64 focused:w-80 h-10 px-3 
        bg-gray-100 dark:bg-gray-800 
        focused:bg-white dark:focused:bg-gray-700
        focused:ring-2 focused:ring-primary/30
        rounded-lg flex items-center gap-2
        duration-300
      ''',
      children: [
        WIcon(Icons.search, className: 'text-xl text-gray-400'),
        WInput(
          controller: _controller,
          focusNode: _focusNode,
          placeholder: trans('search.placeholder'),
          className: 'flex-1 border-0 pb-1.5',
        ),
        // Clear button
        if (_query.isNotEmpty)
          WAnchor(
            onTap: () {
              _controller.clear();
              _popoverController.hide();
            },
            child: WIcon(
              Icons.close,
              className: 'text-lg text-gray-400 hover:text-gray-600',
            ),
          ),
      ],
    );
  }

  Widget _buildContent() {
    if (_searchResults.isEmpty) {
      return WDiv(
        className:
            'w-full py-8 flex flex-col items-center justify-center gap-2',
        children: [
          WIcon(
            Icons.search_off,
            className: 'text-3xl text-gray-300 dark:text-gray-600',
          ),
          WText(
            trans('search.no_results'),
            className: 'text-sm text-gray-500 dark:text-gray-400',
          ),
        ],
      );
    }

    // Group results by type
    final monitors = _searchResults
        .where((r) => r.type == SearchResultType.monitor)
        .toList();
    final incidents = _searchResults
        .where((r) => r.type == SearchResultType.incident)
        .toList();
    final others = _searchResults
        .where(
          (r) =>
              r.type != SearchResultType.monitor &&
              r.type != SearchResultType.incident,
        )
        .toList();

    return WDiv(
      className: 'overflow-y-auto flex flex-col items-stretch',
      children: [
        if (monitors.isNotEmpty) ...[
          _buildSectionHeader(trans('search.monitors')),
          ...monitors.map(_buildResultItem),
        ],
        if (incidents.isNotEmpty) ...[
          _buildSectionHeader(trans('search.incidents')),
          ...incidents.map(_buildResultItem),
        ],
        if (others.isNotEmpty) ...[
          _buildSectionHeader(trans('search.other')),
          ...others.map(_buildResultItem),
        ],
      ],
    );
  }

  Widget _buildSectionHeader(String title) {
    return WDiv(
      className: 'px-4 py-2 bg-gray-50 dark:bg-gray-700/50',
      child: WText(
        title.toUpperCase(),
        className:
            'text-xs font-semibold text-gray-500 dark:text-gray-400 tracking-wider',
      ),
    );
  }

  Widget _buildResultItem(SearchResult result) {
    final String iconColor = switch (result.type) {
      SearchResultType.monitor => 'text-blue-500',
      SearchResultType.incident => 'text-orange-500',
      SearchResultType.log => 'text-purple-500',
      SearchResultType.statusPage => 'text-green-500',
      SearchResultType.setting => 'text-gray-500',
    };

    return WAnchor(
      onTap: () => _selectResult(result),
      child: WDiv(
        className: '''
          px-4 py-3
          hover:bg-gray-50 dark:hover:bg-gray-700
          flex items-center gap-3
        ''',
        children: [
          WDiv(
            className: '''
              w-8 h-8 rounded-lg 
              bg-gray-100 dark:bg-gray-700
              flex items-center justify-center
            ''',
            child: WIcon(result.icon, className: 'text-lg $iconColor'),
          ),
          WDiv(
            className: 'flex-1 flex flex-col min-w-0',
            children: [
              WText(
                result.title,
                className:
                    'text-sm font-medium text-gray-900 dark:text-white truncate',
              ),
              WText(
                result.subtitle,
                className: 'text-xs text-gray-500 dark:text-gray-400',
              ),
            ],
          ),
          WIcon(
            Icons.arrow_forward_ios,
            className: 'text-sm text-gray-300 dark:text-gray-600',
          ),
        ],
      ),
    );
  }
}
