<?php
/**
 * Simple_Google_Adsense setup
 *
 * @package Simple_Google_Adsense
 * @since   1.0.0
 */

defined('ABSPATH') || exit;

/**
 * Main Simple_Google_Adsense Class.
 *
 * @class Simple_Google_Adsense
 */
final class Simple_Google_Adsense
{

    /**
     * Simple_Google_Adsense version.
     *
     * @var string
     */
    public $version = SIMPLE_GOOGLE_ADSENSE_VERSION;

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense
     * @since 1.0.0
     */
    protected static $_instance = null;


    /**
     * Main Simple_Google_Adsense Instance.
     *
     * Ensures only one instance of Simple_Google_Adsense is loaded or can be loaded.
     *
     * @since 1.0.0
     * @static
     * @see mb_aec_addons()
     * @return Simple_Google_Adsense - Main instance.
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Cloning is forbidden.
     *
     * @since 1.0.0
     */
    public function __clone()
    {
        _doing_it_wrong(__FUNCTION__, esc_html__('Cloning is forbidden.', 'simple-google-adsense'), '1.0.0');
    }

    /**
     * Unserializing instances of this class is forbidden.
     *
     * @since 1.0.0
     */
    public function __wakeup()
    {
        _doing_it_wrong(__FUNCTION__, esc_html__('Unserializing instances of this class is forbidden.', 'simple-google-adsense'), '1.0.0');
    }

    /**
     * Simple_Google_Adsense Constructor.
     */
    public function __construct()
    {
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
        do_action('simple_google_adsense_loaded');
    }

    /**
     * Hook into actions and filters.
     *
     * @since 1.0.0
     */
    private function init_hooks()
    {

        add_action('init', array($this, 'init'), 0);


    }

    /**
     * Define Simple_Google_Adsense Constants.
     */
    private function define_constants()
    {

        $this->define('SIMPLE_GOOGLE_ADSENSE_ABSPATH', dirname(SIMPLE_GOOGLE_ADSENSE_FILE) . '/');
        $this->define('SIMPLE_GOOGLE_ADSENSE_BASENAME', plugin_basename(SIMPLE_GOOGLE_ADSENSE_FILE));
    }

    /**
     * Define constant if not already set.
     *
     * @param string $name Constant name.
     * @param string|bool $value Constant value.
     */
    private function define($name, $value)
    {
        if (!defined($name)) {
            define($name, $value);
        }
    }

    /**
     * What type of request is this?
     *
     * @param  string $type admin, ajax, cron or frontend.
     * @return bool
     */
    private function is_request($type)
    {
        switch ($type) {
            case 'admin':
                return is_admin();
            case 'ajax':
                return wp_doing_ajax();
            case 'cron':
                return wp_doing_cron();
            case 'frontend':
                return (!is_admin() || wp_doing_ajax()) && !wp_doing_cron() && !defined('REST_REQUEST');
        }
    }

    /**
     * Include required core files used in admin and on the frontend.
     */
    public function includes()
    {

        /**
         * Class autoloader.
         */
        //include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/admin/class-mantrabrain-admin-notices.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-settings.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-admin.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-frontend.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-manual-ads.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-ad-units.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-placements.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-ads-txt.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-inspector.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-upsell.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-dashboard.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-caps.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-stats.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-reports.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-migrate.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-consent.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-docs.php';


        if ($this->is_request('admin')) {
            Simple_Google_Adsense_Admin::instance();
        }

        if ($this->is_request('frontend')) {
            Simple_Google_Adsense_Frontend::instance();
        }


        // Initialize manual ads functionality
        Simple_Google_Adsense_Manual_Ads::instance();
        Simple_Google_Adsense_Ad_Units::instance();
        Simple_Google_Adsense_Placements::instance();
        Simple_Google_Adsense_Ads_Txt::instance();
        Simple_Google_Adsense_Inspector::instance();
        Simple_Google_Adsense_Caps::instance();
        Simple_Google_Adsense_Stats::instance();
        Simple_Google_Adsense_Reports::instance();
        Simple_Google_Adsense_Migrate::instance();
        Simple_Google_Adsense_Consent::instance();

        if (is_admin()) {
            Simple_Google_Adsense_Docs::instance();
            Simple_Google_Adsense_Upsell::instance();
        }

        add_action('widgets_init', array($this, 'register_widgets'));

    }


    /**
     * Register the AdFlow widget.
     *
     * @since 1.4.0
     */
    public function register_widgets()
    {
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-widget.php';

        register_widget('Simple_Google_Adsense_Widget');
    }

    /**
     * Plugin activation.
     *
     * @since 1.4.0
     */
    public static function activate()
    {
        if (!get_option('simple_google_adsense_installed')) {
            add_option('simple_google_adsense_installed', time(), '', false);
        }

        set_transient('simple_google_adsense_activation_redirect', 1, 30);
    }

    /**
     * Init Simple_Google_Adsense when WordPress Initialises.
     */
    public function init()
    {
        // Before init action.
        do_action('before_simple_google_adsense_init');

        // Set up localisation.
        $this->load_plugin_textdomain();


        // Init action.
        do_action('simple_google_adsense_init');
    }

    /**
     * Load Localisation files.
     *
     * Note: the first-loaded translation file overrides any following ones if the same translation is present.
     *
     * Locales found in:
     *      - WP_LANG_DIR/plugins/simple-google-adsense-LOCALE.mo
     *      - {plugin}/languages/simple-google-adsense-LOCALE.mo
     */
    public function load_plugin_textdomain()
    {
        load_plugin_textdomain(
            'simple-google-adsense',
            false,
            dirname(plugin_basename(SIMPLE_GOOGLE_ADSENSE_FILE)) . '/languages'
        );
    }


    /**
     * Get the plugin url.
     *
     * @return string
     */
    public function plugin_url()
    {
        return untrailingslashit(plugins_url('/', SIMPLE_GOOGLE_ADSENSE_FILE));
    }

    /**
     * Get the plugin path.
     *
     * @return string
     */
    public function plugin_path()
    {
        return untrailingslashit(plugin_dir_path(SIMPLE_GOOGLE_ADSENSE_FILE));
    }

    /**
     * Get the template path.
     *
     * @return string
     */
    public function template_path()
    {
        return apply_filters('simple_google_adsense_template_path', 'simple-google-adsense/');
    }

    /**
     * Get Ajax URL.
     *
     * @return string
     */
    public function ajax_url()
    {
        return admin_url('admin-ajax.php', 'relative');
    }


}
