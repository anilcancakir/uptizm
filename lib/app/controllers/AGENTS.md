# CONTROLLERS

> Skill: `magic-framework`

## PATTERN

```dart
class XController extends MagicController with MagicStateMixin<T>, ValidatesRequests {
  static XController get instance => Magic.findOrPut(XController.new);
  
  final itemsNotifier = ValueNotifier<List<Item>>([]);
  final selectedNotifier = ValueNotifier<Item?>(null);
  
  // Laravel-style actions
  Widget index() => const ItemsIndexView();
  Widget create() => const ItemCreateView();
  Widget show() => const ItemShowView();
  Widget edit() => const ItemEditView();
  
  Future<void> store({...}) async {
    setLoading();
    clearErrors();
    try {
      final item = Item()..name = name;
      if (await item.save()) {
        setSuccess(true);
        Magic.toast(trans('items.created'));
        MagicRoute.to('/items/${item.id}');
      }
    } catch (e) {
      Log.error('Failed', e);
      setError(trans('errors.network_error'));
    }
  }
  
  @override
  void dispose() {
    itemsNotifier.dispose();
    selectedNotifier.dispose();
    super.dispose();
  }
}
```

## STATE MANAGEMENT

| Pattern | Use Case |
|---------|----------|
| `ValueNotifier<List<T>>` | Lists, collections |
| `ValueNotifier<T?>` | Selected item, nullable state |
| `MagicStateMixin<T>` | Single resource with loading/error states |

## ACTIONS

| Method | Purpose |
|--------|---------|
| `index()` | Return list view widget |
| `create()` | Return create form view |
| `show()` | Return detail view |
| `edit()` | Return edit form view |
| `store({...})` | Create new record |
| `update(id, {...})` | Update existing record |
| `destroy(id)` | Delete with confirmation |

## NAVIGATION

```dart
MagicRoute.to('/monitors');           // Navigate
MagicRoute.back();                    // Go back
final id = MagicRouter.instance.pathParameter('id');  // Get route param
```

## API CALLS

```dart
// GET with query params
final response = await Http.get('/items', query: {'page': 1});

// POST action
final response = await Http.post('/monitors/$id/pause');

// Check success
if (response.successful) { ... }

// Unwrap Laravel data
final data = response.data['data'] as List;
```

## CHECKLIST

- [ ] Singleton via `Magic.findOrPut()`
- [ ] Dispose all ValueNotifiers
- [ ] Use `trans()` for all strings
- [ ] Handle errors with try-catch
- [ ] Relative Http paths only
- [ ] Confirmation dialogs for destructive actions
