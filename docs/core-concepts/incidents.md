---
title: Incidents
description: Folding a burst of unrelated errors back into the one thing that broke
weight: 23
---

# Incidents

## The problem

A cache node dies at 02:14. Your application does not report "the cache is
down". It reports:

- `RedisException: Connection refused` in the session handler
- `TypeError: Argument must be of type array, null given` where a cached
  array came back empty
- `RuntimeException: Session store not set on request`
- `ErrorException: Undefined array key "rate"` in a rate limiter

Four fingerprints, four places in the code, four alerts, none of which
mentions the cache. Whoever is on call spends the first twenty minutes
deciding which of four unrelated-looking bugs to chase.

They are one event. An incident is that event.

## How a burst is found

Groups are sorted by **onset** — the earliest occurrence of that fingerprint
in the window, not its latest noise — and split into runs that started
within `burst_seconds` of each other (120 by default).

A run needs at least `min_groups` members (3) to be an incident at all. One
error group firing is an error, not an incident; the whole value here is in
the coincidence.

## How the cause is found

Three explanations are tried, and the one that explains most wins.

### A failing shared dependency

For each group in the burst, one sample trace is fetched and its
**downstreams** read: every database, cache, queue and HTTP peer the request
talked to. A dependency is identified by the instance —
`db.system.name` plus `server.address` — never by the statement or the cache
key, which differ on every call.

A dependency is named as the cause when it is present in at least
`shared_ratio` of the inspected traces (60%) **and the call to it failed**.

That second condition is the one that matters. The primary database is in
every trace of every burst; sharing a dependency is not evidence. Sharing a
*failing* one is.

Once the dashboard has discovered an exporter watching that dependency,
the incident asks it what it saw. That is the step past every other tool:
not "your calls to the cache failed", but

> 100% of the affected groups called this cache, and the call failed in 9
> of 9 traces inspected. **Its own exporter agrees: memory used was 3.97
> against a usual 1.20, evictions was 4200 against a usual 0.**

Corroboration from the dependency itself is the strongest evidence
available, so a cause backed by it is reported with high confidence rather
than hedged. When no exporter is discovered, or it saw nothing out of the
ordinary, the incident says only what the traces support. See
[infrastructure discovery](https://github.com/cboxdk/laravel-telemetry-ui/blob/main/docs/core-concepts/infrastructure.md).

### A recent change

The nearest deploy, migration or incident annotation at or before onset,
within `change_window_minutes`. Weaker — plenty of deploys are innocent —
but it is the first thing anyone asks.

### Host pressure

A host or runtime signal materially outside its normal band at onset. A
symptom more than a cause, so it only wins when nothing else explains the
burst.

## Confidence

Three coarse steps, never a percentage: the inputs are heuristics over
sampled telemetry, and "0.83" would imply a precision nobody has.

Every cause carries the sentence that produced it — *"100% of the affected
groups called this cache, and the call failed in 9 of 9 traces
inspected"* — because a verdict you cannot check is a verdict you cannot
trust.

## Identity

An incident's signature is derived from its cause and the hour it started,
not from the set of fingerprints in it. A burst picks up more groups as it
goes, and an id that changed every time a tenth error joined would file a
new incident on every pass. Without a cause there is nothing stabler than
the members, so those are used instead.

## Cost

One pass fetches at most `max_trace_probes` traces (20). Correlation is
read-only and fails open at every step: a backend that will not answer
leaves the incident without a cause rather than failing the run, because an
incident with no cause is still worth seeing.
