---
globs: ["lib/app/controllers/**/*.dart"]
---

# Controller Rules

## Skills to Activate

When working on controllers, **ALWAYS activate:**
- `magic-framework` - Facades, state management, validation

## Controller Pattern

### Singleton Pattern (Required)

```dart
class UserController extends MagicController {
  UserController._();
  static final instance = UserController._();

  // Controller logic here
}

// Usage in views
class UserView extends MagicView<UserController> {
  @override
  UserController get controller => UserController.instance;
}
```

### With State Management

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

// In view: controller.renderState((user) => Text(user.name))
```

### With ValueNotifier

```dart
class TeamController extends MagicController {
  TeamController._();
  static final instance = TeamController._();

  final teamsNotifier = ValueNotifier<List<Team>>([]);
  final selectedTeamNotifier = ValueNotifier<Team?>(null);

  Future<void> loadTeams() async {
    final response = await Http.get('/teams');
    teamsNotifier.value = response.data.map((t) => Team.fromMap(t)).toList();
  }

  void selectTeam(Team team) {
    selectedTeamNotifier.value = team;
  }

  @override
  void dispose() {
    teamsNotifier.dispose();
    selectedTeamNotifier.dispose();
    super.dispose();
  }
}

// In view: ValueListenableBuilder<List<Team>>(
//   valueListenable: controller.teamsNotifier,
//   builder: (context, teams, _) => ...
// )
```

## Eloquent ORM (Primary Data Layer)

**IMPORTANT:** Magic Framework has a powerful Eloquent ORM. Use it as your **PRIMARY** data layer instead of Http facade when possible.

### Model Operations

#### Finding Records

```dart
// Find by ID
final user = await User.find(1);
if (user != null) {
  print(user.name);
}

// Find or fail (throws if not found)
final team = await Team.find(teamId);
if (team == null) {
  Magic.toast('Team not found');
  return;
}

// Get all records
final users = await User.all();
final teams = await Team.all();

// Get current authenticated user
final currentUser = User.current; // Auth.user<User>()
```

#### Creating Records

```dart
// Method 1: Create and save
final team = Team()
  ..name = 'My Team'
  ..set('description', 'Team description');

final success = await team.save();
if (success) {
  Magic.toast('Team created successfully');
  print('Team ID: ${team.id}');
}

// Method 2: Fill and save
final user = User()..fill({
  'name': 'John Doe',
  'email': 'john@example.com',
  'timezone': 'UTC',
});
await user.save();

// Method 3: From API response
final response = await Http.post('/teams', data: {'name': teamName});
if (response.success) {
  final team = Team.fromMap(response.data);
  teamsNotifier.value = [...teamsNotifier.value, team];
}
```

#### Updating Records

```dart
// Method 1: Modify and save
final user = await User.find(userId);
if (user != null) {
  user.name = 'Updated Name';
  user.email = 'newemail@example.com';
  await user.save();
}

// Method 2: Using set()
final team = await Team.find(teamId);
if (team != null) {
  team.set('name', newName);
  team.set('description', newDescription);
  await team.save();
}

// Method 3: Fill with map
final user = await User.find(userId);
if (user != null) {
  user.fill({
    'name': 'John',
    'phone': '+1234567890',
    'timezone': 'America/New_York',
  });
  await user.save();
}
```

#### Deleting Records

```dart
// Delete a model instance
final team = await Team.find(teamId);
if (team != null) {
  final success = await team.delete();
  if (success) {
    Magic.toast('Team deleted successfully');
  }
}

// With confirmation
final confirmed = await Magic.confirm(
  title: trans('common.confirm'),
  message: trans('teams.delete_confirm'),
);

if (confirmed) {
  final team = await Team.find(teamId);
  await team?.delete();
  await loadTeams(); // Refresh list
}
```

### Typed Accessors

Models have typed getters/setters for better DX:

```dart
final user = await User.find(1);

// ✅ GOOD: Type-safe accessors
print(user?.name);           // String?
print(user?.email);          // String?
print(user?.bornAt);         // Carbon?
user?.name = 'New Name';
user?.timezone = 'UTC';
await user?.save();

