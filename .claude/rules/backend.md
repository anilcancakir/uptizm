---
paths:
  - "back-end/app/**/*.php"
  - "back-end/routes/api/v1.php"
  - "back-end/database/migrations/**/*.php"
  - "back-end/tests/Feature/**/*.php"
---

# Backend Rules (Laravel)

> Routes: `back-end/routes/api/v1.php` | Tests: `cd back-end && php artisan test`

## Controller Pattern

```php
class TeamController extends Controller
{
    public function index(): JsonResponse {
        return response()->json(['data' => TeamResource::collection(auth()->user()->teams)]);
    }

    public function store(StoreTeamRequest $request): JsonResponse {
        $team = auth()->user()->teams()->create($request->validated());
        return response()->json(['data' => new TeamResource($team), 'message' => 'Team created'], 201);
    }

    public function show(Team $team): JsonResponse {
        $this->authorize('view', $team);
        return response()->json(['data' => new TeamResource($team)]);
    }

    public function update(UpdateTeamRequest $request, Team $team): JsonResponse {
        $this->authorize('update', $team);
        $team->update($request->validated());
        return response()->json(['data' => new TeamResource($team), 'message' => 'Team updated']);
    }

    public function destroy(Team $team): JsonResponse {
        $this->authorize('delete', $team);
        $team->delete();
        return response()->json(['message' => 'Team deleted']);
    }
}
```

**Rules:** Form Requests for validation | API Resources for responses | `$this->authorize()` | Response: `{data, message}`

## Form Requests

```php
class StoreTeamRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        return ['name' => ['required', 'string', 'max:255']];
    }
}

// Unique ignore for updates
'email' => ['required', 'email', 'unique:teams,email,' . $this->route('team')->id]
```

## API Resources

```php
class TeamResource extends JsonResource {
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at?->toISOString(),
            'owner' => new UserResource($this->whenLoaded('owner')),
            'member_count' => $this->when($this->relationLoaded('members'), fn() => $this->members->count()),
        ];
    }
}
```

## Policies

```php
class TeamPolicy {
    public function view(User $user, Team $team): bool {
        return $team->members()->where('user_id', $user->id)->exists();
    }
    public function update(User $user, Team $team): bool {
        return $team->owner_id === $user->id;
    }
}
// Register: AuthServiceProvider → Team::class => TeamPolicy::class
```

## Testing

```php
public function test_user_can_create_team(): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->postJson('/api/v1/teams', ['name' => 'My Team'])
        ->assertStatus(201)
        ->assertJsonStructure(['data' => ['id', 'name']]);
    $this->assertDatabaseHas('teams', ['name' => 'My Team']);
}
```

## Query Optimization

```php
// ALWAYS eager load
Team::with('owner')->get();
Team::with(['members' => fn($q) => $q->where('role', 'admin')])->get();
```

## Naming

| Type | Convention |
|------|------------|
| Model | `Team` (singular) |
| Table | `teams` (plural snake) |
| Controller | `TeamController` |
| Request | `StoreTeamRequest` |
| Resource | `TeamResource` |

## Checklist

- [ ] Form Requests for validation
- [ ] API Resources for responses
- [ ] Authorization checks
- [ ] Eager loading (no N+1)
- [ ] Tests for endpoints
