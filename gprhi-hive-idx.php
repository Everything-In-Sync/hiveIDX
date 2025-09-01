<?php
/**
 * Plugin Name: Gentry Prime Rentals – Hive MLS IDX
 * Description: IDX integration via SourceRE RESO OData for Hive MLS. Provides a shortcode to render listings.
 * Version: 0.2.0
 */

// Security: prevent direct access to the file.
if (!defined('ABSPATH')) {
  exit;
}

// Basic plugin constants for paths/URLs.
if (!defined('GPRHI_VERSION')) {
  define('GPRHI_VERSION', '0.2.0');
}
if (!defined('GPRHI_PLUGIN_FILE')) {
  define('GPRHI_PLUGIN_FILE', __FILE__);
}
if (!defined('GPRHI_PLUGIN_DIR')) {
  define('GPRHI_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('GPRHI_PLUGIN_URL')) {
  define('GPRHI_PLUGIN_URL', plugin_dir_url(__FILE__));
}

// API base: prefer a plugin-specific constant but honor the previous one if already defined.
if (!defined('SOURCERE_API_BASE')) {
  define('SOURCERE_API_BASE', 'https://api.sourceredb.com/odata/');
}
if (!defined('GPRHI_API_BASE')) {
  define('GPRHI_API_BASE', SOURCERE_API_BASE);
}

// Load public-facing functionality.
require_once GPRHI_PLUGIN_DIR . 'includes/public.php';


