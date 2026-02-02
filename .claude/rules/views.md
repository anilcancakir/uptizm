---
globs: ["lib/resources/views/**/*.dart"]
---

# View Rules

## Skills to Activate

When working on views, **ALWAYS activate these skills:**
- `wind-ui` - Wind widgets, className composition
- `flutter-design` - Theme colors, typography, spacing
- `mobile-app-design-mastery` - Mobile UX patterns, touch targets

## Wind UI Widgets

**CRITICAL RULE: ALWAYS use Wind UI widgets, NEVER use Flutter widgets directly**

**Base Widgets:**
- `WDiv` - Container/layout (replaces Container, Row, Column in most cases)
- `WText` - Text display with className styling, supports `selectable: true` (replaces SelectableText)
- `WInput` - Text input with `type: InputType.multiline` for textarea (replaces TextField)
- `WButton` - Interactive buttons with loading states (replaces ElevatedButton, TextButton, IconButton)
- `WIcon` - Material Symbols Outlined icons (use with WButton for icon buttons)
- `WImage` - Images with asset:// support
- `WSvg` - SVG rendering
- `WPopover` - Dropdown menus, tooltips
- `WAnchor` - State wrapper for hover/focus

**Widget Replacements:**
- ❌ `TextField` → ✅ `WInput` (use `type: InputType.multiline` for multiline)
- ❌ `SelectableText` → ✅ `WText` (use `selectable: true`)
- ❌ `IconButton` → ✅ `WButton` with `WIcon` child
- ❌ `ElevatedButton` → ✅ `WButton`
- ❌ `Container` → ✅ `WDiv`
- ❌ `Text` → ✅ `WText`

**Form Widgets (Use These for ALL Forms):**
- `WFormInput` - Text input with validation, label, hint, error display
- `WFormSelect<T>` - Single select with validation, searchable, async
- `WFormMultiSelect<T>` - Multi-select with validation
- `WFormCheckbox` - Checkbox with validation

**When to Use WForm vs Base Widgets:**
- ✅ Use `WFormInput` when: field needs validation, has label, shows errors
- ✅ Use `WInput` when: simple input without validation (search bars, filters)
- ✅ Use `WFormSelect` when: dropdown needs validation or is in a form
- ✅ Use `WSelect` when: simple dropdown without validation

## Form Patterns

### Complete Form Example

```dart
class MyFormView extends MagicStatefulView<MyController> {
  @override
  Widget build(BuildContext context) {
    final form = MagicFormData({
      'name': '',
      'email': '',
      'role': 'member',
      'tags': <String>[],
      'agreed': false,
    });

    return MagicForm(
      formData: form,
      child: WDiv(
        className: 'flex flex-col gap-4 p-6',
        children: [
          // Text Input
          WFormInput(
            label: trans('attributes.name'),
            hint: trans('attributes.name_hint'),
            controller: form['name'],
            validator: rules([Required(), Min(3)], field: 'name'),
            autovalidateMode: AutovalidateMode.onUserInteraction,
            labelClassName: 'text-sm font-medium text-gray-700 dark:text-gray-300',
            className: '''
              w-full px-3 py-3 rounded-lg
              bg-white dark:bg-gray-800
              border border-gray-200 dark:border-gray-700
              text-gray-900 dark:text-white
              focus:border-primary focus:ring-2 focus:ring-primary/20
              error:border-red-500
            ''',
            prefix: WIcon(Icons.person_outline),
          ),

          // Email Input
          WFormInput(
            label: trans('attributes.email'),
            controller: form['email'],
            type: InputType.email,
            validator: rules([Required(), Email()], field: 'email'),
            labelClassName: 'text-sm font-medium text-gray-700 dark:text-gray-300',
            className: '''
              w-full px-3 py-3 rounded-lg
              bg-white dark:bg-gray-800
              border border-gray-200 dark:border-gray-700
              focus:border-primary focus:ring-2 focus:ring-primary/20
              error:border-red-500
            ''',
          ),

          // Single Select
          WFormSelect<String>(
            label: trans('attributes.role'),
            value: 'member',
            options: [
              SelectOption(value: 'admin', label: 'Admin'),
              SelectOption(value: 'member', label: 'Member'),
            ],
            onChange: (v) => form.set('role', v),
            validator: (v) => v == null ? 'Required' : null,
            labelClassName: 'text-sm font-medium text-gray-700 dark:text-gray-300',
            className: '''
              w-full px-3 py-3 rounded-lg
              bg-white dark:bg-gray-800
              border border-gray-200 dark:border-gray-700
              error:border-red-500
            ''',
            menuClassName: '''
              bg-white dark:bg-gray-800
              border border-gray-200 dark:border-gray-700
              rounded-xl shadow-xl
            ''',
          ),

          // Multi Select
          WFormMultiSelect<String>(
            label: trans('attributes.tags'),
            values: [],
            options: tagOptions,
            onMultiChange: (values) => form.set('tags', values),
            searchable: true,
            className: 'border rounded-lg error:border-red-500',
          ),

          // Checkbox
          WFormCheckbox(
            value: false,
            onChanged: (v) => form.set('agreed', v),
            labelText: trans('forms.agree_to_terms'),
            validator: (v) => v != true ? 'Must accept' : null,
            className: 'w-5 h-5 rounded border checked:bg-primary',
          ),

          // Submit Button
          WButton(
            onTap: () async {
              if (form.validate()) {
                await controller.submit(
                  name: form.get('name'),
                  email: form.get('email'),
                  role: form.get('role'),
                  tags: form.get('tags'),
                );
              }
            },
            isLoading: controller.isLoading,
            className: '''
              w-full px-4 py-3 rounded-lg
              bg-primary hover:bg-green-600
              text-white font-medium
              disabled:opacity-50
            ''',
            child: WText(trans('common.submit')),
          ),
        ],
      ),
    );
  }
}
```

