# VIEWS KNOWLEDGE BASE

## OVERVIEW

Wind UI views with dark mode, responsive layouts, and MagicForm validation. All views return from controller actions.

## STRUCTURE

```
views/
├── layouts/
│   ├── app_layout.dart              # Sidebar + topbar (authenticated)
│   └── guest_layout.dart            # Centered card (auth pages)
├── monitors/                        # 7 views — largest module
│   ├── monitors_index_view.dart     # (479 lines) Filter/search/paginate
│   ├── monitor_create_view.dart     # MagicForm + sections
│   ├── monitor_edit_view.dart       # (375 lines) Pre-filled form
│   ├── monitor_show_view.dart       # (891 lines) COMPLEXITY HOTSPOT
│   ├── monitor_analytics_view.dart  # (402 lines) Charts + date range
│   ├── monitor_alerts_view.dart     # Alert rules per monitor
│   └── monitor_alerts_tab.dart      # Embedded alerts tab
├── alerts/
│   ├── alert_rules_index_view.dart  # Team-level rules list
│   ├── alert_rule_create_view.dart  # Wraps AlertRuleForm
│   ├── alert_rule_edit_view.dart    # Wraps AlertRuleForm (edit mode)
│   └── alerts_index_view.dart       # Active/resolved alerts history
├── auth/                            # 4 views, GuestLayout
├── dashboard/                       # dashboard_view.dart (stat cards + monitor list)
├── settings/                        # profile (543 lines), notification preferences
├── teams/                           # create, settings, members (500 lines)
├── notifications/                   # notifications_list_view.dart
└── components/                      # 15+ shared, 6 subdirs
    ├── monitors/                    # 12 section/badge components
    ├── navigation/                  # sidebar, header, team selector, nav items
    ├── alerts/                      # alert_rule_form (411 lines), severity badge, list items
    ├── charts/                      # response_time_chart, sparkline, timeline, multi_line
    ├── analytics/                   # date_range_selector, metric_selector, data_table
    ├── dashboard/                   # stat_card, activity_item, monitor_list_item
    ├── settings/                    # page_header, settings_card, buttons, form_input
    ├── auth/                        # auth_form_card, social_login_buttons
    ├── app_card.dart                # Reusable expandable card
    ├── app_list.dart                # Generic paginated list
    ├── app_page_header.dart         # Page title + actions
    ├── notification_dropdown.dart   # (464 lines) Popover + stream
    ├── search_autocomplete.dart     # Global search
    ├── photo_picker.dart            # Image upload
    └── pagination_controls.dart     # Page navigation
```

## WHERE TO LOOK

| Task | Location |
|------|----------|
| Add page view | Create in feature dir, add route in `lib/routes/app.dart` |
| Add reusable component | `components/` root or feature subdir |
| Add form section | `components/monitors/` (see monitor_basic_info_section pattern) |
| Add chart | `components/charts/` (uses fl_chart) |
| Modify layout | `layouts/app_layout.dart` (sidebar nav list in `navigation/navigation_list.dart`) |
| Add page header | Use `AppPageHeader` component (see `app_page_header_examples.md`) |

## CONVENTIONS

- **View base classes**: `MagicView` (stateless) or `MagicStatefulView<ControllerType>` (stateful)
- **Controller access**: `controller` property in `MagicStatefulViewState`
- **Init data**: `onInit()` with `WidgetsBinding.instance.addPostFrameCallback()` for API calls
- **State rendering**: `ValueListenableBuilder` for lists, `controller.renderState()` for single resource
- **Form pattern**: `MagicForm(formData: form, child: ...)` with `WFormInput`/`WFormSelect`
- **Scroll containers**: `WDiv(className: 'overflow-y-auto ...', scrollPrimary: true)` — iOS tap-to-top
- **Responsive**: `grid-cols-1 md:grid-cols-2 lg:grid-cols-4`, `hidden md:flex`
- **Empty states**: Icon + message + CTA button
- **Dark mode**: EVERY bg/text/border needs `dark:` variant

## STYLING RECIPES

```
Card:    bg-white dark:bg-gray-800 rounded-2xl shadow-soft border border-gray-100 dark:border-gray-700 p-6
Input:   w-full px-3 py-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 focus:border-primary error:border-red-500
Button:  px-4 py-2 rounded-lg bg-primary hover:bg-green-600 text-white font-medium disabled:opacity-50
Label:   text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400
Section: flex flex-col gap-4
Page:    overflow-y-auto flex flex-col gap-6 p-4 lg:p-6
```

## ANTI-PATTERNS

- `Container`/`Text`/`Row`/`Column` — use Wind equivalents
- Missing `dark:` variant on any visual property
- Missing `scrollPrimary: true` on scrollable containers
- Building custom before checking Wind widgets (`searchable`, `onCreateOption` exist)
- Hardcoded colors — use `bg-primary`, `text-primary`, theme tokens
- Icons without `_outline` suffix
