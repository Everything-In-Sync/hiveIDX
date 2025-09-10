<?php

/**
 * GPRHI Hive MLS IDX Plugin - Public Functions
 *
 * This file contains all public-facing functions for the GPRHI Hive MLS IDX plugin,
 * including API communication, shortcode rendering, and data processing for real estate listings.
 */

// Security check: Prevent direct access to this file
// This ensures the file can only be loaded within the WordPress environment
if (!defined('ABSPATH')) {
  exit;
}

/**
 * Public-facing functions and hooks for GPRHI Hive MLS IDX plugin.
 * This file handles:
 * - API key retrieval from configuration
 * - OData API requests to SourceRE MLS database
 * - Shortcode rendering for property listings display
 * - Data filtering and pagination
 */

/**
 * Retrieve the API key for SourceRE MLS database authentication.
 *
 * This function checks for the SOURCERE_API_KEY constant defined in wp-config.php
 * which is required for making authenticated API requests to the SourceRE OData service.
 *
 * @return string The API key string if defined, empty string otherwise
 */
function gprhi_get_api_key() {
  // Check if the API key constant is defined in WordPress configuration
  // Return the key if available, otherwise return empty string
  return defined('SOURCERE_API_KEY') ? SOURCERE_API_KEY : '';
}

/**
 * Build and execute an OData request to retrieve property listings from SourceRE MLS database.
 *
 * This function constructs OData queries to fetch property listings with various filtering,
 * sorting, and pagination options. It handles caching, error management, and data transformation.
 *
 * @param array $params Query parameters including filters, pagination, and sorting options
 * @return array{items: array, total: int}|array{items: array, total: int, error: string} Array with listings and metadata, or error information
 */
