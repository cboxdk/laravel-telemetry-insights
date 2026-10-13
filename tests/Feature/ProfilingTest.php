<?php

declare(strict_types=1);

use Cbox\Telemetry\Http\Controllers\DirectivesController;
use Cbox\Telemetry\TelemetryManager;
use Cbox\Telemetry\TelemetryServiceProvider;
use Cbox\TelemetryInsights\Mcp\Tools\ProfilingRotationTool;
use Cbox\TelemetryInsights\Mcp\Tools\RequestDirectiveTool;
use Cbox\TelemetryInsights\Models\ProfilingRun;
use Cbox\TelemetryInsights\Profiling\DirectiveRequest;
use Cbox\TelemetryInsights\Profiling\Directives;
use Cbox\TelemetryInsights\Profiling\LocalDirectives;
use Cbox\TelemetryInsights\Profiling\ProfilingRotation;
use Cbox\TelemetryInsights\Profiling\SignedHttpDirectives;
use Cbox\TelemetryInsights\Ui\Panels\ProfilingTable;
use Cbox\TelemetryUi\Facades\TelemetryUi;
use Cbox\TelemetryUi\Http\Api\RequestScope;
use Cbox\TelemetryUi\Mcp\Servers\TelemetryServer;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

/**
 * Directives: asking a monitored app to keep — and profile — one slice of
 * its traffic for a while. The rotation does it for the costliest routes,
 * in turn; a person or an agent does it ad hoc.
 */
beforeEach(function (): void {
    config()->set('telemetry-insights.profiling.targets', [
        'shop' => ['url' => 'https://shop.test', 'secret' => 's3cret'],
    ]);
});

/**
 * The shop app's directive endpoint, accepting everything.
 */
function directiveEndpoint(int $status = 201): void
{
    Http::fake([
        'shop.test/telemetry/directives' => static function (Request $request) use ($status) {
            $data = $request->data();

            return Http::response($status === 201
                ? ['directive' => ['id' => 'dir-'.md5((string) json_encode($data)), 'expires_at' => time() + 60 * (int) ($data['minutes'] ?? 60)] + $data]
                : ['error' => 'Invalid signature.'], $status);
        },
    ]);
}

/**
 * Seconds spent per route over the window, as Prometheus answers
 * `sum by (service_name, http_route) (increase(…_sum[…]))`.
 *
 * @param  array<string, float>  $routes
 */
function routeTime(array $routes): void
{
    $series = [];

    foreach ($routes as $route => $seconds) {
        $series[] = ['labels' => ['service_name' => 'shop', 'http_route' => $route], 'value' => $seconds];
    }

    Http::fake(['prometheus.test:9090/api/v1/query*' => Http::response(promVector($series))]);
}

it('signs the request the way laravel-telemetry checks it', function (): void {
    directiveEndpoint();

    $run = app(Directives::class)->place(
        new DirectiveRequest('user', '42', minutes: 120, profile: true, reason: 'ticket 881', service: 'shop'),
        ProfilingRun::ADHOC,
    );

    expect($run->status)->toBe(ProfilingRun::PLACED)
        ->and($run->directive_id)->toStartWith('dir-')
        ->and($run->expires_at?->isFuture())->toBeTrue();

    Http::assertSent(static function (Request $request): bool {
        $timestamp = $request->header('X-Telemetry-Timestamp')[0] ?? '';

        return $request->url() === 'https://shop.test/telemetry/directives'
            && abs(time() - (int) $timestamp) < 5
            && $request->header('X-Telemetry-Signature')[0] === SignedHttpDirectives::sign('s3cret', $timestamp, 'POST', '', $request->body())
            && $request->data() === ['match' => 'user', 'value' => '42', 'minutes' => 120, 'profile' => true, 'reason' => 'ticket 881', 'created_by' => 'insights'];
    });
});

it('records a refusal rather than throwing, with the reason', function (): void {
    directiveEndpoint(401);

    $run = app(Directives::class)->place(new DirectiveRequest('route', 'checkout', service: 'shop'), ProfilingRun::ADHOC);

    expect($run->status)->toBe(ProfilingRun::FAILED)
        ->and($run->error)->toContain('(401): Invalid signature.');
});

it('says what to configure when there is no way to reach a service', function (): void {
    $run = app(Directives::class)->place(new DirectiveRequest('route', 'checkout', service: 'billing'), ProfilingRun::ADHOC);

    expect($run->status)->toBe(ProfilingRun::FAILED)
        ->and($run->error)->toContain('add it under telemetry-insights.profiling.targets');
});

