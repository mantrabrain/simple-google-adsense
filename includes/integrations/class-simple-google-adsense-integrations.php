<?php
/**
 * Simple_Google_Adsense page builder elements
 *
 * Loads the Elementor widget and the Bricks element, only when those builders
 * are active. Both choose a saved ad unit and render through render_unit().
 *
 * @package Simple_Google_Adsense
 * @since   1.6.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Integrations Class.
 *
 * @class Simple_Google_Adsense_Integrations
 */
final class Simple_Google_Adsense_Integrations
{

    /**
     * Hook the builders.
     *
     * @since 1.6.0
     */
    public static function init()
    {
        add_action('elementor/widgets/register', array(__CLASS__, 'register_elementor'));
        // Bricks documents init at priority 11 for custom elements.
        add_action('init', array(__CLASS__, 'register_bricks'), 11);
    }

    /**
     * Render a saved ad unit for a page builder.
     *
     * @param int $ad_id Ad unit ID.
     * @param string $source Builder name, for the render context.
     * @return string
     * @since 1.6.0
     */
    public static function render_unit($ad_id, $source)
    {
        $unit = Simple_Google_Adsense_Ad_Units::get(absint($ad_id));

        return $unit ? Simple_Google_Adsense_Manual_Ads::render_unit($unit, array('source' => $source)) : '';
    }

    /**
     * Stand-in shown inside a builder's editor: an ad must not load or count there.
     *
     * @param int $ad_id Ad unit ID.
     * @return string
     * @since 1.6.0
     */
    public static function editor_preview($ad_id)
    {
        $unit = Simple_Google_Adsense_Ad_Units::get(absint($ad_id));
        $choices = Simple_Google_Adsense_Ad_Units::get_choices();

        if ($unit && isset($choices[(int) $ad_id])) {
            /* translators: %s: ad unit name */
            $text = sprintf(__('AdFlow ad: %s', 'simple-google-adsense'), $choices[(int) $ad_id]);
        } else {
            $text = __('AdFlow ad: choose an ad unit.', 'simple-google-adsense');
        }

        return '<div style="padding:24px;border:1px dashed #8c8f94;background:#f6f7f7;color:#1d2327;font-size:14px;text-align:center">' . esc_html($text) . '</div>';
    }

    /**
     * Ad unit choices for a builder's select control.
     *
     * @return array id => name
     * @since 1.6.0
     */
    public static function choices()
    {
        return array('' => __('Choose an ad unit…', 'simple-google-adsense')) + Simple_Google_Adsense_Ad_Units::get_choices();
    }

    /**
     * Register the Elementor widget.
     *
     * @param object $widgets_manager Elementor's widgets manager.
     * @since 1.6.0
     */
    public static function register_elementor($widgets_manager)
    {
        if (!class_exists('\Elementor\Widget_Base')) {
            return;
        }

        include_once __DIR__ . '/class-simple-google-adsense-elementor-widget.php';
        $widgets_manager->register(new Simple_Google_Adsense_Elementor_Widget());
    }

    /**
     * Register the Bricks element.
     *
     * @since 1.6.0
     */
    public static function register_bricks()
    {
        if (!class_exists('\Bricks\Elements') || !class_exists('\Bricks\Element')) {
            return;
        }

        \Bricks\Elements::register_element(__DIR__ . '/class-simple-google-adsense-bricks-element.php');
    }
}
