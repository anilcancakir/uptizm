# VIEWS

> Skills: `wind-ui` + `flutter-design` + `mobile-app-design-mastery`

## VIEW PATTERN

```dart
class ItemsIndexView extends MagicView<ItemController> {
  const ItemsIndexView({super.key});

  @override
  ItemController get controller => ItemController.instance;

  @override
  void onInit() {
    controller.loadItems();
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex-1 overflow-y-auto',
      scrollPrimary: true,  // REQUIRED for iOS tap-to-top
      child: WDiv(
        className: 'p-5 flex flex-col gap-5',
        children: [
          AppPageHeader(title: trans('items.title')),
          _buildContent(),
        ],
      ),
    );
  }
}
```

## WIDGET RULES

| ❌ Never Use | ✅ Use Instead |
|-------------|----------------|
| `Container` | `WDiv` |
| `Text` | `WText` |
| `TextField` | `WFormInput` |
| `Icon` | `WIcon` |
| `ElevatedButton` | `WButton` |
| `GestureDetector` | `WAnchor` |

## WIND UI ESSENTIALS

### Layout
```dart
WDiv(className: 'flex flex-col gap-4')      // Vertical stack
WDiv(className: 'flex flex-row gap-4')      // Horizontal row
WDiv(className: 'grid grid-cols-2 gap-4')   // Grid
```

### Responsive
```dart
className: 'p-4 md:p-6 lg:p-8'              // Breakpoint prefixes
className: 'grid-cols-1 md:grid-cols-2'     // Responsive grid
```

### Dark Mode (REQUIRED)
```dart
className: 'bg-white dark:bg-gray-800'
className: 'text-gray-900 dark:text-white'
className: 'border-gray-200 dark:border-gray-700'
```

### States
```dart
className: 'hover:bg-gray-100 focus:ring-2'
className: 'disabled:opacity-50'
```

## REACTIVITY

```dart
// List listening
MagicBuilder<List<Item>>(
  listenable: controller.itemsNotifier,
  builder: (items) => _buildList(items),
)

// State rendering (loading/error/success)
controller.renderState((item) => _buildDetail(item))
```

## FORMS

```dart
MagicForm(
  key: formKey,
  onSubmit: (form) => controller.store(form),
  child: WDiv(
    className: 'flex flex-col gap-4',
    children: [
      WFormInput(
        name: 'name',
        label: trans('fields.name'),
        rules: 'required|min:3',
      ),
      WFormSelect(
        name: 'status',
        label: trans('fields.status'),
        options: ItemStatus.selectOptions,
      ),
      WButton(
        className: 'bg-primary text-white',
        text: trans('common.save'),
        type: ButtonType.submit,
      ),
    ],
  ),
)
```

## DESIGN TOKENS

| Element | Classes |
|---------|---------|
| Card | `bg-white dark:bg-gray-800 rounded-2xl shadow-soft p-5` |
| Input | `px-3 py-3 rounded-lg text-sm` |
| Primary button | `bg-primary hover:bg-primary-600 text-white rounded-xl` |
| Section label | `text-xs font-bold uppercase tracking-wide text-gray-500` |

## SCROLLING

```dart
// ✅ Correct - enables iOS tap-to-top
WDiv(
  className: 'flex-1 overflow-y-auto',
  scrollPrimary: true,
  child: content,
)

// ❌ Wrong - breaks iOS behavior
WDiv(className: 'overflow-y-auto', child: content)
```

## ICONS

```dart
// ✅ Material Symbols Outlined only
WIcon(Icons.person_outline, className: 'text-primary')
WIcon(Icons.settings_outlined, size: 20)

// ❌ Never filled variants
WIcon(Icons.person)
```