function gprhi_api_get(array $params) {
  // Setup API endpoint URL
  // Use custom base URL if defined, fallback to SourceRE default
  $base = defined('GPRHI_API_BASE') ? GPRHI_API_BASE : (defined('SOURCERE_API_BASE') ? SOURCERE_API_BASE : 'https://api.sourceredb.com/odata/');
  $url = trailingslashit($base) . 'Property';

  // Helper function for OData-escaping single-quoted strings
  // OData requires single quotes in strings to be escaped as double single quotes
  $quote = static function($value) {
    $v = (string) $value;
    return "'" . str_replace("'", "''", $v) . "'";
  };

  // Setup pagination parameters
  // Ensure limit is at least 1, default to 12 listings per page
  $limit = max(1, intval($params['limit'] ?? 12));
  // Ensure page is at least 1, default to first page
  $page  = max(1, intval($params['page']  ?? 1));
  // Limit maximum items per request to prevent API overload (OData $top limit)
  $top   = min($limit, 1000);
  // Calculate number of items to skip for pagination
  $skip  = ($page - 1) * $top;

  // Build OData filter conditions based on user parameters
  $filters = [];

  // Location filter - match exact city name
  if (!empty($params['city'])) {
    $filters[] = 'City eq ' . $quote($params['city']);
  }

  // Price range filters - greater than or equal to min price
  if (!empty($params['min_price'])) {
    $filters[] = 'ListPrice ge ' . intval($params['min_price']);
  }

  // Price range filters - less than or equal to max price
  if (!empty($params['max_price'])) {
    $filters[] = 'ListPrice le ' . intval($params['max_price']);
  }

  // Bedroom filter - minimum number of bedrooms
  if (!empty($params['beds'])) {
    $filters[] = 'BedroomsTotal ge ' . intval($params['beds']);
  }

  // Bathroom filter - minimum number of bathrooms
  if (!empty($params['baths'])) {
    $filters[] = 'BathroomsTotalInteger ge ' . intval($params['baths']);
  }

  // Property type filter - match exact property type
  if (!empty($params['property_type'])) {
    $filters[] = 'PropertyType eq ' . $quote($params['property_type']);
  }
  // Rental property filter - filter by property type for rentals
  // Uses PropertyType field to identify rental vs sale properties
  // Can be customized via 'gprhi_rental_property_type' filter hook
  if (isset($params['rental']) && $params['rental'] !== '') {
    $rental_flag = strtolower((string) $params['rental']);
    $rental_pt = apply_filters('gprhi_rental_property_type', 'Residential Lease', $params);
    if ($rental_flag === '1' || $rental_flag === 'true' || $rental_flag === 'yes') {
      // Include only rental properties
      $filters[] = 'PropertyType eq ' . $quote($rental_pt);
    } elseif ($rental_flag === '0' || $rental_flag === 'false' || $rental_flag === 'no') {
      // Exclude rental properties
      $filters[] = 'PropertyType ne ' . $quote($rental_pt);
    }
  }

  // Office, Agent, Team, and Status targeting filters
  // These allow filtering listings by specific real estate entities
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
  // Available listings filter - exclude non-active statuses
  // When enabled, filters out listings with inactive statuses like Closed, Canceled, Expired
  // Can be customized via 'gprhi_excluded_statuses' filter hook
  if (isset($params['available_only']) && $params['available_only'] !== '') {
    $avail_flag = strtolower((string) $params['available_only']);
    if ($avail_flag === '1' || $avail_flag === 'true' || $avail_flag === 'yes') {
      // Get list of statuses to exclude (customizable via filter)
      $excluded_statuses = apply_filters('gprhi_excluded_statuses', ['Closed', 'Canceled', 'Expired'], $params);
      foreach ($excluded_statuses as $ex_status) {
        if ($ex_status === '' || !is_string($ex_status)) { continue; }
        $filters[] = 'StandardStatus ne ' . $quote($ex_status);
      }
    }
  }

  // Combine all filters with AND operator for OData query
  $filter = implode(' and ', $filters);

  // Construct OData query parameters
  $odata = [
    // Select only the fields needed for listing display to optimize API response
    '$select' => 'ListingKey,UnparsedAddress,City,ListPrice,BedroomsTotal,BathroomsTotalInteger',
    // Expand media collection with URL and order, sorted by display order
    '$expand' => 'Media($select=MediaURL,Order;$orderby=Order asc)',
    // Default sort by most recently modified, can be overridden by user
    '$orderby' => !empty($params['orderby']) ? $params['orderby'] : 'APIModificationTimestamp desc',
    // Limit number of results returned
    '$top' => $top,
    // Number of results to skip for pagination
    '$skip' => $skip,
    // Include total count in response for pagination
    '$count' => 'true',
  ];

  // Add filters to query only if any filters were constructed
  if ($filter !== '') {
    $odata['$filter'] = $filter;
  }

  // Implement caching to improve performance and reduce API calls
  // Create unique cache key based on URL and query parameters
  $cache_key = 'gprhi_' . md5($url . '|' . wp_json_encode($odata));
  // Check if we have a cached response for this query
  $cached = get_transient($cache_key);
  // Return cached data if available to avoid unnecessary API calls
  if ($cached) return $cached;

  // Set up HTTP headers for API authentication
  $headers = [
    // Request JSON response format
    'Accept'        => 'application/json',
    // Include Bearer token for API authentication
    'Authorization' => 'Bearer ' . gprhi_get_api_key(),
  ];

  // Execute HTTP GET request to SourceRE OData API
  $resp = wp_remote_get(add_query_arg($odata, $url), [
    'headers' => $headers,
    // Set reasonable timeout for API response
    'timeout' => 12,
  ]);

  // Handle HTTP request errors
  if (is_wp_error($resp)) {
    error_log('GPRHI HTTP error: ' . $resp->get_error_message());
    return ['items' => [], 'total' => 0, 'error' => 'http'];
  }

  // Extract response data
  $code = wp_remote_retrieve_response_code($resp);
  $body = wp_remote_retrieve_body($resp);
  $json = json_decode($body, true);

  // Validate response - check for HTTP 200 and valid JSON structure
  if ($code !== 200 || !is_array($json)) {
    error_log('GPRHI bad response: ' . $code . ' body=' . substr($body, 0, 500));
    return ['items' => [], 'total' => 0, 'error' => 'bad_response'];
  }

  // Extract listings data with filter hook for customization
  $items = apply_filters('gprhi_results_path', $json['value'] ?? [], $json);
  // Get total count from OData response or fallback to array count
  $total = isset($json['@odata.count']) ? intval($json['@odata.count']) : count(is_array($items) ? $items : []);

  // Prepare final result structure
  $result = ['items' => is_array($items) ? $items : [], 'total' => intval($total)];
  // Cache the successful response for 5 minutes to improve performance
  set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
  return $result;
}

/**
 * Fetch a single property listing by its unique ListingKey for detailed view display.
 *
 * This function retrieves comprehensive property details including expanded media
 * and additional fields not needed in the listing grid view.
 *
 * @param string $listing_key The unique identifier for the property listing
 * @return array{item: array}|array{item: array, error: string} Array with single listing data or error information
 */
