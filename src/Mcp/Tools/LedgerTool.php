<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Mcp\Tools;

use Cbox\TelemetryInsights\Issues\IssueVisibility;
use Cbox\TelemetryInsights\Models\Issue;
use Cbox\TelemetryUi\Support\ScopeLock;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

/**
 * Shared base for the issue-ledger tools an agent reaches through the
 * dashboard's MCP server.
 *
 * Two promises every subclass keeps. The viewer's tenancy lock applies here
 * exactly as it does on the dashboard — a viewer locked to one service sees
 * that service's issues and no others, and a lock to nothing shows nothing.
 * And output is built for a reader that pays per token: absent means none,
 * so empty keys are dropped rather than sent.
 */
abstract class LedgerTool extends Tool
{
    protected const MAX_LIMIT = 100;

    public function __construct(protected readonly ScopeLock $lock) {}

    /**
     * Narrow a query to what the viewer may see. Fails closed: an issue with
     * no environment recorded (an error group) is hidden from a viewer whose
     * environments are locked, because it cannot be shown to be theirs.
     *
     * @param  Builder<Issue>  $query
     * @return Builder<Issue>
     */
    protected function visible(Builder $query): Builder
    {
        return (new IssueVisibility($this->lock))->apply($query);
    }

    /**
     * One issue by fingerprint, or by a unique prefix of one — fingerprints
     * are long and agents copy them from earlier output. Ambiguous is null.
     */
    protected function find(string $fingerprint): ?Issue
    {
        $fingerprint = trim($fingerprint);

        if ($fingerprint === '' || preg_match('/^[A-Za-z0-9]+$/', $fingerprint) !== 1) {
            return null;
        }

        $exact = $this->visible(Issue::query())->where('fingerprint', $fingerprint)->first();

        if ($exact instanceof Issue) {
            return $exact;
        }

        $matches = $this->visible(Issue::query())->where('fingerprint', 'like', $fingerprint.'%')->limit(2)->get();

        $first = $matches->first();

        return $matches->count() === 1 && $first instanceof Issue ? $first : null;
    }

    protected function string(Request $request, string $key): ?string
    {
        $value = $request->get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function int(Request $request, string $key, int $default, int $min, int $max): int
    {
        $value = $request->get($key, $default);

        return min($max, max($min, is_numeric($value) ? (int) $value : $default));
    }

    /**
     * Who is asking, as the history should name them: an email or a name
     * when the user has one, else their id. Null on a transport with no
     * user (the local stdio server).
     */
    protected function actor(Request $request): ?string
    {
        $user = $request->user();

        if (! $user instanceof Authenticatable) {
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

    /**
     * Drop null, empty-string and empty-array values, recursively — an agent
     * pays for every key it reads, and an absent key says "none" for free.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected function compact(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $this->compact($value);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $out[$key] = $value;
        }

        return array_is_list($data) ? array_values($out) : $out;
    }

    protected static function time(?\DateTimeInterface $at): ?string
    {
        return $at?->format(DATE_ATOM);
    }
}
