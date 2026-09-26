---
title: HTTP API
description: Read and change issues and incidents from your own screen
weight: 43
---

# HTTP API

The pages this package adds to the dashboard are read-only lists. When you
want buttons, build the screen in your own app and point it here.

Everything is mounted beside the dashboard's own API, under its path,
middleware and throttle — so the gate that guards the dashboard guards
these, and there is no second authorization to wire up.

```
GET  {path}/api/v2/insights/issues[?status=open]
POST {path}/api/v2/insights/issues/{fingerprint}
GET  {path}/api/v2/insights/incidents
POST {path}/api/v2/insights/incidents/{signature}
```

`{path}` is `telemetry-ui.path`, so by default
`/telemetry-ui/api/v2/insights/issues`.

## Authorization

Reading takes `viewTelemetryUi`, the dashboard's own ability. **Changing
anything takes `manageTelemetryUi`**, checked in the controller and never
trusted to the client — a viewer who can see an issue cannot resolve it.

A refusal is the dashboard's error shape:

```json
{"error": {"type": "forbidden", "message": "You are not authorized to change issues."}}
```

with `not_found` (404) and `invalid` (422) for the other two ways a request
can be wrong.

## Changing an issue

```http
POST /telemetry-ui/api/v2/insights/issues/aaaa00000001
Content-Type: application/json

{"action": "resolve", "release": "v2.4.1", "by": "sylvester"}
```

| `action` | Extra fields |
| --- | --- |
| `resolve` | `release`, `by` |
| `reopen` | — |
| `ignore` | — |
| `snooze` | `until` (e.g. `"3 days"`; a day when omitted) |
| `assign` | `to` |

The response carries the issue as the list returns it, with `status`
already the **effective** one — a lapsed snooze reads as open, here as
everywhere else.

## Changing an incident

```http
POST /telemetry-ui/api/v2/insights/incidents/abc123def456

{"action": "acknowledge", "by": "sylvester"}
```

`acknowledge`, `resolve` or `reopen`. Acknowledging is the point of
persisting incidents at all: it is how a team says *someone is on this*
without muting the errors underneath.

## Shapes

An issue:

```json
{
  "fingerprint": "aaaa00000001",
  "status": "open",
  "type": "RedisException",
  "message": "Connection refused",
  "service": "checkout",
  "assignee": null,
  "lastSeen": "2026-09-26T08:14:02+00:00",
  "firstRecorded": "2026-09-24T02:14:55+00:00",
  "snoozedUntil": null,
  "resolvedAt": null,
  "resolvedIn": null
}
```

An incident, whose `cause` is `null` when none was established:

```json
{
  "signature": "abc123def456",
  "status": "open",
  "title": "redis cache-1:6379 — 9 error groups affected",
  "onsetAt": "2026-09-24T02:14:00+00:00",
  "occurrences": 412,
  "groupCount": 9,
  "fingerprints": ["aaaa00000001"],
  "services": ["checkout"],
  "cause": {
    "kind": "dependency",
    "label": "redis cache-1:6379",
    "evidence": "100% of the affected groups called this cache, and the call failed in 9 of 9 traces inspected.",
    "confidence": "high",
    "traceId": "4458d523d54e5a50"
  },
  "acknowledgedAt": null,
  "acknowledgedBy": null,
  "resolvedAt": null
}
```

## Headless

These routes are part of the soft half: they come from the dashboard's
route group, so an app running `TELEMETRY_UI_ENABLED=false` has no HTTP
surface from this package either. Use the
[`IssueActions` service](../core-concepts/issues.md) or the artisan
commands there.
