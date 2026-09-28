<?php
/**
 * AdFlow uninstall
 *
 * Removes the plugin's options and saved ad units when the plugin is deleted
 * from the Plugins screen (not when it is merely deactivated). On multisite
 * every site is cleaned.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

/**
 * Remove AdFlow data from the current site.
 *
 * @since 1.4.0
 */
function simple_google_adsense_uninstall_site()
{
    foreach (array(
        'simple_google_adsense_settings',
        'simple_google_adsense_placements',
        'simple_google_adsense_ads_txt',
        'simple_google_adsense_installed',
        'simple_google_adsense_review_dismissed',
        'widget_simple_google_adsense_widget',
        'simple_google_adsense_tracking',
        'simple_google_adsense_stats_db',
        'simple_google_adsense_stats_transport',
        'adflow_consent',
        'adflow_import_map',
        'adflow_import_report',
        'adflow_caps_version',
        'adflow_last_render_error',
        'adflow_pro_notice_dismissed',
    ) as $simple_google_adsense_option) {
        delete_option($simple_google_adsense_option);
    }

    foreach (wp_roles()->role_objects as $simple_google_adsense_role) {
        foreach (array('adflow_access', 'adflow_manage_ads', 'adflow_publish_ads', 'adflow_view_reports') as $simple_google_adsense_cap) {
            $simple_google_adsense_role->remove_cap($simple_google_adsense_cap);
        }
    }

    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}afx_stats"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange

    wp_clear_scheduled_hook('adflow_daily_maintenance');
    delete_transient('adflow_ads_txt_check');
    delete_transient('simple_google_adsense_activation_redirect');

    $simple_google_adsense_ids = get_posts(array(
        'post_type' => 'adflow_ad',
        'post_status' => array_keys(get_post_stati()),
        'numberposts' => -1,
        'fields' => 'ids',
    ));

    foreach ($simple_google_adsense_ids as $simple_google_adsense_id) {
        wp_delete_post($simple_google_adsense_id, true);
    }
}

/**
 * Filters whether AdFlow data is removed on uninstall.
 *
 * Return false from a mu-plugin to keep settings when reinstalling.
 *
 * @param bool $delete
 * @since 1.4.0
 */
/*
 * Keep everything unless the site owner asked for a clean removal
 * (Settings → General → Your data). Reinstalling never loses ads or stats.
 */
/**
 * Whether the current site asked for its data to be deleted.
 *
 * @return bool
 * @since 1.4.0
 */
function simple_google_adsense_wants_delete()
{
    $settings = get_option('simple_google_adsense_settings');

    return (bool) apply_filters('adflow_delete_data_on_uninstall', is_array($settings) && !empty($settings['delete_data']));
}

// Each site decides for itself (on multisite, one site's choice never affects another).
if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $simple_google_adsense_site_id) {
        switch_to_blog($simple_google_adsense_site_id);
        wp_clear_scheduled_hook('adflow_daily_maintenance');
        if (simple_google_adsense_wants_delete()) {
            simple_google_adsense_uninstall_site();
        }
        restore_current_blog();
    }
} else {
    wp_clear_scheduled_hook('adflow_daily_maintenance');
    if (simple_google_adsense_wants_delete()) {
        simple_google_adsense_uninstall_site();
    }
}