it('goes in-process when this app runs laravel-telemetry with directives', function (): void {
    // Not running here until its provider is registered.
    expect(LocalDirectives::available())->toBeFalse();

    $this->app->register(TelemetryServiceProvider::class);
    expect(LocalDirectives::available())->toBeTrue();

    Http::fake();
    config()->set('telemetry-insights.profiling.targets', []);

    $run = app(Directives::class)->place(
        new DirectiveRequest('route', '/checkout', minutes: 15, profile: true, reason: 'in-process'),
        ProfilingRun::ADHOC,
    );

    $store = app(TelemetryManager::class)->directives();
    $placed = collect($store->all())->firstWhere('id', $run->directive_id);

    expect($run->status)->toBe(ProfilingRun::PLACED)
        ->and($placed)->not->toBeNull()
        ->and($placed?->match)->toBe('route')
        ->and($placed?->value)->toBe('/checkout')
        ->and($placed?->profile)->toBeTrue()
        ->and($placed?->createdBy)->toBe('insights');

    // Nothing went over HTTP.
    Http::assertNothingSent();
});

it('refuses a directive that cannot match anything', function (string $match, string $value, ?string $key): void {
    new DirectiveRequest($match, $value, key: $key);
})->with([
    'unknown match' => ['tenant', '7', null],
    'empty value' => ['route', ' ', null],
    'context without key' => ['context', '7', null],
])->throws(InvalidArgumentException::class);

it('profiles the costliest routes, then moves on to the next ones', function (): void {
    config()->set('telemetry-insights.profiling.rotation.routes', 2);
    directiveEndpoint();
    routeTime(['/checkout' => 900.0, '/search' => 400.0, '/' => 300.0, '/health' => 1.0]);

    $runs = app(ProfilingRotation::class)->rotate(new RequestScope(period: '24h'));

    expect(array_map(static fn (ProfilingRun $r): string => $r->value, $runs))->toBe(['/checkout', '/search'])
        ->and($runs[0]->source)->toBe(ProfilingRun::ROTATION)
        ->and($runs[0]->profile)->toBeTrue()
        ->and($runs[0]->minutes)->toBe(15)
        ->and($runs[0]->impact)->toBe(900.0)
        ->and($runs[0]->reason)->toContain('profiling rotation: #1 by impact, 900s total');

    Http::assertSent(static fn (Request $r): bool => ($r->data()['match'] ?? null) === 'route' && ($r->data()['created_by'] ?? null) === 'insights');

    // In their cooldown: the next pass takes the next two.
    $next = app(ProfilingRotation::class)->rotate(new RequestScope(period: '24h'));

    expect(array_map(static fn (ProfilingRun $r): string => $r->value, $next))->toBe(['/', '/health']);
});

it('ranks by p95 times request rate when told to', function (): void {
    config()->set('telemetry-insights.profiling.rotation.impact', 'p95_rate');

    Http::fake(['prometheus.test:9090/api/v1/query*' => static function (Request $request) {
        $query = (string) ($request->data()['query'] ?? '');
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);
        $query = is_string($params['query'] ?? null) ? $params['query'] : $query;

        return Http::response(promVector(str_contains($query, 'histogram_quantile')
            ? [['labels' => ['service_name' => 'shop', 'http_route' => '/slow-rare'], 'value' => 3.0],
                ['labels' => ['service_name' => 'shop', 'http_route' => '/busy'], 'value' => 0.4]]
            : [['labels' => ['service_name' => 'shop', 'http_route' => '/slow-rare'], 'value' => 0.01],
                ['labels' => ['service_name' => 'shop', 'http_route' => '/busy'], 'value' => 20.0]]));
    }]);

    $candidates = app(ProfilingRotation::class)->candidates(new RequestScope(period: '24h'));

    expect($candidates[0]->route)->toBe('/busy')
        ->and($candidates[0]->label)->toBe('400ms p95 × 20 req/s');
});

it('rotates from the console only when enabled, and lists what it did', function (): void {
    directiveEndpoint();
    routeTime(['/checkout' => 900.0]);

    $this->artisan('telemetry-insights:profiling')->expectsOutputToContain('rotation is off')->assertSuccessful();
    $this->artisan('telemetry-insights:profiling', ['--dry-run' => true])->expectsOutputToContain('/checkout')->assertSuccessful();

    config()->set('telemetry-insights.profiling.rotation.enabled', true);

    $this->artisan('telemetry-insights:profiling')->expectsOutputToContain('Profiling /checkout on shop for 15m')->assertSuccessful();
    $this->artisan('telemetry-insights:profiling', ['--list' => true])->expectsOutputToContain('route /checkout')->assertSuccessful();
});

