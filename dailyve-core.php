<?php
/**
 * Plugin Name: Dailyve Core
 * Plugin URI: https://dailyve.com.vn/
 * Description: Core logic for Dailyve Booking System (Extracted from Flatsome Child Theme).
 * Version: 1.0.0
 * Author: Antigravity AI
 * Text Domain: dailyve-core
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DAILYVE_CORE_PATH', plugin_dir_path(__FILE__));

// Load Logic Modules
require_once DAILYVE_CORE_PATH . 'inc/taxonomies.php';
require_once DAILYVE_CORE_PATH . 'inc/acf-fields.php';

require_once DAILYVE_CORE_PATH . 'inc/api-functions.php';
require_once DAILYVE_CORE_PATH . 'inc/optimize-company.php';
require_once DAILYVE_CORE_PATH . 'inc/auth-functions.php';
// require_once DAILYVE_CORE_PATH . 'inc/tour-function.php';
require_once DAILYVE_CORE_PATH . 'inc/ctv-functions.php';
require_once DAILYVE_CORE_PATH . 'inc/custom-function.php';
require_once DAILYVE_CORE_PATH . 'inc/bmd-functions.php';
require_once DAILYVE_CORE_PATH . 'inc/admin-tickets.php';

