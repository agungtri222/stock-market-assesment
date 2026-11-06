<?php

declare(strict_types=1);

namespace Drupal\market_feed\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\market_feed\Service\MarketFeedImporterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller handling the JSON import endpoint for market feed data.
 *
 * Machine-to-Machine (M2M) Authentication Strategy:
 * --------------------------------------------------
 * In a production architecture, this endpoint is invoked by automated data pipelines
 * and market vendor systems. While protected here via the 'import market feed data'
 * Drupal permission, enterprise M2M callers should authenticate via one of:
 * 1. OAuth2 Client Credentials Grant (recommended): Using Drupal's 'simple_oauth'
 *    module with JWT bearer tokens containing scoped claims (e.g., 'market_feed:write').
 * 2. Mutual TLS (mTLS): Terminated at the reverse proxy (Nginx/Envoy/Cloudflare),
 *    validating vendor client certificates against a private CA.
 * 3. HMAC-SHA256 Signatures: Vendor signs request payload with an API secret key
 *    passed in 'X-Signature-SHA256' and 'X-Timestamp' headers to prevent replay attacks.
 */
class MarketFeedApiController extends ControllerBase {

  /**
   * Constructs a new MarketFeedApiController.
   *
   * @param \Drupal\market_feed\Service\MarketFeedImporterInterface $importer
   *   The market feed importer service.
   */
  public function __construct(
    protected readonly MarketFeedImporterInterface $importer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('market_feed.importer'),
    );
  }

  /**
   * Handles POST /api/market-feed/import requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response with import summary.
   */
  public function import(Request $request): JsonResponse {
    $content = $request->getContent();
    if (empty($content)) {
      return new JsonResponse([
        'error' => 'Empty request body. Expected a JSON array of price items.',
      ], Response::HTTP_BAD_REQUEST);
    }

    try {
      $items = \json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      return new JsonResponse([
        'error' => 'Invalid JSON payload: ' . $e->getMessage(),
      ], Response::HTTP_BAD_REQUEST);
    }

    if (!\is_array($items) || (!empty($items) && !\array_is_list($items))) {
      return new JsonResponse([
        'error' => 'Malformed payload. Top-level element must be a JSON array of objects.',
      ], Response::HTTP_BAD_REQUEST);
    }

    // Delegate the upsert and validation logic to the injectable service.
    $result = $this->importer->import($items);

    return new JsonResponse($result->toArray(), Response::HTTP_OK);
  }

}

