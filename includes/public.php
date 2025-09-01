<?php
// Exit if accessed directly.
if (!defined('ABSPATH')) {
  exit;
}

/**
 * Public-facing functions and hooks for GPRHI Hive MLS IDX plugin.
 */

/**
 * Get API key from wp-config.php.
 *
 * @return string
 */
function gprhi_get_api_key() {
  return defined('SOURCERE_API_KEY') ? SOURCERE_API_KEY : '';
}

/**
 * Build and execute an OData request to the Property resource.
 *
 * @param array $params
 * @return array{items: array, total: int}|array{items: array, total: int, error: string}
 */
function gprhi_api_get(array $params) {
  $base = defined('GPRHI_API_BASE') ? GPRHI_API_BASE : (defined('SOURCERE_API_BASE') ? SOURCERE_API_BASE : 'https://api.sourceredb.com/odata/');
  $url = trailingslashit($base) . 'Property';

  // Helper for OData-escaping single-quoted strings.
  $quote = static function($value) {
    $v = (string) $value;
    return "'" . str_replace("'", "''", $v) . "'";
  };

  // Paging
  $limit = max(1, intval($params['limit'] ?? 12));
  $page  = max(1, intval($params['page']  ?? 1));
  $top   = min($limit, 1000);
  $skip  = ($page - 1) * $top;

  // Filters
  $filters = [];
  if (!empty($params['city'])) {
    $filters[] = 'City eq ' . $quote($params['city']);
  }
  if (!empty($params['min_price'])) {
    $filters[] = 'ListPrice ge ' . intval($params['min_price']);
  }
  if (!empty($params['max_price'])) {
    $filters[] = 'ListPrice le ' . intval($params['max_price']);
  }
  if (!empty($params['beds'])) {
    $filters[] = 'BedroomsTotal ge ' . intval($params['beds']);
  }
  if (!empty($params['baths'])) {
    $filters[] = 'BathroomsTotalInteger ge ' . intval($params['baths']);
  }
  if (!empty($params['property_type'])) {
    $filters[] = 'PropertyType eq ' . $quote($params['property_type']);
  }
  $filter = implode(' and ', $filters);

  // OData query
  $odata = [
    '$select' => 'ListingKey,UnparsedAddress,City,ListPrice,BedroomsTotal,BathroomsTotalInteger',
    '$expand' => 'Media($select=MediaURL,Order;$orderby=Order asc)',
    '$orderby' => !empty($params['orderby']) ? $params['orderby'] : 'APIModificationTimestamp desc',
    '$top' => $top,
    '$skip' => $skip,
    '$count' => 'true',
  ];
  if ($filter !== '') {
    $odata['$filter'] = $filter;
  }

  $cache_key = 'gprhi_' . md5($url . '|' . wp_json_encode($odata));
  $cached = get_transient($cache_key);
  if ($cached) return $cached;

  $headers = [
    'Accept'        => 'application/json',
    'Authorization' => 'Bearer ' . gprhi_get_api_key(),
  ];

  $resp = wp_remote_get(add_query_arg($odata, $url), [
    'headers' => $headers,
    'timeout' => 12,
  ]);

  if (is_wp_error($resp)) {
    error_log('GPRHI HTTP error: ' . $resp->get_error_message());
    return ['items' => [], 'total' => 0, 'error' => 'http'];
  }

  $code = wp_remote_retrieve_response_code($resp);
  $body = wp_remote_retrieve_body($resp);
  $json = json_decode($body, true);

  if ($code !== 200 || !is_array($json)) {
    error_log('GPRHI bad response: ' . $code . ' body=' . substr($body, 0, 500));
    return ['items' => [], 'total' => 0, 'error' => 'bad_response'];
  }

  $items = apply_filters('gprhi_results_path', $json['value'] ?? [], $json);
  $total = isset($json['@odata.count']) ? intval($json['@odata.count']) : count(is_array($items) ? $items : []);

  $result = ['items' => is_array($items) ? $items : [], 'total' => intval($total)];
  set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
  return $result;
}

/**
 * Shortcode renderer for listings grid.
 *
 * @param array $atts
 * @return string
 */
function gprhi_listings_shortcode($atts = []) {
  if (!gprhi_get_api_key()) return '<div class="source-re-error">Missing API key</div>';

  $a = shortcode_atts([
    'city' => '',
    'min_price' => '',
    'max_price' => '',
    'beds' => '',
    'baths' => '',
    'property_type' => '',
    'orderby' => 'APIModificationTimestamp desc',
    'limit' => 12,
    'page' => 1,
  ], $atts, 'gprhi_listings');

  $data = gprhi_api_get($a);
  if (!empty($data['error'])) {
    return '<div class="source-re-error">Unable to load listings right now</div>';
  }

  $items = $data['items'];

  ob_start();
  ?>
  <div class="source-re-grid">
    <?php foreach ($items as $i):
      $id     = esc_attr($i['ListingKey'] ?? '');
      $addr   = esc_html($i['UnparsedAddress'] ?? '');
      $city   = esc_html($i['City'] ?? '');
      $price  = isset($i['ListPrice']) ? number_format_i18n(floatval($i['ListPrice'])) : '';
      $beds   = esc_html($i['BedroomsTotal'] ?? '');
      $baths  = esc_html($i['BathroomsTotalInteger'] ?? '');
      $media  = isset($i['Media']) && is_array($i['Media']) ? $i['Media'] : [];
      $first  = !empty($media) ? (isset($media[0]['MediaURL']) ? $media[0]['MediaURL'] : '') : '';
      $photo  = esc_url($first);
      $detail = '';
    ?>
      <article class="source-re-card">
        <a href="<?php echo $detail ?: '#'; ?>" class="source-re-thumb" <?php echo $detail ? '' : 'aria-disabled="true"'; ?>>
          <?php if ($photo): ?>
            <img src="<?php echo $photo; ?>" alt="<?php echo $addr ? $addr : 'Listing'; ?>" loading="lazy">
          <?php else: ?>
            <div class="source-re-placeholder"></div>
          <?php endif; ?>
        </a>
        <div class="source-re-meta">
          <div class="source-re-price"><?php echo $price ? '$' . $price : ''; ?></div>
          <div class="source-re-addr"><?php echo $addr; ?><?php echo $city ? ', ' . $city : ''; ?></div>
          <div class="source-re-specs">
            <?php if ($beds !== ''): ?><span><?php echo $beds; ?> bd</span><?php endif; ?>
            <?php if ($baths !== ''): ?><span><?php echo $baths; ?> ba</span><?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <style>
    .source-re-grid {display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
    .source-re-card {border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff}
    .source-re-thumb {display:block;aspect-ratio:4/3;background:#f3f4f6}
    .source-re-thumb img {width:100%;height:100%;object-fit:cover;display:block}
    .source-re-placeholder {width:100%;height:100%;background:#f3f4f6}
    .source-re-meta {padding:12px}
    .source-re-price {font-weight:600;font-size:1.125rem}
    .source-re-addr {color:#374151;margin-top:2px}
    .source-re-specs {display:flex;gap:12px;color:#4b5563;margin-top:8px;font-size:.95rem}
    .source-re-error {padding:12px;background:#fff4f4;border:1px solid #ffdada;border-radius:8px}
  </style>
  <?php
  return ob_get_clean();
}

// Register shortcodes on init. Provide a backward-compatible alias.
add_action('init', function() {
  add_shortcode('gprhi_listings', 'gprhi_listings_shortcode');
  add_shortcode('source_re_listings', 'gprhi_listings_shortcode');
});


