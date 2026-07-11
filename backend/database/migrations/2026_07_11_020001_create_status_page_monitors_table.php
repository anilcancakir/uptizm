<?php

use FlutterSdk\MagicStarter\Support\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status-page monitors pivot: which monitors are shown on a status page, and
 * in what order. `display_order` drives the public page's component list
 * ordering; `custom_label` lets a team rename a monitor for public display
 * without renaming the underlying monitor. The `(status_page_id, monitor_id)`
 * unique constraint prevents the same monitor from being attached twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_page_monitors', function (Blueprint $table): void {
            MigrationHelper::primaryKey($table);
            MigrationHelper::foreignKey($table, 'status_page_id')
                ->constrained('status_pages')
                ->cascadeOnDelete();
            MigrationHelper::foreignKey($table, 'monitor_id')
                ->constrained('monitors')
                ->cascadeOnDelete();

            $table->integer('display_order')->default(0);
            $table->string('custom_label')->nullable();

            $table->timestamps();

            $table->unique([
                'status_page_id',
                'monitor_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_monitors');
    }
};
