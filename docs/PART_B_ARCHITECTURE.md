# Part B — Production Market Feed Architecture Design Document

**Author:** Senior Software Engineer  
**Scope:** High-Throughput Market Data Feed Ingestion, Dual-Tier Storage & Low-Latency Caching Strategy  
**Scale Target:** 4 exchanges, ~8,000 symbols, 1 full snapshot/min/exchange + intraday corrections, 200 req/s peak API, nightly EOD batch.

---

## 1. System Architecture Diagram

```
[ Market Data Vendor ] 
       │ (Push HTTP/Webhook / WebSockets)
       ▼
[ Cloudflare / Envoy API Gateway ] ────► Rate Limiting, mTLS, TLS Termination
       │
       ▼
[ Ingestion Ingress Service (Go / Swoole) ] 
       │ (Fast validation, Dedup check)
       ├───────────────────────────────────────────┐
       ▼                                           ▼
[ Apache Kafka / AWS MSK ]                 [ Redis Stream / In-Memory PubSub ]
 Topic: `market.ticks.raw`                         │
       │                                           ▼
       ▼                                  [ Redis Hot Store (Cluster) ]
[ Ingestion Consumers (Workers) ]          Hashes: `quote:{symbol}`
       │ (Bulk batch upsert)               (Sub-millisecond reads for 200 req/s API)
       ├──────────────────────────┐                ▲
       ▼                          ▼                │
[ MySQL / Aurora Primary ]  [ Redis Cache ] ───────┘
 Table: `market_feed_prices` (Cache invalidation events)
       │
       ▼ (Nightly EOD Batch / Airflow / DBT)
[ Historical Prices DB (Partitioned) ]
 Table: `historical_prices`
```

---

## 2. Ingestion Architecture

### 2.1 Push vs. Pull
* **Primary (Vendor Push):** We establish webhook ingress endpoints (`POST /ingest/v1/snapshot/{exchange}`) where the vendor pushes JSON/Protobuf snapshots. Push is optimal here because the vendor controls the dissemination clock (market tick events), eliminating polling overhead across 4 exchanges.
* **Secondary (Health & Reconciliation Pull):** A lightweight background cron job polls the vendor's `/status` or `/snapshot/head` endpoint every 30 seconds. If no push arrives from an active exchange within 75 seconds during market hours, the puller triggers an alert and initiates catch-up polling.

### 2.2 Decoupling via Message Queueing (Kafka / SQS)
* Webhook ingress pods do **not** write synchronously to relational storage.
* Upon arrival, ingress pods perform fast stream-schema validation, attach an ingestion timestamp, and produce to a distributed event log (**Apache Kafka** with topics `market.ticks.{exchange}`):
  * **Partitioning Key:** `symbol` (ensures strict per-symbol ordered processing).
  * **Durability:** `acks=all`, minimum in-sync replicas = 2, 7-day data retention.
* Dedicated ingestion workers consume messages in batches of 500–1,000 records, executing bulk upserts into storage.

### 2.3 Handling Bursts and Duplicates
* **Bursts:** During market opens or economic announcements, vendor bursts are absorbed by Kafka's partition buffers. Ingestion endpoints return `202 Accepted` within 10ms, decoupling vendor throughput from database write latency.
* **Duplicates & Out-of-Order Delivery:**
  * Every payload must carry a vendor sequence number and timestamp `(exchange_id, sequence_id, timestamp)`.
  * We use an atomic Redis deduplication filter (`SET quote:seq:{symbol} {seq} GT` or comparing monotonically increasing snapshot version). If a worker receives a payload with a timestamp $\le$ the currently stored timestamp for that symbol, it is safely dropped as a redundant/stale tick.

---

## 3. Storage Design

### 3.1 Dual-Tier Storage: "Latest Price" vs. "Historical Prices"

| Attribute | Tier 1: Latest Price Store | Tier 2: Historical Prices Store |
| :--- | :--- | :--- |
| **Engine** | **Redis Cluster** + MySQL `market_feed_prices` | **MySQL / PostgreSQL / AWS Aurora** |
| **Data Structure** | Redis Hashes (`HSET quote:{symbol}`) | Partitioned Relational Table (`historical_prices`) |
| **Access Pattern** | Ultra-high read throughput (200+ req/s), point lookups | Analytical, range queries, date-based scans |
| **Retention** | Current trading day only | Permanent / Multi-year archive |
| **Latency SLA** | $< 2\text{ ms}$ (Redis in-memory read) | $50\text{--}200\text{ ms}$ |

### 3.2 Schema Definition

