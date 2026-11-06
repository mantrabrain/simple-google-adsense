<?php
/**
 * Elementor widget: AdFlow Ad
 *
 * Loaded only from Elementor's own widget hook, when its base class exists.
 *
 * @package Simple_Google_Adsense
 * @since   1.6.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Elementor_Widget Class.
 */
class Simple_Google_Adsense_Elementor_Widget extends \Elementor\Widget_Base
{

    /**
     * Widget name.
     *
     * @return string
     */
    public function get_name()
    {
        return 'adflow_ad';
    }

    /**
     * Widget title.
     *
     * @return string
     */
    public function get_title()
    {
        return __('AdFlow Ad', 'simple-google-adsense');
    }

    /**
     * Widget icon.
     *
     * @return string
     */
    public function get_icon()
    {
        return 'eicon-banner';
    }

    /**
     * Panel categories.
     *
     * @return string[]
     */
    public function get_categories()
    {
        return array('general');
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
     * Ads rotate and follow rules per request: never store them in Elementor's element cache.
     *
     * @return bool
     */
    protected function is_dynamic_content(): bool
    {
        return true;
    }

    /**
     * Controls.
     */
    protected function register_controls()
    {
        $this->start_controls_section('adflow_content', array(
            'label' => __('Ad', 'simple-google-adsense'),
        ));

        $this->add_control('ad_id', array(
            'label' => __('Ad unit', 'simple-google-adsense'),
            'type' => \Elementor\Controls_Manager::SELECT,
            'options' => Simple_Google_Adsense_Integrations::choices(),
            'default' => '',
            'description' => __('Create ad units under AdFlow → Ad Units.', 'simple-google-adsense'),
        ));

        $this->end_controls_section();
    }

    /**
     * Front-end and editor output.
     */
    protected function render()
    {
        $settings = $this->get_settings_for_display();
        $ad_id = isset($settings['ad_id']) ? absint($settings['ad_id']) : 0;
        $plugin = \Elementor\Plugin::$instance;

        // In the editor and its preview an ad must not load or count.
        if (($plugin->editor && $plugin->editor->is_edit_mode()) || ($plugin->preview && $plugin->preview->is_preview_mode())) {
            echo Simple_Google_Adsense_Integrations::editor_preview($ad_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in editor_preview().
            return;
        }

        echo Simple_Google_Adsense_Integrations::render_unit($ad_id, 'elementor'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_unit().
    }
}
