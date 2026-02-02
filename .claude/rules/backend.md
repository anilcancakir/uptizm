---
globs: ["back-end/**"]
---

# Back-end Rules (Laravel)

## API Structure

### Versioned API Routes

All API routes MUST be in `routes/api/v1.php`:

```php
// ✅ CORRECT
// File: routes/api/v1.php
Route::prefix('teams')->group(function () {
    Route::get('/', [TeamController::class, 'index']);
    Route::post('/', [TeamController::class, 'store']);
    Route::get('/{team}', [TeamController::class, 'show']);
    Route::put('/{team}', [TeamController::class, 'update']);
    Route::delete('/{team}', [TeamController::class, 'destroy']);
});

// ❌ WRONG
// File: routes/api.php (not versioned)
```

### Controller Structure

```php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTeamRequest;
use App\Http\Requests\Api\V1\UpdateTeamRequest;
use App\Http\Resources\Api\V1\TeamResource;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

class TeamController extends Controller
{
    public function index(): JsonResponse
    {
        $teams = auth()->user()->teams;

        return response()->json([
            'data' => TeamResource::collection($teams),
        ]);
    }

    public function store(StoreTeamRequest $request): JsonResponse
    {
        $team = auth()->user()->teams()->create(
            $request->validated()
        );

        return response()->json([
            'data' => new TeamResource($team),
            'message' => 'Team created successfully',
        ], 201);
    }

    public function show(Team $team): JsonResponse
    {
        $this->authorize('view', $team);

        return response()->json([
            'data' => new TeamResource($team),
        ]);
    }

    public function update(UpdateTeamRequest $request, Team $team): JsonResponse
    {
        $this->authorize('update', $team);

        $team->update($request->validated());

        return response()->json([
            'data' => new TeamResource($team),
            'message' => 'Team updated successfully',
        ]);
    }

    public function destroy(Team $team): JsonResponse
    {
        $this->authorize('delete', $team);

        $team->delete();

        return response()->json([
            'message' => 'Team deleted successfully',
        ]);
    }
}
```

## Form Requests (Validation)

**ALWAYS use Form Requests for validation:**

```php
namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Or policy check
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Team name is required',
            'name.max' => 'Team name cannot exceed 255 characters',
        ];
    }
}
```

### Validation Rules Reference

```php
'required'                      // Must be present
'nullable'                      // Can be null
'string'                        // Must be string
'integer'                       // Must be integer
'numeric'                       // Must be numeric
'email'                         // Valid email format
'url'                           // Valid URL format
'date'                          // Valid date
'boolean'                       // Must be boolean
'array'                         // Must be array
'json'                          // Must be valid JSON
'exists:teams,id'               // Must exist in teams table
'unique:teams,email'            // Must be unique in teams table
'in:admin,member'               // Must be in list
'not_in:guest'                  // Must not be in list
'min:3'                         // Minimum length/value
'max:255'                       // Maximum length/value
'between:18,65'                 // Between min and max
'size:10'                       // Exact size
'regex:/^[A-Z]{2}$/'            // Must match regex
'confirmed'                     // Must match {field}_confirmation
'same:password'                 // Must match another field
'different:old_password'        // Must differ from another field
'before:2024-01-01'             // Date before
'after:2024-01-01'              // Date after
'alpha'                         // Only letters
'alpha_num'                     // Letters and numbers
'alpha_dash'                    // Letters, numbers, dashes, underscores
```

### Update Request Pattern

```php
class UpdateTeamRequest extends FormRequest
{
    public function rules(): array
    {
        $teamId = $this->route('team')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:teams,email,' . $teamId],
            // Ignore current record in unique check
        ];
    }
}
```

## API Resources (Response Formatting)

**ALWAYS use API Resources for responses:**

```php
namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'owner_id' => $this->owner_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Relationships (only when loaded)
            'owner' => new UserResource($this->whenLoaded('owner')),
            'members' => UserResource::collection($this->whenLoaded('members')),

            // Computed attributes
            'member_count' => $this->when(
                $this->relationLoaded('members'),
                fn() => $this->members->count()
            ),
        ];
    }
}
```

### Collection Resource

```php
namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class TeamCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'total' => $this->total(),
                'current_page' => $this->currentPage(),
                'last_page' => $this->lastPage(),
            ],
        ];
    }
}
```

## Eloquent Models

