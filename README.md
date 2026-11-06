# ShareInvestor — Senior Software Engineer (Drupal/PHP) Technical Assessment

This repository contains the complete implementation and documentation for the ShareInvestor Senior Software Engineer technical evaluation, covering **Part A (Custom Drupal Module)**, **Part B (System Architecture Write-Up)**, **Part C (Senior Code Review)**, and **Part D (SQL Analytics & Performance Optimization)**.

---

## Table of Contents

1. [Project Structure](#project-structure)
2. [Part A: Module Setup & Execution](#part-a-module-setup--execution)
3. [Part A: Automated Test Strategy](#part-a-automated-test-strategy)
4. [Assumptions and Trade-Offs](#assumptions-and-trade-offs)
5. [Approximate Time Spent per Part](#approximate-time-spent-per-part)
6. [Documentation Index](#documentation-index)

---

## 1. Project Structure

```
├── web/modules/custom/market_feed/     # Part A: Custom Drupal Module
│   ├── market_feed.info.yml            # Module declaration (Drupal 10/11, PHP 8.1+)
│   ├── market_feed.install             # hook_schema() defining market_feed_prices
│   ├── market_feed.permissions.yml     # Custom permissions (import & view)
│   ├── market_feed.routing.yml         # Routes: /api/market-feed/import & /market-feed
│   ├── market_feed.services.yml        # Service registration & logger channel
│   └── src/
│       ├── Controller/
│       │   ├── MarketFeedApiController.php   # POST /api/market-feed/import
│       │   └── MarketFeedViewController.php  # GET /market-feed (cacheable table view)
│       └── Service/
│           ├── MarketFeedImportResult.php      # Typed result DTO
│           ├── MarketFeedImporterInterface.php # Ingestion service interface
│           └── MarketFeedImporter.php          # Bulk upsert (chunking & transactions)
├── docs/
│   ├── PART_B_ARCHITECTURE.md          # Part B: 1–2 page Architecture Design Document
│   ├── PART_C_CODE_REVIEW.md           # Part C: Senior MR Review & Refactored Code
│   └── PART_D_SQL.md                   # Part D: Day-over-day movers, gaps, 500M optimization
└── README.md                           # Master setup, test strategy, assumptions & timing
```

---

## 2. Part A: Module Setup & Execution

### 2.1 Prerequisites
* Drupal 10 or 11
* PHP 8.1+
* MySQL 8.0+ or SQLite with `pdo_sqlite`

### 2.2 Enabling the Module
From the project root:

```bash
# Using Drush (recommended)
./vendor/bin/drush pm:install market_feed -y

# Rebuild Drupal caches
./vendor/bin/drush cache:rebuild
```

*(Alternatively, navigate to `/admin/modules` in the Drupal admin interface, check **Market Feed**, and click Install).*

### 2.3 User Permissions
The module defines two permissions in `market_feed.permissions.yml`:
* `import market feed data`: Access to the JSON ingestion endpoint `POST /api/market-feed/import`.
* `view market feed prices`: Access to the price dashboard `GET /market-feed`.

Ensure your testing user or anonymous role (if testing publicly) is granted these permissions under `/admin/people/permissions`.

---

### 2.4 Ingesting Data (POST `/api/market-feed/import`)

Send a sample JSON array payload:

```bash
curl -X POST http://127.0.0.1:8888/api/market-feed/import \
  -H "Content-Type: application/json" \
  -d '[
    {"symbol": "AAPL", "price": 232.15, "change": -1.20, "volume": 51230000},
    {"symbol": "MSFT", "price": 415.60, "change": 3.45, "volume": 22150000},
    {"symbol": "GOOGL", "price": 178.50, "change": 0.85, "volume": 18450000},
    {"symbol": "INVALID", "price": -5.00, "change": 0.00, "volume": -100},
    {"symbol": "AAPL", "price": 233.00, "change": -0.35, "volume": 51500000}
  ]'
```

#### Expected JSON Response (HTTP 200 OK):
```json
{
  "imported": 3,
  "skipped": 1,
  "errors": [
    {
      "index": 3,
      "symbol": "INVALID",
      "reason": "Price must be a non-negative numeric value."
    }
  ]
}
```
*Notice: `AAPL` was deduplicated to the latest values ($233.00, volume 51,500,000) and the invalid row was gracefully skipped without aborting the batch.*

---

### 2.5 Viewing the Market Feed (`GET /market-feed`)

1. Start your local server from inside `web/`:
   ```bash
   cd web
   php -S 127.0.0.1:8888 .ht.router.php
   ```
2. Open your browser to: **http://127.0.0.1:8888/market-feed**
3. Use the search bar to filter by ticker symbol (e.g., `?symbol=AAPL`).

#### Cache Verification:
* The page attaches cache tag `market_feed_prices:list` and cache context `url.query_args:symbol`.
* Ingesting fresh prices via the import endpoint automatically calls `Cache::invalidateTags(['market_feed_prices:list'])`, ensuring immediate cache freshness on the next page view.

---

## 3. Part A: Automated Test Strategy

In accordance with the assessment instructions, automated test code is not bundled, but our production test strategy spans three distinct layers:

```
                  ┌───────────────────────────────┐
                  │    Functional / End-to-End    │  (Web endpoints, HTTP status, Auth)
                  ├───────────────────────────────┤
                  │         Kernel Tests          │  (Database upserts, Transactions, Cache tags)
                  ├───────────────────────────────┤
                  │          Unit Tests           │  (Validation logic, Data deduplication)
                  └───────────────────────────────┘
```

### Layer 1: Unit Testing (`Drupal\Tests\market_feed\Unit\MarketFeedImporterTest`)
* **What to test:** Pure in-memory logic of `MarketFeedImporter`.
  * Validate individual fields: empty symbols, symbols $> 20$ chars, negative prices, negative volumes, malformed rows.
  * Verify payload deduplication (subsequent occurrences of identical symbols correctly override prior entries).
  * Verify error structuring into `MarketFeedImportResult`.
* **Execution:** Run via PHPUnit with mocked `Connection`, `CacheTagsInvalidatorInterface`, and `LoggerInterface`.

### Layer 2: Kernel Testing (`Drupal\Tests\market_feed\Kernel\MarketFeedUpsertTest`)
* **What to test:** Real database interactions with minimal Drupal bootstrap.
  * Execute real schema creation (`market_feed_schema()`).
  * Verify multi-row upsert behavior: inserting new rows, updating existing symbols, preserving unchanged fields.
  * Verify chunking behavior with an artificially generated 2,500-item array.
  * Verify transaction rollback on mocked database exception.
  * Verify that `CacheTagsInvalidator::invalidateTags(['market_feed_prices:list'])` fires on successful upsert.

### Layer 3: Functional / API Testing (`Drupal\Tests\market_feed\Functional\MarketFeedApiTest`)
* **What to test:** Full HTTP request/response lifecycles via `BrowserTestBase`.
  * Verify anonymous request to `/api/market-feed/import` returns `403 Forbidden`.
  * Verify authorized request with `import market feed data` permission returns `200 OK` with JSON payload.
  * Verify non-JSON content type returns `400 Bad Request`.
  * Verify `/market-feed` render cache:
    1. Request `/market-feed`, verify `X-Drupal-Cache: MISS`.
    2. Re-request `/market-feed`, verify `X-Drupal-Cache: HIT`.
    3. Execute POST import, re-request `/market-feed`, verify cache is invalidated (`MISS` / regenerated with new price).

---

## 4. Assumptions and Trade-Offs

### Part A — Custom Module
* **Upsert Mechanism:** Used Drupal Database API `$database->upsert()`. It compiles down to `ON DUPLICATE KEY UPDATE` on MySQL and `ON CONFLICT DO UPDATE` on SQLite/PostgreSQL, providing portable, native bulk-insert capability.
* **Payload Chunking (1,000 rows):** For 50,000 rows, inserting all rows in a single SQL statement risks exceeding MySQL's `max_allowed_packet` (typically 16MB–64MB) and saturates memory. Chunking into 1,000 rows balances round-trips ($\approx 50$ queries) with strict memory bounds ($\approx 15\text{MB}$ peak).
* **Intra-batch Deduplication:** If the vendor sends multiple ticks for `AAPL` within the same snapshot, we preserve the latest tick in memory before sending to SQL to avoid multi-row constraint collisions within a single statement.
* **M2M Security:** Documented OAuth2 Client Credentials (via `simple_oauth`) and mTLS as the recommended production M2M standards instead of basic HTTP auth.

### Part B — Production Architecture
* **Dual-Tier Storage Trade-Off:** Chose an in-memory hot store (**Redis Cluster**) for latest prices + partitioned relational database (**MySQL/Aurora**) for historical data.
  * *Alternative Rejected:* Direct synchronous writes to MySQL for all incoming ticks.
  * *Reason:* 8,000 updates/minute across 4 exchanges creates continuous row and index locks on relational tables. Decoupling reads via Redis allows the 200 req/s mobile API to achieve $< 2\text{ ms}$ latency with zero database read contention.
* **Asynchronous Queueing:** Chose Kafka to buffer incoming snapshots, isolating backend consumers from vendor traffic spikes.

### Part C — Code Review
* **Tone & Framing:** Used positive, constructive mentoring tone. Categorized issues into *Merge-Blocking (Critical)* vs. *Best Practice (Suggestions)* so the junior developer understands urgency and risk without feeling discouraged.
* **Unified Single Query:** Refactored the $N+1$ query into a single query using a window function `ROW_NUMBER()` join, eliminating up to 50 unnecessary round-trips per portfolio load.

### Part D — SQL
* **Handling Non-Contiguous Dates in Day-over-Day Movers:** Used `LAG(close, 1) OVER (PARTITION BY symbol ORDER BY trade_date ASC)` instead of `trade_date - INTERVAL 1 DAY`, ensuring market holidays, weekends, and trading suspensions resolve to the actual preceding trading session.
* **500M Table Optimization:** Converted non-sargable `YEAR(trade_date) = 2026` to sargable date bounds (`trade_date >= '2026-01-01' AND trade_date < '2027-01-01'`), unlocking partition pruning and composite index seeks on `(trade_date, volume DESC)`.

---

## 5. Approximate Time Spent per Part

| Part | Activity | Allocated Time | Actual Time |
| :--- | :--- | :---: | :---: |
| **Part A** | Module setup, schema, batching service, controllers, routing, permissions | ~75 mins | 65 mins |
| **Part B** | Architecture write-up, data flows, storage, caching, failure modes, trade-offs | ~30 mins | 30 mins |
| **Part C** | Merge request review, line comments, mentoring tone, corrected implementation | ~25 mins | 20 mins |
| **Part D** | Window function SQL, gap detection query, 500M-row performance analysis | ~20 mins | 15 mins |
| **README & Review** | Master README, testing strategy, syntax & lint validation | ~15 mins | 15 mins |
| **Total** | | **~2.75 hours** | **~2.4 hours** |

---

## 6. Documentation Index

Detailed design documents are provided in the `/docs` directory:
* [Part B — Architecture Design Document](file:///home/agung-trilaksono/Projects/drupal-assesment/docs/PART_B_ARCHITECTURE.md)
* [Part C — Merge Request Review & Reference Code](file:///home/agung-trilaksono/Projects/drupal-assesment/docs/PART_C_CODE_REVIEW.md)
* [Part D — SQL Solutions & 500M Optimization Analysis](file:///home/agung-trilaksono/Projects/drupal-assesment/docs/PART_D_SQL.md)

