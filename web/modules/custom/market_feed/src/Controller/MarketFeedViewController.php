<?php

declare(strict_types=1);

namespace Drupal\market_feed\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\market_feed\Service\MarketFeedImporterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller rendering the /market-feed stock prices dashboard.
 */
class MarketFeedViewController extends ControllerBase {

  /**
   * Constructs a new MarketFeedViewController.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The active database connection.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatting service.
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Renders the market feed table with symbol filter.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request.
   *
   * @return array
   *   A render array with cache tags, cache contexts, and search filter form.
   */
  public function view(Request $request): array {
    $symbolFilter = \trim((string) $request->query->get('symbol', ''));

    // Build Query with Parameterized Sanitization.
    $query = $this->database->select('market_feed_prices', 'mfp')
      ->fields('mfp', ['symbol', 'price', 'change_amount', 'volume', 'updated_at'])
      ->orderBy('mfp.symbol', 'ASC');

    if ($symbolFilter !== '') {
      // Partial prefix/symbol matching using safe placeholders.
      $query->condition('mfp.symbol', '%' . $this->database->escapeLike(\strtoupper($symbolFilter)) . '%', 'LIKE');
    }

    // Pagination for scalability (50 rows per page).
    $pagerQuery = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender')->limit(50);
    $records = $pagerQuery->execute()->fetchAll();

    // Build the Filter Form (pure GET form for SEO, bookmarking, and caching).
    $build['filter_form'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['market-feed-filter-bar', 'form--inline']],
      'form' => [
        '#type' => 'html_tag',
        '#tag' => 'form',
        '#attributes' => [
          'method' => 'GET',
          'action' => Url::fromRoute('market_feed.view')->toString(),
          'style' => 'display: flex; gap: 8px; margin-bottom: 20px; align-items: center;',
        ],
        'symbol' => [
          '#type' => 'html_tag',
          '#tag' => 'input',
          '#attributes' => [
            'type' => 'text',
            'name' => 'symbol',
            'placeholder' => $this->t('Filter by ticker symbol (e.g. AAPL)...'),
            'value' => $symbolFilter,
            'style' => 'padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; min-width: 250px;',
          ],
        ],
        'submit' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Filter'),
          '#attributes' => [
            'type' => 'submit',
            'style' => 'padding: 8px 16px; background-color: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 14px;',
          ],
        ],
        'reset' => [
          '#type' => 'link',
          '#title' => $this->t('Reset'),
          '#url' => Url::fromRoute('market_feed.view'),
          '#attributes' => [
            'style' => 'padding: 8px 16px; background-color: #666; color: #fff; text-decoration: none; border-radius: 4px; font-size: 14px;',
          ],
        ],
      ],
    ];

    // Build Results Table.
    $header = [
      $this->t('Symbol'),
      $this->t('Price ($)'),
      $this->t('Change ($)'),
      $this->t('Volume'),
      $this->t('Last Updated'),
    ];

    $rows = [];
    foreach ($records as $row) {
      $changeAmount = (float) $row->change_amount;
      $changeColor = $changeAmount > 0 ? '#1b8a3c' : ($changeAmount < 0 ? '#d32f2f' : '#666666');
      $changeSign = $changeAmount > 0 ? '+' : '';

      $rows[] = [
        ['data' => ['#markup' => '<strong>' . \htmlspecialchars($row->symbol, ENT_QUOTES, 'UTF-8') . '</strong>']],
        ['data' => ['#markup' => \number_format((float) $row->price, 2)]],
        [
          'data' => [
            '#markup' => \sprintf('<span style="color: %s; font-weight: 600;">%s%s</span>', $changeColor, $changeSign, \number_format($changeAmount, 2)),
          ],
        ],
        ['data' => ['#markup' => \number_format((int) $row->volume)]],
        ['data' => ['#markup' => $this->dateFormatter->format((int) $row->updated_at, 'short')]],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No stock prices found. Ingest price data via POST /api/market-feed/import or clear search filters.'),
      '#attributes' => ['class' => ['market-feed-table']],
    ];

    $build['pager'] = [
      '#type' => 'pager',
    ];

    // 4. Cache Metadata Strategy (Senior Requirement).
    // - Cache Tag 'market_feed_prices:list': Invalidation on import guarantees render cache stays fresh.
    // - Cache Context 'url.query_args:symbol': Caches unique query variants independently.
    // - Cache Context 'url.query_args.pagers': Ensures pagination state is cached properly.
    // - Cache Context 'user.permissions': Segregates access control state.
    $build['#cache'] = [
      'tags' => [
        MarketFeedImporterInterface::CACHE_TAG,
      ],
      'contexts' => [
        'url.query_args:symbol',
        'url.query_args.pagers',
        'user.permissions',
      ],
      'max-age' => 300,
    ];

    return $build;
  }

}

