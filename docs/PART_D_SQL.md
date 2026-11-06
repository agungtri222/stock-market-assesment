# Part D — SQL Solutions & Performance Analysis

**Database Engine Target:** MySQL 8.0+ / PostgreSQL 14+ / ANSI SQL:2016  
**Table Schema:**
```sql
CREATE TABLE historical_prices (
  symbol VARCHAR(20) NOT NULL,
  trade_date DATE NOT NULL,
  open DECIMAL(12,4),
  high DECIMAL(12,4),
  low DECIMAL(12,4),
  close DECIMAL(12,4),
  volume BIGINT,
  PRIMARY KEY (symbol, trade_date)
);
```

---

## 1. Day-over-Day Movers

### Objective
For a given target date (e.g., `'2026-11-06'`), return every symbol with:
* Current close price
* Previous trading day's close price (taking market holidays, weekends, and trading suspensions into account, where previous trading day is not simply `date - 1`)
* Percentage change: $\frac{\text{close} - \text{prev\_close}}{\text{prev\_close}} \times 100$
* Sorted by biggest absolute percentage change (`ABS(percentage_change) DESC`).

### Solution Query
We use the `LAG()` analytic window function partitioned by `symbol` and ordered by `trade_date ASC`. 

To ensure optimal query execution, the inner CTE calculates the previous close across chronological trade dates up to the target date, and the outer query filters strictly for the target date.

```sql
WITH price_history AS (
  SELECT 
    symbol,
    trade_date,
    close AS current_close,
    LAG(close, 1) OVER (
      PARTITION BY symbol 
      ORDER BY trade_date ASC
    ) AS prev_close
  FROM historical_prices
  -- Optimization: Scan only up to the target date.
  WHERE trade_date <= '2026-11-06'
)
SELECT 
  symbol,
  trade_date,
  current_close,
  prev_close,
  ROUND(((current_close - prev_close) / prev_close) * 100, 2) AS pct_change
FROM price_history
WHERE trade_date = '2026-11-06'
  AND prev_close IS NOT NULL
  AND prev_close > 0
ORDER BY ABS((current_close - prev_close) / prev_close) DESC;
```

### Why this handles market holidays & suspensions
* `LAG(close, 1) OVER (PARTITION BY symbol ORDER BY trade_date ASC)` inspects the *immediately preceding available record* for that specific symbol in the dataset.
* If the target date is Monday (`2026-11-09`), the preceding trading row is Friday (`2026-11-06`).
* If a symbol was suspended from trading for 2 weeks, `LAG()` will accurately bind to the last active trading session before the halt.

---

## 2. Gap Detection (Missing Trading Data)

### Objective
Find symbols that have **no row** for one or more dates on which other symbols traded (i.e. market was open, but data is missing for that symbol) within June 2026 (`2026-06-01` to `2026-06-30`).

### Solution Query
We use a relational division / anti-join strategy:
1. Extract the set of **official market trading days** in June 2026 (dates where at least one symbol recorded prices).
2. Generate all expected `(symbol, trade_date)` combinations via a `CROSS JOIN` between all active symbols and trading days.
3. Perform a `LEFT JOIN` back to `historical_prices` and filter for `NULL` matches.

```sql
WITH trading_days AS (
  -- Distinct dates on which market trading occurred in June 2026.
  SELECT DISTINCT trade_date 
  FROM historical_prices
  WHERE trade_date >= '2026-06-01' AND trade_date <= '2026-06-30'
),
active_symbols AS (
  -- Distinct symbols actively tracked during the period.
  SELECT DISTINCT symbol 
  FROM historical_prices
  WHERE trade_date >= '2026-06-01' AND trade_date <= '2026-06-30'
),
expected_calendar AS (
  -- Full Cartesian matrix of expected trading records.
  SELECT 
    s.symbol,
    d.trade_date
  FROM active_symbols s
  CROSS JOIN trading_days d
)
SELECT 
  e.symbol,
  e.trade_date AS missing_date
FROM expected_calendar e
LEFT JOIN historical_prices hp 
  ON e.symbol = hp.symbol 
 AND e.trade_date = hp.trade_date
WHERE hp.symbol IS NULL
ORDER BY e.symbol ASC, e.trade_date ASC;
```

### Aggregated Variant (Summary by Symbol)
If we want a concise summary of symbols with gaps and their missing date count:
```sql
SELECT 
  e.symbol,
  COUNT(e.trade_date) AS missing_days_count,
  GROUP_CONCAT(e.trade_date ORDER BY e.trade_date ASC SEPARATOR ', ') AS missing_dates
FROM expected_calendar e
LEFT JOIN historical_prices hp 
  ON e.symbol = hp.symbol 
 AND e.trade_date = hp.trade_date
WHERE hp.symbol IS NULL
GROUP BY e.symbol
ORDER BY missing_days_count DESC;
```

