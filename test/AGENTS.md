# TESTS

## OVERVIEW

TDD is mandatory. **RED → GREEN → REFACTOR**.

## STRUCTURE

```
test/                                # Flutter tests (mirrors lib/)
├── app/
│   ├── controllers/                 # Controller action tests
│   ├── models/                      # Model parsing, persistence
│   ├── enums/                       # fromValue(), selectOptions
│   └── helpers/                     # Utility function tests
├── resources/views/                 # View rendering tests
│   ├── components/                  # Reusable UI components
│   ├── monitors/                    # Feature-specific views
│   └── layouts/                     # Layout tests
├── unit/                            # Pure logic tests
├── widget/                          # Widget interaction tests
└── integration/                     # Full flow tests

back-end/tests/                      # Laravel tests
├── Feature/
│   ├── Api/V1/                      # API endpoint tests
│   ├── Jobs/                        # Queue job tests
│   ├── Listeners/                   # Event listener tests
│   └── Migrations/                  # Schema verification
└── Unit/                            # Service/utility tests
```

## FLUTTER TEST PATTERNS

### Model Test
```dart
void main() {
  group('Monitor', () {
    test('fromMap parses correctly', () {
      final map = {'id': 1, 'name': 'Test', 'status': 'active'};
      final monitor = Monitor()..setRawAttributes(map);
      
      expect(monitor.id, 1);
      expect(monitor.name, 'Test');
      expect(monitor.status, MonitorStatus.active);
    });

    test('handles null values gracefully', () {
      final monitor = Monitor()..setRawAttributes({});
      
      expect(monitor.id, isNull);
      expect(monitor.name, isNull);
    });
  });
}
```

### Enum Test
```dart
void main() {
  group('MonitorStatus', () {
    test('fromValue returns correct enum', () {
      expect(MonitorStatus.fromValue('active'), MonitorStatus.active);
      expect(MonitorStatus.fromValue('invalid'), isNull);
      expect(MonitorStatus.fromValue(null), isNull);
    });

    test('selectOptions contains all values', () {
      final options = MonitorStatus.selectOptions;
      expect(options.length, MonitorStatus.values.length);
    });
  });
}
```

### Controller Test
```dart
void main() {
  late MonitorController controller;

  setUp(() {
    controller = MonitorController();
  });

  tearDown(() {
    controller.dispose();
  });

  test('loadMonitors updates notifier', () async {
    await controller.loadMonitors();
    expect(controller.monitorsNotifier.value, isNotEmpty);
  });
}
```

### View Test (with harness)
```dart
void main() {
  testWidgets('renders monitor list', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: MonitorsIndexViewTestHarness(),
      ),
    );

    expect(find.text('Monitors'), findsOneWidget);
    expect(find.byType(WDiv), findsWidgets);
  });
}
```

## LARAVEL TEST PATTERNS

### API Test
```php
/** @test */
public function user_can_list_monitors(): void
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->current_team_id = $team->id;
    $user->save();
    
    Monitor::factory()->count(3)->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/monitors');

    $response->assertOk()
        ->assertJsonCount(3, 'data');
}

/** @test */
public function user_cannot_access_other_team_monitors(): void
{
    $user = User::factory()->create();
    $otherTeam = Team::factory()->create();
    Monitor::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/monitors');

    $response->assertOk()
        ->assertJsonCount(0, 'data');
}
```

### Validation Test
```php
/** @test */
public function store_requires_name(): void
{
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/monitors', ['url' => 'https://example.com']);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
}
```

### Migration Test
```php
/** @test */
public function monitors_table_has_required_columns(): void
{
    $this->assertTrue(Schema::hasTable('monitors'));
    $this->assertTrue(Schema::hasColumn('monitors', 'team_id'));
    $this->assertTrue(Schema::hasColumn('monitors', 'name'));
    $this->assertTrue(Schema::hasColumn('monitors', 'status'));
}
```

## MOCKING

### Flutter (Manual)
```dart
class MockMonitorController extends MonitorController {
  @override
  Future<void> loadMonitors() async {
    monitorsNotifier.value = [
      Monitor()..setRawAttributes({'id': 1, 'name': 'Mock'}),
    ];
  }
}
```

### Laravel (Fakes)
```php
Event::fake();
Queue::fake();

// ... perform action ...

Event::assertDispatched(MonitorCreated::class);
Queue::assertPushed(PerformMonitorCheck::class);
```

## COMMANDS

```bash
# Flutter
flutter test                           # All tests
flutter test test/app/models/          # Directory
flutter test --name="fromValue"        # By name pattern

# Laravel
php artisan test                       # All tests
php artisan test --filter=MonitorApi   # By class name
php artisan test --filter=user_can     # By method pattern
```

## GOTCHAS

- Flutter widget tests need `TestHarness` wrappers for layout constraints
- Laravel tests need `RefreshDatabase` trait
- Always reset controller state in `tearDown`
- Use `actingAs($user)` for authenticated Laravel tests
