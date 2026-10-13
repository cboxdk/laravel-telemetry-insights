<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Issues;

use Cbox\TelemetryInsights\Models\Incident;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryUi\Support\ScopeLock;
use Illuminate\Database\Eloquent\Builder;

/**
 * The dashboard's per-viewer tenancy lock, applied to the issue ledger.
 *
 * Every surface that lists or acts on issues for a viewer — the panel, the
 * HTTP API, the agent tools — narrows through here, so a viewer locked to
 * one service sees that service's issues and nothing else.
 *
 * Fails closed, the way the dashboard does: a lock to nothing shows
 * nothing, and an issue with no environment recorded (an error group) is
 * hidden from a viewer whose environments are locked, because it cannot be
 * shown to be theirs.
 */
final readonly class IssueVisibility
{
    public function __construct(private ScopeLock $lock) {}

    /**
     * @param  Builder<Issue>  $query
     * @return Builder<Issue>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->lock->servicesLocked()) {
            $query->whereIn('service', $this->lock->services());
        }

        if ($this->lock->environmentsLocked()) {
            $query->whereIn('environment', $this->lock->environments());
        }

        return $query;
    }

    /** @return Builder<Issue> */
    public function query(): Builder
    {
        return $this->apply(Issue::query());
    }

    /**
     * Whether the viewer may see an incident: every service it touched must
     * be theirs. Incidents carry no environment, so an environment-locked
     * viewer sees none — fail closed, as for error groups.
     */
    public function allowsIncident(Incident $incident): bool
    {
        if ($this->lock->environmentsLocked()) {
            return false;
        }

        if (! $this->lock->servicesLocked()) {
            return true;
        }

        $services = $incident->services;

        return $services !== [] && array_diff($services, $this->lock->services()) === [];
    }

    /** Whether the viewer may act for this service at all. */
    public function allowsService(?string $service): bool
    {
        if (! $this->lock->servicesLocked()) {
            return true;
        }

        return $service !== null && in_array($service, $this->lock->services(), true);
    }
}
