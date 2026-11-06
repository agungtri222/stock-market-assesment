<?php

declare(strict_types=1);

namespace Drupal\market_feed\Service;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Service handling high-volume stock price feed ingestion and upsert.
 */
class MarketFeedImporter implements MarketFeedImporterInterface {

  /**
   * Constructs a new MarketFeedImporter.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The active database connection.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service channel.
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function import(array $items): MarketFeedImportResult {
    $now = (int) ($_SERVER['REQUEST_TIME'] ?? \time());
    $validRecords = [];
    $errors = [];
    $skipped = 0;

    // High-efficiency in-memory validation.
    // Iterating 50,000 rows in memory takes ~15ms in PHP 8.1+.
    // We isolate malformed rows upfront to ensure bad data never touches the database.
    foreach ($items as $index => $item) {
      $validationResult = $this->validateRow($item, $index);
      if (!$validationResult['valid']) {
        $skipped++;
        $errors[] = [
          'index' => $index,
          'symbol' => $validationResult['symbol'] ?? 'UNKNOWN',
          'reason' => $validationResult['reason'],
        ];
        continue;
      }

      // If duplicate symbols exist within the same incoming payload, the latest
      // entry takes precedence, preventing intra-batch unique constraint collisions.
      $validRecords[$validationResult['data']['symbol']] = $validationResult['data'];
    }

    $imported = 0;
    if (!empty($validRecords)) {
      // Chunked bulk upsert operations.
      // 50,000 rows split into chunks of 1,000 avoids packet saturation
      // (MySQL max_allowed_packet) and memory bloat while preventing 50,000 round-trips.
      $chunks = \array_chunk(\array_values($validRecords), self::CHUNK_SIZE);

      foreach ($chunks as $chunk) {
        $transaction = $this->database->startTransaction();
        try {
          $upsert = $this->database->upsert('market_feed_prices')
            ->key('symbol')
            ->fields(['symbol', 'price', 'change_amount', 'volume', 'updated_at']);

          foreach ($chunk as $record) {
            $upsert->values([
              'symbol' => $record['symbol'],
              'price' => $record['price'],
              'change_amount' => $record['change_amount'],
              'volume' => $record['volume'],
              'updated_at' => $now,
            ]);
          }

          $upsert->execute();
          $imported += \count($chunk);
        }
        catch (\Throwable $e) {
          $transaction->rollBack();
          $this->logger->error('Database transaction failed during market feed chunk upsert: @message', [
            '@message' => $e->getMessage(),
          ]);

          // Re-throw or record failure for this chunk.
          foreach ($chunk as $failedItem) {
            $skipped++;
            $errors[] = [
              'index' => -1,
              'symbol' => $failedItem['symbol'],
              'reason' => 'Database storage failure: ' . $e->getMessage(),
            ];
          }
        }
      }

      // Targeted cache invalidation.
      // Invalidate the cache tag so all render caches and views immediately show fresh data.
      if ($imported > 0) {
        $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
      }
    }

    $this->logger->info('Market feed import completed. Imported: @imported, Skipped: @skipped.', [
      '@imported' => $imported,
      '@skipped' => $skipped,
    ]);

    return new MarketFeedImportResult($imported, $skipped, $errors);
  }

  /**
   * Validates a single incoming price feed row.
   *
   * @param mixed $item
   *   The raw item data.
   * @param int $index
   *   The array index of the row.
   *
   * @return array{valid: bool, symbol?: string, data?: array, reason?: string}
   *   Validation result metadata.
   */
  protected function validateRow(mixed $item, int $index): array {
    if (!\is_array($item)) {
      return [
        'valid' => FALSE,
        'symbol' => 'N/A',
        'reason' => \sprintf('Row at index %d is not a valid JSON object.', $index),
      ];
    }

    // Symbol validation.
    $rawSymbol = $item['symbol'] ?? NULL;
    if (!\is_string($rawSymbol) || \trim($rawSymbol) === '') {
      return [
        'valid' => FALSE,
        'symbol' => 'EMPTY',
        'reason' => 'Missing or empty ticker symbol.',
      ];
    }
    $symbol = \strtoupper(\trim($rawSymbol));
    if (\strlen($symbol) > 20) {
      return [
        'valid' => FALSE,
        'symbol' => $symbol,
        'reason' => 'Ticker symbol exceeds maximum length of 20 characters.',
      ];
    }

    // Price validation.
    $rawPrice = $item['price'] ?? NULL;
    if (!\is_numeric($rawPrice) || (float) $rawPrice < 0) {
      return [
        'valid' => FALSE,
        'symbol' => $symbol,
        'reason' => 'Price must be a non-negative numeric value.',
      ];
    }

    // Change amount validation (accepts both "change" and "change_amount").
    $rawChange = $item['change'] ?? $item['change_amount'] ?? NULL;
    if (!\is_numeric($rawChange)) {
      return [
        'valid' => FALSE,
        'symbol' => $symbol,
        'reason' => 'Change amount must be a numeric value.',
      ];
    }

    // Volume validation.
    $rawVolume = $item['volume'] ?? NULL;
    if (!\is_numeric($rawVolume) || (int) $rawVolume < 0) {
      return [
        'valid' => FALSE,
        'symbol' => $symbol,
        'reason' => 'Volume must be a non-negative integer.',
      ];
    }

    return [
      'valid' => TRUE,
      'symbol' => $symbol,
      'data' => [
        'symbol' => $symbol,
        'price' => (float) $rawPrice,
        'change_amount' => (float) $rawChange,
        'volume' => (int) $rawVolume,
      ],
    ];
  }

}

