<?php
/**
 * Simple_Google_Adsense Manual Ads setup
 *
 * @package Simple_Google_Adsense
 * @since   1.2.0
 */

defined('ABSPATH') || exit;

/**
 * Main Simple_Google_Adsense_Manual_Ads Class.
 *
 * @class Simple_Google_Adsense_Manual_Ads
 */
final class Simple_Google_Adsense_Manual_Ads
{

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Manual_Ads
     * @since 1.2.0
     */
    protected static $_instance = null;

    /**
     * Main Simple_Google_Adsense_Manual_Ads Instance.
     *
     * Ensures only one instance of Simple_Google_Adsense_Manual_Ads is loaded or can be loaded.
     *
     * @return Simple_Google_Adsense_Manual_Ads - Main instance.
     * @since 1.2.0
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
     * Simple_Google_Adsense_Manual_Ads Constructor.
     */
    public function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Hook into actions and filters.
     *
     * @since 1.2.0
     */
    private function init_hooks()
    {
        // Register shortcodes
        add_shortcode('adsense', array($this, 'adsense_shortcode'));
        add_shortcode('adsense_banner', array($this, 'banner_ad_shortcode'));
        add_shortcode('adsense_inarticle', array($this, 'inarticle_ad_shortcode'));
        add_shortcode('adsense_infeed', array($this, 'infeed_ad_shortcode'));
        add_shortcode('adsense_matched_content', array($this, 'matched_content_shortcode'));

        // Add Gutenberg block
        add_action('init', array($this, 'register_blocks'));
    }

