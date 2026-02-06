---
paths:
  - "lib/app/controllers/**/*.dart"
---

# Controller Rules

> Skill: `magic-framework` | Cross-cutting: see CLAUDE.md

## Singleton (REQUIRED)

```dart
class UserController extends MagicController {
  UserController._();
  static final instance = UserController._();
}
```

## State Management

**ValueNotifier** (lists/selections):
```dart
final teamsNotifier = ValueNotifier<List<Team>>([]);

Future<void> loadTeams() async {
  final response = await Http.get('/teams');
  if (response.successful) {
    teamsNotifier.value = (response.data['data'] as List)
        .map((t) => Team.fromMap(t)).toList();
  }
}

@override void dispose() { teamsNotifier.dispose(); super.dispose(); }
```

**MagicStateMixin** (single resource):
```dart
class UserController extends MagicController with MagicStateMixin<User> {
  Future<void> fetchUser(String id) async {
    setLoading();
    try { setSuccess(await User.find(id)); }
    catch (e) { setError(e.toString()); }
  }
}
// View: controller.renderState((user) => WText(user.name ?? ''))
```

## CRUD

```dart
// Create
final team = Team()..name = name;
if (await team.save()) teamsNotifier.value = [...teamsNotifier.value, team];

// Update
final user = await User.find(id);
user?.fill(data);
await user?.save();

// Delete (with auth)
if (!Gate.allows('delete', team)) return;
if (await Magic.confirm(title: trans('common.confirm'), message: trans('teams.delete_confirm'))) {
  await team.delete();
  await loadTeams();
}
```

## Http (search/pagination)

```dart
Future<void> loadUsers({bool loadMore = false}) async {
  if (loadMore && (!hasMorePages || isLoadingMore)) return;
  if (loadMore) { isLoadingMore = true; currentPage++; }
  else { currentPage = 1; usersNotifier.value = []; }

  final response = await Http.get('/users', query: {'page': currentPage});
  final newUsers = (response.data['data'] as List).map((u) => User.fromMap(u)).toList();
  usersNotifier.value = loadMore ? [...usersNotifier.value, ...newUsers] : newUsers;
  hasMorePages = response.data['current_page'] < response.data['last_page'];
  isLoadingMore = false;
}
```

## Form Submit

```dart
Future<void> submitForm(MagicFormData form) async {
  if (!form.validate()) return;
  final response = await Http.post('/teams', data: {'name': form.get('name')});
  if (response.successful) { Magic.toast(trans('teams.created')); MagicRoute.back(); }
  else if (response.data?['errors'] != null) form.setErrors(response.data['errors']);
}
```

## Navigation

```dart
MagicRoute.to('/teams');
MagicRoute.back();
final id = MagicRouter.instance.pathParameter('id');
```

## Action Names (Laravel convention)

`index()` list | `create()` form | `store(data)` save | `show(id)` detail | `edit(id)` edit form | `update(id,data)` update | `destroy(id)` delete

## Checklist

- [ ] Singleton pattern
- [ ] Relative Http paths
- [ ] Error handling + user feedback
- [ ] Dispose notifiers
- [ ] `trans()` for strings
- [ ] `form.validate()` before API
