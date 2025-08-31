<?php
/**
 * Plugin Name: Gentry Prime Rentals – Hive MLS IDX (Lite)
 * Description: Server-side fetch of Hive MLS/SourceRE listings, rendered via shortcode with caching.
 * Version: 0.1.0
 */

/**
 * Security: Abort if this file is loaded directly (outside of WordPress).
 */
if (!defined('ABSPATH')) exit;

/**
 * 1) Configure your API base and key
 *    Add this to your site root wp-config.php (NOT inside the plugin):
 *    define('SOURCERE_API_KEY', 'YOUR_API_TOKEN');
 *    Base: https://api.sourceredb.com/odata/
 *    Resource: Property (RESO OData). Supports $filter, $select, $expand, $orderby, $top, $skip, $count.
 */
if (!defined('SOURCERE_API_BASE')) {
  define('SOURCERE_API_BASE', 'https://api.sourceredb.com/odata/'); // TODO: set actual base URL
}

/**
 * Read the API key from the site's wp-config.php.
 *
 * This avoids hard-coding secrets in the plugin. You should define
 *   define('SOURCERE_API_KEY', 'YOUR_API_TOKEN');
 * in the WordPress root wp-config.php.
 *
 * @return string API key or empty string if not set
 */
function source_re_get_api_key() {
  return defined('SOURCERE_API_KEY') ? SOURCERE_API_KEY : '';
}

/**
 * Fetch a page of listings from SourceRE RESO OData `Property` resource.
 *
 * Builds an OData query using shortcode params, calls the API with a
 * bearer token, caches results with a 5-minute transient, and returns a
 * normalized array: ['items' => [...], 'total' => N].
 *
 * Accepted $params keys (strings unless noted):
 * - city
 * - min_price (int)
 * - max_price (int)
 * - beds (int)
 * - baths (int)
 * - property_type
 * - orderby (e.g. 'APIModificationTimestamp desc')
 * - limit (int)
 * - page (int, 1-based)
 *
 * @param array $params
 * @return array{items: array, total: int}|array{items: array, total: int, error: string}
 */
function source_re_api_get(array $params) {
  $url = trailingslashit(SOURCERE_API_BASE) . 'Property';

  // OData helpers
  // Wrap string values in single quotes and escape single quotes per OData.
  $quote = static function($value) {
    $v = (string) $value;
    return "'" . str_replace("'", "''", $v) . "'"; // OData single-quote escaping
  };

  // Normalize shortcode params
  // Convert paging inputs to integers and enforce bounds per OData limits.
  $limit = max(1, intval($params['limit'] ?? 12));
  $page  = max(1, intval($params['page']  ?? 1));
  $top   = min($limit, 1000);
  $skip  = ($page - 1) * $top;

  // Build $filter
  // Assemble RESO/OData filters only for provided inputs.
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

  // Compose OData query
  // $select chooses fields we actually render; $expand pulls related Media.
  // $count=true asks the server to include @odata.count for pagination UX.
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

  // Cache key is derived from the endpoint + OData params. Change inputs, change cache.
  $cache_key = 'source_re_' . md5($url . '|' . wp_json_encode($odata));
  $cached = get_transient($cache_key);
  if ($cached) return $cached;

  // HTTP headers include our bearer token. Timeout is conservative to avoid slow page loads.
  $headers = [
    'Accept'        => 'application/json',
    'Authorization' => 'Bearer ' . source_re_get_api_key(),
  ];

  $resp = wp_remote_get(add_query_arg($odata, $url), [
    'headers' => $headers,
    'timeout' => 12,
  ]);

  // Network-level error handling.
  if (is_wp_error($resp)) {
    error_log('SourceRE HTTP error: ' . $resp->get_error_message());
    return ['items' => [], 'total' => 0, 'error' => 'http'];
  }

  $code = wp_remote_retrieve_response_code($resp);
  $body = wp_remote_retrieve_body($resp);
  $json = json_decode($body, true);

  // Basic response validation; log first 500 bytes for debugging if not 200/JSON.
  if ($code !== 200 || !is_array($json)) {
    error_log('SourceRE bad response: ' . $code . ' body=' . substr($body,0,500));
    return ['items' => [], 'total' => 0, 'error' => 'bad_response'];
  }

  // RESO OData: value + @odata.count
  $items = apply_filters('source_re_results_path', $json['value'] ?? [], $json);
  $total = isset($json['@odata.count']) ? intval($json['@odata.count']) : count(is_array($items) ? $items : []);

  // Cache for a short time to respect rate limits and improve page speed.
  $result = ['items' => is_array($items) ? $items : [], 'total' => intval($total)];
  set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS); // cache for 5 minutes
  return $result;
}

/**
 * Shortcode entry: renders a grid of properties.
 *
 * Usage example:
 *   [source_re_listings city="Austin" min_price="250000" max_price="750000" beds="3" baths="2" limit="12" page="1" property_type="Residential"]
 *
 * Attributes:
 * - city, min_price, max_price, beds, baths, property_type, orderby, limit, page
 *
 * @param array $atts Shortcode attributes
 * @return string HTML markup
 */
function source_re_listings_shortcode($atts = []) {
  if (!source_re_get_api_key()) return '<div class="source-re-error">Missing API key</div>';

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
  ], $atts, 'source_re_listings');

  $data = source_re_api_get($a);
  if (!empty($data['error'])) {
    return '<div class="source-re-error">Unable to load listings right now</div>';
  }

  $items = $data['items'];

  ob_start();
  ?>
  <div class="source-re-grid">
    <?php foreach ($items as $i):
      // Map RESO fields from each item to user-friendly variables for rendering.
      $id     = esc_attr($i['ListingKey'] ?? '');
      $addr   = esc_html($i['UnparsedAddress'] ?? '');
      $city   = esc_html($i['City'] ?? '');
      $price  = isset($i['ListPrice']) ? number_format_i18n(floatval($i['ListPrice'])) : '';
      $beds   = esc_html($i['BedroomsTotal'] ?? '');
      $baths  = esc_html($i['BathroomsTotalInteger'] ?? '');
      // Media may be expanded as an array of objects with MediaURL.
      // We choose the first image by order; you could iterate all of them for a gallery.
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
    /* Minimal, self-contained styles for the listing grid */
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
// Register the shortcode with WordPress.
add_shortcode('source_re_listings', 'source_re_listings_shortcode');
