# AppPageHeader Widget - Usage Examples

## Overview

`AppPageHeader` is a reusable page header component with responsive layout. It supports:

- Optional leading widget (back button, menu icon, etc.)
- Title and optional subtitle
- Optional trailing actions (buttons)
- Responsive: vertical on mobile, horizontal on desktop
- Full dark mode support

## Import

```dart
import '../../../resources/views/components/app_page_header.dart';
```

---

## Examples

### 1. Basic Usage (Title Only)

Simplest form with just a title:

```dart
AppPageHeader(
  title: trans('dashboard.title'),
)
```

**Use case:** Simple pages without actions or navigation

---

### 2. Title + Subtitle

Add context with a subtitle:

```dart
AppPageHeader(
  title: trans('monitors.title'),
  subtitle: trans('monitors.subtitle'),
)
```

**Use case:** Dashboard, overview pages

---

### 3. Title + Single Action Button

Common pattern for "Add" or "Create" actions:

```dart
AppPageHeader(
  title: trans('monitors.title'),
  subtitle: trans('monitors.subtitle'),
  actions: [
    WButton(
      onTap: () => MagicRoute.to('/monitors/create'),
      className: '''
        px-4 py-2 rounded-lg
        bg-primary hover:bg-green-600
        text-white font-medium text-sm
      ''',
      child: WDiv(
        className: 'flex flex-row items-center gap-2',
        children: [
          WIcon(Icons.add, className: 'text-lg text-white'),
          WText(trans('monitors.add')),
        ],
      ),
    ),
  ],
)
```

**Use case:** List pages with create/add functionality

---

### 4. Title + Multiple Actions

Multiple action buttons (search, filter, add):

```dart
AppPageHeader(
  title: trans('team.members'),
  subtitle: trans('team.members_subtitle'),
  actions: [
    WButton(
      onTap: () => showSearchDialog(),
      className: '''
        px-3 py-2 rounded-lg
        bg-gray-100 dark:bg-gray-700
        hover:bg-gray-200 dark:hover:bg-gray-600
      ''',
      child: WIcon(
        Icons.search,
        className: 'text-gray-700 dark:text-gray-300',
      ),
    ),
    WButton(
      onTap: () => showFilterDialog(),
      className: '''
        px-3 py-2 rounded-lg
        bg-gray-100 dark:bg-gray-700
        hover:bg-gray-200 dark:hover:bg-gray-600
      ''',
      child: WIcon(
        Icons.filter_list,
        className: 'text-gray-700 dark:text-gray-300',
      ),
    ),
    WButton(
      onTap: () => MagicRoute.to('/team/invite'),
      className: '''
        px-4 py-2 rounded-lg
        bg-primary hover:bg-green-600
        text-white font-medium text-sm
      ''',
      child: WDiv(
        className: 'flex flex-row items-center gap-2',
        children: [
          WIcon(Icons.person_add, className: 'text-lg text-white'),
          WText(trans('team.invite')),
        ],
      ),
    ),
  ],
)
```

**Use case:** Complex list views with multiple operations

---

### 5. Title + Back Button

Detail pages that need navigation back:

```dart
AppPageHeader(
  leading: WButton(
    onTap: () => MagicRoute.back(),
    className: '''
      p-2 rounded-lg
      hover:bg-gray-100 dark:hover:bg-gray-700
    ''',
    child: WIcon(
      Icons.arrow_back,
      className: 'text-gray-700 dark:text-gray-300',
    ),
  ),
  title: monitor.name ?? trans('monitors.detail'),
  subtitle: monitor.url,
)
```

**Use case:** Detail views, edit pages

---

### 6. Back Button + Title + Save Action

Edit pages with back button and save:

```dart
AppPageHeader(
  leading: WButton(
    onTap: () => MagicRoute.back(),
    className: '''
      p-2 rounded-lg
      hover:bg-gray-100 dark:hover:bg-gray-700
    ''',
    child: WIcon(
      Icons.arrow_back,
      className: 'text-gray-700 dark:text-gray-300',
    ),
  ),
  title: trans('monitors.edit'),
  subtitle: monitor.name,
  actions: [
    WButton(
      onTap: () => handleCancel(),
      className: '''
        px-4 py-2 rounded-lg
        bg-gray-100 dark:bg-gray-700
        hover:bg-gray-200 dark:hover:bg-gray-600
        text-gray-700 dark:text-gray-200
        font-medium text-sm
      ''',
      child: WText(trans('common.cancel')),
    ),
    WButton(
      onTap: () => handleSave(),
      className: '''
        px-4 py-2 rounded-lg
        bg-primary hover:bg-green-600
        text-white font-medium text-sm
      ''',
      isLoading: isSaving,
      child: WText(trans('common.save')),
    ),
  ],
)
```

**Use case:** Forms, edit pages

---

### 7. Menu Button (Mobile Navigation)

Mobile-first pages with drawer/menu:

