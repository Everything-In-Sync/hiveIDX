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
  // Rental filter using PropertyType only (safer). Override the value via 'gprhi_rental_property_type'.
  if (isset($params['rental']) && $params['rental'] !== '') {
    $rental_flag = strtolower((string) $params['rental']);
    $rental_pt = apply_filters('gprhi_rental_property_type', 'Residential Lease', $params);
    if ($rental_flag === '1' || $rental_flag === 'true' || $rental_flag === 'yes') {
      $filters[] = 'PropertyType eq ' . $quote($rental_pt);
    } elseif ($rental_flag === '0' || $rental_flag === 'false' || $rental_flag === 'no') {
      $filters[] = 'PropertyType ne ' . $quote($rental_pt);
    }
  }
  // Office/Agent/Team/Status targeting
  if (!empty($params['office_name'])) {
    $filters[] = 'ListOfficeName eq ' . $quote($params['office_name']);
  }
  if (!empty($params['office_mlsid'])) {
    $filters[] = 'ListOfficeMlsId eq ' . $quote($params['office_mlsid']);
  }
  if (!empty($params['agent_mlsid'])) {
    $filters[] = 'ListAgentMlsId eq ' . $quote($params['agent_mlsid']);
  }
  if (!empty($params['team_name'])) {
    $filters[] = 'ListTeamName eq ' . $quote($params['team_name']);
  }
  if (!empty($params['status'])) {
    $filters[] = 'StandardStatus eq ' . $quote($params['status']);
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
 * Fetch a single Property by ListingKey with expanded fields for detail view.
 *
 * @param string $listing_key
 * @return array{item: array}|array{item: array, error: string}
 */
function gprhi_api_get_by_key($listing_key) {
  $base = defined('GPRHI_API_BASE') ? GPRHI_API_BASE : (defined('SOURCERE_API_BASE') ? SOURCERE_API_BASE : 'https://api.sourceredb.com/odata/');
  $url = trailingslashit($base) . 'Property';

  $quote = static function($value) {
    $v = (string) $value;
    return "'" . str_replace("'", "''", $v) . "'";
  };

  $filter = 'ListingKey eq ' . $quote($listing_key);
  $odata = [
    '$select' => 'ListingKey,UnparsedAddress,City,ListPrice,BedroomsTotal,BathroomsTotalInteger,PublicRemarks,YearBuilt,LivingArea,LotSizeArea,StandardStatus,PropertyType',
    '$expand' => 'Media($select=MediaURL,Order;$orderby=Order asc)',
    '$filter' => $filter,
    '$top' => 1,
  ];

  $headers = [
    'Accept'        => 'application/json',
    'Authorization' => 'Bearer ' . gprhi_get_api_key(),
  ];

  $resp = wp_remote_get(add_query_arg($odata, $url), [
    'headers' => $headers,
    'timeout' => 12,
  ]);

  if (is_wp_error($resp)) {
    error_log('GPRHI single HTTP error: ' . $resp->get_error_message());
    return ['item' => [], 'error' => 'http'];
  }

  $code = wp_remote_retrieve_response_code($resp);
  $body = wp_remote_retrieve_body($resp);
  $json = json_decode($body, true);
  if ($code !== 200 || !is_array($json)) {
    error_log('GPRHI single bad response: ' . $code . ' body=' . substr($body, 0, 500));
    return ['item' => [], 'error' => 'bad_response'];
  }

  $item = is_array($json['value'] ?? null) && !empty($json['value']) ? $json['value'][0] : [];
  return ['item' => is_array($item) ? $item : []];
}

/**
 * Shortcode renderer for listings grid.
 *
 * @param array $atts
 * @return string
 */
function gprhi_listings_shortcode($atts = []) {
  if (!gprhi_get_api_key()) return '<div class="source-re-error">Missing API key</div>';

  // If a listing_key query param is present, render a single listing detail view
  $requested_key = isset($_GET['listing_key']) ? sanitize_text_field(wp_unslash($_GET['listing_key'])) : '';
  if ($requested_key !== '') {
    $single = gprhi_api_get_by_key($requested_key);
    if (!empty($single['error']) || empty($single['item'])) {
      return '<div class="source-re-error">Listing not found</div>';
    }

    $i = $single['item'];
    $id     = esc_attr($i['ListingKey'] ?? '');
    $addr   = esc_html($i['UnparsedAddress'] ?? '');
    $city   = esc_html($i['City'] ?? '');
    $price  = isset($i['ListPrice']) ? number_format_i18n(floatval($i['ListPrice'])) : '';
    $beds   = esc_html($i['BedroomsTotal'] ?? '');
    $baths  = esc_html($i['BathroomsTotalInteger'] ?? '');
    $yr     = esc_html($i['YearBuilt'] ?? '');
    $area   = esc_html($i['LivingArea'] ?? '');
    $lot    = esc_html($i['LotSizeArea'] ?? '');
    $status = esc_html($i['StandardStatus'] ?? '');
    $type   = esc_html($i['PropertyType'] ?? '');
    $desc   = wp_kses_post($i['PublicRemarks'] ?? '');
    $media  = isset($i['Media']) && is_array($i['Media']) ? $i['Media'] : [];

    ob_start();
    ?>
    <div class="gprhi-detail">
      <div class="gprhi-detail-header">
        <div class="gprhi-detail-price"><?php echo $price ? '$' . $price : ''; ?></div>
        <div class="gprhi-detail-addr"><?php echo $addr; ?><?php echo $city ? ', ' . $city : ''; ?></div>
        <div class="gprhi-detail-specs">
          <?php if ($beds !== ''): ?><span><?php echo $beds; ?> bd</span><?php endif; ?>
          <?php if ($baths !== ''): ?><span><?php echo $baths; ?> ba</span><?php endif; ?>
          <?php if ($area !== ''): ?><span><?php echo esc_html($area); ?> sqft</span><?php endif; ?>
          <?php if ($yr !== ''): ?><span><?php echo esc_html($yr); ?></span><?php endif; ?>
          <?php if ($status !== ''): ?><span><?php echo esc_html($status); ?></span><?php endif; ?>
          <?php if ($type !== ''): ?><span><?php echo esc_html($type); ?></span><?php endif; ?>
        </div>
      </div>
      <div class="gprhi-gallery">
        <?php if (!empty($media)): foreach ($media as $m): $u = esc_url($m['MediaURL'] ?? ''); if (!$u) continue; ?>
          <img src="<?php echo $u; ?>" loading="lazy" alt="<?php echo $addr ? $addr : 'Listing image'; ?>">
        <?php endforeach; endif; ?>
      </div>
      <?php if ($desc): ?>
        <div class="gprhi-desc"><?php echo $desc; ?></div>
      <?php endif; ?>
      <style>
        .gprhi-detail {display:block;}
        .gprhi-detail-header {margin-bottom:12px}
        .gprhi-detail-price {font-weight:700;font-size:1.5rem}
        .gprhi-detail-addr {color:#374151;margin-top:2px}
        .gprhi-detail-specs {display:flex;gap:12px;color:#4b5563;margin-top:8px}
        .gprhi-gallery {display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin:16px 0}
        .gprhi-gallery img {width:100%;height:220px;object-fit:cover;border-radius:8px}
        .gprhi-desc {white-space:pre-wrap;color:#374151;line-height:1.5}
      </style>
    </div>
    <?php
    return ob_get_clean();
  }

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
    // Office/Agent/Team/Status filters
    'office_name' => '',
    'office_mlsid' => '',
    'agent_mlsid' => '',
    'team_name' => '',
    'status' => '',
    // Rentals: '1'/'true' to include only rentals; '0'/'false' to exclude rentals
    'rental' => '',
  ], $atts, 'gprhi_listings');

  // Allow URL param to control pagination for on-page navigation
  $url_page = isset($_GET['gprhi_p']) ? intval($_GET['gprhi_p']) : 0;
  if ($url_page > 0) {
    $a['page'] = $url_page;
  }

  $data = gprhi_api_get($a);
  if (!empty($data['error'])) {
    return '<div class="source-re-error">Unable to load listings right now</div>';
  }

  $items = $data['items'];
  $total = isset($data['total']) ? intval($data['total']) : count($items);
  $page  = max(1, intval($a['page']));
  $limit = max(1, intval($a['limit']));
  $pages = max(1, (int) ceil($total / $limit));

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
      // Link to the same page with a query param so the shortcode renders a detail view
      $detail = esc_url(add_query_arg('listing_key', $id, get_permalink()));
    ?>
      <article class="source-re-card">
        <a href="<?php echo $detail ?: '#'; ?>" class="source-re-thumb">
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
  <?php if ($pages > 1):
    $common_args = [];
    foreach (['city','min_price','max_price','beds','baths','property_type','orderby','limit','office_name','office_mlsid','agent_mlsid','team_name','status','rental'] as $k) {
      if (isset($a[$k]) && $a[$k] !== '') { $common_args[$k] = $a[$k]; }
    }
    $prev_url = $page > 1 ? add_query_arg(array_merge($common_args, ['gprhi_p' => $page - 1]), get_permalink()) : '';
    $next_url = $page < $pages ? add_query_arg(array_merge($common_args, ['gprhi_p' => $page + 1]), get_permalink()) : '';
  ?>
    <nav class="gprhi-pager">
      <div class="gprhi-pager-left">
        <?php if ($prev_url): ?><a href="<?php echo esc_url($prev_url); ?>">« Previous</a><?php endif; ?>
      </div>
      <div class="gprhi-pager-center">Page <?php echo esc_html((string)$page); ?> of <?php echo esc_html((string)$pages); ?></div>
      <div class="gprhi-pager-right">
        <?php if ($next_url): ?><a href="<?php echo esc_url($next_url); ?>">Next »</a><?php endif; ?>
      </div>
    </nav>
    <style>
      .gprhi-pager {display:flex;align-items:center;justify-content:space-between;margin-top:16px}
      .gprhi-pager a {text-decoration:none;color:#2563eb}
      .gprhi-pager a:hover {text-decoration:underline}
      .gprhi-pager-center {color:#4b5563}
    </style>
  <?php endif; ?>
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


