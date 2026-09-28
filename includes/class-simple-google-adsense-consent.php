<?php
/**
 * Simple_Google_Adsense_Consent
 *
 * Privacy & consent for visitors from the EEA, UK and Switzerland:
 * - Google Consent Mode v2 defaults (denied until the visitor agrees), kept
 *   up to date from any consent plugin that supports the WP Consent API
 *   (Complianz, CookieYes, Cookiebot, …);
 * - "Load ads after consent": the AdSense library, custom code from other
 *   networks and Ad Manager slots wait until the consent tool has answered -
 *   an IAB TCF v2.2 CMP (Google-certified, e.g. AdSense's own message) or the
 *   WP Consent API.
 *
 * Works with page caching: the decision is made in the visitor's browser.
 * Both options are off by default, so existing sites are unchanged.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */

defined('ABSPATH') || exit;

/**
 * Simple_Google_Adsense_Consent Class.
 *
 * @class Simple_Google_Adsense_Consent
 */
final class Simple_Google_Adsense_Consent
{

    const OPTION_NAME = 'adflow_consent';

    /**
     * EEA, UK and Switzerland (ISO 3166-1 alpha-2), where Google requires consent.
     */
    const REGIONS = array('AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'GB', 'CH');

    /**
     * The single instance of the class.
     *
     * @var Simple_Google_Adsense_Consent
     * @since 1.4.0
     */
    protected static $_instance = null;

    /**
     * Main Simple_Google_Adsense_Consent Instance.
     *
     * @return Simple_Google_Adsense_Consent
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
     * Simple_Google_Adsense_Consent Constructor.
     */
    public function __construct()
    {
        add_action('admin_init', array($this, 'register_setting'));
        add_filter('adflow_settings_tabs', array($this, 'register_tab'), 22);
        add_action('wp_head', array($this, 'print_head_script'), 0);
        add_filter('script_loader_tag', array($this, 'gate_library'), 15, 2);
        add_filter('adflow_health_checks', array($this, 'health_check'), 20);
    }

    /**
     * Settings.
     *
     * @return array consent_mode, wait, region (eea|all)
     * @since 1.4.0
     */
    public static function settings()
    {
        $settings = get_option(self::OPTION_NAME);

        return wp_parse_args(is_array($settings) ? $settings : array(), array(
            'consent_mode' => false,
            'wait' => false,
            'region' => 'eea',
        ));
    }

    /**
     * Whether ads wait for the consent tool.
     *
     * @return bool
     * @since 1.4.0
     */
    public static function waits()
    {
        $settings = self::settings();

        return !empty($settings['wait']);
    }

    /**
     * Consent plugins found on this site.
     *
     * @return string[] Names.
     * @since 1.4.0
     */
    public static function detected_tools()
    {
        $tools = array_unique(Simple_Google_Adsense_Admin::detect_plugins(array(
            'complianz-gdpr/complianz-gpdr.php' => 'Complianz',
            'complianz-gdpr-premium/complianz-gpdr-premium.php' => 'Complianz',
            'cookie-law-info/cookie-law-info.php' => 'CookieYes',
            'cookiebot/cookiebot.php' => 'Cookiebot',
            'gdpr-cookie-compliance/moove-gdpr.php' => 'GDPR Cookie Compliance',
            'cookie-notice/cookie-notice.php' => 'Cookie Notice & Compliance',
            'real-cookie-banner/index.php' => 'Real Cookie Banner',
            'iubenda-cookie-law-solution/iubenda_cookie_solution.php' => 'iubenda',
            'wp-consent-api/wp-consent-api.php' => 'WP Consent API',
        )));

        return array_values($tools);
    }

    /**
     * Print the consent helper as early as possible in <head>.
     *
     * Consent Mode defaults must be set before any Google tag loads.
     *
     * @since 1.4.0
     */
    public function print_head_script()
    {
        $settings = self::settings();

        if (empty($settings['consent_mode']) && empty($settings['wait'])) {
            return;
        }

        $config = array(
            'mode' => !empty($settings['consent_mode']),
            'wait' => !empty($settings['wait']),
            'regions' => 'all' === $settings['region'] ? array() : self::REGIONS,
            // A consent plugin loads its own script: give it time. Without one, Google's
            // own consent message comes with the AdSense library, so load it at once.
            'cmp' => (bool) self::detected_tools(),
        );

        // Small and dependency-free; printed inline so it runs before any ad script.
        $script = <<<'JS'
(function(w,d,c){
if(c.mode){w.dataLayer=w.dataLayer||[];w.gtag=w.gtag||function(){w.dataLayer.push(arguments);};
var no={ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',wait_for_update:500};
if(c.regions.length){no.region=c.regions;w.gtag('consent','default',{ad_storage:'granted',ad_user_data:'granted',ad_personalization:'granted',analytics_storage:'granted'});}
w.gtag('consent','default',no);}
var answered=!c.wait,granted=!c.wait,qa=[],qg=[];
function run(q){q.splice(0).forEach(function(f){try{f();}catch(e){}});}
function answer(){if(!answered){answered=true;run(qa);}}
function grant(){answer();if(!granted){granted=true;run(qg);}}
function wpc(t){return typeof w.wp_has_consent==='function'&&w.wp_has_consent(t);}
function update(){if(!c.mode||typeof w.wp_has_consent!=='function'){return;}var m=wpc('marketing')?'granted':'denied';w.gtag('consent','update',{ad_storage:m,ad_user_data:m,ad_personalization:m,analytics_storage:wpc('statistics')?'granted':'denied'});}
d.addEventListener('wp_listen_for_consent_change',function(){update();if(wpc('marketing')){grant();}});
function tcf(){w.__tcfapi('addEventListener',2,function(t,s){if(!s||!t){return;}
if(t.gdprApplies===false){grant();return;}
if(t.eventStatus==='tcloaded'||t.eventStatus==='useractioncomplete'){answer();if(t.purpose&&t.purpose.consents&&t.purpose.consents[1]){grant();}}});}
function watch(n){if(typeof w.__tcfapi==='function'){tcf();return;}if(n>0){setTimeout(function(){watch(n-1);},250);return;}grant();}
function check(n){
if(typeof w.__tcfapi==='function'){tcf();return;}
if(typeof w.wp_has_consent==='function'){update();if(wpc('marketing')){grant();}return;}
if(n>0){setTimeout(function(){check(n-1);},250);return;}
answer();watch(40);}
w.afxConsent={ready:function(f){if(answered){f();}else{qa.push(f);}},granted:function(){return answered;},
whenGranted:function(f){if(granted){f();}else{qg.push(f);}},isGranted:function(){return granted;}};
var go=function(){check(c.cmp?12:0);};
if(d.readyState==='loading'){d.addEventListener('DOMContentLoaded',go);}else{go();}
})(window,document,
JS;

        echo '<script id="adflow-consent">' . $script . wp_json_encode($config) . ');</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static script plus JSON config.
    }

    /**
     * With "load ads after consent", the AdSense library is added by the
     * consent helper once the consent tool has answered.
     *
     * @param string $tag Script tag.
     * @param string $handle Handle.
     * @return string
     * @since 1.4.0
     */
    public function gate_library($tag, $handle)
    {
        if (Simple_Google_Adsense_Frontend::LIBRARY_HANDLE !== $handle || !self::waits() || !preg_match('/\ssrc=["\']([^"\']+)["\']/', $tag, $m)) {
            return $tag;
        }

        return self::deferred_script(html_entity_decode($m[1]), Simple_Google_Adsense_Frontend::LIBRARY_HANDLE . '-js');
    }

    /**
     * Inline loader that adds a script once consent allows it.
     *
     * @param string $src Script URL.
     * @param string $id Element ID (loaded once per page).
     * @return string
     * @since 1.4.0
     */
    public static function deferred_script($src, $id)
    {
        $js = sprintf(
            '(window.afxConsent?afxConsent.ready:function(f){f();})(function(){if(document.getElementById(%2$s)){return;}var s=document.createElement("script");s.async=true;s.id=%2$s;s.crossOrigin="anonymous";s.src=%1$s;document.head.appendChild(s);});',
            wp_json_encode(esc_url_raw($src)),
            wp_json_encode($id)
        );

        return '<script>' . $js . '</script>' . "\n";
    }

    /*
    |--------------------------------------------------------------------------
    | Settings screen
    |--------------------------------------------------------------------------
    */

    /**
     * Register the setting.
     *
     * @since 1.4.0
     */
    public function register_setting()
    {
        register_setting('adflow_consent', self::OPTION_NAME, array(
            'sanitize_callback' => array($this, 'sanitize'),
        ));
    }

    /**
     * Sanitize.
     *
     * @param array $input Input.
     * @return array
     * @since 1.4.0
     */
    public function sanitize($input)
    {
        $input = is_array($input) ? $input : array();

        Simple_Google_Adsense_Ad_Units::purge_page_cache();

        return array(
            'consent_mode' => !empty($input['consent_mode']),
            'wait' => !empty($input['wait']),
            'region' => isset($input['region']) && 'all' === $input['region'] ? 'all' : 'eea',
        );
    }

    /**
     * Register the tab.
     *
     * @param array $tabs Tabs.
     * @return array
     * @since 1.4.0
     */
    public function register_tab($tabs)
    {
        $tabs['privacy'] = array(
            'label' => __('Privacy & consent', 'simple-google-adsense'),
            'callback' => array($this, 'render_tab'),
            'priority' => 22,
        );

        return $tabs;
    }

    /**
     * Render the tab.
     *
     * @since 1.4.0
     */
    public function render_tab()
    {
        $s = self::settings();
        $name = self::OPTION_NAME;
        $A = 'Simple_Google_Adsense_Admin';
        $tools = self::detected_tools();
        ?>
        <form action="options.php" method="post">
            <?php settings_fields('adflow_consent'); ?>

            <?php $A::panel_start(__('Consent for visitors from the EEA, UK and Switzerland', 'simple-google-adsense'), __('Google requires consent before showing personalised ads in these countries. Show the consent message with AdSense\'s own "Privacy & messaging" or a consent plugin; AdFlow makes your ads respect the answer.', 'simple-google-adsense')); ?>

                <?php $A::field_start(__('Your consent tool', 'simple-google-adsense')); ?>
                    <?php if ($tools) : ?>
                        <p><span class="adflow-pill adflow-pill--ok"><?php esc_html_e('Found', 'simple-google-adsense'); ?></span> <?php echo esc_html(implode(', ', $tools)); ?></p>
                    <?php else : ?>
                        <p><span class="adflow-pill adflow-pill--info"><?php esc_html_e('None found', 'simple-google-adsense'); ?></span> <?php esc_html_e('No consent plugin was found. If you use AdSense\'s own European regulations message, that is fine - it works with both options below.', 'simple-google-adsense'); ?></p>
                    <?php endif; ?>
                <?php $A::field_end(); ?>

                <?php $A::field_start(__('Load ads after consent', 'simple-google-adsense'), __('AdSense, custom code from other networks and Ad Manager slots load only once the consent tool has answered. Your own banners and text ads use no cookies and show straight away.', 'simple-google-adsense')); ?>
                    <?php $A::toggle($name . '[wait]', !empty($s['wait']), __('Wait for the consent tool before loading ads', 'simple-google-adsense')); ?>
                    <p class="description"><?php esc_html_e('Google ads (AdSense, Ad Manager) load once the consent message has been answered - Google reads the visitor\'s choice itself. Other networks\' code loads only after the visitor agrees to marketing / advertising (IAB TCF purpose 1, or "marketing" in the WP Consent API). With AdSense\'s own consent message, the AdSense library loads straight away so the message can appear. Visitors outside the EEA, UK and Switzerland are not delayed.', 'simple-google-adsense'); ?></p>
                <?php $A::field_end(); ?>

                <?php $A::field_start(__('Google Consent Mode v2', 'simple-google-adsense'), __('Tells Google tags that consent is "denied" until the visitor agrees, then passes on their choice. Needed when your consent banner is not a Google-certified TCF message.', 'simple-google-adsense'), 'adflow-consent-region'); ?>
                    <?php $A::toggle($name . '[consent_mode]', !empty($s['consent_mode']), __('Set Consent Mode defaults and updates', 'simple-google-adsense')); ?>
                    <p style="margin-top:10px">
                        <label for="adflow-consent-region"><?php esc_html_e('Deny by default for', 'simple-google-adsense'); ?></label>
                        <select id="adflow-consent-region" name="<?php echo esc_attr($name); ?>[region]">
                            <option value="eea" <?php selected($s['region'], 'eea'); ?>><?php esc_html_e('Visitors from the EEA, UK and Switzerland', 'simple-google-adsense'); ?></option>
                            <option value="all" <?php selected($s['region'], 'all'); ?>><?php esc_html_e('All visitors', 'simple-google-adsense'); ?></option>
                        </select>
                    </p>
                    <?php if (array_intersect($tools, array('Complianz', 'CookieYes', 'Cookiebot', 'Real Cookie Banner', 'iubenda'))) : ?>
                        <p class="description"><?php esc_html_e('Your consent plugin may set Consent Mode itself. Turn it on in only one place.', 'simple-google-adsense'); ?></p>
                    <?php endif; ?>
                    <p class="description"><?php esc_html_e('Choices are read from the WP Consent API ("marketing" for ads, "statistics" for analytics).', 'simple-google-adsense'); ?></p>
                <?php $A::field_end(); ?>

            <?php $A::panel_end(); ?>

            <div class="adflow-savebar">
                <?php submit_button(__('Save changes', 'simple-google-adsense'), 'primary', 'submit', false); ?>
            </div>
        </form>
        <?php
    }

    /**
     * Health check: consent setup.
     *
     * @param array $checks Checks.
     * @return array
     * @since 1.4.0
     */
    public function health_check($checks)
    {
        $s = self::settings();

        if (empty($s['wait']) && empty($s['consent_mode'])) {
            return $checks;
        }

        $tools = self::detected_tools();
        $parts = array();

        if (!empty($s['wait'])) {
            $parts[] = __('ads load after consent', 'simple-google-adsense');
        }
        if (!empty($s['consent_mode'])) {
            $parts[] = __('Consent Mode v2 is on', 'simple-google-adsense');
        }

        $checks['consent'] = array(
            'status' => $tools || empty($s['consent_mode']) ? 'ok' : 'warning',
            'label' => __('Consent (EEA/UK)', 'simple-google-adsense'),
            'message' => ucfirst(implode(', ', $parts)) . '. '
                . ($tools
                    /* translators: %s: plugin names */
                    ? sprintf(__('Consent tool: %s.', 'simple-google-adsense'), implode(', ', $tools))
                    : __('No consent plugin found - Consent Mode stays "denied" in the EEA unless AdSense\'s own message or another tool updates it.', 'simple-google-adsense')),
            'action' => Simple_Google_Adsense_Admin::settings_url('privacy'),
            'action_label' => __('Settings', 'simple-google-adsense'),
        );

        return $checks;
    }
}
