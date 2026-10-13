<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Console;

use Cbox\TelemetryInsights\Models\ProfilingRun;
use Cbox\TelemetryInsights\Profiling\DirectiveRequest;
use Cbox\TelemetryInsights\Profiling\Directives;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Ask a monitored app to keep — and optionally profile — one slice of its
 * traffic for a while: "watch user 42 for two hours, with a profile".
 */
final class WatchCommand extends Command
{
    public const NAME = 'telemetry-insights:watch';

    protected $signature = self::NAME
        .' {--route= : a route name or URI pattern (* allowed)}'
        .' {--path= : a request path pattern, no leading slash}'
        .' {--user= : a user id}'
        .' {--context= : a context dimension, as key=value (e.g. tenant.id=7)}'
        .' {--job= : a job class pattern}'
        .' {--minutes=60 : how long it lasts}'
        .' {--profile : CPU-profile the matching work too}'
        .' {--reason= : why, shown with the directive}'
        .' {--service= : which monitored service (default: this app)}'
        .' {--by= : who is asking}';

    protected $description = 'Keep (and optionally profile) one slice of a monitored app\'s traffic for a while.';

    public function handle(Directives $directives): int
    {
        try {
            $request = $this->request();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $run = $directives->place($request, ProfilingRun::ADHOC);

        if ($run->status !== ProfilingRun::PLACED) {
            $this->error('Not placed: '.$run->error);

            return self::FAILURE;
        }

        $this->info('Watching '.$request->describe().' (directive '.$run->directive_id.', until '.$run->expires_at?->format('Y-m-d H:i').').');

        return self::SUCCESS;
    }

    private function request(): DirectiveRequest
    {
        $given = array_filter([
            'route' => $this->text('route'),
            'path' => $this->text('path'),
            'user' => $this->text('user'),
            'context' => $this->text('context'),
            'job' => $this->text('job'),
        ], static fn (?string $v): bool => $v !== null);

        if (count($given) !== 1) {
            throw new InvalidArgumentException('Give exactly one of --route, --path, --user, --context or --job.');
        }

        $match = '';
        $value = '';

        foreach ($given as $match => $value) {
            break;
        }

        $key = null;

        if ($match === 'context') {
            [$key, $value] = array_pad(explode('=', $value, 2), 2, '');
        }

        $minutes = $this->option('minutes');
        $by = $this->text('by');

        return new DirectiveRequest(
            match: $match,
            value: $value,
            minutes: is_numeric($minutes) ? (int) $minutes : 60,
            profile: (bool) $this->option('profile'),
            reason: $this->text('reason'),
            createdBy: $by !== null ? 'insights ('.$by.')' : 'insights',
            key: $key,
            service: $this->text('service'),
        );
    }

    private function text(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
