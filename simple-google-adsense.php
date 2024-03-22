<?php
/**
 * Plugin Name:       Simple Google AdSense
 * Plugin URI:        https://wordpress.org/plugins/simple-google-adsense/
 * Description:       Add google adsense code to your WordPress site. No need to configure too much, just put publisher ID from Settings->Google AdSense.
 * Version:           1.1
 * Author:            MantraBrain
 * Author URI:        https://mantrabrain.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       simple-google-adsense
 * Domain Path:       /languages
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
    define('SIMPLE_GOOGLE_ADSENSE_VERSION', '1.1');
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