### Searchable Select (Async)

```dart
WFormSelect<String>(
  label: 'Country',
  value: selectedCountry,
  options: countryOptions,
  onChange: (v) => setState(() => selectedCountry = v),
  searchable: true,
  onSearch: (query) async {
    final response = await Http.get('/countries?search=$query');
    return response.data.map((c) =>
      SelectOption(value: c['code'], label: c['name'])
    ).toList();
  },
  className: 'border rounded-lg',
  menuClassName: 'bg-white dark:bg-gray-800 rounded-xl shadow-xl',
)
```

## Dark Mode (REQUIRED)

**Always include dark mode variants for:**
- Backgrounds: `bg-white dark:bg-gray-800`
- Text: `text-gray-900 dark:text-white`
- Borders: `border-gray-200 dark:border-gray-700`
- Shadows: Use opacity-based shadows that work in both modes
- Muted text: `text-gray-600 dark:text-gray-400`

**Testing Dark Mode:**
```dart
// Toggle in app
context.windTheme.toggleTheme()

// Check current mode
context.windTheme.isDark
```

## Styling Guidelines

### Cards

```dart
WDiv(
  className: '''
    bg-white dark:bg-gray-800
    rounded-2xl
    shadow-soft
    border border-gray-100 dark:border-gray-700
    p-6
  ''',
  child: // content
)
```

### Input Fields

```dart
className: '''
  w-full px-3 py-3 rounded-xl
  bg-white dark:bg-gray-800
  border border-gray-200 dark:border-gray-700
  text-gray-900 dark:text-white text-sm
  focus:border-primary focus:ring-2 focus:ring-primary/20
  error:border-red-500
  disabled:opacity-50 disabled:cursor-not-allowed
'''
```

### Buttons (Primary)

```dart
className: '''
  px-4 py-2 rounded-lg
  bg-primary hover:bg-green-600
  text-white font-medium text-sm
  disabled:opacity-50
  transition-colors duration-150
'''
```

### Buttons (Secondary)

```dart
className: '''
  px-4 py-2 rounded-lg
  bg-gray-200 dark:bg-gray-700
  text-gray-700 dark:text-gray-200
  hover:bg-gray-300 dark:hover:bg-gray-600
  font-medium text-sm
'''
```

### Labels

```dart
className: 'text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-400'
```

## Responsive Design

```dart
// Mobile-first approach
WDiv(
  className: '''
    flex flex-col md:flex-row
    gap-4 md:gap-6
    p-4 md:p-6 lg:p-8
    w-full md:w-auto
  ''',
)
```

**Breakpoints:**
- `sm:` - 640px (large phones)
- `md:` - 768px (tablets)
- `lg:` - 1024px (laptops)
- `xl:` - 1280px (desktops)
- `2xl:` - 1536px (large desktops)

## Icons

```dart
// Always use Material Symbols Outlined
WIcon(
  Icons.person_outline,  // ✅ Correct (outlined variant)
  className: 'text-gray-600 dark:text-gray-400',
)

WIcon(
  Icons.person,  // ❌ Wrong (filled variant)
)
```

## Working with Models in Views

### Displaying Model Data

