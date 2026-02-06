# ENUMS

> Skill: `magic-framework`

## PATTERN

```dart
enum ItemStatus {
  active('active', 'Active'),
  inactive('inactive', 'Inactive'),
  archived('archived', 'Archived');

  const ItemStatus(this.value, this.label);
  final String value;
  final String label;

  /// Parse from API value
  static ItemStatus? fromValue(String? value) {
    if (value == null) return null;
    return ItemStatus.values.firstWhereOrNull((e) => e.value == value);
  }

  /// For WFormSelect options
  static List<SelectOption> get selectOptions =>
      ItemStatus.values.map((e) => SelectOption(value: e.value, label: e.label)).toList();
}
```

## REQUIRED MEMBERS

| Member | Purpose |
|--------|---------|
| `value` | API/database string value |
| `label` | Human-readable display text |
| `fromValue(String?)` | Parse API response → enum |
| `selectOptions` | Generate dropdown options |

## LIST CONVERSION (for multi-select)

```dart
/// Parse list from API
static List<ItemStatus>? fromValueList(List? values) {
  if (values == null) return null;
  return values
      .map((v) => ItemStatus.fromValue(v?.toString()))
      .whereNotNull()
      .toList();
}

/// Convert to API format
static List<String> toValueList(List<ItemStatus> items) =>
    items.map((e) => e.value).toList();
```

## USAGE IN MODELS

```dart
// Getter
ItemStatus? get status => ItemStatus.fromValue(get<String>('status'));

// Setter
set status(ItemStatus? value) => set('status', value?.value);

// List getter
List<Location>? get locations => Location.fromValueList(get<List>('locations'));

// List setter
set locations(List<Location>? value) => set('locations', Location.toValueList(value ?? []));
```

## USAGE IN VIEWS

```dart
WFormSelect(
  name: 'status',
  label: trans('fields.status'),
  options: ItemStatus.selectOptions,
  initialValue: item.status?.value,
)
```

## TEST COVERAGE

```dart
test('fromValue returns correct enum', () {
  expect(ItemStatus.fromValue('active'), ItemStatus.active);
  expect(ItemStatus.fromValue('invalid'), isNull);
  expect(ItemStatus.fromValue(null), isNull);
});

test('selectOptions contains all values', () {
  expect(ItemStatus.selectOptions.length, ItemStatus.values.length);
});
```
