# MODELS

> Skill: `magic-framework`

## PATTERN

```dart
class Item extends Model with HasTimestamps, InteractsWithPersistence {
  Item() : super();

  @override
  String get table => 'items';

  @override
  String get resource => 'items';

  @override
  List<String> get fillable => ['team_id', 'name', 'status', 'config'];

  // Typed getters
  int? get id => get<int>('id');
  String? get name => get<String>('name');
  ItemStatus? get status => ItemStatus.fromValue(get<String>('status'));
  Map<String, dynamic>? get config => get<Map<String, dynamic>>('config');

  // Typed setters
  set name(String? value) => set('name', value);
  set status(ItemStatus? value) => set('status', value?.value);
  set config(Map<String, dynamic>? value) => set('config', value);

  // Computed properties
  bool get isActive => status?.value == 'active';

  // Static methods
  static Future<Item?> find(int id) async {
    return await InteractsWithPersistence.findById<Item>(id, Item.new);
  }

  static Future<List<Item>> all() async {
    return await InteractsWithPersistence.allModels<Item>(Item.new);
  }
}
```

## GETTER PATTERN

| Type | Pattern |
|------|---------|
| `int?` | `get<int>('key')` |
| `String?` | `get<String>('key')` |
| `List<T>?` | `get<List>('key')?.cast<T>()` |
| `Map?` | `get<Map<String, dynamic>>('key')` |
| `Enum?` | `EnumType.fromValue(get<String>('key'))` |
| `DateTime?` | `Carbon.parse(get<String>('key'))` |

## SETTER PATTERN

| Type | Pattern |
|------|---------|
| Primitive | `set('key', value)` |
| Enum | `set('key', value?.value)` |
| List<Enum> | `set('key', EnumType.toValueList(value ?? []))` |
| Nested object | `set('key', value?.toMap())` |

## TYPE SAFETY

```dart
// ❌ NEVER - Laravel returns num, not int
int count = json['count'] as int;

// ✅ ALWAYS - Safe cast
int count = (json['count'] as num?)?.toInt() ?? 0;
```

## PERSISTENCE

```dart
// Create
final item = Item()..name = 'Test';
await item.save();  // POST /items

// Update
final item = await Item.find(1);
item?.name = 'Updated';
await item?.save();  // PUT /items/1

// Delete
await item.delete();  // DELETE /items/1
```

## NOTES

- Framework auto-unwraps `{data: {...}}` from Laravel
- `team_id` auto-set by backend from `current_team_id`
- Use `fromMap()` for nested objects from API responses
