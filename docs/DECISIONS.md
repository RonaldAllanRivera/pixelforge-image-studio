# Decisions

The brief leaves some things open on purpose. These are the choices made, why, and what wasn't chosen.

## Money is stored as whole nanodollars

Every amount is an integer number of nanodollars (1 USD = 1,000,000,000) in a `bigint` column. Pixelforge reports money to 9 decimal places, so nanodollars hold its numbers exactly, and integer arithmetic can't drift. Rejected: floats (inexact), and decimal columns with decimal math everywhere (correct, but every calculation then needs a library call).

## A provider cost is never rounded

Pixelforge publishes rates in USD per 1M tokens with 2 decimals. $1 per 1M tokens is exactly 1,000 nanodollars per token, so any rate with up to 3 decimals makes every token cost a whole number of nanodollars. Rates are parsed with `brick/math`, and a rate with more decimals is refused instead of rounded, because that would need a design change. So per-generation costs add up to Pixelforge's daily `/v1/usage` totals exactly.

## The only rounding: the 25% markup, rounded up

The user pays cost × 1.25, rounded up to the next nanodollar, in `Nanodollars::addMarkup()`, so rounding never loses money. With today's rates every cost is a multiple of 4 nanodollars, so the charge is exact and the rounding never actually triggers. The markup lives in `config/pricing.php`.

## Estimate and maximum

Before a generation runs, image and output tokens are exact, but text and reasoning tokens are not. The **estimate** uses Pixelforge's own text estimate (`ceil(characters / 4)`) and the middle of the quality's reasoning range. The **maximum** uses the text estimate + 15%, rounded up, and the top of the reasoning range. The maximum is the most a user can ever pay, which is what a credit balance must cover. Rejected: showing only one number, which is either wrong or alarming.

## Characters are Unicode characters

Prompt length is counted with `mb_strlen`, so an emoji counts as one character, as Pixelforge counts it. The estimate is computed on the server: JavaScript's `string.length` counts UTF-16 units and gets emoji wrong.

## Tests run on Postgres

Tests use a separate `pixelforge_testing` Postgres database, the same engine as production, because the money code relies on Postgres row locks and unique indexes that SQLite doesn't behave the same way with. Any HTTP call in a test must be faked, so the suite can never reach Pixelforge.

## Errors are grouped by what to do next

The client turns every Pixelforge error into a typed exception, chosen by `error.code` rather than the HTTP status, because a 429 can mean "wait a second" (rate limit), "wait for a free slot" (concurrency) or "come back tomorrow" (daily limit). Each one belongs to a family the caller acts on: `RetryLater`, `Rejected` or `OutcomeUnknown`. Not chosen: a single generic error type, which would push the "can I retry?" question into every caller.

## An unknown outcome is never a failure

A timeout, a 5xx or an unreadable answer doesn't tell us whether Pixelforge created the generation. If we marked it failed, Pixelforge could still create and bill it while we charged the user nothing. So it stays pending and is retried with the same `Idempotency-Key`, which returns the existing generation if there is one. The request body is built only from stored fields, so a retry is byte-identical, as Pixelforge requires.

## Live prices for estimates, with a fallback

Estimates use Pixelforge's live `GET /v1/pricing`, kept for 10 minutes. If it can't be fetched, the published prices in `config/pixelforge.php` are used for a minute before trying again, so the estimate never hangs on a dead connection. This only affects estimates: a generation is always billed at the rates Pixelforge fixes on the generation itself.

## Fixtures come from the real API

Test fixtures are real responses captured with a local key: queued, succeeded and failed generations, every error body, and real webhook events. Signed image URLs are replaced with a stable example. Tests can't drift from what Pixelforge actually sends.

## One place where status and money change

The create response, polling and webhooks all report on the same generation, in any order and sometimes twice. They all go through `ApplyGenerationSnapshot`, which locks the generation's row and applies a report only if it moves the status forward (pending → queued → processing → succeeded or failed). A final status never changes again. So a duplicate webhook, a late `processing` event and a poll racing a webhook all end in the same state, and the cost is recorded once. HTTP calls happen before the transaction, never inside it, so the lock is held for milliseconds. Not chosen: deduplicating each path separately, which leaves the gaps between the paths open.

## A charge never exceeds the quoted maximum