### Model Structure

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Team extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'owner_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $hidden = [
        'pivot', // Hide pivot data in relationships
    ];

    protected $appends = [
        'member_count', // Always include in JSON
    ];

    // Relationships

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    // Accessors

    public function getMemberCountAttribute(): int
    {
        return $this->members()->count();
    }

    // Scopes

    public function scopeOwnedBy($query, int $userId)
    {
        return $query->where('owner_id', $userId);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }
}
```

### Relationship Types

```php
// One to One
public function profile(): HasOne
{
    return $this->hasOne(Profile::class);
}

// One to Many
public function posts(): HasMany
{
    return $this->hasMany(Post::class);
}

// Many to One (Inverse)
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}

// Many to Many
public function roles(): BelongsToMany
{
    return $this->belongsToMany(Role::class)
        ->withPivot('assigned_at', 'assigned_by')
        ->withTimestamps();
}

// Has Many Through
public function posts(): HasManyThrough
{
    return $this->hasManyThrough(Post::class, Team::class);
}

// Polymorphic
public function comments(): MorphMany
{
    return $this->morphMany(Comment::class, 'commentable');
}
```

## Policies (Authorization)

```php
namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Team $team): bool
    {
        return $team->members()->where('user_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Team $team): bool
    {
        return $team->owner_id === $user->id ||
               $team->members()
                   ->where('user_id', $user->id)
                   ->where('role', 'admin')
                   ->exists();
    }

    public function delete(User $user, Team $team): bool
    {
        return $team->owner_id === $user->id;
    }
}
```

### Register Policies

```php
// In AuthServiceProvider
protected $policies = [
    Team::class => TeamPolicy::class,
];
```

### Use in Controllers

```php
public function update(UpdateTeamRequest $request, Team $team)
{
    $this->authorize('update', $team);

    // Or with custom response
    if (!Gate::allows('update', $team)) {
        return response()->json([
            'message' => 'Unauthorized'
        ], 403);
    }

    // Update logic
}
```

## Database Migrations

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
```

### Common Column Types

```php
$table->id();                           // Auto-increment ID
$table->foreignId('user_id');           // Foreign key (bigint unsigned)
$table->string('name', 100);            // VARCHAR(100)
$table->text('description');            // TEXT
$table->integer('count');               // INTEGER
$table->decimal('price', 8, 2);         // DECIMAL(8,2)
$table->boolean('active');              // BOOLEAN
$table->date('born_at');                // DATE
$table->datetime('published_at');       // DATETIME
$table->timestamp('verified_at');       // TIMESTAMP
$table->timestamps();                   // created_at, updated_at
$table->softDeletes();                  // deleted_at
$table->json('metadata');               // JSON
$table->enum('role', ['admin', 'member']); // ENUM

// Modifiers
$table->string('email')->unique();
$table->string('phone')->nullable();
$table->string('status')->default('pending');
$table->string('name')->index();
```

### Foreign Keys

```php
// Explicit foreign key
$table->foreignId('team_id')
    ->constrained('teams')
    ->onDelete('cascade')
    ->onUpdate('cascade');

// Convention-based (looks for 'teams' table)
$table->foreignId('team_id')->constrained()->cascadeOnDelete();

// Nullable foreign key
$table->foreignId('team_id')->nullable()->constrained();
```

## Seeders & Factories

### Factory

```php
namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class TeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'description' => fake()->sentence(),
            'owner_id' => User::factory(),
        ];
    }

    public function withOwner(User $user): static
    {
        return $this->state([
            'owner_id' => $user->id,
        ]);
    }
}
```

### Seeder

```php
namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::factory()->create();

        Team::factory()
            ->count(10)
            ->withOwner($owner)
            ->create();
    }
}
```

## Testing (PHPUnit/Pest)

### Feature Test

```php
namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_team(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/teams', [
                'name' => 'My Team',
                'description' => 'Team description',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'name', 'description'],
                'message',
            ]);

        $this->assertDatabaseHas('teams', [
            'name' => 'My Team',
            'owner_id' => $user->id,
        ]);
    }

    public function test_user_cannot_delete_team_they_dont_own(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $team = Team::factory()->withOwner($owner)->create();

        $response = $this->actingAs($user)
            ->deleteJson("/api/v1/teams/{$team->id}");

        $response->assertStatus(403);

        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    public function test_validation_fails_for_missing_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/teams', [
                'description' => 'Team description',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }
}
```

### Pest Syntax

```php
use App\Models\Team;
use App\Models\User;

it('allows user to create team', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/teams', [
            'name' => 'My Team',
        ]);

    $response->assertStatus(201);
    expect($response->json('data.name'))->toBe('My Team');
});

it('prevents unauthorized team deletion', function () {
    $owner = User::factory()->create();
    $user = User::factory()->create();
    $team = Team::factory()->withOwner($owner)->create();

    $response = $this->actingAs($user)
        ->deleteJson("/api/v1/teams/{$team->id}");

    expect($response->status())->toBe(403);
});
```

## Query Optimization

### Eager Loading (N+1 Prevention)

```php
// ❌ BAD: N+1 Query Problem
$teams = Team::all();
foreach ($teams as $team) {
    echo $team->owner->name; // Executes query for each team
}

// ✅ GOOD: Eager Loading
$teams = Team::with('owner')->get();
foreach ($teams as $team) {
    echo $team->owner->name; // No additional queries
}

// Multiple relationships
$teams = Team::with(['owner', 'members', 'members.user'])->get();

// Conditional eager loading
$teams = Team::with([
    'members' => fn($q) => $q->where('role', 'admin')
])->get();
```

### Query Scopes

```php
// In Model
public function scopeActive($query)
{
    return $query->whereNull('deleted_at');
}

public function scopeOwnedBy($query, int $userId)
{
    return $query->where('owner_id', $userId);
}

// Usage
$teams = Team::active()->ownedBy($user->id)->get();
```

### Chunking Large Datasets

```php
// Process large datasets efficiently
Team::chunk(100, function ($teams) {
    foreach ($teams as $team) {
        // Process team
    }
});
```

## Error Handling

### API Error Responses

```php
// In Handler.php
public function render($request, Throwable $exception)
{
    if ($request->is('api/*')) {
        if ($exception instanceof ModelNotFoundException) {
            return response()->json([
                'message' => 'Resource not found'
            ], 404);
        }

        if ($exception instanceof AuthorizationException) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        if ($exception instanceof ValidationException) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'message' => $exception->getMessage()
        ], 500);
    }

    return parent::render($request, $exception);
}
```

## Anti-Patterns (DON'T DO)

❌ **Don't validate in controllers:**
```php
// Bad
public function store(Request $request)
{
    $request->validate([...]); // ❌ Validation in controller
}

// Good
public function store(StoreTeamRequest $request)
{
    // Validation in Form Request ✅
}
```

❌ **Don't return raw models:**
```php
// Bad
return response()->json($team); // ❌ Raw model

// Good
return response()->json([
    'data' => new TeamResource($team), // ✅ API Resource
]);
```

❌ **Don't use snake_case in PHP:**
```php
// Bad
class team_controller {}          // ❌
function get_all_teams() {}       // ❌

// Good
class TeamController {}           // ✅
function getAllTeams() {}         // ✅
```

❌ **Don't skip authorization:**
```php
// Bad
public function delete(Team $team)
{
    $team->delete(); // ❌ No auth check
}

// Good
public function delete(Team $team)
{
    $this->authorize('delete', $team); // ✅ Auth check
    $team->delete();
}
```

❌ **Don't forget to eager load:**
```php
// Bad
$teams = Team::all();
foreach ($teams as $team) {
    echo $team->owner->name; // ❌ N+1 problem
}

// Good
$teams = Team::with('owner')->get(); // ✅ Eager load
```

## Naming Conventions

| Type | Convention | Example |
|------|-----------|---------|
| Model | Singular PascalCase | `Team`, `TeamMember` |
| Table | Plural snake_case | `teams`, `team_members` |
| Controller | Singular PascalCase + Controller | `TeamController` |
| Request | Action + Model + Request | `StoreTeamRequest` |
| Resource | Model + Resource | `TeamResource` |
| Policy | Model + Policy | `TeamPolicy` |
| Factory | Model + Factory | `TeamFactory` |
| Seeder | Model + Seeder | `TeamSeeder` |
| Migration | action_table_table | `create_teams_table` |
| Column | snake_case | `owner_id`, `created_at` |
| Foreign Key | singular_id | `team_id`, `user_id` |
| Method | camelCase | `storeTeam()`, `deleteUser()` |

## Checklist Before Committing

- [ ] Routes in `routes/api/v1.php` (versioned)
- [ ] Form Requests for all validation
- [ ] API Resources for all responses
- [ ] Authorization checks (`$this->authorize()`)
- [ ] Proper error handling
- [ ] Eager loading to prevent N+1
- [ ] Tests written (Feature tests for endpoints)
- [ ] Policies registered in AuthServiceProvider
- [ ] Migrations have `down()` method
- [ ] No snake_case in PHP code (only DB columns)
- [ ] JSON responses follow structure: `{data, message, meta}`
