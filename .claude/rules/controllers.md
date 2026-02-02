---
paths:
  - "lib/app/controllers/**/*.dart"
---

# Controller Rules

> Cross-cutting rules (Eloquent model pattern, API names, Http vs Eloquent) are in CLAUDE.md. This file covers controller-specific patterns only.

## Skills: ALWAYS activate `magic-framework`

## Singleton Pattern (REQUIRED)

```dart
class UserController extends MagicController {
  UserController._();
  static final instance = UserController._();
}

// In views:
class UserView extends MagicView<UserController> {
  @override
  UserController get controller => UserController.instance;
}
```

## State Management

### ValueNotifier (preferred for lists/selections)

```dart
class TeamController extends MagicController {
  TeamController._();
  static final instance = TeamController._();

  final teamsNotifier = ValueNotifier<List<Team>>([]);
  final selectedTeamNotifier = ValueNotifier<Team?>(null);

  Future<void> loadTeams() async {
    final response = await Http.get('/teams');
    if (response.successful) {
      teamsNotifier.value = (response.data['data'] as List)
          .map((t) => Team.fromMap(t)).toList();
    }
  }

  @override
  void dispose() {
    teamsNotifier.dispose();
    selectedTeamNotifier.dispose();
    super.dispose();
  }
}
```

### MagicStateMixin (for single-resource loading/error/success)

```dart
class UserController extends MagicController with MagicStateMixin<User> {
  UserController._();
  static final instance = UserController._();

  Future<void> fetchUser(String id) async {
    setLoading();
    try {
      final user = await User.find(id);
      setSuccess(user);
    } catch (e) {
      setError(e.toString());
    }
  }
}
// In view: controller.renderState((user) => WText(user.name ?? ''))
```

## CRUD Operations

### Create (Eloquent)
```dart
Future<void> createTeam(String name) async {
  final team = Team()..name = name;
  final success = await team.save();
  if (success) {
    Magic.toast(trans('teams.created'));
    teamsNotifier.value = [...teamsNotifier.value, team];
  }
}
```

### Update (Eloquent)
```dart
Future<void> updateUser(String id, Map<String, dynamic> data) async {
  final user = await User.find(id);
  if (user != null) {
    user.fill(data);
    await user.save();
  }
}
```

### Delete (with confirmation)
```dart
Future<void> deleteTeam(Team team) async {
  if (!Gate.allows('delete', team)) {
    Magic.toast(trans('errors.unauthorized'));
    return;
  }
  final confirmed = await Magic.confirm(
    title: trans('common.confirm'),
    message: trans('teams.delete_confirm'),
  );
  if (confirmed) {
    await team.delete();
    await loadTeams();
  }
}
```

## Http Integration (search/pagination/custom endpoints)

### With Error Handling
```dart
Future<void> loadUsers() async {
  try {
    final response = await Http.get('/users');
    if (response.successful) {
      usersNotifier.value = (response.data['data'] as List)
          .map((u) => User.fromMap(u)).toList();
    } else {
      Magic.toast(trans('errors.load_failed'));
    }
  } catch (e) {
    Log.error('Failed to load users', e);
    Magic.toast(trans('errors.network'));
  }
}
```

### Pagination
```dart
int currentPage = 1;
bool hasMorePages = true;
bool isLoadingMore = false;

Future<void> loadUsers({bool loadMore = false}) async {
  if (loadMore && (!hasMorePages || isLoadingMore)) return;
  if (loadMore) { isLoadingMore = true; currentPage++; }
  else { currentPage = 1; usersNotifier.value = []; }

  try {
    final response = await Http.get('/users', query: {'page': currentPage});
    final data = response.data;
    final newUsers = (data['data'] as List).map((u) => User.fromMap(u)).toList();

    usersNotifier.value = loadMore
        ? [...usersNotifier.value, ...newUsers]
        : newUsers;
    hasMorePages = data['current_page'] < data['last_page'];
  } finally {
    isLoadingMore = false;
  }
}
```

## Form Handling

```dart
Future<void> submitForm(MagicFormData form) async {
  if (!form.validate()) return;

  try {
    final response = await Http.post('/teams', data: {
      'name': form.get('name'),
    });
    if (response.successful) {
      Magic.toast(trans('teams.created'));
      MagicRoute.back();
    } else if (response.data is Map && response.data.containsKey('errors')) {
      form.setErrors(response.data['errors']); // Server-side validation
    }
  } catch (e) {
    Log.error('Submit failed', e);
    Magic.toast(trans('errors.network'));
  }
}
```

## Caching

```dart
Future<List<Country>> getCountries() async {
  final cached = await Cache.get<List<Country>>('countries');
  if (cached != null) return cached;

  final response = await Http.get('/countries');
  final countries = (response.data as List).map((c) => Country.fromMap(c)).toList();
  await Cache.put('countries', countries, duration: Duration(hours: 1));
  return countries;
}

// Invalidate: await Cache.forget('countries');
```

## Events

```dart
// Dispatch
await Event.dispatch(TeamCreated(team));

// Listen (register in EventServiceProvider)
@override
Map<Type, List<MagicListener Function()>> get listen => {
  TeamCreated: [() => RefreshTeamListListener()],
};
```

## Navigation

```dart
MagicRoute.to('/teams');           // Navigate
MagicRoute.back();                 // Go back
MagicRoute.page('/path', () => W()); // Register route
MagicRoute.group(layout: ..., middleware: [...], routes: () { ... });

// Route params
final id = MagicRouter.instance.pathParameter('id');
```

## Controller Action Names (Laravel convention)

| Action | Purpose |
|--------|---------|
| `index()` | List view |
| `create()` | Create form |
| `store(data)` | Save new record |
| `show(id)` | Detail view |
| `edit(id)` | Edit form |
| `update(id, data)` | Update record |
| `destroy(id)` | Delete record |

## Checklist

- [ ] Singleton pattern (`static final instance`)
- [ ] Relative Http paths (`/users` not absolute URLs)
- [ ] Eloquent for CRUD, Http for search/pagination/custom
- [ ] Error handling with try-catch, Log.error, user feedback
- [ ] Loading states managed
- [ ] Auth checks before destructive actions
- [ ] Dispose notifiers/subscriptions in `dispose()`
- [ ] `trans()` for all user-facing strings
- [ ] `form.validate()` before API calls
