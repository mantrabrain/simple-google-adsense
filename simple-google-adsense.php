<?php
/**
 * Plugin Name:       AdFlow – Ad Inserter & Ad Manager: AdSense, Banner Ads & ads.txt
 * Plugin URI:        https://wordpress.org/plugins/simple-google-adsense/
 * Description:       Ad inserter and ad manager for Google AdSense, any ad network and your own banner ads: Auto Ads, reusable ad units, automatic placements, ads.txt, Consent Mode v2, sponsor ads with statistics, block, widget and shortcodes.
 * Version:           1.4.0
 * Author:            MantraBrain
 * Author URI:        https://mantrabrain.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       simple-google-adsense
 * Domain Path:       /languages
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      7.4
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define SIMPLE_GOOGLE_ADSENSE_PLUGIN_FILE.
if (!defined('SIMPLE_GOOGLE_ADSENSE_FILE')) {
    define('SIMPLE_GOOGLE_ADSENSE_FILE', __FILE__);
}

// Define SIMPLE_GOOGLE_ADSENSE_VERSION.
if (!defined('SIMPLE_GOOGLE_ADSENSE_VERSION')) {
    define('SIMPLE_GOOGLE_ADSENSE_VERSION', '1.4.0');
}

// Define SIMPLE_GOOGLE_ADSENSE_PLUGIN_URI.
if (!defined('SIMPLE_GOOGLE_ADSENSE_PLUGIN_URI')) {
    define('SIMPLE_GOOGLE_ADSENSE_PLUGIN_URI', plugins_url('', SIMPLE_GOOGLE_ADSENSE_FILE));
}

// Define SIMPLE_GOOGLE_ADSENSE_PLUGIN_DIR.
if (!defined('SIMPLE_GOOGLE_ADSENSE_PLUGIN_DIR')) {
    define('SIMPLE_GOOGLE_ADSENSE_PLUGIN_DIR', plugin_dir_path(SIMPLE_GOOGLE_ADSENSE_FILE));
}


// Include the main Simple_Google_Adsense class.
if (!class_exists('Simple_Google_Adsense')) {
    include_once dirname(__FILE__) . '/includes/class-simple-google-adsense.php';
}


/**
 * Main instance of Simple_Google_Adsense.
 *
 * Returns the main instance of WC to prevent the need to use globals.
 *
 * @return Simple_Google_Adsense
 * @since  1.0.0
 */
function simple_google_adsense_instance()
{
    return Simple_Google_Adsense::instance();
}

// Global for backwards compatibility.
$GLOBALS['simple-google-adsense-instance'] = simple_google_adsense_instance();

register_activation_hook(__FILE__, array('Simple_Google_Adsense', 'activate'));
register_deactivation_hook(__FILE__, 'simple_google_adsense_deactivate');

/**
 * Stop AdFlow's daily task while the plugin is inactive.
 *
 * @since 1.4.0
 */
function simple_google_adsense_deactivate()
{
    wp_clear_scheduled_hook('adflow_daily_maintenance');
}
