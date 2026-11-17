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

## Sign-up and login only

The starter kit's email verification, two-factor login and passkeys were left out. They don't serve this app, and email verification would lock out anyone testing a deployment without a mail service.