```dart
class UserProfileView extends MagicView<UserController> {
  @override
  Widget build(BuildContext context) {
    final user = User.current; // Get authenticated user

    return WDiv(
      className: 'p-6',
      children: [
        // Type-safe accessors
        WText(user.name ?? 'Unknown', className: 'text-xl font-bold'),
        WText(user.email ?? '', className: 'text-gray-600 dark:text-gray-400'),

        // Computed properties
        if (user.timezone != null)
          WText('Timezone: ${user.timezone}', className: 'text-sm'),

        // Timestamps (Carbon)
        if (user.createdAt != null)
          WText(
            'Joined: ${user.createdAt!.format('MMM d, yyyy')}',
            className: 'text-xs text-gray-500',
          ),
      ],
    );
  }
}
```

### Editing Models

```dart
class EditUserView extends MagicStatefulView<UserController> {
  @override
  Widget build(BuildContext context) {
    final user = User.current;
    final nameController = TextEditingController(text: user.name);
    final emailController = TextEditingController(text: user.email);

    return WDiv(
      className: 'p-6',
      children: [
        WFormInput(
          label: 'Name',
          controller: nameController,
          className: 'border rounded-lg p-3',
        ),
        WFormInput(
          label: 'Email',
          type: InputType.email,
          controller: emailController,
          className: 'border rounded-lg p-3',
        ),
        WButton(
          onTap: () async {
            user.name = nameController.text;
            user.email = emailController.text;

            final success = await user.save();
            if (success) {
              Magic.toast('Profile updated successfully');
            }
          },
          isLoading: controller.isLoading,
          className: 'bg-primary text-white px-4 py-2 rounded-lg',
          child: WText('Save Changes'),
        ),
      ],
    );
  }
}
```

### Relationships in Views

```dart
class TeamDashboardView extends MagicView<TeamController> {
  @override
  Widget build(BuildContext context) {
    final user = User.current;
    final currentTeam = user.currentTeam;

    if (currentTeam == null) {
      return WText('No team selected');
    }

    return WDiv(
      children: [
        WText(currentTeam.name ?? 'Unnamed Team', className: 'text-2xl font-bold'),

        // Role-based UI
        if (currentTeam.canManageMembers)
          WButton(
            onTap: () => Route.to('/teams/members'),
            child: WText('Manage Members'),
          ),

        if (currentTeam.isOwner)
          WButton(
            onTap: () => Route.to('/teams/settings'),
            child: WText('Team Settings'),
          ),

        // All teams dropdown
        WSelect<Team>(
          value: currentTeam,
          options: user.allTeams
            .map((team) => SelectOption(value: team, label: team.name ?? ''))
            .toList(),
          onChange: (team) async {
            if (team != null) {
              await controller.switchTeam(team);
            }
          },
        ),
      ],
    );
  }
}
```

### Model Lists with ValueNotifier

```dart
class TeamListView extends MagicStatefulView<TeamController> {
  @override
  void initState() {
    super.initState();
    controller.loadTeams(); // Load teams on init
  }

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<List<Team>>(
      valueListenable: controller.teamsNotifier,
      builder: (context, teams, _) {
        if (teams.isEmpty) {
          return WDiv(
            className: 'flex flex-col items-center justify-center py-12',
            children: [
              WIcon(Icons.groups_outlined, className: 'text-6xl text-gray-400'),
              WText('No teams yet', className: 'text-gray-600 dark:text-gray-400'),
            ],
          );
        }

        return WDiv(
          className: 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4',
          children: teams.map((team) => TeamCard(team)).toList(),
        );
      },
    );
  }
}

class TeamCard extends StatelessWidget {
  final Team team;

  const TeamCard(this.team, {super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: '''
        bg-white dark:bg-gray-800 rounded-2xl p-6
        border border-gray-200 dark:border-gray-700
        hover:shadow-lg transition-shadow cursor-pointer
      ''',
      onTap: () => Route.push('/teams/${team.id}'),
      children: [
        WText(team.name ?? 'Unnamed', className: 'text-lg font-bold'),

        // Role badge
        WDiv(
          className: '''
            inline-block px-2 py-1 rounded-full text-xs
            ${team.isOwner ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'}
          ''',
          child: WText(team.userRole ?? 'member'),
        ),

        // Timestamps
        if (team.createdAt != null)
          WText(
            'Created ${team.createdAt!.diffForHumans()}',
            className: 'text-xs text-gray-500 dark:text-gray-400',
          ),
      ],
    );
  }
}
```

### Creating Models from Forms

