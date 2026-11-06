# Part C — Senior Merge Request Review: "Portfolio Value" Endpoint

**Reviewer:** Senior Software Engineer  
**Merge Request:** `feature/portfolio-value-endpoint`  
**Target Branch:** `main`  
**Review Status:** ❌ **Request Changes (Merge Blocked on Critical Security & Performance Issues)**

---

## 1. Summary Comment (Posted on the MR)

> Hi @developer, thanks for opening this MR and getting the core portfolio value calculation working!
>
> The business logic of multiplying quantity by the latest close price is spot on. However, before this can be merged into production, there are a few **critical security vulnerabilities (SQL Injection and XSS)** and a **major performance bottleneck ($N+1$ query pattern)** that we need to address together.
>
> I've broken the review down into:
> 1. **🔴 Blocking Issues (Must fix before merge):** Security vulnerabilities and query performance.
> 2. **🟡 Important Best Practices:** Architecture, separation of concerns, and error handling.
> 3. **💡 Reference Implementation:** A fully refactored version using Drupal's Database API and modern PHP practices that you can use as a reference.
>
> Don't worry — these are very common patterns when starting out. Take a look at the line-by-line comments below, and feel free to ping me on Slack if you'd like to pair on the refactor!

---

## 2. Line-by-Line Code Comments

### Line 1: Function Signature
```php
function get_portfolio($db, $user_id) {
```
> **🟡 Suggestion (Clean Code / Type Safety):**  
> Notice that `$user_id` is passed as a parameter in the signature, but on lines 3 and 14 the code reads directly from `$_GET['user_id']`. We should always use the passed parameter instead of reading directly from global superglobals inside business functions.  
> Also, adding type declarations (e.g. `int $user_id` or `string $user_id`) and a return type makes the function self-documenting and prevents unexpected type coercion bugs.

---

### Line 2–3: Direct Global Input & SQL Injection
```php
$result = mysqli_query($db,
"SELECT * FROM portfolios WHERE user_id = " . $_GET['user_id']);
```
> **🔴 Blocking (Critical Security — SQL Injection):**  
> `$_GET['user_id']` is directly concatenated into the SQL query without parameterization or escaping. An attacker could pass a payload like `?user_id=1 OR 1=1` or `?user_id=1; DROP TABLE portfolios;`, exposing or destroying the entire database.  
> **How to fix:** Always use prepared statements with placeholders (e.g., `:user_id` in Drupal DB API or `?` in PDO/mysqli prepared statements). Never concatenate raw input into an SQL string.

---

### Lines 5–10: The $N+1$ Query Anti-Pattern
```php
while ($row = mysqli_fetch_assoc($result)) {
$price = mysqli_fetch_assoc(mysqli_query($db,
"SELECT close FROM historical_prices
WHERE symbol = '" . $row['symbol'] . "'
ORDER BY trade_date DESC LIMIT 1"));
$row['last_price'] = $price['close'];
$rows[] = $row;
}
```
> **🔴 Blocking (Performance & Database Saturation):**  
> Running a database query *inside* a `while` loop is known as the **$N+1$ Query Problem**. If a user owns 50 stocks in their portfolio, this code will fire 51 separate SQL queries over the network! Under concurrent traffic, this will quickly saturate our database connection pool.  
> Furthermore, `row['symbol']` is also string-interpolated directly into SQL on line 7 without escaping.  
> **How to fix:** We can fetch all portfolio positions and their corresponding latest price in a **single SQL query** using a `JOIN` with a window function (`ROW_NUMBER()`) or correlated subquery.

---

### Line 13–17: Direct Echo & Cross-Site Scripting (XSS)
```php
echo "<h2>Portfolio for user " . $_GET['user_id'] . "</h2>";
foreach ($rows as $r) {
echo "<div>" . $r['symbol'] . ": " . $r['qty'] * $r['last_price'] . "</div>";
}
```
> **🔴 Blocking (Security — Stored/Reflected XSS):**  
> `echo $_GET['user_id']` outputs raw user input straight into the HTML document without sanitization. If a user visits `?user_id=<script>stealCookies()</script>`, the script executes in their browser. Always sanitize with `htmlspecialchars()` or use Drupal's render arrays which auto-escape variables.  
>
> **🟡 Architecture (Separation of Concerns):**  
> Business functions should not directly `echo` HTML. If this endpoint needs to be consumed by an API or a mobile app in the future, raw HTML strings break the integration. Instead, return a structured array or Data Transfer Object (DTO), and let the Controller / Template layer handle the output formatting.

---

