<?php
/**
 * Simple_Google_Adsense widget
 *
 * Classic widget that renders a saved Ad Unit. The widget class name matches
 * the `.widget_simple_google_adsense_widget` rule already in adsense.css.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Widget Class.
 *
 * @class Simple_Google_Adsense_Widget
 */
class Simple_Google_Adsense_Widget extends WP_Widget
{

    /**
     * Simple_Google_Adsense_Widget Constructor.
     */
    public function __construct()
    {
        parent::__construct(
            'simple_google_adsense_widget',
            __('AdFlow Ad', 'simple-google-adsense'),
            array(
                'classname' => 'widget_simple_google_adsense_widget',
                'description' => __('Show one of your AdFlow ad units.', 'simple-google-adsense'),
                'customize_selective_refresh' => true,
            )
        );
    }

    /**
     * Front-end output.
     *
     * @param array $args Sidebar args.
     * @param array $instance Widget settings.
     */
    public function widget($args, $instance)
    {
        $unit = Simple_Google_Adsense_Ad_Units::get(isset($instance['ad_id']) ? $instance['ad_id'] : 0);

        if (!$unit) {
            return;
        }

        $html = Simple_Google_Adsense_Manual_Ads::render_unit($unit, array('source' => 'widget'));

        if ('' === $html) {
            return;
        }

        $title = isset($instance['title']) ? apply_filters('widget_title', $instance['title'], $instance, $this->id_base) : '';

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.

        if ('' !== (string) $title) {
            echo $args['before_title'] . esc_html($title) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.
        }

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_unit().
        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme markup.
    }

    /**
     * Settings form.
     *
     * @param array $instance Widget settings.
     * @return void
     */
    public function form($instance)
    {
        $title = isset($instance['title']) ? $instance['title'] : '';
        $ad_id = isset($instance['ad_id']) ? (int) $instance['ad_id'] : 0;
        $units = Simple_Google_Adsense_Ad_Units::get_choices();
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title (optional):', 'simple-google-adsense'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('ad_id')); ?>"><?php esc_html_e('Ad unit:', 'simple-google-adsense'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('ad_id')); ?>" name="<?php echo esc_attr($this->get_field_name('ad_id')); ?>">
                <option value="0"><?php esc_html_e('- Select -', 'simple-google-adsense'); ?></option>
                <?php foreach ($units as $id => $label) : ?>
                    <option value="<?php echo esc_attr($id); ?>" <?php selected($ad_id, (int) $id); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php if (empty($units)) : ?>
            <p><a href="<?php echo esc_url(admin_url('post-new.php?post_type=' . Simple_Google_Adsense_Ad_Units::POST_TYPE)); ?>"><?php esc_html_e('Create your first ad unit →', 'simple-google-adsense'); ?></a></p>
        <?php endif;
    }

    /**
     * Save settings.
     *
     * @param array $new_instance New settings.
     * @param array $old_instance Old settings.
     * @return array
     */
    public function update($new_instance, $old_instance)
    {
        return array(
            'title' => isset($new_instance['title']) ? sanitize_text_field($new_instance['title']) : '',
            'ad_id' => isset($new_instance['ad_id']) ? absint($new_instance['ad_id']) : 0,
        );
    }
}
