<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only, no existing column is touched. `plan`/`plan_status`
     * are the entitlement columns read through `Team::entitledPlan()`;
     * `stripe_id`/`pm_type`/`pm_last_four`/`trial_ends_at` are the Cashier
     * `Billable` customer columns (S12 deleted the stock published
     * migration, so this is their sole definition).
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            if (! Schema::hasColumn('teams', 'plan')) {
                $table->string('plan')->default('free')->after('profile_photo_path');
            }

            if (! Schema::hasColumn('teams', 'plan_status')) {
                $table->string('plan_status')->nullable()->after('plan');
            }

            if (! Schema::hasColumn('teams', 'stripe_id')) {
                $table->string('stripe_id')->nullable()->unique()->after('plan_status');
            }

            if (! Schema::hasColumn('teams', 'pm_type')) {
                $table->string('pm_type')->nullable()->after('stripe_id');
            }

            if (! Schema::hasColumn('teams', 'pm_last_four')) {
                $table->string('pm_last_four', 4)->nullable()->after('pm_type');
            }

            if (! Schema::hasColumn('teams', 'trial_ends_at')) {
                $table->timestamp('trial_ends_at')->nullable()->after('pm_last_four');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            foreach (['plan', 'plan_status', 'stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at'] as $column) {
                if (Schema::hasColumn('teams', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