it('watches one user ad hoc from the console', function (): void {
    directiveEndpoint();

    $this->artisan('telemetry-insights:watch', ['--user' => '42', '--minutes' => 120, '--profile' => true, '--service' => 'shop', '--by' => 'sylvester'])
        ->expectsOutputToContain('Watching user 42 for 120m with a CPU profile')
        ->assertSuccessful();

    Http::assertSent(static fn (Request $r): bool => ($r->data()['created_by'] ?? null) === 'insights (sylvester)' && $r->data()['profile'] === true);

    $this->artisan('telemetry-insights:watch', ['--user' => '42', '--route' => 'x'])->assertFailed();
    $this->artisan('telemetry-insights:watch', ['--context' => 'tenant.id=7', '--service' => 'shop'])->assertSuccessful();

    expect(ProfilingRun::query()->latest('id')->first()?->target())->toBe('context tenant.id=7');
});

it('shows the rotation to an agent, read-only', function (): void {
    directiveEndpoint();
    routeTime(['/checkout' => 900.0, '/search' => 400.0]);
    config()->set('telemetry-insights.profiling.rotation.routes', 1);
    app(ProfilingRotation::class)->rotate(new RequestScope(period: '24h'));

    TelemetryServer::tool(ProfilingRotationTool::class, ['next' => true])
        ->assertOk()
        ->assertSee('"directive":"route /checkout"')
        ->assertSee('"state":"active"')
        ->assertSee('"profile":true')
        ->assertSee('"next":[{"service":"shop","route":"/search"');
});

it('lets an agent ask for a directive, attributed and gated', function (): void {
    directiveEndpoint();
    $user = new User;
    $user->forceFill(['id' => 7, 'email' => 'owner@example.com']);

    TelemetryServer::actingAs($user)->tool(RequestDirectiveTool::class, [
        'match' => 'user', 'value' => '42', 'minutes' => 120, 'profile' => true, 'service' => 'shop', 'reason' => 'ticket 881',
    ])->assertOk()->assertSee('Watching user 42 for 120m with a CPU profile on shop');

    Http::assertSent(static fn (Request $r): bool => ($r->data()['created_by'] ?? null) === 'insights (agent: owner@example.com)');

    Gate::define('manageTelemetryUi', static fn (?object $user = null): bool => false);

    TelemetryServer::actingAs($user)->tool(RequestDirectiveTool::class, ['match' => 'user', 'value' => '42', 'service' => 'shop'])
        ->assertHasErrors(['manageTelemetryUi']);
});

it('keeps a locked viewer to their own services', function (): void {
    directiveEndpoint();
    config()->set('telemetry-ui.scope.lock', ['services' => ['billing']]);

    TelemetryServer::tool(RequestDirectiveTool::class, ['match' => 'user', 'value' => '42', 'service' => 'shop'])
        ->assertHasErrors(['that service']);
    TelemetryServer::tool(RequestDirectiveTool::class, ['match' => 'user', 'value' => '42'])
        ->assertHasErrors(['that service']);

    expect(ProfilingRun::query()->count())->toBe(0);
});

it('shows what was profiled on the dashboard', function (): void {
    directiveEndpoint();
    app(Directives::class)->place(new DirectiveRequest('route', 'checkout', minutes: 15, profile: true, service: 'shop'), ProfilingRun::ROTATION);

    expect(TelemetryUi::panels('profiling'))->toContain(ProfilingTable::class);

    $rows = (new ProfilingTable(new RequestScope(period: '24h')))->data()['rows'];

    expect($rows[0]['directive']['v'])->toBe('route checkout')
        ->and($rows[0]['state']['v'])->toBe('Active')
        ->and($rows[0]['source']['v'])->toBe('Rotation');
});

