<?php
/**
 * Simple_Google_Adsense settings helper
 *
 * Central place for reading plugin options so that defaults, and the
 * normalisation of the Publisher ID, stay consistent between the admin
 * screen, the frontend Auto Ads snippet and the Manual Ads output.
 *
 * @package Simple_Google_Adsense
 * @since   1.3.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Settings Class.
 *
 * @class Simple_Google_Adsense_Settings
 */
final class Simple_Google_Adsense_Settings
{

    /**
     * Option name used to store the plugin settings.
     */
    const OPTION_NAME = 'simple_google_adsense_settings';

    /**
     * Default values for every stored setting.
     *
     * @return array
     * @since 1.3.0
     */
    public static function defaults()
    {
        return array(
            'publisher_id' => '',
            'enable_auto_ads' => true,
            'enable_manual_ads' => true,
        );
    }

    /**
     * Get all settings merged over the defaults.
     *
     * @return array
     * @since 1.3.0
     */
    public static function get_all()
    {
        $options = get_option(self::OPTION_NAME);

        if (!is_array($options)) {
            $options = array();
        }

        return wp_parse_args($options, self::defaults());
    }

    /**
     * Get a single setting.
     *
     * @param string $key Setting key.
     * @return mixed
     * @since 1.3.0
     */
    public static function get($key)
    {
        $options = self::get_all();

        return isset($options[$key]) ? $options[$key] : null;
    }

    /**
     * Get the stored Publisher ID, normalised to the `pub-XXXXXXXXXXXXXXXX` form.
     *
     * Users routinely paste the ID as `ca-pub-1234...` (the ad client) rather
     * than `pub-1234...`. Stripping the prefix here keeps the generated ad
     * client from ending up as `ca-ca-pub-1234...`, which never serves an ad.
     *
     * @return string Empty string when no Publisher ID has been configured.
     * @since 1.3.0
     */
    public static function get_publisher_id()
    {
        $publisher_id = trim((string) self::get('publisher_id'));

        if ('' === $publisher_id) {
            return '';
        }

        // Accept `ca-pub-XXXX`, `pub-XXXX` and a bare numeric ID.
        $publisher_id = preg_replace('/^ca-/i', '', $publisher_id);

        if (!preg_match('/^pub-/i', $publisher_id)) {
            $publisher_id = 'pub-' . $publisher_id;
        }

        return $publisher_id;
    }

    /**
     * Get the AdSense ad client (`ca-pub-XXXXXXXXXXXXXXXX`).
     *
     * @return string Empty string when no Publisher ID has been configured.
     * @since 1.3.0
     */
    public static function get_ad_client()
    {
        $publisher_id = self::get_publisher_id();

        return '' === $publisher_id ? '' : 'ca-' . $publisher_id;
    }

    /**
     * Whether Auto Ads should be printed on the frontend.
     *
     * @return bool
     * @since 1.3.0
     */
    public static function is_auto_ads_enabled()
    {
        return (bool) self::get('enable_auto_ads') && '' !== self::get_publisher_id();
    }

    /**
     * Whether Manual Ads (shortcodes and the block) should render ad markup.
     *
     * @return bool
     * @since 1.3.0
     */
    public static function is_manual_ads_enabled()
    {
        return (bool) self::get('enable_manual_ads');
    }
}