// ✅ ALSO GOOD: Generic get/set with defaults
final name = user?.get<String>('name', defaultValue: 'Unknown');
user?.set('custom_field', 'value');

// ❌ AVOID: Direct attribute access (unless necessary)
final name = user?.getAttribute('name'); // No type safety
```

### Model Properties

```dart
final user = await User.find(1);

// Check if model exists in database
if (user?.exists == true) {
  print('User exists in database');
}

// Get model ID
print('User ID: ${user?.id}');

// Check dirty attributes
user?.name = 'New Name';
if (user?.isDirty('name') == true) {
  print('Name has changed');
}

// Get original value
final originalName = user?.getOriginal('name');

// Timestamps (with HasTimestamps mixin)
print('Created: ${user?.createdAt}'); // Carbon
print('Updated: ${user?.updatedAt}'); // Carbon

// Touch timestamps
await user?.touch(); // Updates updated_at

// Convert to Map/JSON
final userMap = user?.toMap();
final userJson = user?.toJson();
```

### Relationships (From API)

```dart
// User has current_team in API response
final user = User.current;
final currentTeam = user.currentTeam; // Team?

// User has all_teams in API response
final allTeams = user.allTeams; // List<Team>

// Team has user_role in API response
final team = await Team.find(teamId);
print(team?.userRole); // 'owner', 'admin', 'editor', 'member'

// Computed properties
if (team?.canManageMembers == true) {
  // Show member management UI
}

if (team?.canEdit == true) {
  // Show edit button
}
```

### When to Use Eloquent vs Http

**Use Eloquent when:**
- ✅ Working with single records (find, create, update, delete)
- ✅ Need type-safe model accessors
- ✅ Want automatic serialization/deserialization
- ✅ Working with authenticated user data
- ✅ Need model validation and casting

**Use Http when:**
- ✅ Complex queries with filters/search
- ✅ Paginated results
- ✅ Custom endpoints not following REST
- ✅ Bulk operations
- ✅ File uploads

```dart
// ✅ GOOD: Eloquent for single record
final user = await User.find(userId);
user?.timezone = newTimezone;
await user?.save();

// ✅ GOOD: Http for search/filters
final response = await Http.get('/users', queryParams: {
  'search': searchQuery,
  'role': 'admin',
  'page': currentPage,
});

// ❌ BAD: Http for simple updates
final response = await Http.put('/users/$userId', data: {
  'timezone': newTimezone,
});
// Should use Eloquent instead ⬆️

// ✅ GOOD: Eloquent for creation
final team = Team()..name = teamName;
await team.save();

// ❌ BAD: Http for simple creation
await Http.post('/teams', data: {'name': teamName});
// Should use Eloquent instead ⬆️
```

## Controller Actions (Laravel-Style)

Follow Laravel naming conventions:

```dart
class PostController extends MagicController {
  PostController._();
  static final instance = PostController._();

  // Display a listing of resources
  Widget index() {
    return PostListView();
  }

  // Show the form for creating a new resource
  Widget create() {
    return PostCreateView();
  }

  // Store a newly created resource
  Future<void> store(Map<String, dynamic> data) async {
    await Http.post('/posts', data: data);
    Route.back();
  }

  // Display the specified resource
  Widget show(String id) {
    return PostDetailView(postId: id);
  }

  // Show the form for editing the specified resource
  Widget edit(String id) {
    return PostEditView(postId: id);
  }

  // Update the specified resource
  Future<void> update(String id, Map<String, dynamic> data) async {
    await Http.put('/posts/$id', data: data);
    Route.back();
  }

  // Remove the specified resource
  Future<void> destroy(String id) async {
    final confirmed = await Magic.confirm(
      title: trans('common.confirm'),
      message: trans('posts.delete_confirm'),
    );

    if (confirmed) {
      await Http.delete('/posts/$id');
      Route.back();
    }
  }
}
```

## API Integration

### Http Facade (Use Relative Paths)

```dart
// ✅ CORRECT: Relative paths
final response = await Http.get('/users');
final response = await Http.post('/users', data: {'name': 'John'});
final response = await Http.put('/users/1', data: {'name': 'Jane'});
final response = await Http.delete('/users/1');