```dart
void _showCreateTeamDialog() {
  final form = MagicFormData({'name': ''});

  Magic.dialog(
    MagicForm(
      formData: form,
      child: WDiv(
        className: 'bg-white dark:bg-gray-800 rounded-2xl p-6',
        children: [
          WText('Create Team', className: 'text-lg font-bold mb-4'),

          WFormInput(
            label: 'Team Name',
            controller: form['name'],
            validator: rules([Required(), Min(3)], field: 'name'),
            className: 'border rounded-lg p-3',
          ),

          WButton(
            onTap: () async {
              if (form.validate()) {
                // Create using Eloquent
                final team = Team()..name = form.get('name');
                final success = await team.save();

                if (success) {
                  Magic.toast('Team created successfully');
                  Magic.closeDialog();
                  controller.loadTeams(); // Refresh list
                }
              }
            },
            className: 'bg-primary text-white px-4 py-2 rounded-lg w-full',
            child: WText('Create Team'),
          ),
        ],
      ),
    ),
  );
}
```

## State Management in Views

### With ValueListenableBuilder

```dart
class MyView extends MagicStatefulView<MyController> {
  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<List<Team>>(
      valueListenable: controller.teamsNotifier,
      builder: (context, teams, _) {
        return WDiv(
          children: teams.map((team) => TeamCard(team)).toList(),
        );
      },
    );
  }
}
```

### With MagicStateMixin

```dart
class MyView extends MagicView<MyController> {
  @override
  Widget build(BuildContext context) {
    return controller.renderState(
      (data) => WText('Success: ${data.name}'),
      loading: () => WDiv(child: CircularProgressIndicator()),
      error: (error) => WText('Error: $error'),
    );
  }
}
```

## Authorization in Views

```dart
// Show/hide based on policy
MagicCan(
  ability: 'update',
  arguments: team,
  child: WButton(
    onTap: () => controller.updateTeam(team),
    child: WText('Edit'),
  ),
)

// Or check directly
if (Gate.allows('update', team)) {
  return EditButton();
}
```

## Common Patterns

### Loading States

```dart
WButton(
  onTap: () => controller.submit(),
  isLoading: controller.isLoading,
  className: 'bg-primary text-white px-4 py-2 rounded-lg',
  child: WText('Submit'),
)
```

### Empty States

```dart
if (items.isEmpty) {
  return WDiv(
    className: 'flex flex-col items-center justify-center py-12',
    children: [
      WIcon(Icons.inbox_outlined, className: 'text-6xl text-gray-400'),
      WText(
        trans('common.no_items'),
        className: 'text-gray-600 dark:text-gray-400 mt-4',
      ),
    ],
  );
}
```

### Dialogs

```dart
Magic.dialog(
  WDiv(
    className: 'bg-white dark:bg-gray-800 rounded-2xl p-6 w-96',
    children: [
      WText('Dialog Title', className: 'text-lg font-semibold'),
      // content
      WDiv(
        className: 'flex justify-end gap-2 mt-4',
        children: [
          WButton(
            onTap: () => Magic.closeDialog(),
            child: WText('Cancel'),
          ),
          WButton(
            onTap: () => handleConfirm(),
            child: WText('Confirm'),
          ),
        ],
      ),
    ],
  ),
);
```

### Confirmations

```dart
final confirmed = await Magic.confirm(
  title: trans('common.confirm'),
  message: trans('teams.delete_confirm'),
  confirmText: trans('common.delete'),
  cancelText: trans('common.cancel'),
);

if (confirmed) {
  await controller.delete();
}
```

## Anti-Patterns (DON'T DO)

❌ **Don't use Flutter widgets directly when Wind has equivalent:**
```dart
Container(color: Colors.blue)  // Bad
WDiv(className: 'bg-primary')   // Good
```

❌ **Don't hardcode colors:**
```dart
Color(0xFF009E60)              // Bad
bg-primary                     // Good
```

❌ **Don't build custom form inputs:**
```dart
TextFormField(...)             // Bad (unless WForm doesn't support)
WFormInput(...)                // Good
```

❌ **Don't skip dark mode:**
```dart
className: 'bg-white text-black'           // Bad
className: 'bg-white dark:bg-gray-800 text-gray-900 dark:text-white'  // Good
```

❌ **Don't use arbitrary spacing:**
```dart
padding: EdgeInsets.all(17)    // Bad (off 4px grid)
className: 'p-4'               // Good (16px, on grid)
```

## Checklist Before Committing

- [ ] All forms use WForm widgets (WFormInput, WFormSelect, WFormCheckbox)
- [ ] Dark mode variants on all backgrounds, text, borders
- [ ] Responsive classes for mobile/tablet/desktop
- [ ] Icons are Material Symbols Outlined
- [ ] No hardcoded colors (use bg-primary, text-gray-700, etc.)
- [ ] Spacing on 4px grid (p-4, gap-6, mt-2, etc.)
- [ ] Loading states for async operations
- [ ] Empty states for lists
- [ ] Authorization checks with MagicCan or Gate
- [ ] Translation keys (trans()) for all user-facing text
