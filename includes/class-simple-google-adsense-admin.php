<?php
/**
 * Simple_Google_Adsense admin setup
 *
 * @package Simple_Google_Adsense
 * @since   1.0.0
 */

defined('ABSPATH') || exit;

/**
 * Main Simple_Google_Adsense_Admin Class.
 *
 * @class Simple_Google_Adsense
 */
final class Simple_Google_Adsense_Admin
{

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Admin
     * @since 1.0.0
     */
    protected static $_instance = null;


    /**
     * Main Simple_Google_Adsense_Admin Instance.
     *
     * Ensures only one instance of Simple_Google_Adsense_Admin is loaded or can be loaded.
     *
     * @return Simple_Google_Adsense_Admin - Main instance.
     * @since 1.0.0
     * @static
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Simple_Google_Adsense Constructor.
     */
    public function __construct()
    {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Hook into actions and filters.
     *
     * @since 1.0.0
     */
    private function init_hooks()
    {

        add_filter('plugin_action_links', array($this, 'action_links'), 10, 2);
        add_action('admin_init', array($this, 'settings'));
        add_action('admin_menu', array($this, 'option_menu'));


    }


    public function action_links($links, $file)
    {
        if ($file == SIMPLE_GOOGLE_ADSENSE_BASENAME) {
            $links[] = '<a href="options-general.php?page=simple-google-adsense-settings">' . __('Settings', 'simple-google-adsense') . '</a>';
        }
        return $links;

    }

    function sanitize($input)
    {
        $sanitized_input = array();
        if (isset($input['publisher_id'])) {
            $sanitized_input['publisher_id'] = sanitize_text_field($input['publisher_id']);
        }
        return $sanitized_input;
    }

    public function settings()
    {
        $args = array(
            'sanitize_callback' => array($this, 'sanitize')
        );
        register_setting('simple_google_adsense_page', 'simple_google_adsense_settings', $args);

        add_settings_section(
            'simple_google_adsense_section',
            __('General Settings', 'simple-google-adsense'),
            array($this, 'setting_section_callback'),
            'simple_google_adsense_page'
        );

        add_settings_field(
            'publisher_id',
            __('Publisher ID', 'simple-google-adsense'),
            array($this, 'publisher_id_render'),
            'simple_google_adsense_page',
            'simple_google_adsense_section'
        );
    }

    function publisher_id_render()
    {
        $options = get_option('simple_google_adsense_settings');
        $publisher_id = isset($options['publisher_id']) ? $options['publisher_id'] : '';
        ?>
        <input type='text' name='simple_google_adsense_settings[publisher_id]'
               value='<?php echo esc_attr($publisher_id) ?>'>
        <p class="description"><?php printf(__('Enter your Google AdSense Publisher ID (e.g %s).', 'simple-google-adsense'), 'pub-1234567890123456'); ?></p>
        <?php
    }

    public function option_menu()
    {
        if (is_admin()) {
            add_options_page(__('Google AdSense', 'simple-google-adsense'),
                __('Google AdSense', 'simple-google-adsense'), 'manage_options',
                'simple-google-adsense-settings', array($this, 'options_page'));
        }
    }

    function options_page()
    {
        ?>
        <div class="wrap">
            <h2><?php __('Simple Google Adsense', 'simple-google-adsense') ?></h2>
            <form action='options.php' method='post'>
                <?php
                settings_fields('simple_google_adsense_page');
                do_settings_sections('simple_google_adsense_page');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function setting_section_callback()
    {

    }

    /**
     * Include required core files used in frontend.
     */
    public function includes()
    {

        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/admin/dashboard/class-mantrabrain-admin-dashboard.php';
    }


}