function gprhi_api_get_by_key($listing_key) {
  // Setup API endpoint URL (same as main function)
  $base = defined('GPRHI_API_BASE') ? GPRHI_API_BASE : (defined('SOURCERE_API_BASE') ? SOURCERE_API_BASE : 'https://api.sourceredb.com/odata/');
  $url = trailingslashit($base) . 'Property';

  // OData string escaping helper (same as main function)
  $quote = static function($value) {
    $v = (string) $value;
    return "'" . str_replace("'", "''", $v) . "'";
  };

  // Create filter to match the specific listing by its unique key
  $filter = 'ListingKey eq ' . $quote($listing_key);

  // Construct OData query for detailed property view
  $odata = [
    // Select comprehensive property fields for detail display
    '$select' => 'ListingKey,UnparsedAddress,City,ListPrice,BedroomsTotal,BathroomsTotalInteger,PublicRemarks,YearBuilt,LivingArea,LotSizeArea,StandardStatus,PropertyType',
    // Include all media with ordering
    '$expand' => 'Media($select=MediaURL,Order;$orderby=Order asc)',
    // Filter to specific listing
    '$filter' => $filter,
    // Limit to single result
    '$top' => 1,
  ];

  // HTTP request setup for authentication
  $headers = [
    'Accept'        => 'application/json',
    'Authorization' => 'Bearer ' . gprhi_get_api_key(),
  ];

  // Execute API request for single property
  $resp = wp_remote_get(add_query_arg($odata, $url), [
    'headers' => $headers,
    'timeout' => 12,
  ]);

  // Handle HTTP errors
  if (is_wp_error($resp)) {
    error_log('GPRHI single HTTP error: ' . $resp->get_error_message());
    return ['item' => [], 'error' => 'http'];
  }

  // Process API response
  $code = wp_remote_retrieve_response_code($resp);
  $body = wp_remote_retrieve_body($resp);
  $json = json_decode($body, true);

  // Validate response format
  if ($code !== 200 || !is_array($json)) {
    error_log('GPRHI single bad response: ' . $code . ' body=' . substr($body, 0, 500));
    return ['item' => [], 'error' => 'bad_response'];
  }

  // Extract the single property item from response
  $item = is_array($json['value'] ?? null) && !empty($json['value']) ? $json['value'][0] : [];
  return ['item' => is_array($item) ? $item : []];
}

/**
 * Main shortcode renderer for GPRHI Hive MLS IDX property listings.
 *
 * This function handles both grid view (multiple listings) and detail view (single listing).
 * It processes shortcode attributes, handles URL-based navigation, and renders the appropriate view.
 *
 * @param array $atts Shortcode attributes for filtering and display options
 * @return string HTML output for the listings display
 */
