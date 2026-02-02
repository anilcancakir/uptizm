---
paths:
  - "lib/resources/views/**/*.dart"
---

# View Rules

> Cross-cutting rules (Wind-only widgets, API names, imports) are in CLAUDE.md. This file covers view-specific patterns only.

## Skills: ALWAYS activate `wind-ui` + `flutter-design` + `mobile-app-design-mastery`

## Wind Widget Catalog

**Base widgets:** `WDiv`, `WText`, `WInput`, `WButton`, `WIcon`, `WImage`, `WSvg`, `WPopover`, `WAnchor`, `WSelect`, `WCheckbox`

**Form widgets (use for ALL validated fields):**
- `WFormInput` — text input with label, hint, error, validation
- `WFormSelect<T>` — single select with searchable, async
- `WFormMultiSelect<T>` — multi-select with tag creation
- `WFormCheckbox` — checkbox with validation

Use base widgets (WInput, WSelect) only for non-validated contexts (search bars, filters).

## className Prefixes

| Prefix | Purpose |
|--------|---------|
| `dark:` | Dark mode variant |
| `hover:` / `focus:` | Interaction states |
| `disabled:` | Disabled state |
| `error:` | Auto-activates on validation failure |
| `checked:` | Checkbox/toggle checked state |
| `sm:` `md:` `lg:` `xl:` `2xl:` | Responsive breakpoints (640/768/1024/1280/1536px) |

## WFormInput

```dart
WFormInput(
  label: 'Email',
  hint: 'Help text below',
  controller: form['email'],
  type: InputType.email,
  validator: rules([Required(), Email()], field: 'email'),
  autovalidateMode: AutovalidateMode.onUserInteraction,
  labelClassName: 'text-sm font-medium text-gray-700 dark:text-gray-300',
  className: 'w-full px-3 py-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-white focus:border-primary focus:ring-2 focus:ring-primary/20 error:border-red-500',
  errorClassName: 'text-red-500 text-xs mt-1',
  prefix: WIcon(Icons.email_outlined),
)
```

## WFormSelect (with async search)

```dart
WFormSelect<String>(
  label: 'Country',
  value: selected,
  options: countryOptions,
  onChange: (v) => setState(() => selected = v),
  validator: (v) => v == null ? 'Required' : null,
  searchable: true,
  onSearch: (query) async => await fetchCountries(query),
  className: 'w-full px-3 py-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 error:border-red-500',
  menuClassName: 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-xl',
)
```

## WFormMultiSelect (tag input)

```dart
WFormMultiSelect<String>(
  label: 'Tags',
  values: _tags,
  options: _tagOptions,
  onMultiChange: (tags) => setState(() => _tags = tags),
  searchable: true,
  onCreateOption: (query) async {
    final opt = SelectOption(value: query, label: query);
    setState(() => _tagOptions.add(opt)); // MUST persist in state
    return opt;
  },
  className: 'border rounded-lg error:border-red-500',
)
```

## Form Pattern

```dart
final form = MagicFormData({'email': '', 'role': 'member'});

MagicForm(
  formData: form,
  child: WDiv(
    className: 'flex flex-col gap-4 p-6',
    children: [
      WFormInput(
        controller: form['email'],
        validator: rules([Required(), Email()], field: 'email'),
        // ... className as above
      ),
      WFormSelect<String>(
        value: 'member',
        options: TeamRole.selectOptions,
        onChange: (v) => form.set('role', v),
      ),
      WButton(
        onTap: () { if (form.validate()) submitForm(form); },
        isLoading: controller.isLoading,
        className: 'w-full px-4 py-3 rounded-lg bg-primary hover:bg-green-600 text-white font-medium disabled:opacity-50',
        child: WText('Submit'),
      ),
    ],
  ),
)
```

## Styling Recipes

### Card
```dart
className: 'bg-white dark:bg-gray-800 rounded-2xl shadow-soft border border-gray-100 dark:border-gray-700 p-6'
```

### Input Field
```dart
className: 'w-full px-3 py-3 rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-900 dark:text-white text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 error:border-red-500 disabled:opacity-50'
```

### Primary Button
```dart
className: 'px-4 py-2 rounded-lg bg-primary hover:bg-green-600 text-white font-medium text-sm disabled:opacity-50'
```

### Secondary Button
```dart
className: 'px-4 py-2 rounded-lg bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-600 font-medium text-sm'
```

### Label
```dart
className: 'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400'
```

## Dark Mode (REQUIRED on every element)

| Element | Light | Dark |
|---------|-------|------|
| Background | `bg-white` | `dark:bg-gray-800` |
| Text | `text-gray-900` | `dark:text-white` |
| Muted text | `text-gray-600` | `dark:text-gray-400` |
| Borders | `border-gray-200` | `dark:border-gray-700` |

Toggle: `context.windTheme.toggleTheme()` | Check: `context.windTheme.isDark`

## Responsive Layout

```dart
WDiv(
  className: 'flex flex-col md:flex-row gap-4 md:gap-6 p-4 md:p-6 lg:p-8',
  children: [...],
)
```

## Icons

Always use outlined variant: `Icons.person_outline` (not `Icons.person`).

## Common View Patterns

### Empty State
```dart
WDiv(
  className: 'flex flex-col items-center justify-center py-12',
  children: [
    WIcon(Icons.inbox_outlined, className: 'text-6xl text-gray-400'),
    WText(trans('common.no_items'), className: 'text-gray-600 dark:text-gray-400 mt-4'),
  ],
)
```

### Loading Button
```dart
WButton(onTap: () => controller.submit(), isLoading: controller.isLoading, ...)
```

### Authorization
```dart
MagicCan(ability: 'update', arguments: team, child: EditButton())
// or: if (Gate.allows('update', team)) { ... }
```

### Dialog
```dart
Magic.dialog(
  WDiv(
    className: 'bg-white dark:bg-gray-800 rounded-2xl p-6 w-96',
    children: [
      WText('Title', className: 'text-lg font-semibold'),
      // content...
      WDiv(className: 'flex justify-end gap-2 mt-4', children: [
        WButton(onTap: () => Magic.closeDialog(), child: WText('Cancel')),
        WButton(onTap: () => handleConfirm(), child: WText('Confirm')),
      ]),
    ],
  ),
);
```

### Scrollable Content
```dart
// Wind overflow-auto does NOT work for scroll. Use Flutter scroll widgets:
WDiv(
  className: 'p-4 rounded-lg bg-gray-900 max-h-[300px]',
  child: SingleChildScrollView(
    child: WText(content, className: 'font-mono text-xs text-white'),
  ),
)
```

### ValueListenableBuilder
```dart
ValueListenableBuilder<List<Team>>(
  valueListenable: controller.teamsNotifier,
  builder: (context, teams, _) => WDiv(
    children: teams.map((t) => TeamCard(t)).toList(),
  ),
)
```

## Checklist

- [ ] All forms use WForm widgets
- [ ] Dark mode on all backgrounds, text, borders
- [ ] Responsive classes for mobile/tablet/desktop
- [ ] Outlined Material icons only
- [ ] No hardcoded colors
- [ ] Spacing on 4px grid
- [ ] Loading states for async ops
- [ ] Empty states for lists
- [ ] Auth checks with MagicCan/Gate
- [ ] `trans()` for all user-facing text
