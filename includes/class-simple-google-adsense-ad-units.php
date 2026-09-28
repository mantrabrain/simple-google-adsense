<?php
/**
 * Simple_Google_Adsense Ad Units library
 *
 * Stores reusable AdSense ad units (slot, type, format) as a private post type
 * so that an ad is configured once and referenced everywhere - shortcodes,
 * the block, the widget and automatic placements.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Ad_Units Class.
 *
 * @class Simple_Google_Adsense_Ad_Units
 */
final class Simple_Google_Adsense_Ad_Units
{

    /**
     * Post type holding the ad units.
     */
    const POST_TYPE = 'adflow_ad';

    /**
     * Meta key holding the unit configuration.
     */
    const META_KEY = '_adflow_ad';

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Ad_Units
     * @since 1.4.0
     */
    protected static $_instance = null;

    /**
     * Main Simple_Google_Adsense_Ad_Units Instance.
     *
     * @return Simple_Google_Adsense_Ad_Units
     * @since 1.4.0
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
     * Simple_Google_Adsense_Ad_Units Constructor.
     */
    public function __construct()
    {
        add_filter('wp_insert_post_data', array($this, 'enforce_review'), 10, 2);
        add_filter('map_meta_cap', array($this, 'guard_live_ads'), 10, 4);
        add_filter('pre_trash_post', array($this, 'protect_live_ads'), 10, 2);
        add_filter('pre_delete_post', array($this, 'protect_live_ads'), 10, 2);
        add_action('init', array($this, 'register_post_type'));
        add_action('add_meta_boxes_' . self::POST_TYPE, array($this, 'add_meta_boxes'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_edit_script'));
        add_action('save_post_' . self::POST_TYPE, array($this, 'save'), 10, 2);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array($this, 'columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array($this, 'column_content'), 10, 2);
        add_filter('post_updated_messages', array($this, 'updated_messages'));
        add_filter('enter_title_here', array($this, 'title_placeholder'), 10, 2);
        add_filter('rest_pre_dispatch', array($this, 'restrict_rest'), 10, 3);
        add_filter('wp_untrash_post_status', array($this, 'untrash_status'), 10, 3);
    }

    /**
     * Restore trashed units as published again (core restores as draft, and
     * a draft unit silently renders nothing).
     *
     * @param string $new_status Status core would use.
     * @param int $post_id Post ID.
     * @param string $previous_status Status before trashing.
     * @return string
     * @since 1.4.0
     */
    public function untrash_status($new_status, $post_id, $previous_status)
    {
        return self::POST_TYPE === get_post_type($post_id) && $previous_status ? $previous_status : $new_status;
    }

    /**
     * Published posts of any REST-enabled type are publicly readable by
     * default; ad units are only listed for people who can write content
     * (the block's unit picker). Editing still requires manage_options.
     *
     * @param mixed $result Result.
     * @param WP_REST_Server $server Server.
     * @param WP_REST_Request $request Request.
     * @return mixed
     * @since 1.4.0
     */
    public function restrict_rest($result, $server, $request)
    {
        if (0 === strpos($request->get_route(), '/wp/v2/adflow-ads') && !current_user_can('edit_posts')) {
            return new WP_Error('rest_forbidden', __('Sorry, you are not allowed to view ad units.', 'simple-google-adsense'), array('status' => rest_authorization_required_code()));
        }

        return $result;
    }

    /**
     * Register the private ad unit post type.
     *
     * @since 1.4.0
     */
    public function register_post_type()
    {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Ad Units', 'simple-google-adsense'),
                'singular_name' => __('Ad Unit', 'simple-google-adsense'),
                'add_new' => __('Add Ad Unit', 'simple-google-adsense'),
                'add_new_item' => __('Add Ad Unit', 'simple-google-adsense'),
                'edit_item' => __('Edit Ad Unit', 'simple-google-adsense'),
                'new_item' => __('New Ad Unit', 'simple-google-adsense'),
                'search_items' => __('Search Ad Units', 'simple-google-adsense'),
                'not_found' => __('No ad units yet. Create one with the slot ID from your AdSense account.', 'simple-google-adsense'),
                'not_found_in_trash' => __('No ad units in the trash.', 'simple-google-adsense'),
                'menu_name' => __('Ad Units', 'simple-google-adsense'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => Simple_Google_Adsense_Admin::MENU_SLUG,
            // Titles only - lets the block list units through core-data.
            'show_in_rest' => true,
            'rest_base' => 'adflow-ads',
            'supports' => array('title'),
            'capability_type' => 'post',
            'capabilities' => array(
                'edit_post' => Simple_Google_Adsense_Caps::MANAGE_ADS,
                'read_post' => Simple_Google_Adsense_Caps::MANAGE_ADS,
                'delete_post' => Simple_Google_Adsense_Caps::MANAGE_ADS,
                'edit_posts' => Simple_Google_Adsense_Caps::MANAGE_ADS,
                'edit_others_posts' => Simple_Google_Adsense_Caps::MANAGE_ADS,
                'delete_posts' => Simple_Google_Adsense_Caps::MANAGE_ADS,
                'delete_published_posts' => Simple_Google_Adsense_Caps::PUBLISH_ADS,
                'edit_published_posts' => Simple_Google_Adsense_Caps::PUBLISH_ADS,
                // Without this, ads are "Submitted for review" - an approval workflow.
                'publish_posts' => Simple_Google_Adsense_Caps::PUBLISH_ADS,
                'read_private_posts' => Simple_Google_Adsense_Caps::MANAGE_ADS,
                'create_posts' => Simple_Google_Adsense_Caps::MANAGE_ADS,
            ),
            'map_meta_cap' => false,
            'rewrite' => false,
            'query_var' => false,
        ));
    }

    /**
     * Supported ad types.
     *
     * `matched_content` is kept as an alias of `multiplex` (Google renamed the
     * product) so shortcodes and blocks saved before 1.4.0 keep working.
     *
     * @return array
     * @since 1.4.0
     */
    public static function get_types()
    {
        /**
         * Filters the ad unit types. AdFlow Pro adds Google Ad Manager.
         * Extra types render through the `adflow_render_ad_type` filter.
         *
         * @param array $types type => label
         * @since 1.4.0
         */
        return apply_filters('adflow_ad_types', array(
            'display' => __('Display ad', 'simple-google-adsense'),
            'inarticle' => __('In-article ad', 'simple-google-adsense'),
            'infeed' => __('In-feed ad', 'simple-google-adsense'),
            'multiplex' => __('Multiplex ad (formerly Matched Content)', 'simple-google-adsense'),
            'image' => __('Image banner (your own or a sponsor\'s)', 'simple-google-adsense'),
            'text' => __('Text ad (headline, text and link)', 'simple-google-adsense'),
            'custom' => __('Custom code (other ad networks, HTML/JS)', 'simple-google-adsense'),
            'group' => __('Rotation group (several ads take turns)', 'simple-google-adsense'),
        ));
    }

    /**
     * AdSense unit types (rendered as <ins class="adsbygoogle">).
     *
     * @return string[]
     * @since 1.4.0
     */
    public static function adsense_types()
    {
        return array('display', 'inarticle', 'infeed', 'multiplex');
    }

    /**
     * Map any accepted type spelling to a canonical type.
     *
     * @param string $type Raw type.
     * @return string
     * @since 1.4.0
     */
    public static function normalize_type($type)
    {
        $aliases = array(
            'banner' => 'display',
            'display' => 'display',
            'inarticle' => 'inarticle',
            'in-article' => 'inarticle',
            'infeed' => 'infeed',
            'in-feed' => 'infeed',
            'matched_content' => 'multiplex',
            'multiplex' => 'multiplex',
            'custom' => 'custom',
            'image' => 'image',
            'text' => 'text',
            'group' => 'group',
        );

        $type = strtolower(trim((string) $type));

        if (isset($aliases[$type])) {
            return $aliases[$type];
        }

        // Types added by an add-on (e.g. Pro's "gam").
        return '' !== $type && array_key_exists($type, self::get_types()) ? $type : 'display';
    }

    /**
     * Default unit configuration.
     *
     * @return array
     * @since 1.4.0
     */
    public static function defaults()
    {
        return array(
            'slot' => '',
            'type' => 'display',
            'format' => 'auto',
            'layout_key' => '',
            'full_width_responsive' => true,
            'code' => '',
            // Your own ads (image, text, custom code).
            'image_id' => 0,
            'url' => '',
            'alt' => '',
            'new_tab' => true,
            'nofollow' => false,
            'headline' => '',
            'body' => '',
            'cta' => '',
            'disclosure' => 'sponsored',
            'start' => '',
            'end' => '',
            'track' => true,
            'fallback_id' => 0,
            // Rotation groups.
            'members' => array(),
            'rotation' => 'weighted',
        );
    }

    /**
     * Whether a type is one of your own ads (not AdSense).
     *
     * @param string $type Type.
     * @return bool
     * @since 1.4.0
     */
    public static function is_own_ad($type)
    {
        return in_array(self::normalize_type($type), array('image', 'text', 'custom'), true);
    }

    /**
     * Schedule timestamps (site time zone) of a unit.
     *
     * @param array $unit Unit data.
     * @return array start, end (Unix timestamps, 0 = none)
     * @since 1.4.0
     */
    public static function schedule($unit)
    {
        $tz = wp_timezone();
        $out = array('start' => 0, 'end' => 0);

        foreach (array('start', 'end') as $key) {
            if (!empty($unit[$key])) {
                $date = date_create_immutable((string) $unit[$key], $tz);
                $out[$key] = $date ? $date->getTimestamp() : 0;
            }
        }

        return $out;
    }

    /**
     * Running status of an own ad.
     *
     * @param array $unit Unit data.
     * @return string running|scheduled|expired
     * @since 1.4.0
     */
    public static function status($unit)
    {
        $schedule = self::schedule($unit);
        $now = time();

        if ($schedule['start'] && $now < $schedule['start']) {
            return 'scheduled';
        }

        if ($schedule['end'] && $now > $schedule['end']) {
            return 'expired';
        }

        return 'running';
    }

    /**
     * Status labels.
     *
     * @return array
     * @since 1.4.0
     */
    public static function status_labels()
    {
        return array(
            'running' => __('Running', 'simple-google-adsense'),
            'scheduled' => __('Scheduled', 'simple-google-adsense'),
            'expired' => __('Expired', 'simple-google-adsense'),
        );
    }

    /**
     * Disclosure labels for own ads.
     *
     * @return array
     * @since 1.4.0
     */
    public static function disclosures()
    {
        return array(
            'sponsored' => __('Sponsored', 'simple-google-adsense'),
            'advertisement' => __('Advertisement', 'simple-google-adsense'),
            'none' => __('No label', 'simple-google-adsense'),
        );
    }

    /**
     * Get one ad unit as a normalised array.
     *
     * @param int $id Ad unit post ID.
     * @return array|null Null when the unit does not exist or is not published.
     * @since 1.4.0
     */
    public static function get($id)
    {
        $post = get_post(absint($id));

        if (!$post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status) {
            return null;
        }

        $data = get_post_meta($post->ID, self::META_KEY, true);
        $data = wp_parse_args(is_array($data) ? $data : array(), self::defaults());

        $data['id'] = (int) $post->ID;
        $data['title'] = get_the_title($post);
        $raw_type = strtolower(trim((string) $data['type']));
        $data['type'] = self::normalize_type($raw_type);

        // A type from an add-on that is not active (e.g. Pro's Ad Manager
        // unit): show nothing rather than an empty AdSense unit.
        if ('display' === $data['type'] && !in_array($raw_type, array('', 'display', 'banner'), true)) {
            return null;
        }

        /**
         * Filters a loaded ad unit. Pro stores extra keys (e.g. reserved height).
         *
         * @param array $data
         * @param WP_Post $post
         * @since 1.4.0
         */
        return apply_filters('adflow_ad_unit', $data, $post);
    }

    /**
     * Get every published ad unit.
     *
     * @return array[]
     * @since 1.4.0
     */
    public static function get_all()
    {
        // Full posts with the meta cache primed - one query each, not one per unit.
        $posts = get_posts(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ));

        $units = array();

        foreach ($posts as $post) {
            $unit = self::get($post->ID);

            if ($unit) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    /**
     * Options for a `<select>` of ad units.
     *
     * @return array id => title
     * @since 1.4.0
     */
    public static function get_choices()
    {
        $choices = array();

        foreach (self::get_all() as $unit) {
            $choices[$unit['id']] = $unit['title'] . ('' !== $unit['slot'] ? ' (' . $unit['slot'] . ')' : '');
        }

        return $choices;
    }

    /**
     * Register the settings meta box.
     *
     * @since 1.4.0
     */
    public function add_meta_boxes()
    {
        add_meta_box(
            'adflow-ad-unit',
            __('Ad unit settings', 'simple-google-adsense'),
            array($this, 'render_meta_box'),
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'adflow-ad-unit-delivery',
            __('Delivery', 'simple-google-adsense'),
            array($this, 'render_delivery_box'),
            self::POST_TYPE,
            'normal',
            'default'
        );

        add_meta_box(
            'adflow-ad-unit-usage',
            __('Use this ad unit', 'simple-google-adsense'),
            array($this, 'render_usage_box'),
            self::POST_TYPE,
            'side'
        );
    }

    /**
     * Field toggling on the unit edit screen.
     *
     * @since 1.4.0
     */
    public function enqueue_edit_script()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen && self::POST_TYPE === $screen->post_type && 'post' === $screen->base) {
            wp_enqueue_media();
            wp_enqueue_script('adflow-ad-unit-edit', SIMPLE_GOOGLE_ADSENSE_PLUGIN_URI . '/assets/js/admin-ad-unit.js', array(), SIMPLE_GOOGLE_ADSENSE_VERSION, true);
            wp_localize_script('adflow-ad-unit-edit', 'adflowUnit', array(
                'choose' => __('Choose banner image', 'simple-google-adsense'),
                'use' => __('Use this image', 'simple-google-adsense'),
            ));
        }
    }

    /**
     * Render the unit settings.
     *
     * @param WP_Post $post Current post.
     * @since 1.4.0
     */
    public function render_meta_box($post)
    {
        $data = get_post_meta($post->ID, self::META_KEY, true);
        $data = wp_parse_args(is_array($data) ? $data : array(), self::defaults());
        $data['type'] = self::normalize_type($data['type']);
        $A = 'Simple_Google_Adsense_Admin';

        wp_nonce_field('adflow_save_ad_unit', 'adflow_ad_unit_nonce');

        $A::field_start(__('Ad type', 'simple-google-adsense'), __('For AdSense, match the type of the unit you created in AdSense. Your own ads: a banner, a text ad or any network\'s code.', 'simple-google-adsense'), 'adflow-type');
        ?>
        <select id="adflow-type" name="adflow_ad[type]">
            <?php
            $groups = array(
                __('Google AdSense', 'simple-google-adsense') => array('display', 'inarticle', 'infeed', 'multiplex'),
                __('Your own ads', 'simple-google-adsense') => array('image', 'text', 'custom'),
                __('Rotation', 'simple-google-adsense') => array('group'),
            );
            $types = self::get_types();
            // Types added by add-ons (e.g. Pro's Google Ad Manager).
            $extra = array_diff(array_keys($types), array('display', 'inarticle', 'infeed', 'multiplex', 'image', 'text', 'custom', 'group'));
            if ($extra) {
                $groups[__('Other ad servers', 'simple-google-adsense')] = array_values($extra);
            }
            foreach ($groups as $group_label => $keys) : ?>
                <optgroup label="<?php echo esc_attr($group_label); ?>">
                    <?php foreach ($keys as $value) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($data['type'], $value); ?>><?php echo esc_html($types[$value]); ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
            <?php if (!$extra && !Simple_Google_Adsense_Settings::is_pro_active()) : ?>
                <optgroup label="<?php esc_attr_e('Other ad servers', 'simple-google-adsense'); ?>">
                    <option disabled><?php esc_html_e('Google Ad Manager (GPT) - AdFlow Pro', 'simple-google-adsense'); ?></option>
                </optgroup>
            <?php endif; ?>
        </select>
        <?php
        $A::field_end();
        $image_url = $data['image_id'] ? wp_get_attachment_image_url($data['image_id'], 'medium') : '';
        ?>
        <div class="adflow-field-image">
            <?php $A::field_start(__('Banner image', 'simple-google-adsense'), __('Tip: avoid words like "ad" or "banner" in the file name - ad blockers hide those.', 'simple-google-adsense')); ?>
            <input type="hidden" id="adflow-image-id" name="adflow_ad[image_id]" value="<?php echo esc_attr($data['image_id']); ?>">
            <div class="adflow-media">
                <img id="adflow-image-preview" src="<?php echo esc_url($image_url); ?>" alt="" <?php echo $image_url ? '' : 'hidden'; ?>>
                <div class="adflow-inline">
                    <button type="button" class="button" id="adflow-image-choose"><?php esc_html_e('Choose image', 'simple-google-adsense'); ?></button>
                    <button type="button" class="button-link" id="adflow-image-remove" <?php echo $image_url ? '' : 'hidden'; ?>><?php esc_html_e('Remove', 'simple-google-adsense'); ?></button>
                </div>
            </div>
            <?php $A::field_end(); ?>
            <?php $A::field_start(__('Alternative text', 'simple-google-adsense'), __('Describes the image for screen readers.', 'simple-google-adsense'), 'adflow-alt'); ?>
            <input type="text" id="adflow-alt" name="adflow_ad[alt]" class="regular-text" value="<?php echo esc_attr($data['alt']); ?>">
            <?php $A::field_end(); ?>
        </div>

        <div class="adflow-field-text">
            <?php $A::field_start(__('Headline', 'simple-google-adsense'), '', 'adflow-headline'); ?>
            <input type="text" id="adflow-headline" name="adflow_ad[headline]" class="regular-text" value="<?php echo esc_attr($data['headline']); ?>">
            <?php $A::field_end(); ?>
            <?php $A::field_start(__('Text', 'simple-google-adsense'), '', 'adflow-body'); ?>
            <textarea id="adflow-body" name="adflow_ad[body]" rows="3" class="large-text"><?php echo esc_textarea($data['body']); ?></textarea>
            <?php $A::field_end(); ?>
            <?php $A::field_start(__('Button text', 'simple-google-adsense'), __('Optional, e.g. "Learn more".', 'simple-google-adsense'), 'adflow-cta'); ?>
            <input type="text" id="adflow-cta" name="adflow_ad[cta]" class="regular-text" value="<?php echo esc_attr($data['cta']); ?>">
            <?php $A::field_end(); ?>
        </div>

        <div class="adflow-field-link">
            <?php $A::field_start(__('Link', 'simple-google-adsense'), __('Where a click goes. Paid links get rel="sponsored", as Google asks.', 'simple-google-adsense'), 'adflow-url'); ?>
            <input type="url" id="adflow-url" name="adflow_ad[url]" class="regular-text code" value="<?php echo esc_attr($data['url']); ?>" placeholder="https://">
            <p><?php $A::toggle('adflow_ad[new_tab]', (bool) $data['new_tab'], __('Open in a new tab', 'simple-google-adsense')); ?></p>
            <p><?php $A::toggle('adflow_ad[nofollow]', (bool) $data['nofollow'], __('Also add rel="nofollow"', 'simple-google-adsense')); ?></p>
            <?php $A::field_end(); ?>
        </div>

        <div class="adflow-field-group">
            <?php
            $A::field_start(__('Ads in this group', 'simple-google-adsense'), __('One of these is shown per page view. Weight 3 is shown three times as often as weight 1. Works with page caching.', 'simple-google-adsense'));
            $choices = array();
            foreach (self::get_all() as $candidate) {
                if ('group' !== $candidate['type'] && (int) $candidate['id'] !== (int) $post->ID) {
                    $choices[$candidate['id']] = $candidate['title'];
                }
            }
            $members = array_values((array) $data['members']);
            $rows = max(4, count($members) + 2);
            ?>
            <table class="adflow-members">
                <thead><tr><th><?php esc_html_e('Ad', 'simple-google-adsense'); ?></th><th><?php esc_html_e('Weight', 'simple-google-adsense'); ?></th></tr></thead>
                <tbody>
                <?php for ($i = 0; $i < $rows; $i++) :
                    $member = isset($members[$i]) ? $members[$i] : array('id' => 0, 'weight' => 1);
                    ?>
                    <tr>
                        <td>
                            <select name="adflow_ad[members][<?php echo esc_attr($i); ?>][id]" aria-label="<?php esc_attr_e('Ad', 'simple-google-adsense'); ?>">
                                <option value="0">&mdash;</option>
                                <?php foreach ($choices as $id => $title) : ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected((int) $member['id'], (int) $id); ?>><?php echo esc_html($title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="number" min="1" max="100" class="small-text" name="adflow_ad[members][<?php echo esc_attr($i); ?>][weight]" value="<?php echo esc_attr(max(1, (int) $member['weight'])); ?>" aria-label="<?php esc_attr_e('Weight', 'simple-google-adsense'); ?>"></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
            <p class="description"><?php esc_html_e('Need more rows? Save and more empty rows appear.', 'simple-google-adsense'); ?></p>
            <?php $A::field_end(); ?>

            <?php $A::field_start(__('Rotation', 'simple-google-adsense'), '', 'adflow-rotation'); ?>
            <select id="adflow-rotation" name="adflow_ad[rotation]">
                <option value="weighted" <?php selected($data['rotation'], 'weighted'); ?>><?php esc_html_e('Random, by weight', 'simple-google-adsense'); ?></option>
                <option value="ordered" <?php selected($data['rotation'], 'ordered'); ?>><?php esc_html_e('In order - each visitor sees the next ad', 'simple-google-adsense'); ?></option>
            </select>
            <?php $A::field_end(); ?>
        </div>

        <div class="adflow-field-slot">
            <?php $A::field_start(__('Ad slot ID', 'simple-google-adsense'), __('The data-ad-slot number from the ad code in AdSense (Ads → By ad unit).', 'simple-google-adsense'), 'adflow-slot'); ?>
            <input type="text" id="adflow-slot" name="adflow_ad[slot]" class="regular-text code" inputmode="numeric" value="<?php echo esc_attr($data['slot']); ?>" placeholder="1234567890">
            <p class="description"><a href="https://support.google.com/adsense/answer/9183566" target="_blank" rel="noopener"><?php esc_html_e('How to create an ad unit in AdSense', 'simple-google-adsense'); ?></a></p>
            <?php $A::field_end(); ?>
        </div>

        <div class="adflow-field-format">
            <?php $A::field_start(__('Size', 'simple-google-adsense'), __('"Responsive" fits any screen and is recommended.', 'simple-google-adsense'), 'adflow-format'); ?>
            <select id="adflow-format" name="adflow_ad[format]">
                <?php
                $formats = array(
                    'auto' => __('Responsive (recommended)', 'simple-google-adsense'),
                    'rectangle' => __('Rectangle', 'simple-google-adsense'),
                    'horizontal' => __('Horizontal', 'simple-google-adsense'),
                    'vertical' => __('Vertical', 'simple-google-adsense'),
                );
                foreach ($formats as $format => $format_label) : ?>
                    <option value="<?php echo esc_attr($format); ?>" <?php selected($data['format'], $format); ?>><?php echo esc_html($format_label); ?></option>
                <?php endforeach; ?>
            </select>
            <p style="margin-top:10px"><?php $A::toggle('adflow_ad[full_width_responsive]', (bool) $data['full_width_responsive'], __('Full width on mobile', 'simple-google-adsense')); ?></p>
            <?php $A::field_end(); ?>
        </div>

        <div class="adflow-field-layout-key">
            <?php $A::field_start(__('Layout key', 'simple-google-adsense'), __('In-feed ads only. Copy data-ad-layout-key from the ad code - in-feed ads do not serve without it.', 'simple-google-adsense'), 'adflow-layout-key'); ?>
            <input type="text" id="adflow-layout-key" name="adflow_ad[layout_key]" class="regular-text code" value="<?php echo esc_attr($data['layout_key']); ?>" placeholder="-fb+5w+4e-db+86">
            <?php $A::field_end(); ?>
        </div>

        <div class="adflow-field-code">
            <?php $A::field_start(__('Ad code', 'simple-google-adsense'), __('From Google Ad Manager, Media.net or any other network. Output exactly as entered.', 'simple-google-adsense'), 'adflow-code'); ?>
            <?php if (current_user_can('unfiltered_html')) : ?>
                <textarea id="adflow-code" name="adflow_ad[code]" rows="8" class="large-text code" placeholder="&lt;script&gt;…&lt;/script&gt;"><?php echo esc_textarea($data['code']); ?></textarea>
            <?php else : ?>
                <p class="description"><?php esc_html_e('Only users allowed to post unfiltered HTML can edit custom ad code.', 'simple-google-adsense'); ?></p>
            <?php endif; ?>
            <?php $A::field_end(); ?>
        </div>
        <?php
        /**
         * Fires after the core ad unit fields. Pro adds its fields here.
         *
         * @param array $data Unit data.
         * @param WP_Post $post
         * @since 1.4.0
         */
        do_action('adflow_ad_unit_fields', $data, $post);
    }

    /**
     * Delivery settings for your own ads: label, schedule, tracking, fallback.
     *
     * @param WP_Post $post Post.
     * @since 1.4.0
     */
    public function render_delivery_box($post)
    {
        $data = get_post_meta($post->ID, self::META_KEY, true);
        $data = wp_parse_args(is_array($data) ? $data : array(), self::defaults());
        $A = 'Simple_Google_Adsense_Admin';
        $adsense_units = array();

        foreach (self::get_all() as $unit) {
            if (!self::is_own_ad($unit['type']) && (int) $unit['id'] !== (int) $post->ID) {
                $adsense_units[$unit['id']] = $unit['title'];
            }
        }

        $A::field_start(__('Disclosure label', 'simple-google-adsense'), __('Laws in many countries (FTC, ASA) require paid placements to be clearly marked.', 'simple-google-adsense'), 'adflow-disclosure');
        ?>
        <select id="adflow-disclosure" name="adflow_ad[disclosure]">
            <?php foreach (self::disclosures() as $key => $label) : ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($data['disclosure'], $key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
        $A::field_end();

        $A::field_start(__('Schedule', 'simple-google-adsense'), __('Optional. Uses your site\'s time zone. Outside these dates the ad is not shown.', 'simple-google-adsense'));
        ?>
        <div class="adflow-inline">
            <label for="adflow-start"><?php esc_html_e('Start', 'simple-google-adsense'); ?></label>
            <input type="datetime-local" id="adflow-start" name="adflow_ad[start]" value="<?php echo esc_attr($data['start']); ?>">
            <label for="adflow-end" style="margin-left:12px"><?php esc_html_e('End', 'simple-google-adsense'); ?></label>
            <input type="datetime-local" id="adflow-end" name="adflow_ad[end]" value="<?php echo esc_attr($data['end']); ?>">
        </div>
        <?php
        $A::field_end();

        $A::field_start(__('When not running', 'simple-google-adsense'), __('Keep earning after a campaign ends: show an AdSense unit in its place.', 'simple-google-adsense'), 'adflow-fallback');
        ?>
        <select id="adflow-fallback" name="adflow_ad[fallback_id]">
            <option value="0"><?php esc_html_e('Show nothing', 'simple-google-adsense'); ?></option>
            <?php foreach ($adsense_units as $id => $title) : ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected((int) $data['fallback_id'], (int) $id); ?>><?php echo esc_html($title); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
        $A::field_end();

        $A::field_start(__('Statistics', 'simple-google-adsense'), __('Cookieless impression and click counting.', 'simple-google-adsense'));
        $A::toggle('adflow_ad[track]', (bool) $data['track'], __('Count impressions and clicks', 'simple-google-adsense'));
        $A::field_end();

        /**
         * Fires at the end of the Delivery box. Pro adds advertiser details.
         *
         * @param array $data
         * @param WP_Post $post
         * @since 1.4.0
         */
        do_action('adflow_ad_unit_delivery_fields', $data, $post);
    }

    /**
     * Every condition that can stop this ad from showing, pass or fail.
     *
     * @param array $unit Unit (from get()).
     * @return array[] Each: status ok|warning|error, text.
     * @since 1.4.0
     */
    public static function diagnose($unit)
    {
        $out = array();
        $add = function ($status, $text) use (&$out) {
            $out[] = array('status' => $status, 'text' => $text);
        };
        $type = $unit['type'];

        if (!Simple_Google_Adsense_Settings::is_manual_ads_enabled()) {
            $add('error', __('Manual ads are switched off in Settings.', 'simple-google-adsense'));
        }

        if ('group' === $type) {
            $count = count((array) $unit['members']);
            $add($count ? 'ok' : 'error', $count
                /* translators: %d: number of ads */
                ? sprintf(_n('%d ad in the rotation.', '%d ads in the rotation.', $count, 'simple-google-adsense'), $count)
                : __('The group has no ads yet.', 'simple-google-adsense'));
        } elseif (self::is_own_ad($type)) {
            $status = self::status($unit);
            $add('running' === $status ? 'ok' : 'error', 'running' === $status ? __('Within its schedule.', 'simple-google-adsense') : ('scheduled' === $status ? __('Scheduled - not started yet.', 'simple-google-adsense') : __('Expired - the end date has passed.', 'simple-google-adsense')));

            if ('image' === $type) {
                $add(wp_get_attachment_image_url((int) $unit['image_id']) ? 'ok' : 'error', wp_get_attachment_image_url((int) $unit['image_id']) ? __('Banner image set.', 'simple-google-adsense') : __('No banner image.', 'simple-google-adsense'));
            } elseif ('text' === $type) {
                $add('' !== trim((string) $unit['headline']) ? 'ok' : 'error', '' !== trim((string) $unit['headline']) ? __('Headline set.', 'simple-google-adsense') : __('No headline.', 'simple-google-adsense'));
            } else {
                $add('' !== trim((string) $unit['code']) ? 'ok' : 'error', '' !== trim((string) $unit['code']) ? __('Ad code set.', 'simple-google-adsense') : __('No ad code.', 'simple-google-adsense'));
            }

            if (in_array($type, array('image', 'text'), true) && '' === (string) $unit['url']) {
                $add('warning', __('No link - clicks cannot be counted.', 'simple-google-adsense'));
            }
        } elseif (in_array($type, self::adsense_types(), true)) {
            $add(Simple_Google_Adsense_Settings::is_publisher_id_valid() ? 'ok' : 'error', Simple_Google_Adsense_Settings::is_publisher_id_valid() ? __('Publisher ID set.', 'simple-google-adsense') : __('No valid Publisher ID in Settings.', 'simple-google-adsense'));
            $add('' !== (string) $unit['slot'] ? 'ok' : 'error', '' !== (string) $unit['slot'] ? __('Ad slot ID set.', 'simple-google-adsense') : __('No ad slot ID.', 'simple-google-adsense'));

            if ('infeed' === $type && '' === (string) $unit['layout_key']) {
                $add('error', __('In-feed ads need a layout key.', 'simple-google-adsense'));
            }

            // Only the last known result: never fetch ads.txt while an editor screen loads.
            $ads_txt = get_transient(Simple_Google_Adsense_Ads_Txt::CHECK_TRANSIENT);
            if (is_array($ads_txt) && 'ok' !== $ads_txt['status']) {
                $add('warning', __('ads.txt is not authorizing this site - AdSense may limit ads.', 'simple-google-adsense'));
            }
        }

        $placed = self::used_in($unit['id']);
        $add($placed ? 'ok' : 'warning', $placed
            /* translators: %s: placement names */
            ? sprintf(__('Placed automatically: %s.', 'simple-google-adsense'), implode(', ', $placed))
            : __('Not in any automatic placement - add it with the block, widget or shortcode, or choose a placement.', 'simple-google-adsense'));

        if (Simple_Google_Adsense_Settings::get('hide_for_admins')) {
            $add('warning', __('Ads are hidden while you are logged in (Settings).', 'simple-google-adsense'));
        }

        /**
         * Filters the ad diagnostics (Pro adds targeting and caps).
         *
         * @param array $out
         * @param array $unit
         * @since 1.4.0
         */
        return apply_filters('adflow_ad_diagnostics', $out, $unit);
    }

    /**
     * Render the usage hints.
     *
     * @param WP_Post $post Current post.
     * @since 1.4.0
     */
    public function render_usage_box($post)
    {
        if ('publish' !== $post->post_status) {
            echo '<p class="description">' . esc_html__('Publish this ad unit to use it.', 'simple-google-adsense') . '</p>';
            return;
        }

        $unit = self::get($post->ID);

        if ($unit) {
            $checks = self::diagnose($unit);
            $problems = count(array_filter($checks, function ($check) {
                return 'error' === $check['status'];
            }));
            ?>
            <details class="adflow-diagnose" <?php echo $problems ? 'open' : ''; ?>>
                <summary>
                    <strong><?php esc_html_e('Will this ad show?', 'simple-google-adsense'); ?></strong>
                    <span class="adflow-pill adflow-pill--<?php echo $problems ? 'error' : 'ok'; ?>"><?php echo $problems ? esc_html__('No', 'simple-google-adsense') : esc_html__('Yes', 'simple-google-adsense'); ?></span>
                </summary>
                <ul>
                    <?php foreach ($checks as $check) : ?>
                        <li><span class="adflow-dot adflow-dot--<?php echo esc_attr($check['status']); ?>" aria-hidden="true"></span> <?php echo esc_html($check['text']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <hr>
            <?php
        }

        if ($unit && self::is_own_ad($unit['type'])) {
            $status = self::status($unit);
            $labels = self::status_labels();
            $stats = Simple_Google_Adsense_Stats::totals(array('days' => 30, 'ad_id' => $post->ID));
            ?>
            <p><span class="adflow-pill adflow-pill--<?php echo 'running' === $status ? 'ok' : ('expired' === $status ? 'error' : 'info'); ?>"><?php echo esc_html($labels[$status]); ?></span></p>
            <div class="adflow-mini-stats">
                <div><strong><?php echo esc_html(number_format_i18n($stats['impressions'])); ?></strong><span><?php esc_html_e('Impressions', 'simple-google-adsense'); ?></span></div>
                <div><strong><?php echo esc_html(Simple_Google_Adsense_Stats::format_ctr($stats['viewability'])); ?></strong><span><?php esc_html_e('Viewable', 'simple-google-adsense'); ?></span></div>
                <div><strong><?php echo esc_html(number_format_i18n($stats['clicks'])); ?></strong><span><?php esc_html_e('Clicks', 'simple-google-adsense'); ?></span></div>
                <div><strong><?php echo esc_html(Simple_Google_Adsense_Stats::format_ctr($stats['ctr'])); ?></strong><span><?php esc_html_e('CTR', 'simple-google-adsense'); ?></span></div>
            </div>
            <p class="description"><?php esc_html_e('Last 30 days.', 'simple-google-adsense'); ?> <a href="<?php echo esc_url(add_query_arg('ad', $post->ID, admin_url('admin.php?page=' . Simple_Google_Adsense_Reports::PAGE_SLUG))); ?>"><?php esc_html_e('Full report', 'simple-google-adsense'); ?> &rarr;</a></p>
            <?php
            /**
             * Fires in the "Use this ad unit" box for own ads. Pro adds the advertiser report link.
             *
             * @param array $unit
             * @since 1.4.0
             */
            do_action('adflow_ad_unit_sidebar', $unit);
            echo '<hr>';
        }
        ?>
        <p><strong><?php esc_html_e('Place it automatically', 'simple-google-adsense'); ?></strong><br>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . Simple_Google_Adsense_Placements::PAGE_SLUG)); ?>"><?php esc_html_e('Choose a placement', 'simple-google-adsense'); ?> &rarr;</a></p>
        <p><strong><?php esc_html_e('Or add it by hand', 'simple-google-adsense'); ?></strong><br>
            <?php esc_html_e('Use the "AdFlow Ad" block or widget, or this shortcode:', 'simple-google-adsense'); ?></p>
        <p><code class="adflow-copy" data-adflow-copy="[adflow id=&quot;<?php echo esc_attr($post->ID); ?>&quot;]" title="<?php esc_attr_e('Click to copy', 'simple-google-adsense'); ?>">[adflow id="<?php echo esc_html($post->ID); ?>"]</code></p>
        <?php
    }

    /**
     * Save the unit settings.
     *
     * @param int $post_id Post ID.
     * @param WP_Post $post Post object.
     * @since 1.4.0
     */
    public function save($post_id, $post)
    {
        if (!isset($_POST['adflow_ad_unit_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['adflow_ad_unit_nonce'])), 'adflow_save_ad_unit')
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || !current_user_can(Simple_Google_Adsense_Caps::MANAGE_ADS)) {
            return;
        }

        $input = isset($_POST['adflow_ad']) && is_array($_POST['adflow_ad']) ? wp_unslash($_POST['adflow_ad']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per key below.

        $previous = get_post_meta($post_id, self::META_KEY, true);
        $previous_code = is_array($previous) && isset($previous['code']) ? (string) $previous['code'] : '';

        $data = self::sanitize_data($input, $post_id, $previous_code);

        update_post_meta($post_id, self::META_KEY, $data);

        self::purge_page_cache();
    }

    /**
     * Sanitize a destination URL, keeping tracking macros such as {ad_id}
     * (esc_url_raw() would strip the braces).
     *
     * @param string $url URL.
     * @return string
     * @since 1.4.0
     */
    public static function sanitize_url($url)
    {
        $url = str_replace(array('{', '}'), array('%7B', '%7D'), trim((string) $url));

        return esc_url_raw($url, array('http', 'https'));
    }

    /**
     * Sanitize ad unit data (edit screen, and imports from an AdFlow export).
     *
     * @param array $input Raw (unslashed) data.
     * @param int $post_id Ad unit ID.
     * @param string $previous_code Code kept when the user may not post unfiltered HTML.
     * @return array
     * @since 1.4.0
     */
    public static function sanitize_data($input, $post_id, $previous_code = '')
    {
        $input = is_array($input) ? $input : array();
        $format = isset($input['format']) ? sanitize_key($input['format']) : 'auto';

        $data = array(
            // Raw HTML/JS is only accepted from users who may post unfiltered HTML.
            'code' => current_user_can('unfiltered_html') && isset($input['code']) ? trim((string) $input['code']) : $previous_code,
            'slot' => isset($input['slot']) ? preg_replace('/[^0-9]/', '', (string) $input['slot']) : '',
            'type' => self::normalize_type(isset($input['type']) ? $input['type'] : 'display'),
            'format' => in_array($format, array('auto', 'rectangle', 'horizontal', 'vertical'), true) ? $format : 'auto',
            'layout_key' => isset($input['layout_key']) ? sanitize_text_field($input['layout_key']) : '',
            'full_width_responsive' => !empty($input['full_width_responsive']),
            'image_id' => isset($input['image_id']) ? absint($input['image_id']) : 0,
            'url' => isset($input['url']) ? self::sanitize_url($input['url']) : '',
            'alt' => isset($input['alt']) ? sanitize_text_field($input['alt']) : '',
            'new_tab' => !empty($input['new_tab']),
            'nofollow' => !empty($input['nofollow']),
            'headline' => isset($input['headline']) ? sanitize_text_field($input['headline']) : '',
            'body' => isset($input['body']) ? sanitize_textarea_field($input['body']) : '',
            'cta' => isset($input['cta']) ? sanitize_text_field($input['cta']) : '',
            'disclosure' => isset($input['disclosure']) && array_key_exists($input['disclosure'], self::disclosures()) ? $input['disclosure'] : 'sponsored',
            'start' => self::sanitize_datetime(isset($input['start']) ? $input['start'] : ''),
            'end' => self::sanitize_datetime(isset($input['end']) ? $input['end'] : ''),
            'track' => !empty($input['track']),
            'fallback_id' => isset($input['fallback_id']) && (int) $input['fallback_id'] !== (int) $post_id ? absint($input['fallback_id']) : 0,
            'members' => self::sanitize_members(isset($input['members']) ? $input['members'] : array(), $post_id),
            'rotation' => isset($input['rotation']) && 'ordered' === $input['rotation'] ? 'ordered' : 'weighted',
        );

        /**
         * Filters the ad unit data before it is saved.
         *
         * @param array $data Sanitized data.
         * @param array $input Raw (unslashed) input.
         * @param int $post_id
         * @since 1.4.0
         */
        $data = apply_filters('adflow_sanitize_ad_unit', $data, $input, $post_id);

        return $data;
    }

    /**
     * Clean group members: existing, non-group units other than itself.
     *
     * @param array $members Raw rows.
     * @param int $post_id Group ID.
     * @return array[] id, weight
     * @since 1.4.0
     */
    private static function sanitize_members($members, $post_id)
    {
        $clean = array();

        foreach ((array) $members as $member) {
            $id = isset($member['id']) ? absint($member['id']) : 0;

            $meta = get_post_meta($id, self::META_KEY, true);
            $member_type = is_array($meta) && isset($meta['type']) ? self::normalize_type($meta['type']) : '';

            if (!$id || $id === (int) $post_id || isset($clean[$id]) || 'group' === $member_type || self::POST_TYPE !== get_post_type($id)) {
                continue;
            }

            $clean[$id] = array('id' => $id, 'weight' => isset($member['weight']) ? max(1, min(100, absint($member['weight']))) : 1);
        }

        return array_values($clean);
    }

    /**
     * Normalise a datetime-local value (Y-m-d\TH:i).
     *
     * @param string $value Value.
     * @return string
     * @since 1.4.0
     */
    private static function sanitize_datetime($value)
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $value) ? $value : '';
    }

    /**
     * Users who may create but not publish ads can never change a live ad
     * directly: their changes go back to "Pending review".
     *
     * @param array $data Post data.
     * @param array $postarr Raw post array.
     * @return array
     * @since 1.4.0
     */
    public function enforce_review($data, $postarr)
    {
        if (self::POST_TYPE !== $data['post_type'] || !in_array($data['post_status'], array('publish', 'future'), true)) {
            return $data;
        }

        // Only real users (cron, WP-CLI and imports run without one or as admins).
        if (get_current_user_id() && !current_user_can(Simple_Google_Adsense_Caps::PUBLISH_ADS)) {
            $data['post_status'] = 'pending';
        }

        return $data;
    }

    /**
     * Live (published or scheduled) ads can only be edited or deleted by users
     * who may publish ads. Everyone else sees them read-only, so saving can
     * never pull a running ad off the site while it waits for review.
     *
     * @param array $caps Primitive caps.
     * @param string $cap Meta cap being checked.
     * @param int $user_id User.
     * @param array $args Arguments (post ID first).
     * @return array
     * @since 1.4.0
     */
    public function guard_live_ads($caps, $cap, $user_id, $args)
    {
        // Core checks both the meta caps and, in places such as the REST API, the
        // post type's mapped cap with a post ID.
        if (!in_array($cap, array('edit_post', 'delete_post', Simple_Google_Adsense_Caps::MANAGE_ADS), true) || empty($args[0])) {
            return $caps;
        }

        $post = is_numeric($args[0]) || $args[0] instanceof WP_Post ? get_post($args[0]) : null;

        if ($post && self::POST_TYPE === $post->post_type && in_array($post->post_status, array('publish', 'future'), true)
            && !user_can($user_id, Simple_Google_Adsense_Caps::PUBLISH_ADS)) {
            return array('do_not_allow');
        }

        return $caps;
    }

    /**
     * Only users who may publish ads can trash or delete a live ad.
     *
     * @param mixed $check Short-circuit value.
     * @param WP_Post $post Post.
     * @return mixed
     * @since 1.4.0
     */
    public function protect_live_ads($check, $post)
    {
        if ($post instanceof WP_Post && self::POST_TYPE === $post->post_type && 'publish' === $post->post_status
            && get_current_user_id() && !current_user_can(Simple_Google_Adsense_Caps::PUBLISH_ADS)) {
            return false;
        }

        return $check;
    }

    /**
     * Ask common caching plugins to purge, so ad changes show at once.
     *
     * @since 1.4.0
     */
    public static function purge_page_cache()
    {
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }

        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        do_action('litespeed_purge_all');

        /**
         * Fires when AdFlow asks page caches to purge.
         *
         * @since 1.4.0
         */
        do_action('adflow_purge_page_cache');
    }

    /**
     * List table columns.
     *
     * @param array $columns Columns.
     * @return array
     * @since 1.4.0
     */
    public function columns($columns)
    {
        return array(
            'cb' => isset($columns['cb']) ? $columns['cb'] : '<input type="checkbox" />',
            'title' => __('Name', 'simple-google-adsense'),
            'adflow_type' => __('Type', 'simple-google-adsense'),
            'adflow_slot' => __('Slot / link', 'simple-google-adsense'),
            'adflow_status' => __('Status', 'simple-google-adsense'),
            'adflow_stats' => __('Last 30 days', 'simple-google-adsense'),
            'adflow_used' => __('Used in', 'simple-google-adsense'),
            'adflow_shortcode' => __('Shortcode', 'simple-google-adsense'),
        );
    }

    /**
     * Short type names for the list table.
     *
     * @return array
     * @since 1.4.0
     */
    private static function short_types()
    {
        return array(
            'display' => __('Display', 'simple-google-adsense'),
            'inarticle' => __('In-article', 'simple-google-adsense'),
            'infeed' => __('In-feed', 'simple-google-adsense'),
            'multiplex' => __('Multiplex', 'simple-google-adsense'),
            'custom' => __('Custom code', 'simple-google-adsense'),
            'image' => __('Image banner', 'simple-google-adsense'),
            'text' => __('Text ad', 'simple-google-adsense'),
            'group' => __('Rotation group', 'simple-google-adsense'),
        );
    }

    /**
     * Placement labels that use a unit (primary or in an A/B rotation).
     *
     * @param int $unit_id Unit ID.
     * @return string[]
     * @since 1.4.0
     */
    private static function used_in($unit_id)
    {
        static $placements = null;

        if (null === $placements) {
            $placements = Simple_Google_Adsense_Placements::get_all();
        }

        $types = Simple_Google_Adsense_Placements::get_types();
        $used = array();

        foreach ($placements as $key => $placement) {
            if (empty($placement['enabled'])) {
                continue;
            }

            $ids = array_map('intval', array_merge(array($placement['ad_id']), isset($placement['rotation_ids']) ? (array) $placement['rotation_ids'] : array()));

            if (in_array((int) $unit_id, $ids, true)) {
                $used[] = isset($types[$key]['label']) ? $types[$key]['label'] : $key;
            }
        }

        return $used;
    }

    /**
     * List table column content.
     *
     * @param string $column Column key.
     * @param int $post_id Post ID.
     * @since 1.4.0
     */
    public function column_content($column, $post_id)
    {
        $data = get_post_meta($post_id, self::META_KEY, true);
        $data = wp_parse_args(is_array($data) ? $data : array(), self::defaults());
        $type = self::normalize_type($data['type']);
        $types = self::short_types();

        switch ($column) {
            case 'adflow_type':
                echo esc_html(isset($types[$type]) ? $types[$type] : $type);
                break;
            case 'adflow_status':
                if ('group' === $type) {
                    echo '<span class="description">' . esc_html('ordered' === $data['rotation'] ? __('In order', 'simple-google-adsense') : __('By weight', 'simple-google-adsense')) . '</span>';
                } elseif (self::is_own_ad($type)) {
                    $status = self::status($data);
                    $labels = self::status_labels();
                    echo '<span class="adflow-pill adflow-pill--' . esc_attr('running' === $status ? 'ok' : ('expired' === $status ? 'error' : 'info')) . '">' . esc_html($labels[$status]) . '</span>';
                } else {
                    echo '<span class="description">' . esc_html__('AdSense', 'simple-google-adsense') . '</span>';
                }
                break;
            case 'adflow_stats':
                static $stats = null;
                if (null === $stats) {
                    $stats = Simple_Google_Adsense_Stats::grouped('ad_id', array('days' => 30));
                }
                if ('group' === $type) {
                    echo '<span class="description">' . esc_html__('Counted per ad', 'simple-google-adsense') . '</span>';
                } elseif (!self::is_own_ad($type)) {
                    echo '<span class="description">' . esc_html__('See Earnings', 'simple-google-adsense') . '</span>';
                } elseif (isset($stats[$post_id])) {
                    $row = $stats[$post_id];
                    printf(
                        '<span class="adflow-list-stats"><strong>%1$s</strong> %2$s &middot; <strong>%3$s</strong> %4$s &middot; %5$s</span>',
                        esc_html(number_format_i18n($row['impressions'])),
                        esc_html__('views', 'simple-google-adsense'),
                        esc_html(number_format_i18n($row['clicks'])),
                        esc_html__('clicks', 'simple-google-adsense'),
                        esc_html(Simple_Google_Adsense_Stats::format_ctr($row['ctr']))
                    );
                } else {
                    echo '<span class="description">' . esc_html__('No data yet', 'simple-google-adsense') . '</span>';
                }
                break;
            case 'adflow_slot':
                if ('group' === $type) {
                    /* translators: %d: number of ads */
                    echo esc_html(sprintf(_n('%d ad', '%d ads', count((array) $data['members']), 'simple-google-adsense'), count((array) $data['members'])));
                } elseif (in_array($type, array('image', 'text'), true)) {
                    $host = $data['url'] ? wp_parse_url($data['url'], PHP_URL_HOST) : '';
                    echo $host ? '<span title="' . esc_attr($data['url']) . '">' . esc_html($host) . '</span>' : '<span aria-hidden="true">&mdash;</span>';
                } elseif ('custom' === $type) {
                    echo '<span aria-hidden="true">&mdash;</span>';
                } elseif ('' !== $data['slot']) {
                    echo '<code>' . esc_html($data['slot']) . '</code>';
                } else {
                    echo '<span class="adflow-pill adflow-pill--error">' . esc_html__('Missing', 'simple-google-adsense') . '</span>';
                }
                break;
            case 'adflow_used':
                $used = self::used_in($post_id);
                echo $used ? esc_html(implode(', ', $used)) : '<span class="description">' . esc_html__('No automatic placement', 'simple-google-adsense') . '</span>';
                break;
            case 'adflow_shortcode':
                echo '<code class="adflow-copy" data-adflow-copy="[adflow id=&quot;' . esc_attr($post_id) . '&quot;]" title="' . esc_attr__('Click to copy', 'simple-google-adsense') . '">[adflow id="' . esc_html($post_id) . '"]</code>';
                break;
        }
    }

    /**
     * Friendlier update messages.
     *
     * @param array $messages Messages.
     * @return array
     * @since 1.4.0
     */
    public function updated_messages($messages)
    {
        $saved = __('Ad unit saved.', 'simple-google-adsense');

        $messages[self::POST_TYPE] = array_fill(0, 11, $saved);
        // "Post published" etc. make no sense for an ad unit.
        $messages[self::POST_TYPE][0] = '';

        return $messages;
    }

    /**
     * Title placeholder.
     *
     * @param string $placeholder Placeholder.
     * @param WP_Post $post Post.
     * @return string
     * @since 1.4.0
     */
    public function title_placeholder($placeholder, $post)
    {
        return self::POST_TYPE === $post->post_type ? __('Name, e.g. "Sidebar 300x250"', 'simple-google-adsense') : $placeholder;
    }
}
