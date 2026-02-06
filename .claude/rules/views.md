---
paths:
  - "lib/resources/views/**/*.dart"
---

# View Rules

> Skills: `wind-ui` + `flutter-design` + `mobile-app-design-mastery` | Cross-cutting: see CLAUDE.md

## Widgets

**Base:** `WDiv`, `WText`, `WButton`, `WIcon`, `WImage`, `WSvg`, `WPopover`, `WAnchor`, `WSelect`, `WCheckbox`, `WSpacer`

**Form (validated):** `WFormInput`, `WFormSelect<T>`, `WFormMultiSelect<T>`, `WFormCheckbox`, `WFormDatePicker`

Use base widgets only for non-validated contexts (search, filters).

## className Prefixes

| Prefix | Purpose |
|--------|---------|
| `dark:` | Dark mode |
| `hover:`/`focus:` | Interaction |
| `disabled:` | Disabled state |
| `error:` | Validation failure |
| `sm:`/`md:`/`lg:`/`xl:`/`2xl:` | Breakpoints (640/768/1024/1280/1536) |

## Form Widgets

```dart
// Input
WFormInput(
  controller: form['email'],
  label: 'Email',
  validator: rules([Required(), Email()], field: 'email'),
  className: 'w-full px-3 py-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 focus:border-primary error:border-red-500',
  labelClassName: 'text-sm font-medium text-gray-700 dark:text-gray-300',
  errorClassName: 'text-red-500 text-xs mt-1',
)

// Select with search
WFormSelect<String>(
  value: selected,
  options: options,
  onChange: (v) => setState(() => selected = v),
  searchable: true,
  onSearch: (query) async => await fetchOptions(query),
  menuClassName: 'bg-white dark:bg-gray-800 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700',
)

// Multi-select with tag creation
WFormMultiSelect<String>(
  values: _tags,
  options: _tagOptions,
  onMultiChange: (tags) => setState(() => _tags = tags),
  onCreateOption: (query) async {
    final opt = SelectOption(value: query, label: query);
    setState(() => _tagOptions.add(opt));  // MUST persist
    return opt;
  },
)
```

## Styling Recipes

```dart
// Card
'bg-white dark:bg-gray-800 rounded-2xl shadow-soft border border-gray-100 dark:border-gray-700 p-6'

// Input
'w-full px-3 py-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 focus:border-primary focus:ring-2 focus:ring-primary/20 error:border-red-500'

// Primary button
'px-4 py-2 rounded-lg bg-primary hover:bg-green-600 text-white font-medium disabled:opacity-50'

// Secondary button
'px-4 py-2 rounded-lg bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-600'

// Label
'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400'
```

## Dark Mode (REQUIRED)

| Element | Light | Dark |
|---------|-------|------|
| Background | `bg-white` | `dark:bg-gray-800` |
| Text | `text-gray-900` | `dark:text-white` |
| Muted | `text-gray-600` | `dark:text-gray-400` |
| Border | `border-gray-200` | `dark:border-gray-700` |

Toggle: `context.windTheme.toggleTheme()` | Check: `context.windTheme.isDark`

## Common Patterns

```dart
// Empty state
WDiv(
  className: 'flex flex-col items-center justify-center py-12',
  children: [
    WIcon(Icons.inbox_outlined, className: 'text-6xl text-gray-400'),
    WText(trans('common.no_items'), className: 'text-gray-600 dark:text-gray-400 mt-4'),
  ],
)

// Main page scroll (iOS tap-to-top)
WDiv(
  className: 'overflow-y-auto flex flex-col gap-6 p-4 lg:p-6',
  scrollPrimary: true,
  children: [...],
)

// Dialog
Magic.dialog(WDiv(
  className: 'bg-white dark:bg-gray-800 rounded-2xl p-6 w-96',
  children: [
    WText('Title', className: 'text-lg font-semibold'),
    WDiv(className: 'flex justify-end gap-2 mt-4', children: [
      WButton(onTap: () => Magic.closeDialog(), child: WText('Cancel')),
      WButton(onTap: handleConfirm, child: WText('Confirm')),
    ]),
  ],
));

// Auth check
MagicCan(ability: 'update', arguments: team, child: EditButton())

// List builder
ValueListenableBuilder<List<Team>>(
  valueListenable: controller.teamsNotifier,
  builder: (context, teams, _) => WDiv(children: teams.map((t) => TeamCard(t)).toList()),
)
```

## Icons

Always outlined: `Icons.person_outline` (NOT `Icons.person`)

## Checklist

- [ ] WForm widgets for all validated fields
- [ ] Dark mode on all bg/text/border
- [ ] Responsive classes
- [ ] Outlined icons only
- [ ] 4px spacing grid
- [ ] Loading/empty states
- [ ] `trans()` for text
