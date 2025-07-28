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
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_block_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));

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
        if (isset($input['enable_manual_ads'])) {
            $sanitized_input['enable_manual_ads'] = (bool) $input['enable_manual_ads'];
        }
        if (isset($input['enable_auto_ads'])) {
            $sanitized_input['enable_auto_ads'] = (bool) $input['enable_auto_ads'];
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
        $options = get_option('simple_google_adsense_settings');
        $publisher_id = isset($options['publisher_id']) ? $options['publisher_id'] : '';
        ?>
        <input type='text' name='simple_google_adsense_settings[publisher_id]'
               value='<?php echo esc_attr($publisher_id) ?>' placeholder="pub-1234567890123456">
        <p class="description">
            <?php printf(__('Enter your Google AdSense Publisher ID (e.g %s).', 'simple-google-adsense'), 'pub-1234567890123456'); ?>
            <br>
            <a href="https://support.google.com/adsense/answer/105516?hl=en" target="_blank" class="adsense-help-link">
                <span class="dashicons dashicons-external-alt"></span>
                <?php _e('How to find your Publisher ID', 'simple-google-adsense'); ?>
            </a>
        </p>
        <?php
    }

    function enable_auto_ads_render()
    {
        $options = get_option('simple_google_adsense_settings');
        $enable_auto_ads = isset($options['enable_auto_ads']) ? $options['enable_auto_ads'] : true;
        ?>
        <label>
            <input type='checkbox' name='simple_google_adsense_settings[enable_auto_ads]' 
                   value='1' <?php checked($enable_auto_ads, true); ?>>
            <?php _e('Enable Google AdSense Auto Ads (recommended for beginners)', 'simple-google-adsense'); ?>
        </label>
        <p class="description"><?php _e('Auto Ads uses machine learning to automatically place ads on your website.', 'simple-google-adsense'); ?></p>
        <?php
    }

    function enable_manual_ads_render()
    {
        $options = get_option('simple_google_adsense_settings');
        $enable_manual_ads = isset($options['enable_manual_ads']) ? $options['enable_manual_ads'] : false;
        ?>
        <label>
            <input type='checkbox' name='simple_google_adsense_settings[enable_manual_ads]' 
                   value='1' <?php checked($enable_manual_ads, true); ?>>
            <?php _e('Enable Manual Ad Placement (for advanced users)', 'simple-google-adsense'); ?>
        </label>
        <p class="description"><?php _e('Allows you to place ads manually using shortcodes and Gutenberg blocks.', 'simple-google-adsense'); ?></p>
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
                            <div class="adsense-title"><?php _e('AdFlow Settings', 'simple-google-adsense'); ?></div>
                            <span class="adsense-version">v<?php echo SIMPLE_GOOGLE_ADSENSE_VERSION; ?></span>
                        </div>
                    </div>
                    
                    <h2><?php _e('Configure the settings', 'simple-google-adsense'); ?></h2>
                    
                    <form action='options.php' method='post'>
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
     * Enqueue block editor assets
     */
    public function enqueue_block_assets()
    {
        wp_enqueue_script(
            'simple-google-adsense-blocks',
            SIMPLE_GOOGLE_ADSENSE_PLUGIN_URI . '/assets/js/blocks.js',
            array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n'),
            SIMPLE_GOOGLE_ADSENSE_VERSION,
            true
        );
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
                <h3><?php _e('🔑 How to Get Your IDs', 'simple-google-adsense'); ?></h3>
                
                <div class="id-guide">
                    <h4><?php _e('📋 Publisher ID (Required)', 'simple-google-adsense'); ?></h4>
                    <ol>
                        <li><?php _e('Go to', 'simple-google-adsense'); ?> <a href="https://www.google.com/adsense" target="_blank">adsense.google.com</a></li>
                        <li><?php _e('Sign in with your Google account', 'simple-google-adsense'); ?></li>
                        <li><?php _e('Click "Account" → "Settings" → "Account information"', 'simple-google-adsense'); ?></li>
                        <li><?php _e('Find your Publisher ID (starts with "pub-")', 'simple-google-adsense'); ?></li>
                        <li><?php _e('Copy the full ID (e.g., pub-1234567890123456)', 'simple-google-adsense'); ?></li>
                    </ol>
                    <p><em><?php _e('Based on', 'simple-google-adsense'); ?> <a href="https://support.google.com/adsense/answer/105516?hl=en" target="_blank"><?php _e('official Google AdSense documentation', 'simple-google-adsense'); ?></a></em></p>
                    
                    <h4><?php _e('🎯 Ad Slot ID (For Manual Ads)', 'simple-google-adsense'); ?></h4>
                    <ol>
                        <li><?php _e('In AdSense, go to "Ads" → "By ad unit"', 'simple-google-adsense'); ?></li>
                        <li><?php _e('Click "Create new ad unit"', 'simple-google-adsense'); ?></li>
                        <li><?php _e('Choose ad type (Banner, In-article, etc.)', 'simple-google-adsense'); ?></li>
                        <li><?php _e('Set size and format options', 'simple-google-adsense'); ?></li>
                        <li><?php _e('Click "Create" and copy the Ad Slot ID', 'simple-google-adsense'); ?></li>
                    </ol>
                </div>
                
                                    <div class="id-usage">
                        <h4><?php _e('💡 How to Use These IDs', 'simple-google-adsense'); ?></h4>
                        <ul>
                            <li><strong><?php _e('Publisher ID:', 'simple-google-adsense'); ?></strong> <?php _e('Enter in the settings above for Auto Ads', 'simple-google-adsense'); ?></li>
                            <li><strong><?php _e('Ad Slot ID:', 'simple-google-adsense'); ?></strong> <?php _e('Use in shortcodes and Gutenberg blocks for Manual Ads', 'simple-google-adsense'); ?></li>
                            <li><strong><?php _e('Example Shortcode:', 'simple-google-adsense'); ?></strong> <code>[adsense ad_slot="YOUR_AD_SLOT_ID"]</code></li>
                            <li><strong><?php _e('Example Block:', 'simple-google-adsense'); ?></strong> <?php _e('Add "AdFlow Ad" block and enter Ad Slot ID', 'simple-google-adsense'); ?></li>
                        </ul>
                        
                        <div class="shortcode-examples">
                            <h5><?php _e('📝 Shortcode Examples:', 'simple-google-adsense'); ?></h5>
                            <ul>
                                <li><code>[adsense ad_slot="YOUR_AD_SLOT_ID"]</code> - <?php _e('Basic banner ad', 'simple-google-adsense'); ?></li>
                                <li><code>[adsense_banner ad_slot="YOUR_AD_SLOT_ID"]</code> - <?php _e('Banner ad specifically', 'simple-google-adsense'); ?></li>
                                <li><code>[adsense_inarticle ad_slot="YOUR_AD_SLOT_ID"]</code> - <?php _e('In-article ad', 'simple-google-adsense'); ?></li>
                                <li><code>[adsense_infeed ad_slot="YOUR_AD_SLOT_ID"]</code> - <?php _e('In-feed ad', 'simple-google-adsense'); ?></li>
                            </ul>
                            <p><em><?php _e('Replace "YOUR_AD_SLOT_ID" with the actual Ad Slot ID from your AdSense account', 'simple-google-adsense'); ?></em></p>
                        </div>
                    </div>
            </div>

            <div class="adsense-doc-section">
                <h3><?php _e('🚀 Getting Started (Step-by-Step)', 'simple-google-adsense'); ?></h3>
                <ol>
                    <li><strong><?php _e('Step 1:', 'simple-google-adsense'); ?></strong> <?php _e('Get your Google AdSense Publisher ID from your AdSense account', 'simple-google-adsense'); ?></li>
                    <li><strong><?php _e('Step 2:', 'simple-google-adsense'); ?></strong> <?php _e('Enter the Publisher ID in the field above', 'simple-google-adsense'); ?></li>
                    <li><strong><?php _e('Step 3:', 'simple-google-adsense'); ?></strong> <?php _e('Choose Auto Ads (recommended) or Manual Ads', 'simple-google-adsense'); ?></li>
                    <li><strong><?php _e('Step 4:', 'simple-google-adsense'); ?></strong> <?php _e('Click "Save Changes"', 'simple-google-adsense'); ?></li>
                    <li><strong><?php _e('Step 5:', 'simple-google-adsense'); ?></strong> <?php _e('Wait 24-48 hours for ads to appear', 'simple-google-adsense'); ?></li>
                </ol>
            </div>

            <div class="adsense-doc-section">
                <h3><?php _e('🎯 Auto Ads (Easiest Option)', 'simple-google-adsense'); ?></h3>
                <p><?php _e('Perfect for beginners! Google\'s artificial intelligence automatically places ads where they work best.', 'simple-google-adsense'); ?></p>
                <ul>
                    <li>✅ <?php _e('No technical knowledge required', 'simple-google-adsense'); ?></li>
                    <li>✅ <?php _e('Google handles everything automatically', 'simple-google-adsense'); ?></li>
                    <li>✅ <?php _e('Optimized for maximum earnings', 'simple-google-adsense'); ?></li>
                    <li>✅ <?php _e('Works on all devices (mobile, tablet, desktop)', 'simple-google-adsense'); ?></li>
                </ul>
                <p><em><?php _e('Just enable Auto Ads and Google will do the rest!', 'simple-google-adsense'); ?></em></p>
            </div>

            <div class="adsense-doc-section">
                <h3><?php _e('⚙️ Manual Ads (For Advanced Users)', 'simple-google-adsense'); ?></h3>
                <p><?php _e('Want more control? Place ads exactly where you want them using these methods:', 'simple-google-adsense'); ?></p>
                
                <h4><?php _e('Method 1: Copy & Paste Shortcodes', 'simple-google-adsense'); ?></h4>
                <p><?php _e('Copy these codes and paste them in your posts or pages:', 'simple-google-adsense'); ?></p>
                <div class="shortcode-examples">
                    <p><strong><?php _e('Basic Ad:', 'simple-google-adsense'); ?></strong></p>
                    <code>[adsense ad_slot="1234567890"]</code>
                    
                    <p><strong><?php _e('Banner Ad:', 'simple-google-adsense'); ?></strong></p>
                    <code>[adsense_banner ad_slot="1234567890"]</code>
                    
                    <p><strong><?php _e('In-Article Ad:', 'simple-google-adsense'); ?></strong></p>
                    <code>[adsense_inarticle ad_slot="1234567890"]</code>
                    
                    <p><strong><?php _e('In-Feed Ad:', 'simple-google-adsense'); ?></strong></p>
                    <code>[adsense_infeed ad_slot="1234567890"]</code>
                </div>
                
                <h4><?php _e('Method 2: Block Editor (Gutenberg)', 'simple-google-adsense'); ?></h4>
                <ol>
                    <li><?php _e('Edit any post or page', 'simple-google-adsense'); ?></li>
                    <li><?php _e('Click the "+" button to add a block', 'simple-google-adsense'); ?></li>
                    <li><?php _e('Search for "AdFlow Ad"', 'simple-google-adsense'); ?></li>
                    <li><?php _e('Add the block to your content', 'simple-google-adsense'); ?></li>
                    <li><?php _e('Enter your ad slot ID in the block settings', 'simple-google-adsense'); ?></li>
                </ol>
            </div>

            <div class="adsense-doc-section">
                <h3><?php _e('📊 Understanding Ad Types', 'simple-google-adsense'); ?></h3>
                <div class="ad-type-explanations">
                    <div class="ad-type">
                        <strong><?php _e('Banner Ads:', 'simple-google-adsense'); ?></strong>
                        <p><?php _e('Traditional rectangular ads that appear at the top, bottom, or sides of your website.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="ad-type">
                        <strong><?php _e('In-Article Ads:', 'simple-google-adsense'); ?></strong>
                        <p><?php _e('Ads that appear naturally within your article content, between paragraphs.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="ad-type">
                        <strong><?php _e('In-Feed Ads:', 'simple-google-adsense'); ?></strong>
                        <p><?php _e('Ads that appear in lists of content, like blog post lists or category pages.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="ad-type">
                        <strong><?php _e('Matched Content:', 'simple-google-adsense'); ?></strong>
                        <p><?php _e('Content recommendation ads that show related articles to your visitors.', 'simple-google-adsense'); ?></p>
                    </div>
                </div>
            </div>

            <div class="adsense-doc-section">
                <h3><?php _e('🎨 Ad Sizes & Formats', 'simple-google-adsense'); ?></h3>
                <div class="ad-format-explanations">
                    <div class="ad-format">
                        <strong><?php _e('Auto Format:', 'simple-google-adsense'); ?></strong>
                        <p><?php _e('Google automatically chooses the best size for each device and screen.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="ad-format">
                        <strong><?php _e('Fluid Format:', 'simple-google-adsense'); ?></strong>
                        <p><?php _e('Responsive ads that adapt to different screen sizes automatically.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="ad-format">
                        <strong><?php _e('Rectangle (300x250):', 'simple-google-adsense'); ?></strong>
                        <p><?php _e('Medium-sized ads that fit well in content areas.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="ad-format">
                        <strong><?php _e('Horizontal (728x90):', 'simple-google-adsense'); ?></strong>
                        <p><?php _e('Wide ads perfect for header or footer placement.', 'simple-google-adsense'); ?></p>
                    </div>
                </div>
            </div>

            <div class="adsense-doc-section">
                <h3><?php _e('🔧 Common Problems & Solutions', 'simple-google-adsense'); ?></h3>
                
                <div class="problem-solution">
                    <h4><?php _e('❌ Problem: Ads not showing up', 'simple-google-adsense'); ?></h4>
                    <ul>
                        <li><?php _e('Check that your Publisher ID is correct', 'simple-google-adsense'); ?></li>
                        <li><?php _e('Make sure you\'ve waited 24-48 hours after setup', 'simple-google-adsense'); ?></li>
                        <li><?php _e('Disable ad blockers in your browser', 'simple-google-adsense'); ?></li>
                        <li><?php _e('Check if your AdSense account is approved', 'simple-google-adsense'); ?></li>
                    </ul>
                </div>
                
                <div class="problem-solution">
                    <h4><?php _e('❌ Problem: Ads look broken on mobile', 'simple-google-adsense'); ?></h4>
                    <ul>
                        <li><?php _e('Use "Auto" or "Fluid" ad formats', 'simple-google-adsense'); ?></li>
                        <li><?php _e('Enable "Full Width Responsive" option', 'simple-google-adsense'); ?></li>
                        <li><?php _e('Test on different devices', 'simple-google-adsense'); ?></li>
                    </ul>
                </div>
                
                <div class="problem-solution">
                    <h4><?php _e('❌ Problem: Too many ads showing', 'simple-google-adsense'); ?></h4>
                    <ul>
                        <li><?php _e('Reduce the number of ads per page', 'simple-google-adsense'); ?></li>
                        <li><?php _e('Space ads further apart', 'simple-google-adsense'); ?></li>
                        <li><?php _e('Consider user experience', 'simple-google-adsense'); ?></li>
                    </ul>
                </div>
            </div>

            <div class="adsense-doc-section">
                <h3><?php _e('💡 Best Practices for Success', 'simple-google-adsense'); ?></h3>
                <div class="best-practices">
                    <div class="practice">
                        <strong>✅ <?php _e('Start Simple:', 'simple-google-adsense'); ?></strong>
                        <p><?php _e('Begin with Auto Ads - they\'re optimized for maximum earnings.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="practice">
                        <strong>✅ <?php _e('Quality Content:', 'simple-google-adsense'); ?></strong>
                        <p><?php _e('Focus on creating valuable content - ads perform better on quality sites.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="practice">
                        <strong>✅ <?php _e('Mobile First:', 'simple-google-adsense'); ?></strong>
                        <p><?php _e('Most visitors use mobile devices, so ensure ads work well on phones.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="practice">
                        <strong>✅ <?php _e('Patience:', 'simple-google-adsense'); ?></strong>
                        <p><?php _e('It takes time to build traffic and earnings. Don\'t expect instant results.', 'simple-google-adsense'); ?></p>
                    </div>
                    
                    <div class="practice">
                        <strong>✅ <?php _e('Monitor Performance:', 'simple-google-adsense'); ?></strong>
                        <p><?php _e('Check your AdSense dashboard regularly to track earnings and performance.', 'simple-google-adsense'); ?></p>
                    </div>
                </div>
            </div>

            <div class="adsense-doc-section">
                <h3><?php _e('📖 Where to Get Help', 'simple-google-adsense'); ?></h3>
                <div class="help-resources">
                    <div class="resource">
                        <strong><?php _e('Google AdSense Help Center:', 'simple-google-adsense'); ?></strong>
                        <p><a href="https://support.google.com/adsense" target="_blank"><?php _e('Official Google AdSense documentation', 'simple-google-adsense'); ?></a></p>
                    </div>
                    
                    <div class="resource">
                        <strong><?php _e('Plugin Support:', 'simple-google-adsense'); ?></strong>
                        <p><a href="https://wordpress.org/plugins/simple-google-adsense/" target="_blank"><?php _e('Visit the plugin page for support', 'simple-google-adsense'); ?></a></p>
                    </div>
                    
                    <div class="resource">
                        <strong><?php _e('Developer Website:', 'simple-google-adsense'); ?></strong>
                        <p><a href="https://mantrabrain.com/" target="_blank"><?php _e('MantraBrain - Plugin developers', 'simple-google-adsense'); ?></a></p>
                    </div>
                </div>
            </div>

            <div class="adsense-doc-section">
                <h3><?php _e('🎯 Quick Tips for Beginners', 'simple-google-adsense'); ?></h3>
                <ul>
                    <li>🚀 <?php _e('Start with Auto Ads - they\'re the easiest to set up', 'simple-google-adsense'); ?></li>
                    <li>📱 <?php _e('Test your website on mobile devices', 'simple-google-adsense'); ?></li>
                    <li>⏰ <?php _e('Be patient - ads can take 24-48 hours to appear', 'simple-google-adsense'); ?></li>
                    <li>📊 <?php _e('Check your AdSense dashboard regularly', 'simple-google-adsense'); ?></li>
                    <li>💡 <?php _e('Focus on creating quality content first', 'simple-google-adsense'); ?></li>
                    <li>🔍 <?php _e('Use Google Analytics to understand your audience', 'simple-google-adsense'); ?></li>
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
                    <h3><?php _e('Love this plugin?', 'simple-google-adsense'); ?></h3>
                </div>
                <p><?php _e('If AdFlow has helped you monetize your website, please consider leaving a review. Your feedback helps us improve and motivates us to add more features!', 'simple-google-adsense'); ?></p>
                <div class="adsense-review-actions">
                    <a href="https://wordpress.org/support/plugin/simple-google-adsense/reviews/?filter=5" target="_blank" class="button button-primary">
                        <span class="dashicons dashicons-external-alt"></span>
                        <?php _e('Leave a 5-Star Review', 'simple-google-adsense'); ?>
                    </a>
                    <a href="https://wordpress.org/support/plugin/simple-google-adsense/" target="_blank" class="button button-secondary">
                        <span class="dashicons dashicons-format-chat"></span>
                        <?php _e('Get Support', 'simple-google-adsense'); ?>
                    </a>
                </div>
                <div class="adsense-review-stats">
                    <div class="adsense-stat">
                        <span class="dashicons dashicons-download"></span>
                        <span class="stat-number">1,000+</span>
                        <span class="stat-label"><?php _e('Downloads', 'simple-google-adsense'); ?></span>
                    </div>
                    <div class="adsense-stat">
                        <span class="dashicons dashicons-star-filled"></span>
                        <span class="stat-number">4.8</span>
                        <span class="stat-label"><?php _e('Rating', 'simple-google-adsense'); ?></span>
                    </div>
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
