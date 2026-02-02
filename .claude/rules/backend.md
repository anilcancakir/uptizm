---
paths:
  - "back-end/app/**/*.php"
  - "back-end/routes/api/v1.php"
  - "back-end/database/migrations/**/*.php"
  - "back-end/tests/Feature/**/*.php"
---

# Backend Rules (Laravel)

> All routes in `back-end/routes/api/v1.php`. Run tests: `cd back-end && php artisan test`

## Controller Pattern

```php
namespace App\Http\Controllers\Api\V1;

class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => TeamResource::collection(auth()->user()->teams)]);
    }

    public function store(StoreTeamRequest $request): JsonResponse
    {
        $team = auth()->user()->teams()->create($request->validated());
        return response()->json(['data' => new TeamResource($team), 'message' => 'Team created'], 201);
    }

    public function show(Team $team): JsonResponse
    {
        $this->authorize('view', $team);
        return response()->json(['data' => new TeamResource($team)]);
    }

    public function update(UpdateTeamRequest $request, Team $team): JsonResponse
    {
        $this->authorize('update', $team);
        $team->update($request->validated());
        return response()->json(['data' => new TeamResource($team), 'message' => 'Team updated']);
    }

    public function destroy(Team $team): JsonResponse
    {
        $this->authorize('delete', $team);
        $team->delete();
        return response()->json(['message' => 'Team deleted']);
    }
}
```

**Rules:**
- ALWAYS use Form Requests for validation (never validate in controllers)
- ALWAYS use API Resources for responses (never return raw models)
- ALWAYS authorize actions (`$this->authorize()`)
- Response structure: `{data, message, meta}`

## Form Requests

```php
class StoreTeamRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

// For updates with unique ignore:
class UpdateTeamRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:teams,email,' . $this->route('team')->id],
        ];
    }
}
```

## API Resources

```php
class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'owner_id' => $this->owner_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'owner' => new UserResource($this->whenLoaded('owner')),
            'members' => UserResource::collection($this->whenLoaded('members')),
            'member_count' => $this->when($this->relationLoaded('members'), fn() => $this->members->count()),
        ];
    }
}
```

## Models

```php
class Team extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'description', 'owner_id'];
    protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime'];

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function members(): HasMany { return $this->hasMany(TeamMember::class); }

    public function scopeOwnedBy($query, int $userId) { return $query->where('owner_id', $userId); }
}
```

## Policies

```php
class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $team->members()->where('user_id', $user->id)->exists();
    }

    public function update(User $user, Team $team): bool
    {
        return $team->owner_id === $user->id ||
               $team->members()->where('user_id', $user->id)->where('role', 'admin')->exists();
    }

    public function delete(User $user, Team $team): bool
    {
        return $team->owner_id === $user->id;
    }
}
// Register in AuthServiceProvider: Team::class => TeamPolicy::class
```

## Migrations

```php
Schema::create('teams', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->text('description')->nullable();
    $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
    $table->timestamps();
    $table->softDeletes();
    $table->index('owner_id');
});
```

Common columns: `id()`, `foreignId()->constrained()->cascadeOnDelete()`, `string()`, `text()->nullable()`, `integer()`, `decimal(8,2)`, `boolean()`, `json()`, `timestamps()`, `softDeletes()`

Modifiers: `->unique()`, `->nullable()`, `->default()`, `->index()`

## Testing

```php
// Feature test
public function test_user_can_create_team(): void
{
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/teams', ['name' => 'My Team'])
        ->assertStatus(201)
        ->assertJsonStructure(['data' => ['id', 'name'], 'message']);

    $this->assertDatabaseHas('teams', ['name' => 'My Team', 'owner_id' => $user->id]);
}

public function test_unauthorized_delete_returns_403(): void
{
    $team = Team::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson("/api/v1/teams/{$team->id}")
        ->assertStatus(403);
}

public function test_validation_fails_without_name(): void
{
    $this->actingAs(User::factory()->create())
        ->postJson('/api/v1/teams', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
}
```

Pest syntax: `it('allows user to create team', function () { ... });`

## Query Optimization

```php
// ALWAYS eager load to prevent N+1
$teams = Team::with('owner')->get();              // Single relationship
$teams = Team::with(['owner', 'members'])->get();  // Multiple
$teams = Team::with(['members' => fn($q) => $q->where('role', 'admin')])->get(); // Conditional

// Chunk large datasets
Team::chunk(100, function ($teams) { /* process */ });
```

## Naming Conventions

| Type | Convention | Example |
|------|-----------|---------|
| Model | Singular PascalCase | `Team` |
| Table | Plural snake_case | `teams` |
| Controller | Model + Controller | `TeamController` |
| Request | Action + Model + Request | `StoreTeamRequest` |
| Resource | Model + Resource | `TeamResource` |
| Policy | Model + Policy | `TeamPolicy` |
| Migration | action_table | `create_teams_table` |
| Column | snake_case | `owner_id` |

## Checklist

- [ ] Routes in `routes/api/v1.php`
- [ ] Form Requests for validation
- [ ] API Resources for responses
- [ ] Authorization checks
- [ ] Eager loading (no N+1)
- [ ] Tests for endpoints
- [ ] Migrations have `down()` method
- [ ] `{data, message}` response structure
