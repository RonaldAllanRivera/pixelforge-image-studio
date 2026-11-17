# Pixelforge Image Studio

<!-- Remove the status line below once Phase 10 is done. -->

> **Status: work in progress.** This README describes the design. Sections marked _(pending)_ are filled in as each part ships.

A Laravel, Vue and Inertia app where signed-in users generate images through the Pixelforge API and pay exactly what each generation cost, plus a 25% markup. Built for the PromptEdit Senior Product Engineer take-home assessment.

## Live app

_(pending)_

- URL: _(pending)_
- Logins: anyone can sign up as a normal user. A demo admin account for the usage report is listed here once deployed. The admin role can only read reports.

## What it does

- **Generate:** write a prompt, pick a size and quality, and add up to 4 reference image URLs. The price updates as you type: an estimate, and the most it can cost.
- **History:** every generation with its live status (queued, generating, succeeded or failed), the estimated and the actual cost, and the image.
- **Pay exactly once:** the actual cost is recorded when a generation finishes. Failed generations are free. A duplicate or late webhook can never charge twice.
- **Admin report** _(stretch goal, pending)_: charges, provider costs and profit per day and per user, checked against Pixelforge's `GET /v1/usage`.
- **Credit balances** _(stretch goal, pending)_: a fake "Buy credits" button, and generating is blocked when you can't afford the maximum price.

## Stack

Laravel, Vue 3, Inertia, TypeScript, Tailwind, PostgreSQL and Pest. Deployed on Laravel Cloud.

## How money works

**Units.** Every amount is stored as a whole number of **nanodollars** (1 USD = 1,000,000,000) in a `bigint` column. Pixelforge reports costs to 9 decimal places, so our numbers match theirs exactly. Decimal strings from the API are parsed with `brick/math`, never as floats.

**Provider cost** is `tokens × rate ÷ 1,000,000` for each token type, summed. It uses the `rates` stored on the generation itself, which Pixelforge fixes when the generation is created, never the current price list. A rate with up to 3 decimals makes every token cost a whole number of nanodollars, so the provider cost is never rounded. Per-generation costs therefore add up to Pixelforge's daily totals exactly.

**User charge** is the provider cost × 1.25, rounded up to the next nanodollar. This is the only rounding in the app, and it happens in one function. With today's rates it never triggers, because every provider cost is a multiple of 4 nanodollars.

**Three numbers per generation:**

|          | How it's worked out                                                                                     | Shown as         |
| -------- | ------------------------------------------------------------------------------------------------------- | ---------------- |
| Estimate | Text tokens `ceil(characters ÷ 4)`, exact image and output tokens, reasoning at the middle of its range | "Estimated"      |
| Maximum  | Text tokens + 15%, reasoning at the top of its range                                                    | "Up to"          |
| Actual   | Pixelforge's reported token usage × the generation's rates                                              | The final charge |

Characters are counted as Unicode code points on the server (`mb_strlen`), the way Pixelforge counts them. JavaScript's `string.length` miscounts emoji.

**Charged exactly once.** All status and money changes go through one action, which locks the generation row and only ever moves the status forward (`pending` → `queued` → `processing` → `succeeded` or `failed`). A final status never changes again. On top of that, webhook event ids are unique in the database, and so is the charge per generation.

**Failed generations cost nothing**, including those rejected before Pixelforge accepted them.

## How failures are handled

| Pixelforge does this                   | The app does this                                                                                              | The user sees                                                          |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| `503 overloaded`                       | Retries with the same `Idempotency-Key`, backing off, then gives up                                            | "Pixelforge is busy. You weren't charged."                             |
| `429` concurrency limit                | Waits for a free slot (3 generations at a time per account)                                                    | "Waiting for a free slot"                                              |
| `429` rate limit                       | Waits for `Retry-After`. All calls are throttled below the limit.                                              | Queued                                                                 |
| `429` daily limit                      | Marks it failed, free                                                                                          | "Daily limit reached, try again tomorrow (UTC)."                       |
| `400` content policy                   | Marks it failed, free                                                                                          | "This prompt was rejected by the content policy. You weren't charged." |
| No response or a timeout               | Keeps it pending and retries with the same key. Never marks it failed, because Pixelforge may have created it. | Queued                                                                 |
| Generation fails after it was accepted | Marks it failed, cost 0                                                                                        | The reason, plus a "Try again" button                                  |

## Webhooks

- `POST /webhooks/pixelforge`, outside CSRF protection.
- The signature (`Pixelforge-Signature: t=…,v1=…`, HMAC-SHA256 of `"<t>.<raw body>"`) is checked against the raw body in constant time. Timestamps older than 5 minutes are rejected.
- The event is stored and applied in **one transaction**. A duplicate event id is answered `2xx` and skipped. If applying fails, the insert rolls back, so Pixelforge's retry is processed normally.
- A webhook can arrive before our `POST` returns. We send our own generation id in `metadata`, so the event still finds its row.
- Out-of-order events can't move a generation backwards.
- Webhooks aren't guaranteed, so each generation is also polled with backoff while it's running, and a scheduled command catches anything left behind.

## Running locally

You need PHP 8.3+ (with `pdo_pgsql`), Composer, Node 22+, Docker, and a Pixelforge API key **for local use only**. Each key has its own webhook URL, secret and usage report.

```bash
cp .env.example .env          # then set PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET
docker compose up -d          # Postgres, with a separate pixelforge_testing database for tests
composer setup                # installs dependencies, generates the app key, migrates, builds assets
php artisan db:seed           # test@example.com and admin@example.com, both with password "password"
composer run dev              # app on http://localhost:8000, plus the queue worker and Vite
```

Pixelforge can't reach `localhost`, so open a tunnel and paste its URL into the Pixelforge dashboard as the webhook URL (`https://<tunnel>/webhooks/pixelforge`):

```bash
cloudflared tunnel --url http://localhost:8000
```

## Tests

```bash
php artisan test       # Pest, against the pixelforge_testing Postgres database
composer ci:check      # everything CI runs: formatting, types, Pint, PHPStan and the tests
```

Tests never call Pixelforge: `phpunit.xml` replaces the real key and webhook secret with fake ones.

Prompt keywords force Pixelforge's behaviour, so every path can be tried by hand: `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]` and `[succeed]`.

## Decisions

The choices the brief left open, and why, are in [docs/DECISIONS.md](docs/DECISIONS.md).

## Known limits and next steps

_(pending)_
