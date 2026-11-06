<?php

declare(strict_types=1);

namespace Drupal\market_feed\Service;

/**
 * Interface for the Market Feed price ingestion service.
 */
interface MarketFeedImporterInterface {

  /**
   * The cache tag invalidated whenever market feed prices are updated.
   */
  public const CACHE_TAG = 'market_feed_prices:list';

  /**
   * Maximum number of rows processed in a single bulk upsert transaction chunk.
   *
   * Keeps SQL query payload within max_allowed_packet and memory bounds while
   * avoiding single-row round-trips for 50,000-row payloads.
   */
  public const CHUNK_SIZE = 1000;

  /**
   * Ingests a raw array of price records into the storage backend.
   *
   * Performs an upsert: new symbols are inserted, existing symbols are updated.
   * Malformed records are validated and collected into the result errors array
   * without aborting the entire batch.
   *
   * @param array<int, mixed> $items
   *   The raw array of decoded JSON stock feed items.
   *
   * @return \Drupal\market_feed\Service\MarketFeedImportResult
   *   The structured result detailing imported count, skipped count, and errors.
   */
  public function import(array $items): MarketFeedImportResult;

}