---

## 3. Query Optimization on a 500 Million-Row Table

### Original Query
```sql
SELECT * FROM historical_prices
WHERE YEAR(trade_date) = 2026 AND symbol LIKE '%BANK%'
ORDER BY volume DESC LIMIT 100;
```

---

### Part A: Why This Query is Slow on 500M Rows

1. **Non-Sargable Function on Index Column (`YEAR(trade_date)`):**
   * Wrapping `trade_date` in the function `YEAR(...)` prevents MySQL from using an index on `trade_date` for range lookups (B-Tree index seek). The database engine must evaluate `YEAR()` on every single row.
2. **Leading Wildcard Pattern (`symbol LIKE '%BANK%'`):**
   * Standard B-Trees order keys lexicographically from left to right. A wildcard prefix (`%...`) cannot be traversed using an index seek; the engine is forced to perform a full table scan or full index scan across the entire table.
3. **Ineffective Primary Key Layout for this Query:**
   * The primary key is `(symbol, trade_date)`. Since `symbol` is filtered with a leading wildcard, the composite PK cannot be used to filter by `trade_date`.
4. **Massive In-Memory / Temporary Disk FileSort:**
   * MySQL must evaluate millions of qualifying candidate rows for year 2026, load full row payloads (`SELECT *`) into memory/disk temporary tables, and execute a sort across millions of records to find `LIMIT 100`.
5. **I/O Thrashing via `SELECT *`:**
   * Reading all columns (open, high, low, close, volume) for candidates requires reading thousands of 16KB InnoDB pages from disk into the buffer pool, evicting hot cache data.

---

### Part B: Optimized Query Rewrite

We rewrite `YEAR(trade_date) = 2026` into a **sargable date range** (`trade_date >= ... AND trade_date < ...`).

```sql
SELECT 
  symbol,
  trade_date,
  open,
  high,
  low,
  close,
  volume
FROM historical_prices
WHERE trade_date >= '2026-01-01' 
  AND trade_date < '2027-01-01'
  AND symbol LIKE '%BANK%'
ORDER BY volume DESC
LIMIT 100;
```

---

### Part C: Proposed Index & Schema Changes

To make this query execute in $< 50\text{ ms}$ on 500 million rows, choose the strategy matching your infrastructure:

#### Strategy 1: Targeted Composite Index (Best for Standard MySQL)
Create an index optimized for the date range and volume sort:
```sql
ALTER TABLE historical_prices 
ADD INDEX idx_date_volume_symbol (trade_date, volume DESC, symbol);
```
* **Execution Plan:** The query performs an index range scan on `trade_date BETWEEN '2026-01-01' AND '2026-12-31'`. If `volume` is the second column and the date range is small, or if partitioned by year, the engine avoids the expensive filesort.

#### Strategy 2: Table Partitioning by Year (Production Enterprise Recommendation)
Partition `historical_prices` by range on `trade_date`:
```sql
ALTER TABLE historical_prices 
PARTITION BY RANGE (YEAR(trade_date)) (
  PARTITION p2024 VALUES LESS THAN (2025),
  PARTITION p2025 VALUES LESS THAN (2026),
  PARTITION p2026 VALUES LESS THAN (2027),
  PARTITION p_future VALUES LESS THAN MAXVALUE
);
```
* **Benefit:** With `trade_date >= '2026-01-01' AND trade_date < '2027-01-01'`, the optimizer applies **Partition Pruning**, discarding ~400 million rows immediately and scanning only the 2026 partition (~100M rows).

#### Strategy 3: Full-Text / Generated Trigram or Suffix Index for Substring Matching
If searching arbitrary substrings like `LIKE '%BANK%'` is frequent:
1. **Option A (Full-Text Search):**
   ```sql
   ALTER TABLE historical_prices ADD FULLTEXT INDEX ft_symbol (symbol);
   ```
   Query:
   ```sql
   WHERE trade_date >= '2026-01-01' AND trade_date < '2027-01-01'
     AND MATCH(symbol) AGAINST('BANK' IN BOOLEAN MODE)
   ```
2. **Option B (Sector / Industry Tag Normalization):**
   In financial systems, symbols containing `"BANK"` typically belong to a specific sector. Normalizing the schema by adding a `sector_id` or indexed tag column eliminates `LIKE '%...%'` entirely:
   ```sql
   ALTER TABLE historical_prices ADD COLUMN sector VARCHAR(30) DEFAULT NULL;
   CREATE INDEX idx_date_sector_volume ON historical_prices (trade_date, sector, volume DESC);
   ```
   Query:
   ```sql
   WHERE trade_date >= '2026-01-01' AND trade_date < '2027-01-01'
     AND sector = 'BANK'
   ORDER BY volume DESC LIMIT 100;
   ```
   This transforms the query into an index-seek operation taking $< 5\text{ ms}$.

