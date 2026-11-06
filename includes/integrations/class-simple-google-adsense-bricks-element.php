<?php
/**
 * Bricks element: AdFlow Ad
 *
 * Loaded by \Bricks\Elements::register_element() only, when Bricks is active.
 *
 * @package Simple_Google_Adsense
 * @since   1.6.0
 */

defined('ABSPATH') || exit;

if (!class_exists('\Bricks\Element')) {
    return;
}

/**
 * Simple_Google_Adsense_Bricks_Element Class.
 */
class Simple_Google_Adsense_Bricks_Element extends \Bricks\Element
{

    /**
     * Element category.
     *
     * @var string
     */
    public $category = 'general';

    /**
     * Element name.
     *
     * @var string
     */
    public $name = 'adflow-ad';

    /**
     * Element icon.
     *
     * @var string
     */
    public $icon = 'ti-layout-media-center-alt';

    /**
     * Builder label.
     *
     * @return string
     */
    public function get_label()
    {
        return esc_html__('AdFlow Ad', 'simple-google-adsense');
    }

    /**
     * Search keywords.
     *
     * @return string[]
     */
    public function get_keywords()
    {
        return array('ad', 'ads', 'adsense', 'banner', 'adflow');
    }

    /**
     * Controls.
     */
    public function set_controls()
    {
        $this->controls['ad_id'] = array(
            'tab' => 'content',
            'label' => esc_html__('Ad unit', 'simple-google-adsense'),
            'type' => 'select',
            'options' => Simple_Google_Adsense_Ad_Units::get_choices(),
            'placeholder' => esc_html__('Choose an ad unit…', 'simple-google-adsense'),
        );
    }

    /**
     * Front-end and builder output.
     */
    public function render()
    {
        $ad_id = isset($this->settings['ad_id']) ? absint($this->settings['ad_id']) : 0;
        $in_builder = function_exists('bricks_is_builder') && (bricks_is_builder() || (function_exists('bricks_is_builder_call') && bricks_is_builder_call()));

        // In the builder an ad must not load or count.
        $html = $in_builder
            ? Simple_Google_Adsense_Integrations::editor_preview($ad_id)
            : Simple_Google_Adsense_Integrations::render_unit($ad_id, 'bricks');

        if ('' === $html) {
            return;
        }

        echo "<div {$this->render_attributes('_root')}>" . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_unit() / editor_preview().
    }
}
