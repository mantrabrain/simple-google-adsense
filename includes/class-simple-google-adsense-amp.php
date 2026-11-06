<?php
/**
 * Simple_Google_Adsense AMP output
 *
 * On AMP pages (the official AMP plugin) custom scripts are not allowed, so
 * AdSense units print `<amp-ad>` and Auto Ads print `<amp-auto-ads>`; the AMP
 * plugin adds the component scripts these need. Your own banners and text ads
 * print as plain markup, decided on the server. Other networks' code shows only
 * when it was written for AMP. Nothing here runs on normal pages.
 *
 * @package Simple_Google_Adsense
 * @since   1.6.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Amp Class.
 *
 * @class Simple_Google_Adsense_Amp
 */
final class Simple_Google_Adsense_Amp
{

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Amp
     */
    protected static $_instance = null;

    /**
     * Main instance.
     *
     * @return Simple_Google_Adsense_Amp
     */
    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor.
     */
    public function __construct()
    {
        add_action('wp_body_open', array($this, 'print_auto_ads'), 1);
        // The AMP plugin's Reader mode has its own templates.
        add_action('amp_post_template_body_open', array($this, 'print_auto_ads'), 1);
    }

    /**
     * Print Auto Ads right after <body>, where Google asks for it.
     *
     * @since 1.6.0
     */
    public function print_auto_ads()
    {
        if (!Simple_Google_Adsense_Settings::is_amp() || !Simple_Google_Adsense_Settings::auto_ads_allowed() || Simple_Google_Adsense_Consent::waits()) {
            return;
        }

        echo '<amp-auto-ads type="adsense" data-ad-client="' . esc_attr(Simple_Google_Adsense_Settings::get_ad_client()) . '"></amp-auto-ads>' . "\n";
    }

    /**
     * `<amp-ad>` for an AdSense unit, using Google's AMP ad code.
     *
     * @param array $ins Attributes of the `<ins>` the unit prints on normal pages.
     * @return string
     * @since 1.6.0
     */
    public static function ad_element($ins)
    {
        $format = isset($ins['data-ad-format']) ? $ins['data-ad-format'] : 'auto';
        $attrs = array(
            'type' => 'adsense',
            'data-ad-client' => isset($ins['data-ad-client']) ? $ins['data-ad-client'] : '',
            'data-ad-slot' => isset($ins['data-ad-slot']) ? $ins['data-ad-slot'] : '',
        );
        $sizes = array(
            'rectangle' => array('300', '250', 'fixed'),
            'horizontal' => array('728', '90', 'intrinsic'),
            'vertical' => array('160', '600', 'fixed'),
        );

        if (isset($sizes[$format])) {
            $attrs = array('width' => $sizes[$format][0], 'height' => $sizes[$format][1], 'layout' => $sizes[$format][2]) + $attrs;
            $inner = '';
        } else {
            // Google's responsive AMP code; Multiplex has its own auto format.
            $attrs = array('width' => '100vw', 'height' => '320') + $attrs + array(
                'data-auto-format' => 'autorelaxed' === $format ? 'mcrspv' : 'rspv',
                'data-full-width' => '',
            );
            $inner = '<div overflow=""></div>';
        }

        $html = '<amp-ad';

        foreach ($attrs as $name => $value) {
            $html .= ' ' . esc_attr($name) . '="' . esc_attr($value) . '"';
        }

        return $html . '>' . $inner . '</amp-ad>';
    }
}
