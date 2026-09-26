<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The only state this package keeps.
 *
 * Note what is NOT here: occurrences, stack traces, spans, metrics. Those
 * stay in the telemetry store and are read on demand. These tables hold the
 * decisions people make about that data — status, assignee, what we have
 * already paged about — plus the alert rules themselves. That is what keeps
 * the dashboard's "never writes to your telemetry stores" promise intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::issues(), function (Blueprint $table): void {
            $table->id();
            // The fingerprint the whole stack already groups by; this row is
            // an overlay on it, so it is the natural key.
            $table->string('fingerprint', 64)->unique();
            $table->string('status', 16)->index();
            $table->string('type')->nullable();
            $table->text('message')->nullable();
            $table->string('service')->nullable();
            $table->string('assignee')->nullable();
            $table->text('notes')->nullable();
            // When this package first became aware of the fingerprint — what
            // makes "new issue" answerable without scanning all of history.
            $table->timestamp('first_recorded_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by')->nullable();
            // The release it was resolved in, so a regression is provable.
            $table->string('resolved_in_release')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });

        Schema::create(Tables::incidents(), function (Blueprint $table): void {
            $table->id();
            $table->string('signature', 64)->unique();
            $table->string('status', 16)->index();
            $table->string('title');
            $table->timestamp('onset_at')->index();
            $table->string('cause_kind', 32)->nullable();
            $table->string('cause_label')->nullable();
            $table->text('cause_evidence')->nullable();
            $table->string('cause_confidence', 16)->nullable();
            $table->string('cause_trace_id', 64)->nullable();
            $table->unsignedInteger('occurrences')->default(0);
            $table->unsignedInteger('group_count')->default(0);
            // The members, so an incident page can link its issues without
            // re-running correlation.
            $table->json('fingerprints');
            $table->json('services');
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledged_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });

        Schema::create(Tables::alertRules(), function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 32)->index();
            $table->string('comparator', 16)->nullable();
            $table->double('threshold')->nullable();
            $table->unsignedInteger('window_minutes')->default(5);
            $table->unsignedInteger('cooldown_minutes')->default(30);
            // Which slice of the fleet this watches: service, environment.
            $table->json('scope')->nullable();
            // For AlertType::Metric — the metric to read.
            $table->string('metric')->nullable();
            $table->json('channels')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();
        });

        Schema::create(Tables::alertEvents(), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained(Tables::alertRules())->cascadeOnDelete();
            $table->double('value')->nullable();
            $table->double('threshold')->nullable();
            $table->string('summary');
            // Whatever made the rule fire: the incident signature, the new
            // fingerprint, the measured series.
            $table->json('context')->nullable();
            $table->timestamp('fired_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::alertEvents());
        Schema::dropIfExists(Tables::alertRules());
        Schema::dropIfExists(Tables::incidents());
        Schema::dropIfExists(Tables::issues());
    }
};
