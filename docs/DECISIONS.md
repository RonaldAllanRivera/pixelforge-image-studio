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

Seen live on 2026-11-17: during a local test run the webhook tunnel was down for about half an hour, so every webhook for two generations failed (HTTP 530, retried six times each). Polling still recorded both correctly: the success was charged once, the failure cost nothing, and the balance still matched its ledger.

## Jobs run on Laravel Cloud's managed queue

The app scales to zero when nobody is using it, and queued jobs don't wake a sleeping app. A managed queue has its own workers, which wake when a job arrives and cost nothing while idle, so a generation keeps moving after the user closes the tab. Our jobs fit its limits: delayed dispatches wait at most 60 seconds (the limit is 15 minutes), every job finishes far inside the 90-second Flex limit, and every job is safe to run twice, which matters because SQS may deliver a job more than once. Webhooks don't need the queue at all (see above). Not chosen: a `queue:work` process on the app, which stops when the app sleeps, and an always-on worker, which costs money around the clock.

## Every amount is formatted on the server

The browser gets money only as display strings ("$0.0722", or "$0.072206250" on the detail page), made by `Nanodollars::format()` in API resources and the quote endpoint. A maximum ("never more than") is formatted rounding up (`formatAtMost()`), so the four-decimal figure is never below the real cap. JavaScript numbers are floats, so doing money math in the browser could show a price that differs from the one we charge. The prompt counter in the browser counts characters with `Array.from()` (code points, like `mb_strlen`), but that's only for display: the server validates and prices the prompt.

## Live status: polling, only while something is running

The Studio and the detail page refresh their generation data every 2 seconds with Inertia's `usePoll`, reloading only that one prop, and only while a generation on the page isn't final. Once everything is final, polling stops. So an idle tab costs nothing and lets the app scale to zero. A generation with no news for 15 minutes counts as stalled: by then polling and submit retries have given up, and only the hourly reconcile checks it, so the page stops asking too. Each poll waits for the previous answer, so answers can't arrive out of order. Not chosen: WebSockets (Reverb), which would push updates instantly but cost money on Laravel Cloud and keep the app awake.

## The quote's token counts are stored

When a generation is created, the token counts behind its estimate and its maximum are saved with it (`quoted_usage`). The detail page can then show estimated vs actual tokens per type, which is the honest answer to "why did this cost less than estimated?" (usually reasoning). Recomputing them later from today's price sheet could give different numbers if Pixelforge changed its token rules.

## Webhooks run outside the session stack

The webhook route is loaded outside the `web` middleware group: no session, cookies or CSRF token. Pixelforge signs each delivery instead. Inside the `web` group every delivery started a session, which in production is a database row per webhook.

## Sign-up and login only

The starter kit's email verification, two-factor login and passkeys were left out. They don't serve this app, and email verification would lock out anyone testing a deployment without a mail service.

## Credits are held at the maximum, and settled where status changes

Submitting a generation holds its maximum charge from the user's balance, inside the same transaction that creates it, with the user's row locked. When the generation becomes final, `ApplyGenerationSnapshot` releases the hold and, on success, takes the actual charge, in the same locked transaction that records the status and cost. So several images running at once can never spend more than the user has, and money still changes in one place. A generation made before balances existed has no hold, and settles without touching credits.

## A balance column, an append-only ledger, and two database guards

`users.balance_nano` is the available balance, so checking "can they afford it" is one locked row, not a sum. Every change to it is also an entry in `credit_transactions` (purchase, hold, release, charge), written by one class (`CreditLedger`) in the same transaction, so a balance always equals the sum of its entries and every cent is explained. The database refuses a negative balance (a `CHECK` constraint) and a second hold, release or charge for one generation (a unique index on `generation_id, type`), whatever the code does.

## The server decides whether you can afford it

The quote endpoint says, for each quality, whether the balance covers the maximum, compared in whole nanodollars on the server. The form only disables Generate and says "Not enough credits"; `CreateGeneration` checks again under the lock, and that check is the rule. The balance is one of the price's inputs, so a purchase or a settled image re-asks the server without the user touching the form.

## Balances round down, holds round up

A balance is shown to 4 decimals rounded down (`Nanodollars::formatRoundedDown()`), so the page never shows more than the user has. Rounding half up could show "$0.0220" for a balance of $0.021975 next to a maximum of "$0.0220", and then refuse. A hold and its release are the generation's maximum, so they're shown rounded up (`formatAtMost()`), exactly like the "never more than" the user was quoted: one amount never appears as two different figures. The Credits page also shows the exact 9-decimal balance, and each entry's exact amount on hover.

## Deleting an account while an image settles

`CreateGeneration` locks the user, then writes a new generation; settling locks an existing generation, then updates the user. Deleting an account takes the locks in the opposite order (the user, then their generations and ledger rows), so if someone deletes their account at the very moment one of their images settles, Postgres detects a deadlock and aborts one of the two. Both outcomes are safe: an aborted webhook gets a 500 and Pixelforge retries it, and an aborted delete shows an error and can be tried again.

## The report counts days the way Pixelforge does

The admin report groups our **succeeded** generations by the UTC day of Pixelforge's own `completed_at`, because that's how `GET /v1/usage` counts them (checked live: failed generations aren't counted there). Each day is compared on the number of generations, all four token counts and the cost, to the nanodollar, and anything that differs is listed in plain words. Days with nothing are listed with zeros on both sides, as Pixelforge does.

## Emails are masked on the report

The demo admin login will be public in this README, so anyone could open the report. It shows each user's name and a masked email (`al•••@gmail.com`): enough to tell accounts apart, not enough to contact anyone.

## Pixelforge's usage is fetched live, not saved

The report asks `GET /v1/usage` for exactly the range on screen, once per page load, as a deferred prop: our numbers show at once, Pixelforge's a moment later. If Pixelforge can't be reached or answers something unexpected, the report says so and still shows our numbers. Nothing is cached or scheduled, so a reload always compares fresh, and no extra scheduled task wakes the app. The comparison is limited to 5 a minute across all admins: Pixelforge allows 60 requests a minute per account and the jobs use up to 50, so a public demo admin reloading the report can never hold up anyone's images.

## Why `GET /v1/generations` isn't used to reconcile

Checked live, it lists generations from every API key on the account, not just ours. The local and production keys share an account, so it can't tell whether a generation belongs to this app. `GET /v1/usage` is per key, which is what the report needs.

## Browser tests

The screens are tested in a real Chromium with Pest's browser plugin (Playwright), written before the screens: a new account at $0 is blocked, buying $5 in the dialog keeps the typed prompt and turns Generate on, the balance drops by the actual charge when an image finishes, and the admin report shows each day checked against a faked `/v1/usage`. They run in-process against the test database, with Pixelforge faked like every other test, and at phone width too.
