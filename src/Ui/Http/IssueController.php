<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Ui\Http;

use Cbox\TelemetryInsights\Issues\ActionSource;
use Cbox\TelemetryInsights\Issues\Attribution;
use Cbox\TelemetryInsights\Issues\IssueActions;
use Cbox\TelemetryInsights\Issues\IssueKind;
use Cbox\TelemetryInsights\Issues\IssueStatus;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryUi\Http\Api\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The ledger over HTTP, so a host can build the screen this package does
 * not ship. Reading needs the dashboard's view ability (already applied by
 * the route group); changing anything needs the separate `manageTelemetryUi`
 * ability, enforced here and never trusted to the client.
 */
final class IssueController
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $status = is_string($status) && $status !== '' ? IssueStatus::tryFrom($status) : null;
        $kind = $request->query('kind');
        $kind = is_string($kind) && $kind !== '' ? IssueKind::tryFrom($kind) : null;

        $issues = Issue::query()
            ->when($status !== null, static fn ($query) => $query->where('status', $status?->value))
            ->when($kind !== null, static fn ($query) => $query->where('kind', $kind?->value))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        return new JsonResponse([
            'issues' => $issues->map(static fn (Issue $issue): array => self::payload($issue))->values()->all(),
        ]);
    }

    /**
     * `POST issues/{fingerprint}` with `{"action": "resolve"}`.
     */
    public function update(Request $request, IssueActions $actions, string $fingerprint): JsonResponse
    {
        if (! Gate::allows('manageTelemetryUi')) {
            return ApiError::forbidden('You are not authorized to change issues.');
        }

        $issue = Issue::query()->firstWhere('fingerprint', $fingerprint);

        if (! $issue instanceof Issue) {
            return ApiError::notFound('No issue recorded for that fingerprint.');
        }

        $action = $request->input('action');

        if (! is_string($action)) {
            return ApiError::invalid('An action is required.');
        }

        $as = new Attribution(ActionSource::Api, self::text($request, 'by') ?? self::user($request), self::text($request, 'comment'));

        $updated = match ($action) {
            'resolve' => $actions->resolve($issue, $as->actor, self::text($request, 'release'), $as),
            'reopen' => $actions->reopen($issue, $as),
            'ignore' => $actions->ignore($issue, $as),
            'snooze' => $actions->snooze($issue, self::until($request), $as),
            'assign' => $actions->assign($issue, self::text($request, 'to'), $as),
            default => null,
        };

        if (! $updated instanceof Issue) {
            return ApiError::invalid('Unknown action ['.$action.']. Use resolve, reopen, ignore, snooze or assign.');
        }

        return new JsonResponse(['issue' => self::payload($updated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(Issue $issue): array
    {
        return [
            'fingerprint' => $issue->fingerprint,
            'kind' => $issue->kind()->value,
            'title' => $issue->title(),
            'status' => $issue->effectiveStatus()->value,
            'type' => $issue->type,
            'message' => $issue->message,
            'service' => $issue->service,
            'environment' => $issue->environment,
            'task' => $issue->subject,
            'assignee' => $issue->assignee,
            'lastSeen' => $issue->last_seen_at?->toIso8601String(),
            'firstRecorded' => $issue->first_recorded_at?->toIso8601String(),
            'snoozedUntil' => $issue->snoozed_until?->toIso8601String(),
            'resolvedAt' => $issue->resolved_at?->toIso8601String(),
            'resolvedIn' => $issue->resolved_in_release,
        ];
    }

    /** The signed-in user, as the history should name them. */
    private static function user(Request $request): ?string
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        foreach (['email', 'name'] as $attribute) {
            $value = data_get($user, $attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $id = $user->getAuthIdentifier();

        return is_scalar($id) ? 'user #'.$id : null;
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** A snooze with no end is a day; an unparseable one is not an error worth a 422. */
    private static function until(Request $request): Carbon
    {
        $until = self::text($request, 'until');

        if ($until === null) {
            return Carbon::now()->addDay();
        }

        try {
            return Carbon::parse('+'.ltrim($until, '+'));
        } catch (\Throwable) {
            return Carbon::now()->addDay();
        }
    }
}
