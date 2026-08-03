<?php

namespace Database\Seeders;

use App\Support\Services\SystemTeam;
use Illuminate\Database\Seeder;

/**
 * Seeds the internal team that owns the public service catalog's monitors.
 *
 * Unlike {@see StatusPageSeeder} and {@see AiSuggestionSeeder}, this runs in
 * EVERY environment including production, and {@see DatabaseSeeder} calls it
 * outside the local/testing guard for exactly that reason: the row is reference
 * data the service catalog cannot work without, not demo data. It carries no
 * fixed credential to leak (the owner user's password is random and recorded
 * nowhere), which is what makes it safe on a real host.
 *
 * Idempotent through {@see SystemTeam::resolve()}, which is an existence check
 * before it is a create. Running the seeder twice creates one row, pinned by
 * `tests/Feature/Services/SystemTeamTest.php`.
 */
class SystemTeamSeeder extends Seeder
{
    /**
     * Ensure the system team (and the user row its NOT NULL owner FK needs)
     * exists.
     */
    public function run(): void
    {
        SystemTeam::resolve();
    }
}