// ❌ WRONG: Absolute paths or Config
final response = await Http.get('${Config.get('app.apiUrl')}/users');
final response = await Http.get('http://localhost:8000/api/v1/users');
```

### With Loading & Error Handling

```dart
Future<void> loadUsers() async {
  setLoading(true);

  try {
    final response = await Http.get('/users');

    if (response.success) {
      usersNotifier.value = response.data
        .map((u) => User.fromMap(u))
        .toList();
    } else {
      Magic.toast(response.message ?? 'Failed to load users');
    }
  } catch (e) {
    Log.error('Failed to load users', e);
    Magic.toast('Network error. Please try again.');
  } finally {
    setLoading(false);
  }
}
```

### With Pagination

```dart
class UserController extends MagicController {
  final usersNotifier = ValueNotifier<List<User>>([]);
  int currentPage = 1;
  bool hasMorePages = true;
  bool isLoadingMore = false;

  Future<void> loadUsers({bool loadMore = false}) async {
    if (loadMore) {
      if (!hasMorePages || isLoadingMore) return;
      isLoadingMore = true;
      currentPage++;
    } else {
      currentPage = 1;
      usersNotifier.value = [];
    }

    try {
      final response = await Http.get('/users?page=$currentPage');

      final newUsers = response.data['data']
        .map((u) => User.fromMap(u))
        .toList();

      if (loadMore) {
        usersNotifier.value = [...usersNotifier.value, ...newUsers];
      } else {
        usersNotifier.value = newUsers;
      }

      hasMorePages = response.data['current_page'] < response.data['last_page'];
    } finally {
      isLoadingMore = false;
    }
  }
}
```

## Form Handling

### With MagicFormData

```dart
Future<void> createTeam() async {
  final form = MagicFormData({
    'name': '',
    'description': '',
  });

  Magic.dialog(
    MagicForm(
      formData: form,
      child: TeamCreateDialog(
        form: form,
        onSubmit: () async {
          if (form.validate()) {
            await _storeTeam(
              name: form.get('name'),
              description: form.get('description'),
            );
            Magic.closeDialog();
          }
        },
      ),
    ),
  );
}

Future<void> _storeTeam({
  required String name,
  required String description,
}) async {
  setLoading(true);

  try {
    final response = await Http.post('/teams', data: {
      'name': name,
      'description': description,
    });

    if (response.success) {
      Magic.toast('Team created successfully');
      await loadTeams(); // Refresh list
    } else {
      Magic.toast(response.message ?? 'Failed to create team');
    }
  } catch (e) {
    Log.error('Failed to create team', e);
    Magic.toast('Network error. Please try again.');
  } finally {
    setLoading(false);
  }
}
```

### With Server-Side Validation Errors

```dart
Future<void> updateUser(MagicFormData form, String userId) async {
  if (!form.validate()) return;

  setLoading(true);

  try {
    final response = await Http.put('/users/$userId', data: {
      'name': form.get('name'),
      'email': form.get('email'),
    });

    if (response.success) {
      Magic.toast('User updated successfully');
      Route.back();
    } else {
      // Server returned validation errors
      if (response.data is Map && response.data.containsKey('errors')) {
        form.setErrors(response.data['errors']);
      } else {
        Magic.toast(response.message ?? 'Failed to update user');
      }
    }
  } catch (e) {
    Log.error('Failed to update user', e);
    Magic.toast('Network error. Please try again.');
  } finally {
    setLoading(false);
  }
}
```

## Validation

### Client-Side Validation

```dart
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

// In controller or use in forms
final validator = Validator.make(
  {'email': 'test@example.com', 'age': '25'},
  {
    'email': [Required(), Email()],
    'age': [Required(), Numeric(), Min(18), Max(100)],
  },
);

if (validator.fails()) {
  print(validator.errors());
  // {'email': ['The email field is required.'], 'age': [...]}
}

