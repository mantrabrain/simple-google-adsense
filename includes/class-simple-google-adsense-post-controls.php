<?php
/**
 * Simple_Google_Adsense per-post ad controls
 *
 * A box on every public post type to switch off all ads, only Auto Ads, or
 * only manual ads on one post or page. Until 1.6.0 this box was part of AdFlow
 * Pro; the meta keys are unchanged, so existing choices keep working.
 *
 * @package Simple_Google_Adsense
 * @since   1.6.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Post_Controls Class.
 *
 * @class Simple_Google_Adsense_Post_Controls
 */
final class Simple_Google_Adsense_Post_Controls
{

    /**
     * Meta key: no AdFlow ad on the post.
     */
    const META_DISABLE_ALL = '_adflow_disable_ads';

    /**
     * Meta key: no Auto Ads on the post.
     */
    const META_DISABLE_AUTO = '_adflow_disable_auto_ads';

    /**
     * Meta key: no manual ads (placements, block, widgets, shortcodes) on the post.
     */
    const META_DISABLE_MANUAL = '_adflow_disable_manual_ads';

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Post_Controls
     */
    protected static $_instance = null;

    /**
     * Main instance.
     *
     * @return Simple_Google_Adsense_Post_Controls
     * @since 1.6.0
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
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        // An older AdFlow Pro still adds its own box: one box is enough.
        add_action('add_meta_boxes', array($this, 'remove_older_pro_box'), 100);
        add_action('save_post', array($this, 'save'), 10, 2);
        add_filter('adflow_ads_allowed', array($this, 'ads_allowed'));
        add_filter('adflow_auto_ads_allowed', array($this, 'auto_ads_allowed'));
        add_filter('adflow_manual_ads_excluded', array($this, 'manual_ads_excluded'));
    }

    /**
     * Add the box.
     *
     * @param string $post_type Post type.
     * @since 1.6.0
     */
    public function add_meta_box($post_type)
    {
        $types = get_post_types(array('public' => true));
        unset($types['attachment']);

        if (!isset($types[$post_type]) || !current_user_can(Simple_Google_Adsense_Caps::MANAGE_ADS)) {
            return;
        }

        add_meta_box('adflow-post-controls', __('AdFlow Ads', 'simple-google-adsense'), array($this, 'render'), $post_type, 'side', 'low');
    }

    /**
     * Remove the box of an older AdFlow Pro.
     *
     * @param string $post_type Post type.
     * @since 1.6.0
     */
    public function remove_older_pro_box($post_type)
    {
        remove_meta_box('adflow-pro-post-controls', $post_type, 'side');
    }

    /**
     * Render the box.
     *
     * @param WP_Post $post Post.
     * @since 1.6.0
     */
    public function render($post)
    {
        wp_nonce_field('adflow_post_controls', 'adflow_post_controls_nonce');
        ?>
        <p>
            <label><input type="checkbox" name="adflow_disable_ads" value="1" <?php checked((bool) get_post_meta($post->ID, self::META_DISABLE_ALL, true)); ?>>
                <?php esc_html_e('No ads on this page', 'simple-google-adsense'); ?></label>
        </p>
        <p>
            <label><input type="checkbox" name="adflow_disable_auto_ads" value="1" <?php checked((bool) get_post_meta($post->ID, self::META_DISABLE_AUTO, true)); ?>>
                <?php esc_html_e('No Auto Ads on this page', 'simple-google-adsense'); ?></label>
        </p>
        <p>
            <label><input type="checkbox" name="adflow_disable_manual_ads" value="1" <?php checked((bool) get_post_meta($post->ID, self::META_DISABLE_MANUAL, true)); ?>>
                <?php esc_html_e('No manual ads on this page', 'simple-google-adsense'); ?></label>
        </p>
        <?php
    }

    /**
     * Save the box.
     *
     * @param int $post_id Post ID.
     * @param WP_Post $post Post.
     * @since 1.6.0
     */
    public function save($post_id, $post)
    {
        if (!isset($_POST['adflow_post_controls_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['adflow_post_controls_nonce'])), 'adflow_post_controls')
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || wp_is_post_revision($post_id)
            || !current_user_can(Simple_Google_Adsense_Caps::MANAGE_ADS)
            || !current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = array(
            'adflow_disable_ads' => self::META_DISABLE_ALL,
            'adflow_disable_auto_ads' => self::META_DISABLE_AUTO,
            'adflow_disable_manual_ads' => self::META_DISABLE_MANUAL,
        );

        foreach ($fields as $field => $meta_key) {
            if (!empty($_POST[$field])) {
                update_post_meta($post_id, $meta_key, 1);
            } else {
                delete_post_meta($post_id, $meta_key);
            }
        }
    }

    /**
     * Post being viewed, or 0. Includes the page set as the Posts page.
     *
     * @return int
     * @since 1.6.0
     */
    private function current_post_id()
    {
        if (!did_action('wp')) {
            return 0;
        }

        $object = get_queried_object();

        return $object instanceof WP_Post ? (int) $object->ID : 0;
    }

    /**
     * No ads at all on flagged posts.
     *
     * @param bool $allowed Allowed.
     * @return bool
     * @since 1.6.0
     */
    public function ads_allowed($allowed)
    {
        $post_id = $this->current_post_id();

        return $allowed && $post_id && get_post_meta($post_id, self::META_DISABLE_ALL, true) ? false : $allowed;
    }

    /**
     * No Auto Ads on flagged posts.
     *
     * @param bool $allowed Allowed.
     * @return bool
     * @since 1.6.0
     */
    public function auto_ads_allowed($allowed)
    {
        $post_id = $this->current_post_id();

        return $allowed && $post_id && get_post_meta($post_id, self::META_DISABLE_AUTO, true) ? false : $allowed;
    }

    /**
     * No manual ads on flagged posts.
     *
     * @param bool $excluded Excluded.
     * @return bool
     * @since 1.6.0
     */
    public function manual_ads_excluded($excluded)
    {
        $post_id = $this->current_post_id();

        return !$excluded && $post_id && get_post_meta($post_id, self::META_DISABLE_MANUAL, true) ? true : $excluded;
    }
}
