<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Support\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two additions to the ledger.
 *
 * An issue is no longer only an error group: a scheduled task that missed
 * its slot, overran or keeps failing is a thing the team decides about too,
 * so the row says what KIND of issue it is and, for a task, which one.
 *
 * And the decisions get a history: who resolved, ignored or reopened it,
 * through which door (terminal, API, agent, the scan itself), and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::issues(), function (Blueprint $table): void {
            // Existing rows are error groups, which is what the default says.
            $table->string('kind', 32)->default('exception')->index();
            // The scheduled task's name; null for an error group.
            $table->string('subject')->nullable();
            $table->string('environment')->nullable();
            // The newest occurrence's trace, so "show me one" needs no search.
            $table->string('last_trace_id', 64)->nullable();
        });

        Schema::create(Tables::issueEvents(), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issue_id')->constrained(Tables::issues())->cascadeOnDelete();
            $table->string('action', 32);
            // Who, when the door knows: a user, `--by`, or nobody.
            $table->string('actor')->nullable();
            // Which door: console, api, agent, scan or code.
            $table->string('source', 16)->index();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::issueEvents());

        Schema::table(Tables::issues(), function (Blueprint $table): void {
            $table->dropIndex(['kind']);
            $table->dropColumn(['kind', 'subject', 'environment', 'last_trace_id']);
        });
    }
};