// Or use with MagicFormData
form.validate(); // Uses validators defined in WFormInput/WFormSelect
```

### Available Validation Rules

```dart
Required()                    // Field is required
Email()                       // Valid email format
Min(3)                        // Minimum length/value
Max(100)                      // Maximum length/value
Between(18, 65)               // Between min and max
Numeric()                     // Must be numeric
Alpha()                       // Only letters
AlphaNumeric()                // Letters and numbers only
Confirmed()                   // Must match {field}_confirmation
In(['admin', 'member'])       // Must be in list
NotIn(['guest'])              // Must not be in list
Regex(r'^[A-Z]{2}$')          // Must match regex
```

## Authorization

### Check Permissions

```dart
Future<void> deleteTeam(Team team) async {
  // Check permission first
  if (!Gate.allows('delete', team)) {
    Magic.toast('You do not have permission to delete this team');
    return;
  }

  final confirmed = await Magic.confirm(
    title: trans('common.confirm'),
    message: trans('teams.delete_confirm'),
  );

  if (confirmed) {
    await Http.delete('/teams/${team.id}');
    await loadTeams();
  }
}
```

### Using Policies

```dart
// Define policy in lib/app/policies/team_policy.dart
class TeamPolicy extends MagicPolicy {
  bool view(User user, Team team) {
    return team.members.any((m) => m.id == user.id);
  }

  bool update(User user, Team team) {
    return team.ownerId == user.id ||
           team.members.any((m) => m.id == user.id && m.role == 'admin');
  }

  bool delete(User user, Team team) {
    return team.ownerId == user.id;
  }
}

// Register in service provider
Gate.policy<Team>(TeamPolicy());

// Use in controller
if (Gate.allows('update', team)) {
  // Allow update
}
```

## Events & Listeners

### Dispatching Events

```dart
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

Future<void> createUser(Map<String, dynamic> data) async {
  final response = await Http.post('/users', data: data);

  if (response.success) {
    final user = User.fromMap(response.data);

    // Dispatch event
    await Event.dispatch(UserCreated(user));

    Magic.toast('User created successfully');
  }
}
```

### Listening to Events

```dart
// In EventServiceProvider
@override
Map<Type, List<MagicListener Function()>> get listen => {
  UserCreated: [
    () => SendWelcomeEmailListener(),
    () => LogUserCreationListener(),
  ],
};
```

## Caching

### Cache API Responses

```dart
Future<List<Country>> getCountries() async {
  // Check cache first
  final cached = await Cache.get<List<Country>>('countries');
  if (cached != null) {
    return cached;
  }

  // Fetch from API
  final response = await Http.get('/countries');
  final countries = response.data
    .map((c) => Country.fromMap(c))
    .toList();

  // Cache for 1 hour
  await Cache.put('countries', countries, duration: Duration(hours: 1));

  return countries;
}
```

### Cache Invalidation

```dart
Future<void> updateCountry(String id, Map<String, dynamic> data) async {
  await Http.put('/countries/$id', data: data);

  // Invalidate cache
  await Cache.forget('countries');
  await Cache.forget('country_$id');

  // Or clear all
  await Cache.flush();
}
```

## Logging

```dart
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

void performAction() {
  Log.debug('Starting action');

  try {
    // Do something
    Log.info('Action completed successfully');
  } catch (e, stackTrace) {
    Log.error('Action failed', e, stackTrace);
  }
}

// Log levels: debug, info, warning, error, critical
```

## Translation

```dart
// Use trans() for all user-facing text
Magic.toast(trans('teams.created_successfully'));
Magic.toast(trans('errors.network_error'));

// With parameters
Magic.toast(trans('teams.member_added', {
  'name': userName,
  'team': teamName,
}));
```

## Navigation

```dart
// Route to named route
Route.toNamed('teams.settings');

// Route with path
Route.to('/teams/create');

// Push (can go back)
Route.push('/teams/details');

// Replace (can't go back)
Route.replace('/teams/list');

// Go back
Route.back();

// With result
final result = await Route.push('/teams/select');
if (result != null) {
  selectedTeam.value = result;
}
```

## Lifecycle & Cleanup

```dart
class TeamController extends MagicController {
  final _subscriptions = <StreamSubscription>[];
  final _notifiers = <ValueNotifier>[];

  TeamController._() {
    _init();
  }
  static final instance = TeamController._();

