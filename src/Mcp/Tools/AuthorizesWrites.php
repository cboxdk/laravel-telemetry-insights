<?php

declare(strict_types=1);

namespace Cbox\TelemetryInsights\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * The one rule for every tool that changes something: the dashboard's
 * `manageTelemetryUi` ability, checked for the transport's user. With no
 * user, only the local stdio server is trusted — it runs under artisan,
 * for someone with a shell on the box anyway. An HTTP request with no user
 * is refused, whatever middleware a host forgot.
 */
trait AuthorizesWrites
{
    protected function refuseUnlessAllowed(Request $request, string $verb): ?Response
    {
        $user = $request->user();

        if ($user instanceof Authenticatable) {
            return Gate::forUser($user)->allows('manageTelemetryUi')
                ? null
                : Response::error('Not authorized to '.$verb.': that takes the manageTelemetryUi ability.');
        }

        return app()->runningInConsole()
            ? null
            : Response::error('Not authorized to '.$verb.': this transport has no authenticated user.');
    }
}