```dart
AppPageHeader(
  leading: WButton(
    onTap: () => Scaffold.of(context).openDrawer(),
    className: '''
      p-2 rounded-lg
      hover:bg-gray-100 dark:hover:bg-gray-700
    ''',
    child: WIcon(
      Icons.menu,
      className: 'text-gray-700 dark:text-gray-300',
    ),
  ),
  title: trans('dashboard.title'),
  subtitle: trans('dashboard.welcome', {'name': user.name}),
  actions: [
    WButton(
      onTap: () => showNotifications(),
      className: '''
        p-2 rounded-lg relative
        hover:bg-gray-100 dark:hover:bg-gray-700
      ''',
      child: WDiv(
        children: [
          WIcon(
            Icons.notifications_outlined,
            className: 'text-gray-700 dark:text-gray-300',
          ),
          // Notification badge
          if (hasUnread)
            WDiv(
              className: '''
                absolute top-1 right-1
                w-2 h-2 rounded-full bg-red-500
              ''',
            ),
        ],
      ),
    ),
  ],
)
```

**Use case:** Dashboard, mobile-first navigation

---

## Responsive Behavior

### Mobile (< 640px)

- Layout: **Vertical stack** (`flex-col`)
- Title and actions stack on top of each other
- Full-width action buttons
- Padding: `p-4` (16px)

### Desktop (≥ 640px)

- Layout: **Horizontal row** (`sm:flex-row`)
- Title on left, actions on right
- Aligned center vertically
- Padding: `lg:p-6` (24px on large screens)

---

## Styling Customization

### Custom Button Styles

You can customize action buttons to match your design:

```dart
// Primary button (brand color)
className: 'px-4 py-2 rounded-lg bg-primary hover:bg-green-600 text-white'

// Secondary button (gray)
className: 'px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200'

// Danger button (red)
className: 'px-4 py-2 rounded-lg bg-red-500 hover:bg-red-600 text-white'

// Icon-only button
className: 'p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700'

// Outlined button
className: 'px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 hover:bg-gray-50'
```

### Custom Leading Widget

Leading can be any widget:

```dart
// Custom icon
leading: WIcon(Icons.chevron_left, className: 'text-2xl text-primary'),

// Logo
leading: WImage(src: 'asset://assets/logo.png', className: 'w-8 h-8'),

// Custom button with badge
leading: WDiv(
  children: [
    WButton(
      onTap: () => handleBack(),
      child: WIcon(Icons.arrow_back),
    ),
  ],
),
```

---

## Best Practices

1. **Always provide a title** — it's required for accessibility
2. **Keep subtitles short** — they truncate on mobile
3. **Limit actions to 2-3 buttons** — more clutters the header
4. **Use icon-only buttons on mobile** — saves space
5. **Primary action should be rightmost** — natural reading order
6. **Back button on left** — standard navigation pattern
7. **Dark mode support** — always provide `dark:` variants

---

## Common Mistakes

❌ **Don't do this:**

```dart
// Too many actions
actions: [btn1, btn2, btn3, btn4, btn5] // Cluttered!

// Missing dark mode
className: 'bg-white text-black' // No dark: variants

// Long subtitle
subtitle: 'This is a very long subtitle that will overflow on mobile screens'
```

✅ **Do this instead:**

```dart
// Grouped actions or dropdown
actions: [
  WButton(...), // Primary action
  WPopover(...), // More actions in menu
]

// Always dark mode
className: 'bg-white dark:bg-gray-800 text-black dark:text-white'

// Short, descriptive subtitle
subtitle: trans('monitors.count', {'count': monitors.length})
```

---

## Integration with Other Components

### With Search Bar

```dart
WDiv(
  className: 'overflow-y-auto flex flex-col',
  scrollPrimary: true,
  children: [
    AppPageHeader(
      title: trans('monitors.title'),
      actions: [...],
    ),

    // Search bar below header
    WDiv(
      className: 'p-4 border-b border-gray-200 dark:border-gray-700',
      child: WInput(
        placeholder: trans('common.search'),
        prefix: WIcon(Icons.search),
      ),
    ),

    // Content
    _buildContent(),
  ],
)
```

### With Tabs

```dart
WDiv(
  className: 'overflow-y-auto flex flex-col',
  children: [
    AppPageHeader(
      title: trans('monitor.detail'),
      leading: backButton,
      actions: [editButton],
    ),

    // Tab bar
    _buildTabBar(),

    // Tab content
    _buildTabContent(),
  ],
)
```

---

## Testing

All functionality is covered by comprehensive tests. See:

```
test/widget/components/app_page_header_test.dart
```

Test coverage includes:
- ✅ Rendering (title, subtitle, leading, actions)
- ✅ Responsive layout (mobile vs desktop)
- ✅ Styling (borders, padding, text styles)
- ✅ Null states (without subtitle, leading, actions)
- ✅ Interaction (button taps work correctly)
- ✅ Full width behavior

---

## Related Components

- **PageHeader** (settings pages) — `/components/settings/page_header.dart`
- **AppHeader** (navigation) — `/components/navigation/app_header.dart`
- **AppSidebar** (navigation) — `/components/navigation/app_sidebar.dart`

---

**Last updated:** 2026-02-05
