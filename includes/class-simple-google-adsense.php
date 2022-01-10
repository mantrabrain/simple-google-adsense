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
        _doing_it_wrong(__FUNCTION__, __('Cloning is forbidden.', 'simple-google-adsense'), '1.0.0');
    }

    /**
     * Unserializing instances of this class is forbidden.
     *
     * @since 1.0.0
     */
    public function __wakeup()
    {
        _doing_it_wrong(__FUNCTION__, __('Unserializing instances of this class is forbidden.', 'simple-google-adsense'), '1.0.0');
    }

    /**
     * Auto-load in-accessible properties on demand.
     *
     * @param mixed $key Key name.
     * @return mixed
     */
    public function __get($key)
    {
        if (in_array($key, array(''), true)) {
            return $this->$key();
        }
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
                return defined('DOING_AJAX');
            case 'cron':
                return defined('DOING_CRON');
            case 'frontend':
                return (!is_admin() || defined('DOING_AJAX')) && !defined('DOING_CRON') && !defined('REST_REQUEST');
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
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-admin.php';
        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/class-simple-google-adsense-frontend.php';


        if ($this->is_request('admin')) {
            Simple_Google_Adsense_Admin::instance();
        }

        if ($this->is_request('frontend')) {
            Simple_Google_Adsense_Frontend::instance();
        }

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
     *      - WP_LANG_DIR/simple-google-adsense/simple-google-adsense-LOCALE.mo
     *      - WP_LANG_DIR/plugins/simple-google-adsense-LOCALE.mo
     */
    public function load_plugin_textdomain()
    {
        $locale = is_admin() && function_exists('get_user_locale') ? get_user_locale() : get_locale();
        $locale = apply_filters('plugin_locale', $locale, 'simple-google-adsense');
        unload_textdomain('simple-google-adsense');
        load_textdomain('simple-google-adsense', WP_LANG_DIR . '/simple-google-adsense/simple-google-adsense-' . $locale . '.mo');
        load_plugin_textdomain('simple-google-adsense', false, plugin_basename(dirname(SIMPLE_GOOGLE_ADSENSE_FILE)) . '/i18n/languages');
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
