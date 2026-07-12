<?php

use App\Models\EscalationStep;
use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escalation policies table: a team-scoped, named paging policy that owns an
 * ordered set of {@see EscalationStep} rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalation_policies', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'team_id')
                ->constrained('teams')
                ->cascadeOnDelete();

            $table->string('name');

            $table->timestamps();

            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalation_policies');
    }
};