#### Latest Prices (`market_feed_prices` in MySQL)
```sql
CREATE TABLE market_feed_prices (
  symbol VARCHAR(20) NOT NULL,
  exchange VARCHAR(10) NOT NULL,
  price DECIMAL(12,4) NOT NULL,
  change_amount DECIMAL(12,4) NOT NULL,
  volume BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (symbol),
  INDEX idx_exchange_symbol (exchange, symbol),
  INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### Historical Prices (`historical_prices` with Range Partitioning)
```sql
CREATE TABLE historical_prices (
  symbol VARCHAR(20) NOT NULL,
  trade_date DATE NOT NULL,
  open DECIMAL(12,4) NOT NULL,
  high DECIMAL(12,4) NOT NULL,
  low DECIMAL(12,4) NOT NULL,
  close DECIMAL(12,4) NOT NULL,
  volume BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (symbol, trade_date),
  INDEX idx_trade_date (trade_date),
  INDEX idx_symbol_date_close (symbol, trade_date, close)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
PARTITION BY RANGE (YEAR(trade_date)) (
  PARTITION p2024 VALUES LESS THAN (2025),
  PARTITION p2025 VALUES LESS THAN (2026),
  PARTITION p2026 VALUES LESS THAN (2027),
  PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

### 3.3 Keeping the 200 req/s API Fast
1. **Direct Cache-Aside via Redis:** The Mobile REST API (`/api/v1/quote/{symbol}`) reads directly from Redis (`HGETALL quote:{symbol}`). Serving from memory bypasses SQL query planning, connection pooling bottlenecks, and table locking entirely.
2. **Covering Indexes:** For relational queries (e.g. batch multi-symbol queries), covering index `idx_symbol_date_close` allows index-only scans without secondary table heap lookups.
3. **HTTP Keep-Alive & Connection Pooling:** Microservice/API workers maintain persistent connection pools to Redis and DB replicas.

---

## 4. Caching & Invalidation Strategy

### 4.1 Caching Hierarchy

```
[ Client / Mobile App ]
       │
       ▼
[ Layer 1: CDN / Reverse Proxy (Cloudflare / Fastly) ] ── (TTL: 10s, Stale-While-Revalidate: 30s)
       │
       ▼
[ Layer 2: Application Cache (Redis) ] ───────────────── (Key-Value, sub-millisecond)
       │
       ▼
[ Layer 3: Drupal Render Cache ] ─────────────────────── (Cache Tags: market_feed_prices:list)
       │
       ▼
[ Layer 4: Relational Database ]
```

### 4.2 Handling 1-Minute Snapshot Invalidation Without Cache Stampedes
Invalidating 8,000 cached objects every 60 seconds risks crushing backend databases under a **cache stampede (thundering herd)**. We prevent this using three specific techniques:

1. **Surrogate-Key / Cache-Tag Purging at CDN:**
   * Price pages and API endpoints carry HTTP headers: `Surrogate-Key: market-feed market-feed-{exchange}`.
   * When an exchange snapshot completes, the worker sends an async purge request to the CDN edge for tag `market-feed-{exchange}`.
2. **Stale-While-Revalidate & Probabilistic Early Expiration (XFetch):**
   * CDN and Redis return stale price data while a single background worker asynchronously warms the new price.
   * `Cache-Control: public, max-age=15, stale-while-revalidate=45`.
3. **Drupal Render Cache Tag Granularity:**
   * Instead of clearing the entire render cache, invalidate `market_feed_prices:list` for aggregate pages, and granular `market_feed_prices:{symbol}` for symbol detail pages.

---

## 5. Failure Handling & Recovery

### Scenario A: Vendor Feed Goes Down for 20 Minutes
1. **Detection:**
   * Prometheus alert triggers if Kafka topic ingress drops to 0 msgs/sec for $> 90$ seconds during market hours.
   * Ingress health-check probe detects missed heartbeats and notifies on-call via PagerDuty.
2. **User Experience & Circuit Breaking:**
   * API responses include metadata: `"is_stale": true, "as_of": "2026-11-06T09:30:00Z"`.
   * Web UI displays a prominent banner: *"Market feed delayed — displaying last known price from 09:30 AM"*.
3. **Recovery & Catch-up:**
   * Once connectivity resumes, the worker initiates a **Catch-up Replay** calling the vendor's REST Catch-Up API: `GET /vendor/catchup?from={last_sync_timestamp}`.
   * Bulk updates are streamed through Kafka with priority flag to quickly restore current market levels.

### Scenario B: A Corrupt Snapshot Gets Ingested
*(e.g., negative prices, decimal point shift, or erroneous corporate action data)*
1. **Pre-Ingestion Anomaly Quarantine (Prevention):**
   * Workers execute boundary validation before upsert:
     * $Price > 0$.
     * Volatility Guard: If $|\text{new\_price} - \text{prev\_price}| / \text{prev\_price} > 0.40$ (40% intraday swing) without a formal trading halt flag, quarantine the record into a Dead Letter Queue (DLQ) and do not commit to Redis.
2. **Post-Ingestion Recovery (Rollback):**
   * If bad data breached the DB before detection:
     * Emit an immediate emergency CDN purge for `market-feed`.
     * Execute point-in-time recovery using Kafka topic offsets: replay the latest verified snapshot offset prior to the corrupt timestamp.
     * Overwrite `market_feed_prices` and Redis keys from the replayed offset.

---

## 6. Explicit Trade-Off Analysis

### Selected Architecture: Dual-Tier (Redis Hot Store + Async Relational Ingestion)
### Rejected Alternative: Direct Synchronous Relational Database Write-Through

| Evaluation Criteria | Dual-Tier (Redis + Kafka + Relational) [Chosen] | Direct Synchronous DB Write-Through [Rejected] |
| :--- | :--- | :--- |
| **Write Contention** | Near zero: Writes buffer in Kafka and stream to Redis. | Severe: 8,000 upserts/min cause lock contention on InnoDB rows and indexes. |
| **API Latency (200 req/s)** | Sub-millisecond ($< 2\text{ ms}$) served straight from Redis RAM. | $25\text{--}80\text{ ms}$ due to connection pooling, read locks, and buffer pool churn. |
| **Failure Blast Radius** | Isolated: Database slowdown does not affect live API reads. | High: DB connection saturation during snapshot takes down both writes and reads. |
| **Operational Complexity** | Requires managing Kafka and Redis clusters. | Simpler stack (only single database instance). |

### Justification
In financial market applications, read latency and service availability during trading hours are paramount. During peak volatility (market opens, Fed announcements), query volume surges simultaneously with ingestion volume. 

A direct synchronous write-through architecture creates mutual lock contention between incoming updates and user queries. Introducing an in-memory hot store (Redis) fed by an asynchronous buffer (Kafka) isolates the 200 req/s API read path from database ingestion pressure, ensuring consistent $< 2\text{ ms}$ response times even during maximum market turbulence.