function gprhi_listings_shortcode($atts = []) {
  // Check for required API key before proceeding
  if (!gprhi_get_api_key()) return '<div class="source-re-error">Missing API key</div>';

  // Check for single listing detail view request via URL parameter
  // This allows users to click from grid view to see full property details
  $requested_key = isset($_GET['listing_key']) ? sanitize_text_field(wp_unslash($_GET['listing_key'])) : '';
  if ($requested_key !== '') {
    // Fetch detailed property information
    $single = gprhi_api_get_by_key($requested_key);
    // Handle case where listing is not found or API error occurred
    if (!empty($single['error']) || empty($single['item'])) {
      return '<div class="source-re-error">Listing not found</div>';
    }

    // Extract and sanitize property data for display
    $i = $single['item'];
    $id     = esc_attr($i['ListingKey'] ?? '');           // Unique listing identifier
    $addr   = esc_html($i['UnparsedAddress'] ?? '');      // Property street address
    $city   = esc_html($i['City'] ?? '');                 // City location
    $price  = isset($i['ListPrice']) ? number_format_i18n(floatval($i['ListPrice'])) : ''; // Formatted price
    $beds   = esc_html($i['BedroomsTotal'] ?? '');        // Number of bedrooms
    $baths  = esc_html($i['BathroomsTotalInteger'] ?? ''); // Number of bathrooms
    $yr     = esc_html($i['YearBuilt'] ?? '');            // Year property was built
    $area   = esc_html($i['LivingArea'] ?? '');           // Living area in square feet
    $lot    = esc_html($i['LotSizeArea'] ?? '');          // Lot size information
    $status = esc_html($i['StandardStatus'] ?? '');       // Listing status (Active, Pending, etc.)
    $type   = esc_html($i['PropertyType'] ?? '');         // Property type (House, Condo, etc.)
    $desc   = wp_kses_post($i['PublicRemarks'] ?? '');    // Property description with allowed HTML
    $media  = isset($i['Media']) && is_array($i['Media']) ? $i['Media'] : []; // Property photos/media

    // Start output buffering to capture HTML content
    ob_start();
    ?>
    <!-- Single Property Detail View -->
    <div class="gprhi-detail">
      <!-- Property header with price and address -->
      <div class="gprhi-detail-header">
        <div class="gprhi-detail-price"><?php echo $price ? '$' . $price : ''; ?></div>
        <div class="gprhi-detail-addr"><?php echo $addr; ?><?php echo $city ? ', ' . $city : ''; ?></div>
        <!-- Property specifications (beds, baths, area, etc.) -->
        <div class="gprhi-detail-specs">
          <?php if ($beds !== ''): ?><span><?php echo $beds; ?> bd</span><?php endif; ?>
          <?php if ($baths !== ''): ?><span><?php echo $baths; ?> ba</span><?php endif; ?>
          <?php if ($area !== ''): ?><span><?php echo esc_html($area); ?> sqft</span><?php endif; ?>
          <?php if ($yr !== ''): ?><span><?php echo esc_html($yr); ?></span><?php endif; ?>
          <?php if ($status !== ''): ?><span><?php echo esc_html($status); ?></span><?php endif; ?>
          <?php if ($type !== ''): ?><span><?php echo esc_html($type); ?></span><?php endif; ?>
        </div>
      </div>
      <!-- Property photo gallery -->
      <div class="gprhi-gallery">
        <?php if (!empty($media)): foreach ($media as $m): $u = esc_url($m['MediaURL'] ?? ''); if (!$u) continue; ?>
          <img src="<?php echo $u; ?>" loading="lazy" alt="<?php echo $addr ? $addr : 'Listing image'; ?>">
        <?php endforeach; endif; ?>
      </div>
      <!-- Property description if available -->
      <?php if ($desc): ?>
        <div class="gprhi-desc"><?php echo $desc; ?></div>
      <?php endif; ?>
      <!-- Inline CSS styles for single property detail view -->
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
    // Return the buffered HTML content for single listing view
    return ob_get_clean();
  }

  // Setup default shortcode attributes with user-provided overrides
  $a = shortcode_atts([
    // Location and property filters
    'city' => '',           // Filter by city name
    'min_price' => '',      // Minimum price filter
    'max_price' => '',      // Maximum price filter
    'beds' => '',           // Minimum bedroom count
    'baths' => '',          // Minimum bathroom count
    'property_type' => '',  // Filter by property type

    // Display and sorting options
    'orderby' => 'APIModificationTimestamp desc', // Sort order (default: newest first)
    'limit' => 12,          // Number of listings per page
    'page' => 1,            // Current page number

    // Office/Agent/Team/Status targeting filters
    'office_name' => '',    // Filter by listing office name
    'office_mlsid' => '',   // Filter by listing office MLS ID
    'agent_mlsid' => '',    // Filter by listing agent MLS ID
    'team_name' => '',      // Filter by team name
    'status' => 'Active',   // Filter by listing status

    // Special filters
    'rental' => '',         // Rental properties filter (1=true, 0=false)
    'available_only' => 'true', // Exclude inactive listings (1=true, 0=false)
  ], $atts, 'gprhi_listings');

  // Handle pagination via URL parameter for SEO-friendly navigation
  // This allows users to bookmark specific pages and enables browser back/forward
  $url_page = isset($_GET['gprhi_p']) ? intval($_GET['gprhi_p']) : 0;
  if ($url_page > 0) {
    $a['page'] = $url_page;
  }

  // Fetch listings data from API using configured filters and pagination
  $data = gprhi_api_get($a);
  // Handle API errors gracefully
  if (!empty($data['error'])) {
    return '<div class="source-re-error">Unable to load listings right now</div>';
  }

  // Extract data for template rendering
  $items = $data['items'];                           // Array of property listings
  $total = isset($data['total']) ? intval($data['total']) : count($items); // Total number of results
  $page  = max(1, intval($a['page']));              // Current page number (ensure >= 1)
  $limit = max(1, intval($a['limit']));             // Items per page (ensure >= 1)
  $pages = max(1, (int) ceil($total / $limit));     // Total number of pages

  // Start output buffering for grid view HTML
  ob_start();
  ?>
  <!-- Property Listings Grid Container -->
  <div class="source-re-grid">
    <?php foreach ($items as $i):
      // Extract and sanitize property data for grid display
      $id     = esc_attr($i['ListingKey'] ?? '');           // Unique listing identifier
      $addr   = esc_html($i['UnparsedAddress'] ?? '');      // Property street address
      $city   = esc_html($i['City'] ?? '');                 // City location
      $price  = isset($i['ListPrice']) ? number_format_i18n(floatval($i['ListPrice'])) : ''; // Formatted price
      $beds   = esc_html($i['BedroomsTotal'] ?? '');        // Number of bedrooms
      $baths  = esc_html($i['BathroomsTotalInteger'] ?? ''); // Number of bathrooms

      // Extract property photo for thumbnail display
      $media  = isset($i['Media']) && is_array($i['Media']) ? $i['Media'] : [];
      $first  = !empty($media) ? (isset($media[0]['MediaURL']) ? $media[0]['MediaURL'] : '') : '';
      $photo  = esc_url($first);

      // Create detail view URL by adding listing_key parameter to current page
      $detail = esc_url(add_query_arg('listing_key', $id, get_permalink()));
    ?>
      <!-- Individual Property Card -->
      <article class="source-re-card">
        <!-- Property thumbnail with link to detail view -->
        <a href="<?php echo $detail ?: '#'; ?>" class="source-re-thumb">
          <?php if ($photo): ?>
            <!-- Display property photo if available -->
            <img src="<?php echo $photo; ?>" alt="<?php echo $addr ? $addr : 'Listing'; ?>" loading="lazy">
          <?php else: ?>
            <!-- Show placeholder when no photo is available -->
            <div class="source-re-placeholder"></div>
          <?php endif; ?>
        </a>
        <!-- Property metadata section -->
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
  <!-- Pagination controls - only show if there are multiple pages -->
  <?php if ($pages > 1):
    // Preserve current filter parameters when building pagination URLs
    $common_args = [];
    foreach (['city','min_price','max_price','beds','baths','property_type','orderby','limit','office_name','office_mlsid','agent_mlsid','team_name','status','rental','available_only'] as $k) {
      if (isset($a[$k]) && $a[$k] !== '') { $common_args[$k] = $a[$k]; }
    }

    // Generate previous/next page URLs while maintaining all filters
    $prev_url = $page > 1 ? add_query_arg(array_merge($common_args, ['gprhi_p' => $page - 1]), get_permalink()) : '';
    $next_url = $page < $pages ? add_query_arg(array_merge($common_args, ['gprhi_p' => $page + 1]), get_permalink()) : '';
  ?>
    <!-- Pagination navigation -->
    <nav class="gprhi-pager">
      <div class="gprhi-pager-left">
        <?php if ($prev_url): ?><a href="<?php echo esc_url($prev_url); ?>">« Previous</a><?php endif; ?>
      </div>
      <div class="gprhi-pager-center">Page <?php echo esc_html((string)$page); ?> of <?php echo esc_html((string)$pages); ?></div>
      <div class="gprhi-pager-right">
        <?php if ($next_url): ?><a href="<?php echo esc_url($next_url); ?>">Next »</a><?php endif; ?>
      </div>
    </nav>
    <!-- CSS styles for pagination controls -->
    <style>
      .gprhi-pager {display:flex;align-items:center;justify-content:space-between;margin-top:16px}
      .gprhi-pager a {text-decoration:none;color:#2563eb}
      .gprhi-pager a:hover {text-decoration:underline}
      .gprhi-pager-center {color:#4b5563}
    </style>
  <?php endif; ?>

  <!-- CSS styles for property grid layout and cards -->
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
  // Return the complete HTML output for the listings grid
  return ob_get_clean();
}

/**
 * Register WordPress shortcodes during plugin initialization.
 *
 * This registers the main shortcode 'gprhi_listings' and provides a backward-compatible
 * alias 'source_re_listings' for existing implementations that may use the old name.
 */
add_action('init', function() {
  // Register the primary shortcode for GPRHI listings
  add_shortcode('gprhi_listings', 'gprhi_listings_shortcode');

  // Register backward-compatible alias for existing implementations
  add_shortcode('source_re_listings', 'gprhi_listings_shortcode');
});