it('signs exactly as laravel-telemetry\'s DirectivesController verifies', function (): void {
    // The controller's check, restated from cboxdk/laravel-telemetry
    // src/Http/Controllers/DirectivesController.php: a digit-only timestamp
    // within 300s; hash_equals(signature($secret, $timestamp, $method, $id,
    // $request->getContent()), $signature), where the signed string is
    // "<timestamp>.<METHOD>.<id>.<body>" and <id> is empty for POST; and
    // each signature accepted once.
    $seen = [];
    $verifies = static function (Request $request, string $secret) use (&$seen): bool {
        $timestamp = $request->header('X-Telemetry-Timestamp')[0] ?? '';
        $signature = $request->header('X-Telemetry-Signature')[0] ?? '';
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.strtoupper($request->method()).'..'.$request->body(), $secret);

        if (isset($seen[$signature])) {
            return false;
        }

        $seen[$signature] = true;

        return ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= 300
            && hash_equals($expected, $signature);
    };

    Http::fake(['shop.test/telemetry/directives' => static fn (Request $request) => $verifies($request, 's3cret')
        ? Http::response(['directive' => ['id' => '01JTEST', 'match' => 'route', 'key' => null, 'value' => '/', 'profile' => true,
            'expires_at' => time() + 900, 'reason' => null, 'created_by' => 'insights']], 201)
        : Http::response(['error' => 'Invalid signature.'], 401)]);

    $run = app(Directives::class)->place(new DirectiveRequest('route', '/', minutes: 15, profile: true, service: 'shop'), ProfilingRun::ROTATION);

    expect($run->status)->toBe(ProfilingRun::PLACED)
        ->and($run->directive_id)->toBe('01JTEST')
        ->and($run->value)->toBe('/');

    // A wrong secret is what the controller would refuse.
    config()->set('telemetry-insights.profiling.targets.shop.secret', 'other');
    $refused = app(Directives::class)->place(new DirectiveRequest('route', '/', service: 'shop'), ProfilingRun::ADHOC);

    expect($refused->status)->toBe(ProfilingRun::FAILED)
        ->and($refused->error)->toContain('(401)');
});

it('binds the method and directive id into the signature', function (): void {
    $post = SignedHttpDirectives::sign('s3cret', '1760000000', 'post', '', '{"a":1}');

    expect($post)->toBe('sha256='.hash_hmac('sha256', '1760000000.POST..{"a":1}', 's3cret'))
        ->and(SignedHttpDirectives::sign('s3cret', '1760000000', 'DELETE', '01JX', ''))
        ->toBe('sha256='.hash_hmac('sha256', '1760000000.DELETE.01JX.', 's3cret'))
        ->and(SignedHttpDirectives::sign('s3cret', '1760000000', 'GET', '', '{"a":1}'))->not->toBe($post);
});

it('signs byte-for-byte as the core controller does', function (): void {
    foreach ([['POST', '', '{"match":"route","value":"/"}'], ['GET', '', ''], ['DELETE', '01JX', '']] as [$method, $id, $body]) {
        expect(SignedHttpDirectives::sign('s3cret', '1760000000', $method, $id, $body))
            ->toBe(DirectivesController::signature('s3cret', '1760000000', $method, $id, $body));
    }
});

it('cools a refused route down for a shorter while, then tries it again', function (): void {
    config()->set('telemetry-insights.profiling.rotation.routes', 1);
    directiveEndpoint(401);
    routeTime(['/checkout' => 900.0, '/search' => 400.0]);

    $first = app(ProfilingRotation::class)->rotate(new RequestScope(period: '24h'));
    $second = app(ProfilingRotation::class)->rotate(new RequestScope(period: '24h'));

    expect($first[0]->status)->toBe(ProfilingRun::FAILED)
        ->and($first[0]->value)->toBe('/checkout')
        // Not the refused route again on the very next pass.
        ->and($second[0]->value)->toBe('/search');

    $this->travel(2)->hours();

    expect(app(ProfilingRotation::class)->rotate(new RequestScope(period: '24h'))[0]->value)->toBe('/checkout');
});

it('drops routes on a service nothing can reach before taking the top N', function (): void {
    config()->set('telemetry-insights.profiling.rotation.routes', 1);
    directiveEndpoint();

    Http::fake(['prometheus.test:9090/api/v1/query*' => Http::response(promVector([
        ['labels' => ['service_name' => 'billing', 'http_route' => '/invoices'], 'value' => 5000.0],
        ['labels' => ['service_name' => 'shop', 'http_route' => '/checkout'], 'value' => 900.0],
    ]))]);

    $runs = app(ProfilingRotation::class)->rotate(new RequestScope(period: '24h'));

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->service)->toBe('shop')
        ->and($runs[0]->status)->toBe(ProfilingRun::PLACED);
});

it('refuses directives to a viewer locked to some environments, and shows them no runs', function (): void {
    directiveEndpoint();
    app(Directives::class)->place(new DirectiveRequest('route', '/checkout', service: 'shop'), ProfilingRun::ADHOC);

    config()->set('telemetry-ui.scope.lock', ['environments' => ['production']]);

    TelemetryServer::tool(RequestDirectiveTool::class, ['match' => 'user', 'value' => '42', 'service' => 'shop'])
        ->assertHasErrors(['limited to some environments']);
    TelemetryServer::tool(ProfilingRotationTool::class)->assertOk()->assertDontSee('/checkout');

    expect((new ProfilingTable(new RequestScope(period: '24h')))->data()['rows'])->toBe([])
        ->and(ProfilingRun::query()->count())->toBe(1);
});
