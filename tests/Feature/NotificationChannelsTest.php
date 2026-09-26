<?php

declare(strict_types=1);

use Cbox\TelemetryInsights\Notify\Notification;
use Cbox\TelemetryInsights\Notify\Notifier;
use Cbox\TelemetryInsights\Notify\Severity;
use Cbox\TelemetryInsights\Notify\SlackChannel;
use Cbox\TelemetryInsights\Notify\WebhookChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function finding(): Notification
{
    return new Notification(
        title: 'Incident: redis cache-1:6379',
        body: '100% of the affected groups called this cache.',
        severity: Severity::Critical,
        facts: ['Error groups' => '9', 'Occurrences' => '412'],
        url: 'https://app.test/telemetry-ui/p/incidents',
    );
}

it('posts Block Kit to Slack with the severity rail, the facts and a link', function (): void {
    config()->set('telemetry-insights.notify.slack.webhook_url', 'https://hooks.slack.test/abc');
    Http::fake(['hooks.slack.test/*' => Http::response('ok')]);

    expect(app(SlackChannel::class)->send(finding()))->toBeTrue();

    Http::assertSent(function ($request): bool {
        $body = $request->data();
        // json_encode escapes slashes by default, which would hide the URL.
        $rendered = (string) json_encode($body, JSON_UNESCAPED_SLASHES);

        return $body['text'] === 'Incident: redis cache-1:6379'
            && str_contains($rendered, Severity::Critical->color())
            && str_contains($rendered, 'Error groups')
            && str_contains($rendered, 'https://app.test/telemetry-ui/p/incidents');
    });
});

it('stays quiet, rather than erroring, when no Slack webhook is configured', function (): void {
    config()->set('telemetry-insights.notify.slack.webhook_url', null);
    Http::fake();

    expect(app(SlackChannel::class)->send(finding()))->toBeFalse();
    Http::assertNothingSent();
});

it('reports a rejected Slack delivery as failed without logging the webhook URL', function (): void {
    config()->set('telemetry-insights.notify.slack.webhook_url', 'https://hooks.slack.test/secret-token');
    Http::fake(['hooks.slack.test/*' => Http::response('invalid_payload', 400)]);

    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    expect(app(SlackChannel::class)->send(finding()))->toBeFalse()
        ->and(implode(' ', $logged))->toContain('Slack rejected')
        ->not->toContain('secret-token');
});

it('carries the assistant brief to a webhook, where there is room for it', function (): void {
    config()->set('telemetry-insights.notify.webhook.url', 'https://hooks.test/insights');
    Http::fake(['hooks.test/*' => Http::response('', 200)]);

    $notification = new Notification(
        title: 'Telemetry digest',
        body: '5 findings',
        brief: "# Telemetry findings\n\nSlow route /checkout",
    );

    expect(app(WebhookChannel::class)->send($notification))->toBeTrue();

    Http::assertSent(fn ($request): bool => str_contains((string) $request->data()['brief'], 'Slow route /checkout'));
});

it('falls back to the log so a fresh install alerts somewhere', function (): void {
    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = $message->message;
    });

    expect(app(Notifier::class)->send(finding()))->toBe(['log'])
        ->and(implode(' ', $logged))->toContain('Incident: redis cache-1:6379');
});

it('skips a channel nobody registered instead of failing the run', function (): void {
    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = $message->message;
    });

    expect(app(Notifier::class)->send(finding(), ['pager']))->toBe([])
        ->and(implode(' ', $logged))->toContain('unknown notification channel');
});

it('renders as plain text for a channel with no formatting', function (): void {
    $text = finding()->toText();

    expect($text)->toContain('Incident: redis cache-1:6379')
        ->toContain('Error groups: 9')
        ->toContain('https://app.test/telemetry-ui/p/incidents');
});
