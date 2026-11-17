# Pixelforge Image Studio

<!-- Remove the status line below once Phase 10 is done. -->

> **Status: work in progress.** This README describes the design. Sections marked _(pending)_ are filled in as each part ships.

A Laravel, Vue and Inertia app where signed-in users generate images through the Pixelforge API and pay exactly what each generation cost, plus a 25% markup. Built for the PromptEdit Senior Product Engineer take-home assessment.

## Live app

- URL: https://pixelforge-image-studio-production-jvfb2a.laravel.cloud
- Logins: anyone can sign up as a normal user. A demo admin account for the usage report is listed here once deployed. The admin role can only read reports.

## What it does

- **Generate (the Studio):** write a prompt, pick a size and quality, and add up to 4 reference image URLs. The price updates as you type: an estimate, and the most it can cost, for each quality. "How this price is made" shows every token type, its rate and the 25% fee.
- **History:** every generation with its live status (waiting for a free slot, queued, generating, succeeded or failed), the estimated and the actual cost, and the image. The page refreshes the history every 2 seconds while something is running, and stops once everything is finished. A failed generation says why in plain words, shows "You were not charged", and has a Try again button.
- **Detail page:** for each generation, the estimated, maximum and actual tokens of each type, the cost to 9 decimals, our fee, the charge, Pixelforge's id and the timestamps.
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

On Windows, `npm install` leaves the dependencies of Linux-only optional packages out of `package-lock.json` (an npm bug), and the Linux deploy's `npm ci` then fails. CI runs `npm ci` to catch it. To fix the lock file, regenerate it on Linux:

```bash
docker run --rm -v "${PWD}:/Pixelforge" -w /Pixelforge node:22-alpine npm install --package-lock-only
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

- **Images aren't stored here.** Pixelforge's image links expire after 24 hours; after that, the history shows a placeholder. Next step: copy each image to object storage when it succeeds.
- **Live status is polling, not push.** The page asks every 2 seconds, only while something is running. WebSockets would update instantly, but on Laravel Cloud they cost money and keep the app awake.
- **A poll answered "not found" or "invalid key" only logs an error.** The generation stays queued and the hourly reconcile tries again. Next step: flag it for an operator.
- **Waiting for a free slot uses up the shared rate limit.** Each wait counts against our 50-requests-per-minute budget, even though it doesn't call Pixelforge.
- **`completed_at` is a plain timestamp.** It's correct because the app runs in UTC; `timestamptz` would be safer if that ever changed.
