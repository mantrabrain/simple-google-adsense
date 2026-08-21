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

        add_filter('plugin_action_links_' . SIMPLE_GOOGLE_ADSENSE_BASENAME, array($this, 'action_links'));
        add_action('admin_init', array($this, 'settings'));
        add_action('admin_menu', array($this, 'option_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));

    }


    public function action_links($links)
    {
        $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=simple-google-adsense-settings')) . '">'
            . esc_html__('Settings', 'simple-google-adsense') . '</a>';

        array_unshift($links, $settings_link);

        return $links;

    }

    function sanitize($input)
    {
        $input = is_array($input) ? $input : array();

        $sanitized_input = array();

        $sanitized_input['publisher_id'] = isset($input['publisher_id'])
            ? sanitize_text_field($input['publisher_id'])
            : '';

        /*
         * An unchecked checkbox is simply absent from the submitted data, so the
         * value has to be written explicitly. Storing only the checked ones left
         * the option without the key, and the readers fall back to their default
         * of "on" - which made it impossible to turn Auto Ads off.
         */
        $sanitized_input['enable_auto_ads'] = !empty($input['enable_auto_ads']);
        $sanitized_input['enable_manual_ads'] = !empty($input['enable_manual_ads']);

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
            '',
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

        add_settings_field(
            'enable_auto_ads',
            __('Enable Auto Ads', 'simple-google-adsense'),
            array($this, 'enable_auto_ads_render'),
            'simple_google_adsense_page',
            'simple_google_adsense_section'
        );

        add_settings_field(
            'enable_manual_ads',
            __('Enable Manual Ads', 'simple-google-adsense'),
            array($this, 'enable_manual_ads_render'),
            'simple_google_adsense_page',
            'simple_google_adsense_section'
        );
    }

    function publisher_id_render()
    {
        $publisher_id = Simple_Google_Adsense_Settings::get('publisher_id');
        ?>
        <input type='text' name='simple_google_adsense_settings[publisher_id]'
               value='<?php echo esc_attr($publisher_id) ?>' placeholder="pub-1234567890123456">
        <p class="description">
            <?php printf(esc_html__('Enter your Google AdSense Publisher ID (e.g %s).', 'simple-google-adsense'), 'pub-1234567890123456'); ?>
            <br>
            <a href="https://support.google.com/adsense/answer/105516?hl=en" target="_blank" class="adsense-help-link">
                <span class="dashicons dashicons-external-alt"></span>
                <?php esc_html_e('How to find your Publisher ID', 'simple-google-adsense'); ?>
            </a>
        </p>
        <?php
    }

    function enable_auto_ads_render()
    {
        $enable_auto_ads = Simple_Google_Adsense_Settings::get('enable_auto_ads');
        ?>
        <label>
            <input type='checkbox' name='simple_google_adsense_settings[enable_auto_ads]' 
                   value='1' <?php checked((bool) $enable_auto_ads, true); ?>>
            <?php esc_html_e('Enable Google AdSense Auto Ads (recommended for beginners)', 'simple-google-adsense'); ?>
        </label>
        <p class="description"><?php esc_html_e('Auto Ads uses machine learning to automatically place ads on your website.', 'simple-google-adsense'); ?></p>
        <?php
    }

    function enable_manual_ads_render()
    {
        $enable_manual_ads = Simple_Google_Adsense_Settings::get('enable_manual_ads');
        ?>
        <label>
            <input type='checkbox' name='simple_google_adsense_settings[enable_manual_ads]' 
                   value='1' <?php checked((bool) $enable_manual_ads, true); ?>>
            <?php esc_html_e('Enable Manual Ad Placement (for advanced users)', 'simple-google-adsense'); ?>
        </label>
        <p class="description"><?php esc_html_e('Allows you to place ads manually using shortcodes and Gutenberg blocks.', 'simple-google-adsense'); ?></p>
        <?php
    }

    public function option_menu()
    {
        if (is_admin()) {
            add_options_page(__('AdFlow', 'simple-google-adsense'),
                __('AdFlow', 'simple-google-adsense'), 'manage_options',
                'simple-google-adsense-settings', array($this, 'options_page'));
        }
    }

    function options_page()
    {
        ?>
        <div class="wrap">
            <div class="adsense-settings-container">
                <div class="adsense-settings-main">
                    <div class="adsense-header">
                        <div class="adsense-header-main">
                            <div class="adsense-title"><?php esc_html_e('AdFlow Settings', 'simple-google-adsense'); ?></div>
                            <span class="adsense-version">v<?php echo esc_html(SIMPLE_GOOGLE_ADSENSE_VERSION); ?></span>
                        </div>
                    </div>
                    
                    <h2><?php esc_html_e('Configure the settings', 'simple-google-adsense'); ?></h2>
                    
                    <form id="adsense-settings-form" action='options.php' method='post'>
                        <?php
                        settings_fields('simple_google_adsense_page');
                        do_settings_sections('simple_google_adsense_page');
                        submit_button(__('Save Settings', 'simple-google-adsense'));
                        ?>
                    </form>
                    
                    <?php $this->render_review_section(); ?>
                </div>
                <div class="adsense-settings-sidebar">
                    <?php $this->render_documentation_sidebar(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue admin styles and scripts
     */
    public function enqueue_admin_styles()
    {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'settings_page_simple-google-adsense-settings') {
            wp_enqueue_style(
                'simple-google-adsense-admin-settings',
                SIMPLE_GOOGLE_ADSENSE_PLUGIN_URI . '/assets/css/admin/settings.css',
                array(),
                SIMPLE_GOOGLE_ADSENSE_VERSION . '.1'
            );
            
            wp_enqueue_script(
                'simple-google-adsense-admin-settings',
                SIMPLE_GOOGLE_ADSENSE_PLUGIN_URI . '/assets/js/admin-settings.js',
                array('jquery'),
                SIMPLE_GOOGLE_ADSENSE_VERSION,
                true
            );
        }
    }

    /**
     * Render documentation sidebar
     */
    public function render_documentation_sidebar()
    {
        ?>
        <div class="adsense-documentation-sidebar">
            <div class="adsense-doc-section">
                <h3><?php esc_html_e('🔑 How to Get Your IDs', 'simple-google-adsense'); ?></h3>
                
                <div class="id-guide">
                    <h4><?php esc_html_e('📋 Publisher ID (Required)', 'simple-google-adsense'); ?></h4>
                    <ol>
                        <li><?php esc_html_e('Go to', 'simple-google-adsense'); ?> <a href="https://www.google.com/adsense" target="_blank">adsense.google.com</a></li>
                        <li><?php esc_html_e('Sign in with your Google account', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Click "Account" → "Settings" → "Account information"', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Find your Publisher ID (starts with "pub-")', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Copy the full ID (e.g., pub-1234567890123456)', 'simple-google-adsense'); ?></li>
                    </ol>
                    <p><em><?php esc_html_e('Based on', 'simple-google-adsense'); ?> <a href="https://support.google.com/adsense/answer/105516?hl=en" target="_blank"><?php esc_html_e('official Google AdSense documentation', 'simple-google-adsense'); ?></a></em></p>
                    
                    <h4><?php esc_html_e('🎯 Ad Slot ID (For Manual Ads)', 'simple-google-adsense'); ?></h4>
                    <ol>
                        <li><?php esc_html_e('In AdSense, go to "Ads" → "By ad unit"', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Click "Create new ad unit"', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Choose ad type (Banner, In-article, etc.)', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Set size and format options', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Click "Create" and copy the Ad Slot ID', 'simple-google-adsense'); ?></li>
                    </ol>
                </div>
                
                                    <div class="id-usage">
                        <h4><?php esc_html_e('💡 How to Use These IDs', 'simple-google-adsense'); ?></h4>
                        <ul>
                            <li><strong><?php esc_html_e('Publisher ID:', 'simple-google-adsense'); ?></strong> <?php esc_html_e('Enter in the settings above for Auto Ads', 'simple-google-adsense'); ?></li>
                            <li><strong><?php esc_html_e('Ad Slot ID:', 'simple-google-adsense'); ?></strong> <?php esc_html_e('Use in shortcodes and Gutenberg blocks for Manual Ads', 'simple-google-adsense'); ?></li>
                            <li><strong><?php esc_html_e('Example Shortcode:', 'simple-google-adsense'); ?></strong> <code>[adsense ad_slot="YOUR_AD_SLOT_ID"]</code></li>
                            <li><strong><?php esc_html_e('Example Block:', 'simple-google-adsense'); ?></strong> <?php esc_html_e('Add "AdFlow Ad" block and enter Ad Slot ID', 'simple-google-adsense'); ?></li>
                        </ul>
                        
                        <div class="shortcode-examples">
                            <h5><?php esc_html_e('📝 Shortcode Examples:', 'simple-google-adsense'); ?></h5>
                            <ul>
                                <li><code>[adsense ad_slot="YOUR_AD_SLOT_ID"]</code> - <?php esc_html_e('Basic banner ad', 'simple-google-adsense'); ?></li>
                                <li><code>[adsense_banner ad_slot="YOUR_AD_SLOT_ID"]</code> - <?php esc_html_e('Banner ad specifically', 'simple-google-adsense'); ?></li>
                                <li><code>[adsense_inarticle ad_slot="YOUR_AD_SLOT_ID"]</code> - <?php esc_html_e('In-article ad', 'simple-google-adsense'); ?></li>
                                <li><code>[adsense_infeed ad_slot="YOUR_AD_SLOT_ID"]</code> - <?php esc_html_e('In-feed ad', 'simple-google-adsense'); ?></li>
                            </ul>
                            <p><em><?php esc_html_e('Replace "YOUR_AD_SLOT_ID" with the actual Ad Slot ID from your AdSense account', 'simple-google-adsense'); ?></em></p>
                        </div>
                    </div>
            </div>

            <div class="adsense-doc-section">
                <h3><?php esc_html_e('🚀 Getting Started (Step-by-Step)', 'simple-google-adsense'); ?></h3>
                <ol>
                    <li><strong><?php esc_html_e('Step 1:', 'simple-google-adsense'); ?></strong> <?php esc_html_e('Get your Google AdSense Publisher ID from your AdSense account', 'simple-google-adsense'); ?></li>
                    <li><strong><?php esc_html_e('Step 2:', 'simple-google-adsense'); ?></strong> <?php esc_html_e('Enter the Publisher ID in the field above', 'simple-google-adsense'); ?></li>
                    <li><strong><?php esc_html_e('Step 3:', 'simple-google-adsense'); ?></strong> <?php esc_html_e('Choose Auto Ads (recommended) or Manual Ads', 'simple-google-adsense'); ?></li>
                    <li><strong><?php esc_html_e('Step 4:', 'simple-google-adsense'); ?></strong> <?php esc_html_e('Click "Save Changes"', 'simple-google-adsense'); ?></li>
                    <li><strong><?php esc_html_e('Step 5:', 'simple-google-adsense'); ?></strong> <?php esc_html_e('Wait 24-48 hours for ads to appear', 'simple-google-adsense'); ?></li>
                </ol>
            </div>

            <div class="adsense-doc-section">
                <h3><?php esc_html_e('🎯 Auto Ads (Easiest Option)', 'simple-google-adsense'); ?></h3>
                <p><?php esc_html_e('Perfect for beginners! Google\'s artificial intelligence automatically places ads where they work best.', 'simple-google-adsense'); ?></p>
                <ul>
                    <li>✅ <?php esc_html_e('No technical knowledge required', 'simple-google-adsense'); ?></li>
                    <li>✅ <?php esc_html_e('Google handles everything automatically', 'simple-google-adsense'); ?></li>
                    <li>✅ <?php esc_html_e('Optimized for maximum earnings', 'simple-google-adsense'); ?></li>
                    <li>✅ <?php esc_html_e('Works on all devices (mobile, tablet, desktop)', 'simple-google-adsense'); ?></li>
                </ul>
                <p><em><?php esc_html_e('Just enable Auto Ads and Google will do the rest!', 'simple-google-adsense'); ?></em></p>
            </div>

            <div class="adsense-doc-section">
                <h3><?php esc_html_e('⚙️ Manual Ads (For Advanced Users)', 'simple-google-adsense'); ?></h3>
                <p><?php esc_html_e('Want more control? Place ads exactly where you want them using these methods:', 'simple-google-adsense'); ?></p>
                
                <h4><?php esc_html_e('Method 1: Copy & Paste Shortcodes', 'simple-google-adsense'); ?></h4>
                <p><?php esc_html_e('Copy these codes and paste them in your posts or pages:', 'simple-google-adsense'); ?></p>
                <div class="shortcode-examples">
                    <p><strong><?php esc_html_e('Basic Ad:', 'simple-google-adsense'); ?></strong></p>
                    <code>[adsense ad_slot="1234567890"]</code>
                    
                    <p><strong><?php esc_html_e('Banner Ad:', 'simple-google-adsense'); ?></strong></p>
                    <code>[adsense_banner ad_slot="1234567890"]</code>
                    
                    <p><strong><?php esc_html_e('In-Article Ad:', 'simple-google-adsense'); ?></strong></p>
                    <code>[adsense_inarticle ad_slot="1234567890"]</code>
                    
                    <p><strong><?php esc_html_e('In-Feed Ad:', 'simple-google-adsense'); ?></strong></p>
                    <code>[adsense_infeed ad_slot="1234567890"]</code>
                </div>
                
                <h4><?php esc_html_e('Method 2: Block Editor (Gutenberg)', 'simple-google-adsense'); ?></h4>
                <ol>
                    <li><?php esc_html_e('Edit any post or page', 'simple-google-adsense'); ?></li>
                    <li><?php esc_html_e('Click the "+" button to add a block', 'simple-google-adsense'); ?></li>
                    <li><?php esc_html_e('Search for "AdFlow Ad"', 'simple-google-adsense'); ?></li>
                    <li><?php esc_html_e('Add the block to your content', 'simple-google-adsense'); ?></li>
                    <li><?php esc_html_e('Enter your ad slot ID in the block settings', 'simple-google-adsense'); ?></li>
                </ol>
            </div>

            <div class="adsense-doc-section">
                <h3><?php esc_html_e('📊 Understanding Ad Types', 'simple-google-adsense'); ?></h3>
                <div class="ad-type-explanations">
                    <div class="ad-type">
                        <strong><?php esc_html_e('Banner Ads:', 'simple-google-adsense'); ?></strong>
                        <p><?php esc_html_e('Traditional rectangular ads that appear at the top, bottom, or sides of your website.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="ad-type">
                        <strong><?php esc_html_e('In-Article Ads:', 'simple-google-adsense'); ?></strong>
                        <p><?php esc_html_e('Ads that appear naturally within your article content, between paragraphs.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="ad-type">
                        <strong><?php esc_html_e('In-Feed Ads:', 'simple-google-adsense'); ?></strong>
                        <p><?php esc_html_e('Ads that appear in lists of content, like blog post lists or category pages.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="ad-type">
                        <strong><?php esc_html_e('Matched Content:', 'simple-google-adsense'); ?></strong>
                        <p><?php esc_html_e('Content recommendation ads that show related articles to your visitors.', 'simple-google-adsense'); ?></p>
                    </div>
                </div>
            </div>

            <div class="adsense-doc-section">
                <h3><?php esc_html_e('🎨 Ad Sizes & Formats', 'simple-google-adsense'); ?></h3>
                <div class="ad-format-explanations">
                    <div class="ad-format">
                        <strong><?php esc_html_e('Auto Format:', 'simple-google-adsense'); ?></strong>
                        <p><?php esc_html_e('Google automatically chooses the best size for each device and screen.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="ad-format">
                        <strong><?php esc_html_e('Fluid Format:', 'simple-google-adsense'); ?></strong>
                        <p><?php esc_html_e('Responsive ads that adapt to different screen sizes automatically.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="ad-format">
                        <strong><?php esc_html_e('Rectangle (300x250):', 'simple-google-adsense'); ?></strong>
                        <p><?php esc_html_e('Medium-sized ads that fit well in content areas.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="ad-format">
                        <strong><?php esc_html_e('Horizontal (728x90):', 'simple-google-adsense'); ?></strong>
                        <p><?php esc_html_e('Wide ads perfect for header or footer placement.', 'simple-google-adsense'); ?></p>
                    </div>
                </div>
            </div>

            <div class="adsense-doc-section">
                <h3><?php esc_html_e('🔧 Common Problems & Solutions', 'simple-google-adsense'); ?></h3>
                
                <div class="problem-solution">
                    <h4><?php esc_html_e('❌ Problem: Ads not showing up', 'simple-google-adsense'); ?></h4>
                    <ul>
                        <li><?php esc_html_e('Check that your Publisher ID is correct', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Make sure you\'ve waited 24-48 hours after setup', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Disable ad blockers in your browser', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Check if your AdSense account is approved', 'simple-google-adsense'); ?></li>
                    </ul>
                </div>
                
                <div class="problem-solution">
                    <h4><?php esc_html_e('❌ Problem: Ads look broken on mobile', 'simple-google-adsense'); ?></h4>
                    <ul>
                        <li><?php esc_html_e('Use "Auto" or "Fluid" ad formats', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Enable "Full Width Responsive" option', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Test on different devices', 'simple-google-adsense'); ?></li>
                    </ul>
                </div>
                
                <div class="problem-solution">
                    <h4><?php esc_html_e('❌ Problem: Too many ads showing', 'simple-google-adsense'); ?></h4>
                    <ul>
                        <li><?php esc_html_e('Reduce the number of ads per page', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Space ads further apart', 'simple-google-adsense'); ?></li>
                        <li><?php esc_html_e('Consider user experience', 'simple-google-adsense'); ?></li>
                    </ul>
                </div>
            </div>

            <div class="adsense-doc-section">
                <h3><?php esc_html_e('💡 Best Practices for Success', 'simple-google-adsense'); ?></h3>
                <div class="best-practices">
                    <div class="practice">
                        <strong>✅ <?php esc_html_e('Start Simple:', 'simple-google-adsense'); ?></strong>
                        <p><?php esc_html_e('Begin with Auto Ads - they\'re optimized for maximum earnings.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="practice">
                        <strong>✅ <?php esc_html_e('Quality Content:', 'simple-google-adsense'); ?></strong>
                        <p><?php esc_html_e('Focus on creating valuable content - ads perform better on quality sites.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="practice">
                        <strong>✅ <?php esc_html_e('Mobile First:', 'simple-google-adsense'); ?></strong>
                        <p><?php esc_html_e('Most visitors use mobile devices, so ensure ads work well on phones.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="practice">
                        <strong>✅ <?php esc_html_e('Patience:', 'simple-google-adsense'); ?></strong>
                        <p><?php esc_html_e('It takes time to build traffic and earnings. Don\'t expect instant results.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="practice">
                        <strong>✅ <?php esc_html_e('Monitor Performance:', 'simple-google-adsense'); ?></strong>
                        <p><?php esc_html_e('Check your AdSense dashboard regularly to track earnings and performance.', 'simple-google-adsense'); ?></p>
                    </div>
                </div>
            </div>

            <div class="adsense-doc-section">
                <h3><?php esc_html_e('📖 Where to Get Help', 'simple-google-adsense'); ?></h3>
                <div class="help-resources">
                    <div class="resource">
                        <strong><?php esc_html_e('Google AdSense Help Center:', 'simple-google-adsense'); ?></strong>
                        <p><a href="https://support.google.com/adsense" target="_blank"><?php esc_html_e('Official Google AdSense documentation', 'simple-google-adsense'); ?></a></p>
                    </div>
                    
                    <div class="resource">
                        <strong><?php esc_html_e('Plugin Support:', 'simple-google-adsense'); ?></strong>
                        <p><a href="https://wordpress.org/plugins/simple-google-adsense/" target="_blank"><?php esc_html_e('Visit the plugin page for support', 'simple-google-adsense'); ?></a></p>
                    </div>
                    
                    <div class="resource">
                        <strong><?php esc_html_e('Developer Website:', 'simple-google-adsense'); ?></strong>
                        <p><a href="https://mantrabrain.com/" target="_blank"><?php esc_html_e('MantraBrain - Plugin developers', 'simple-google-adsense'); ?></a></p>
                    </div>
                </div>
            </div>

            <div class="adsense-doc-section">
                <h3><?php esc_html_e('🎯 Quick Tips for Beginners', 'simple-google-adsense'); ?></h3>
                <ul>
                    <li>🚀 <?php esc_html_e('Start with Auto Ads - they\'re the easiest to set up', 'simple-google-adsense'); ?></li>
                    <li>📱 <?php esc_html_e('Test your website on mobile devices', 'simple-google-adsense'); ?></li>
                    <li>⏰ <?php esc_html_e('Be patient - ads can take 24-48 hours to appear', 'simple-google-adsense'); ?></li>
                    <li>📊 <?php esc_html_e('Check your AdSense dashboard regularly', 'simple-google-adsense'); ?></li>
                    <li>💡 <?php esc_html_e('Focus on creating quality content first', 'simple-google-adsense'); ?></li>
                    <li>🔍 <?php esc_html_e('Use Google Analytics to understand your audience', 'simple-google-adsense'); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Render review section
     */
    public function render_review_section()
    {
        ?>
        <div class="adsense-review-section">
            <div class="adsense-review-content">
                <div class="adsense-review-header">
                    <span class="dashicons dashicons-star-filled"></span>
                    <h3><?php esc_html_e('Love this plugin?', 'simple-google-adsense'); ?></h3>
                </div>
                <p><?php esc_html_e('If AdFlow has helped you monetize your website, please consider leaving a review. Your feedback helps us improve and motivates us to add more features!', 'simple-google-adsense'); ?></p>
                <div class="adsense-review-actions">
                    <a href="https://wordpress.org/support/plugin/simple-google-adsense/reviews/?filter=5" target="_blank" class="button button-primary">
                        <span class="dashicons dashicons-external-alt"></span>
                        <?php esc_html_e('Leave a 5-Star Review', 'simple-google-adsense'); ?>
                    </a>
                    <a href="https://wordpress.org/support/plugin/simple-google-adsense/" target="_blank" class="button button-secondary">
                        <span class="dashicons dashicons-format-chat"></span>
                        <?php esc_html_e('Get Support', 'simple-google-adsense'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    public function setting_section_callback()
    {
        // Empty callback - no description needed
    }



    /**
     * Include required core files used in frontend.
     */
    public function includes()
    {

        include_once SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'includes/admin/dashboard/class-mantrabrain-admin-dashboard.php';
    }


}