  void _init() {
    // Subscribe to events
    _subscriptions.add(
      Event.listen<TeamUpdated>((event) {
        _handleTeamUpdate(event.team);
      }),
    );
  }

  @override
  void dispose() {
    // Cancel subscriptions
    for (final sub in _subscriptions) {
      sub.cancel();
    }

    // Dispose notifiers
    for (final notifier in _notifiers) {
      notifier.dispose();
    }

    super.dispose();
  }
}
```

## Anti-Patterns (DON'T DO)

❌ **Don't put business logic in views:**
```dart
// Bad: Logic in view
class UserView extends StatelessWidget {
  Widget build(BuildContext context) {
    final response = await Http.get('/users'); // ❌ API call in view
    return ListView(...);
  }
}

// Good: Logic in controller
class UserController extends MagicController {
  Future<void> loadUsers() async {
    final response = await Http.get('/users');
    usersNotifier.value = ...;
  }
}
```

❌ **Don't create multiple controller instances:**
```dart
// Bad: New instance each time
class UserView extends MagicView<UserController> {
  @override
  UserController get controller => UserController(); // ❌ New instance
}

// Good: Singleton pattern
class UserController extends MagicController {
  UserController._();
  static final instance = UserController._(); // ✅ Singleton
}
```

❌ **Don't use absolute URLs or Config for API:**
```dart
// Bad
await Http.get('${Config.get('app.apiUrl')}/users'); // ❌
await Http.get('http://localhost:8000/api/v1/users'); // ❌

// Good
await Http.get('/users'); // ✅ Relative path
```

❌ **Don't use Http for simple CRUD operations:**
```dart
// Bad: Http for simple update
Future<void> updateUser(String id, String name) async {
  await Http.put('/users/$id', data: {'name': name}); // ❌
}

// Good: Eloquent for simple update
Future<void> updateUser(String id, String name) async {
  final user = await User.find(id); // ✅
  if (user != null) {
    user.name = name;
    await user.save();
  }
}

// Bad: Http for creation
Future<void> createTeam(String name) async {
  await Http.post('/teams', data: {'name': name}); // ❌
}

// Good: Eloquent for creation
Future<void> createTeam(String name) async {
  final team = Team()..name = name; // ✅
  await team.save();
}

// ✅ When Http is appropriate:
// - Search/filters: Http.get('/users?search=$query&role=admin')
// - Pagination: Http.get('/users?page=$page')
// - Custom endpoints: Http.post('/teams/$id/invite')
// - Bulk operations: Http.post('/users/bulk-delete', data: {ids: [1,2,3]})
```

❌ **Don't ignore errors:**
```dart
// Bad: No error handling
Future<void> loadUsers() async {
  final response = await Http.get('/users'); // ❌ Can throw
  usersNotifier.value = response.data;
}

// Good: Proper error handling
Future<void> loadUsers() async {
  try {
    final response = await Http.get('/users');
    usersNotifier.value = response.data;
  } catch (e) {
    Log.error('Failed to load users', e);
    Magic.toast('Failed to load users');
  }
}
```

❌ **Don't forget to clean up:**
```dart
// Bad: No disposal
class TeamController extends MagicController {
  final teamsNotifier = ValueNotifier<List<Team>>([]);
  // ❌ No dispose() method
}

// Good: Proper cleanup
class TeamController extends MagicController {
  final teamsNotifier = ValueNotifier<List<Team>>([]);

  @override
  void dispose() {
    teamsNotifier.dispose(); // ✅ Clean up
    super.dispose();
  }
}
```

## Checklist Before Committing

- [ ] Singleton pattern used (`static final instance`)
- [ ] Actions follow Laravel naming (index, create, store, show, edit, update, destroy)
- [ ] Http facade uses relative paths (`/users` not `${baseUrl}/users`)
- [ ] Proper error handling (try-catch, Log.error, user feedback)
- [ ] Loading states managed (setLoading, isLoading)
- [ ] Authorization checks (Gate.allows, policy checks)
- [ ] Clean up resources in dispose() (notifiers, subscriptions)
- [ ] Translation keys used (trans()) not hardcoded strings
- [ ] Validation before API calls (form.validate())
- [ ] User feedback (Magic.toast, Magic.confirm)
