<?php

declare(strict_types=1);

namespace Drupal\market_feed\Service;

/**
 * Value object representing the outcome of a market feed ingestion batch.
 */
final class MarketFeedImportResult {

  /**
   * Constructs a new MarketFeedImportResult.
   *
   * @param int $imported
   *   Total count of successfully inserted or updated rows.
   * @param int $skipped
   *   Total count of malformed rows skipped.
   * @param array<int, array{index: int, symbol: string, reason: string}> $errors
   *   Structured list of error details for skipped rows.
   */
  public function __construct(
    private readonly int $imported,
    private readonly int $skipped,
    private readonly array $errors = [],
  ) {}

  /**
   * Gets the number of successfully imported rows.
   */
  public function getImportedCount(): int {
    return $this->imported;
  }

  /**
   * Gets the number of skipped rows.
   */
  public function getSkippedCount(): int {
    return $this->skipped;
  }

  /**
   * Gets the error items.
   *
   * @return array<int, array{index: int, symbol: string, reason: string}>
   */
  public function getErrors(): array {
    return $this->errors;
  }

  /**
   * Serializes the result to an array suitable for JSON API output.
   *
   * @return array{imported: int, skipped: int, errors: array}
   */
  public function toArray(): array {
    return [
      'imported' => $this->imported,
      'skipped' => $this->skipped,
      'errors' => $this->errors,
    ];
  }

}