    /**
     * Main AdSense shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string
     */
    public function adsense_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'type' => 'banner',
            'ad_slot' => '',
            'ad_client' => '',
            'ad_format' => 'auto',
            'full_width_responsive' => 'true',
            'style' => '',
            'class' => ''
        ), $atts, 'adsense');

        if (!Simple_Google_Adsense_Settings::is_manual_ads_enabled()) {
            return $this->config_notice(
                __('Manual Ads are turned off.', 'simple-google-adsense'),
                __('Enable "Manual Ad Placement" under Settings → AdFlow to render this ad.', 'simple-google-adsense')
            );
        }

        $publisher_ad_client = Simple_Google_Adsense_Settings::get_ad_client();

        if ('' === $publisher_ad_client && empty($atts['ad_client'])) {
            return $this->config_notice(
                __('AdSense Publisher ID not configured.', 'simple-google-adsense'),
                __('Please go to Settings → AdFlow and enter your Publisher ID.', 'simple-google-adsense')
            );
        }

        if (empty($atts['ad_slot'])) {
            return $this->config_notice(
                __('Ad Slot ID is required.', 'simple-google-adsense'),
                __('Please configure the Ad Slot ID in the block settings or shortcode parameters.', 'simple-google-adsense')
            );
        }

        $ad_client = !empty($atts['ad_client']) ? $atts['ad_client'] : $publisher_ad_client;

        // Load the AdSense library and styles for this page.
        Simple_Google_Adsense_Frontend::enqueue_ad_assets();

        $style = !empty($atts['style']) ? ' style="' . esc_attr($atts['style']) . '"' : '';
        $class = !empty($atts['class']) ? ' ' . esc_attr($atts['class']) : '';

        $output = '<div class="adsense-ad' . $class . '"' . $style . '>';
        $output .= '<ins class="adsbygoogle"';
        $output .= ' style="display:block"';
        $output .= ' data-ad-client="' . esc_attr($ad_client) . '"';
        $output .= ' data-ad-slot="' . esc_attr($atts['ad_slot']) . '"';

        if ($atts['ad_format'] === 'auto') {
            $output .= ' data-ad-format="auto"';
            $output .= ' data-full-width-responsive="' . esc_attr($atts['full_width_responsive']) . '"';
        } else {
            $output .= ' data-ad-format="' . esc_attr($atts['ad_format']) . '"';
        }

        $output .= '></ins>';
        $output .= '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Build a configuration notice.
     *
     * Only users who can actually fix the problem see it; visitors get nothing
     * rather than a broken-looking error block in the middle of the content.
     *
     * @param string $title Short description of the problem.
     * @param string $message How to resolve it.
     * @return string
     * @since 1.3.0
     */
    private function config_notice($title, $message)
    {
        if (!current_user_can('manage_options')) {
            return '';
        }

        return '<div class="adsense-error">
                <p><strong>' . esc_html($title) . '</strong></p>
                <p>' . esc_html($message) . '</p>
            </div>';
    }

    /**
     * Banner ad shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string
     */
    public function banner_ad_shortcode($atts)
    {
        $atts = is_array($atts) ? $atts : array();
        $atts['type'] = 'banner';
        return $this->adsense_shortcode($atts);
    }

    /**
     * In-article ad shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string
     */
    public function inarticle_ad_shortcode($atts)
    {
        $atts = is_array($atts) ? $atts : array();
        $atts['type'] = 'inarticle';
        $atts['ad_format'] = 'fluid';
        return $this->adsense_shortcode($atts);
    }

    /**
     * In-feed ad shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string
     */
    public function infeed_ad_shortcode($atts)
    {
        $atts = is_array($atts) ? $atts : array();
        $atts['type'] = 'infeed';
        $atts['ad_format'] = 'fluid';
        return $this->adsense_shortcode($atts);
    }

    /**
     * Matched content shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string
     */
    public function matched_content_shortcode($atts)
    {
        $atts = is_array($atts) ? $atts : array();
        $atts['type'] = 'matched_content';
        $atts['ad_format'] = 'autorelaxed';
        return $this->adsense_shortcode($atts);
    }



    /**
     * Register Gutenberg blocks
     */
    public function register_blocks()
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        register_block_type(
            SIMPLE_GOOGLE_ADSENSE_ABSPATH . 'blocks/adsense-ad',
            array(
                'render_callback' => array($this, 'render_block'),
            )
        );
    }

    /**
     * Render Gutenberg block
     *
     * @param array $attributes Block attributes
     * @return string
     */
    public function render_block($attributes)
    {
        $ad_slot = isset($attributes['adSlot']) ? $attributes['adSlot'] : '';
        $ad_type = isset($attributes['adType']) ? $attributes['adType'] : 'banner';
        $ad_format = isset($attributes['adFormat']) ? $attributes['adFormat'] : 'auto';
        $full_width_responsive = isset($attributes['fullWidthResponsive']) ? $attributes['fullWidthResponsive'] : true;

        $shortcode_atts = array(
            'ad_slot' => $ad_slot,
            'type' => $ad_type,
            'ad_format' => $ad_format,
            'full_width_responsive' => $full_width_responsive ? 'true' : 'false'
        );

        return $this->adsense_shortcode($shortcode_atts);
    }

    /**
     * Get available ad types
     *
     * @return array
     */
    public static function get_ad_types()
    {
        return array(
            'banner' => __('Banner Ad', 'simple-google-adsense'),
            'inarticle' => __('In-Article Ad', 'simple-google-adsense'),
            'infeed' => __('In-Feed Ad', 'simple-google-adsense'),
            'matched_content' => __('Matched Content', 'simple-google-adsense')
        );
    }

    /**
     * Get available ad formats
     *
     * @return array
     */
    public static function get_ad_formats()
    {
        return array(
            'auto' => __('Auto', 'simple-google-adsense'),
            'fluid' => __('Fluid', 'simple-google-adsense'),
            'autorelaxed' => __('Auto Relaxed', 'simple-google-adsense'),
            'rectangle' => __('Rectangle', 'simple-google-adsense'),
            'horizontal' => __('Horizontal', 'simple-google-adsense'),
            'vertical' => __('Vertical', 'simple-google-adsense')
        );
    }
}
