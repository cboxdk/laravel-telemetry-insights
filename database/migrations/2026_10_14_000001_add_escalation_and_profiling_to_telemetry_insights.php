<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive-until-escalating needs the limits it learned when the issue was
 * archived; the profiling rotation needs to remember what it profiled, so
 * the next pass picks something else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::issues(), function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable();
            // Per hour: over the spike limit for the sustained span, or
            // over the burst limit in any one hour, and it escalates.
            $table->unsignedInteger('escalation_spike_limit')->nullable();
            $table->unsignedInteger('escalation_burst_limit')->nullable();
        });

        Schema::create(Tables::profilingRuns(), function (Blueprint $table): void {
            $table->id();
            // rotation | adhoc
            $table->string('source', 16)->index();
            $table->string('service')->nullable()->index();
            $table->string('match', 16);
            $table->string('key')->nullable();
            $table->string('value');
            $table->boolean('profile')->default(false);
            $table->unsignedInteger('minutes');
            // What made the rotation pick it, in the impact's own unit.
            $table->double('impact')->nullable();
            $table->string('reason')->nullable();
            $table->string('created_by')->nullable();
            // The id the monitored app gave the directive.
            $table->string('directive_id', 64)->nullable();
            // placed | failed
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::profilingRuns());

        Schema::table(Tables::issues(), function (Blueprint $table): void {
            $table->dropColumn(['archived_at', 'escalation_spike_limit', 'escalation_burst_limit']);
        });
    }
};