### General: Deprecated Procedural Database Functions
```php
mysqli_query(...), mysqli_fetch_assoc(...)
```
> **🟡 Architecture (Drupal Standards):**  
> In our Drupal platform, we avoid procedural `mysqli_*` functions. We should use Drupal's built-in Database Abstraction Layer (`\Drupal::database()` or injected `@database` service), which automatically handles connection pooling, database prefixing, driver portability, and PDO prepared statements.

---

## 3. Corrected Reference Implementation

Below is the production-ready, refactored implementation. It uses Drupal's Database API, executes in **one single database round-trip**, prevents SQL injection and XSS, handles edge cases (like symbols with no price history), and returns a structured render array.

```php
<?php

declare(strict_types=1);

namespace Drupal\portfolio\Service;

use Drupal\Core\Database\Connection;

/**
 * Service responsible for calculating user portfolio values.
 */
class PortfolioService {

  /**
   * Constructs a new PortfolioService.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The active Drupal database connection.
   */
  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Fetches the portfolio positions and calculated valuation for a given user.
   *
   * Executes a single query joining portfolios with the latest closing price
   * from historical_prices, avoiding the N+1 query problem.
   *
   * @param int $userId
   *   The sanitized ID of the user.
   *
   * @return array{
   *   user_id: int,
   *   total_value: float,
   *   positions: array<int, array{symbol: string, quantity: float, latest_price: float, position_value: float}>
   * }
   */
  public function getPortfolioValuation(int $userId): array {
    // Single query joining user portfolios with the latest trade_date price.
    // Utilizes a subquery with ROW_NUMBER() window function to fetch the single
    // most recent price per symbol in a single database round-trip.
    $sql = "
      SELECT 
        p.symbol,
        p.qty,
        COALESCE(latest.close, 0.0000) AS latest_price,
        (p.qty * COALESCE(latest.close, 0.0000)) AS position_value
      FROM {portfolios} p
      LEFT JOIN (
        SELECT 
          hp.symbol,
          hp.close,
          ROW_NUMBER() OVER (PARTITION BY hp.symbol ORDER BY hp.trade_date DESC) as rn
        FROM {historical_prices} hp
      ) latest ON p.symbol = latest.symbol AND latest.rn = 1
      WHERE p.user_id = :user_id
      ORDER BY position_value DESC
    ";

    $records = $this->database->query($sql, [
      ':user_id' => $userId,
    ])->fetchAll();

    $positions = [];
    $totalValue = 0.0;

    foreach ($records as $row) {
      $positionValue = (float) $row->position_value;
      $totalValue += $positionValue;

      $positions[] = [
        'symbol' => (string) $row->symbol,
        'quantity' => (float) $row->qty,
        'latest_price' => (float) $row->latest_price,
        'position_value' => $positionValue,
      ];
    }

    return [
      'user_id' => $userId,
      'total_value' => $totalValue,
      'positions' => $positions,
    ];
  }

}
```

### Controller Usage Example (Safe Rendering)

```php
<?php

declare(strict_types=1);

namespace Drupal\portfolio\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\portfolio\Service\PortfolioService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class PortfolioController extends ControllerBase {

  public function __construct(
    private readonly PortfolioService $portfolioService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('portfolio.service')
    );
  }

  public function view(Request $request): array {
    // 1. Strict input validation and casting.
    $userId = (int) $request->query->get('user_id', 0);
    if ($userId <= 0) {
      return [
        '#markup' => $this->t('Invalid or missing user ID.'),
      ];
    }

    // 2. Fetch data via service.
    $data = $this->portfolioService->getPortfolioValuation($userId);

    // 3. Render array with auto-escaping (prevents XSS).
    $items = [];
    foreach ($data['positions'] as $pos) {
      $items[] = [
        '#markup' => sprintf(
          '<strong>%s</strong>: %s @ $%s = <strong>$%s</strong>',
          htmlspecialchars($pos['symbol'], ENT_QUOTES, 'UTF-8'),
          number_format($pos['quantity'], 2),
          number_format($pos['latest_price'], 2),
          number_format($pos['position_value'], 2)
        ),
      ];
    }

    return [
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Portfolio for User @id (Total: $@total)', [
          '@id' => $data['user_id'],
          '@total' => number_format($data['total_value'], 2),
        ]),
      ],
      'list' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#empty' => $this->t('No stock holdings found for this user.'),
      ],
      '#cache' => [
        'contexts' => ['url.query_args:user_id', 'user.permissions'],
        'tags' => ['portfolio:' . $userId, 'market_feed_prices:list'],
      ],
    ];
  }

}
```

