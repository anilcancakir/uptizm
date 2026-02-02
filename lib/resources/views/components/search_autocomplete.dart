import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

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

  // Mock search results
  static const List<SearchResult> _allResults = [
    // Monitors
    SearchResult(
      id: 'm1',
      title: 'api.example.com',
      subtitle: 'HTTP Monitor • Up',
      type: SearchResultType.monitor,
      path: '/monitors/1',
      icon: Icons.dns,
    ),
    SearchResult(
      id: 'm2',
      title: 'web.example.com',
      subtitle: 'HTTP Monitor • Up',
      type: SearchResultType.monitor,
      path: '/monitors/2',
      icon: Icons.dns,
    ),
    SearchResult(
      id: 'm3',
      title: 'db.example.com',
      subtitle: 'TCP Monitor • Down',
      type: SearchResultType.monitor,
      path: '/monitors/3',
      icon: Icons.dns,
    ),
    // Incidents
    SearchResult(
      id: 'i1',
      title: 'API Outage - 2h ago',
      subtitle: 'Incident • Resolved',
      type: SearchResultType.incident,
      path: '/incidents/1',
      icon: Icons.warning,
    ),
    SearchResult(
      id: 'i2',
      title: 'Database Connection Issues',
      subtitle: 'Incident • Investigating',
      type: SearchResultType.incident,
      path: '/incidents/2',
      icon: Icons.warning,
    ),
    // Logs
    SearchResult(
      id: 'l1',
      title: 'Activity Log',
      subtitle: 'View all activity',
      type: SearchResultType.log,
      path: '/logs',
      icon: Icons.history,
    ),
    // Status Pages
    SearchResult(
      id: 's1',
      title: 'Public Status Page',
      subtitle: 'Status Page • Active',
      type: SearchResultType.statusPage,
      path: '/status-pages/1',
      icon: Icons.public,
    ),
    // Settings
    SearchResult(
      id: 'set1',
      title: 'Team Settings',
      subtitle: 'Manage team',
      type: SearchResultType.setting,
      path: '/settings/team',
      icon: Icons.settings,
    ),
    SearchResult(
      id: 'set2',
      title: 'Notifications Settings',
      subtitle: 'Configure alerts',
      type: SearchResultType.setting,
      path: '/settings/notifications',
      icon: Icons.notifications,
    ),
  ];

  List<SearchResult> get _filteredResults {
    if (_query.isEmpty) return [];
    final q = _query.toLowerCase();
    return _allResults
        .where(
          (r) =>
              r.title.toLowerCase().contains(q) ||
              r.subtitle.toLowerCase().contains(q),
        )
        .take(6)
        .toList();
  }

  @override
  void initState() {
    super.initState();
    _controller.addListener(_onTextChanged);
    _focusNode.addListener(_onFocusChange);
  }

  @override
  void dispose() {
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
        if (_query.isNotEmpty && !_popoverController.isOpen) {
          _popoverController.show();
        } else if (_query.isEmpty && _popoverController.isOpen) {
          _popoverController.hide();
        }
      });
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
        Expanded(
          child: WInput(
            controller: _controller,
            focusNode: _focusNode,
            placeholder: trans('search.placeholder'),
            className: 'border-0 pb-1.5',
          ),
        ),
        // Clear button
        if (_query.isNotEmpty)
          GestureDetector(
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
    if (_filteredResults.isEmpty) {
      return WDiv(
        className: 'py-8 flex flex-col items-center justify-center gap-2',
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
    final monitors = _filteredResults
        .where((r) => r.type == SearchResultType.monitor)
        .toList();
    final incidents = _filteredResults
        .where((r) => r.type == SearchResultType.incident)
        .toList();
    final others = _filteredResults
        .where(
          (r) =>
              r.type != SearchResultType.monitor &&
              r.type != SearchResultType.incident,
        )
        .toList();

    return SingleChildScrollView(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
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
      ),
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
          Expanded(
            child: WDiv(
              className: 'flex flex-col min-w-0',
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