The page promises the user they'll never pay more than the maximum shown before submitting. That can only be broken if Pixelforge changes its rates between our quote and the generation's creation. If it happens, the user pays the maximum and we absorb the difference (logged as a warning). The provider cost stays exact, so the admin report still matches Pixelforge.

## A report about a different generation is ignored

Once a generation is linked to a Pixelforge id, a report carrying another id is logged and ignored rather than applied. That guards against a lookup bug ever charging one generation for another.

## Submitting: wait, retry or fail, by error family

The submit job reads what to do from the error family. _Retry later_: release the job for the time Pixelforge asks (a free slot, a rate limit, or a 429 with a code we don't know yet), or back off 2, 4, 8 and 16 seconds when overloaded, then fail at no cost after the fifth _overloaded_ answer. Rate limits, slot waits and timeouts don't count toward that. _Rejected_: nothing was created, so fail it at no cost. _Unknown outcome_ (also a 408): keep it pending and retry with the same key. Before sending, the job counts our generations queued or processing, so it waits for a free slot instead of spending a request on a certain 429. A row with no news for 10 minutes doesn't count, because its webhooks went missing; it isn't running (even a `[slow]` generation finishes in 90 seconds). So stale rows can never block every new generation. Pixelforge's own limit still protects the account if this count is ever off. The job retries for 30 minutes (`retryUntil`, not a fixed number of tries, because waiting for a slot can take many releases), and backs off 10, 30 and 60 seconds after an unexpected error, so it never retries in a tight loop. While it waits it touches the row, so the reconcile command can see it still has a live job and won't start a second one. After 30 minutes, reconcile picks the generation up again.

## Why failing after an unknown outcome is still safe

If a first attempt timed out, Pixelforge may have created the generation. A later retry carries the same `Idempotency-Key`, and Pixelforge answers a known key with the existing generation (`Idempotent-Replayed: true`). Its docs say a replay doesn't count against the concurrency or daily limits, which implies the key is checked before those limits apply. So a retry that comes back _rejected_ means that key has no generation behind it, and failing locally can't lose a billed generation. The one case this assumption doesn't cover is an `overloaded` answer sent before the key is checked. The cost would be a single generation Pixelforge bills but the user isn't charged for, which the admin report would show as a mismatch.

One rejection is the opposite: `409 idempotency_key_reused` proves a generation already exists under this key. That generation is never failed for free. It stays pending and an error is logged for a person to look at.

## Generations outlive accounts

A generation is a billing record: Pixelforge billed it, and the admin report must keep matching Pixelforge's usage. If a user deletes their account (the starter kit allows it), their generations stay, with `user_id` set to null. Not chosen: cascading the delete, which would make the report's provider costs fall below Pixelforge's.

## Webhooks are applied inline, in the same transaction that stores them

The endpoint verifies the signature on the raw body, then inserts the event (unique by event id, `ON CONFLICT DO NOTHING`) and applies it in one transaction. A duplicate is detected by the insert and answered `204`. If applying fails, the insert rolls back too and the answer is `500`, so Pixelforge's retry gets a clean second try. An event can never be marked as seen without being applied. A webhook that arrives before the create response finds its generation by the UUID we sent as `metadata.local_id`, never by a numeric id that a database reset could reuse. The work is one short locked transaction, far under Pixelforge's 10-second limit, so it doesn't need a queue. Without one, status updates the moment the webhook lands, even while the queue worker is asleep. Not chosen: storing the event and applying it in a queued job, which needs extra handling for "stored but never applied".

## Polling is the safety net, webhooks are the fast path

Webhooks aren't guaranteed, so every accepted generation is also polled: after 20, 30 and 45 seconds, then every minute, for 16 polls (about 15 minutes) counted from the first poll, not from creation, because a generation can wait a long time for a free slot first. Polls only run while something is in flight. An hourly `generations:reconcile` command catches anything older: it polls accepted generations that have been quiet for 2 minutes and resubmits pending ones with their original `Idempotency-Key`. It runs hourly, not every minute, because on Laravel Cloud each scheduled run wakes the sleeping app and database. A per-minute schedule would keep both awake around the clock, outside the free usage credit. Not chosen: a per-minute reconcile as the only safety net, which costs money and still reacts more slowly than per-generation polls.

## Sign-up and login only

The starter kit's email verification, two-factor login and passkeys were left out. They don't serve this app, and email verification would lock out anyone testing a deployment without a mail service.
