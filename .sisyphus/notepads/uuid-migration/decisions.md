# Architectural Decisions — UUID Migration

## Key Decisions
- Fresh migration approach: Edit existing migration files in-place, wipe DB with `migrate:fresh`
- All domain models (including users) converted to UUID
- Laravel internal tables (jobs, cache) remain as auto-increment
- sessions.user_id FK must also use foreignUuid since users.id becomes UUID
