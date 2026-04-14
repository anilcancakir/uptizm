---
path: "lib/app/models/**/*.dart"
---

# Magic ORM Models

## Class Structure

Every model follows this exact structure:

```dart
class MyModel extends Model with HasTimestamps, InteractsWithPersistence {
  @override String get table => 'my_models';
  @override String get resource => 'my-models';
  @override bool get incrementing => false;  // Always false, UUID PKs
  @override List<String> get fillable => ['field1', 'field2'];
  @override Map<String, String> get casts => {};

  // -- Typed Accessors --
  @override String get id => getAttribute('id')?.toString() ?? '';
  String? get field1 => getAttribute('field1') as String?;
  set field1(String? value) => setAttribute('field1', value);

  // -- Static Helpers --
  static Future<MyModel?> find(dynamic id) =>
      InteractsWithPersistence.findById<MyModel>(id, MyModel.new);
  static Future<List<MyModel>> all() =>
      InteractsWithPersistence.allModels<MyModel>(MyModel.new);

  // -- Factory Methods --
  static MyModel fromMap(Map<String, dynamic> map) {
    return MyModel()
      ..setRawAttributes(map, sync: true)
      ..exists = map.containsKey('id');
  }
  static MyModel fromJson(String json) {
    final map = jsonDecode(json) as Map<String, dynamic>;
    return MyModel.fromMap(map);
  }
}
```

## Conventions

- `incrementing` always `false`, all IDs are UUID strings from backend
- Typed getters: `getAttribute('key') as Type?`, nullable return, exact backend key name
- Typed setters: `setAttribute('key', value)`, mirror the getter key
- Nested model accessors: parse Map then call `ChildModel.fromMap(data)` (see User.currentTeam)
- List accessors: cast to `List<dynamic>`, map with `.fromMap()`, return typed List
- `fromMap()` uses `setRawAttributes(map, sync: true)` + set `exists` based on `id` presence
- `fromJson()` delegates to `fromMap()`, never duplicate parsing logic
- Add `Authenticatable` mixin only on User model
- Plugin interop: add `toPluginType()` methods when magic_starter needs the model (see Team.toMagicStarterTeam)
