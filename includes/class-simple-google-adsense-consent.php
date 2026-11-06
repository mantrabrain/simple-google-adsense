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
 *   an IAB TCF v2.2 CMP (Google-certified, e.g. AdSense's own message), the
 *   WP Consent API, CookieYes, Complianz or Cookiebot. No timer ever releases
 *   them: with no readable answer, they stay held.
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
     * Consent plugins whose answer AdFlow can read directly: name => browser key.
     */
    const BRIDGES = array('CookieYes' => 'cookieyes', 'Complianz' => 'complianz', 'Cookiebot' => 'cookiebot');

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
        add_action('admin_notices', array($this, 'held_notice'));
    }

    /**
     * Whether "Load ads after consent" currently holds every visitor's ads for
     * good: nothing on this site can give AdFlow an answer it can read.
     *
     * @return bool
     * @since 1.6.0
     */
    public static function holds_everyone()
    {
        return self::waits() && !self::uses_google_message() && (self::unreadable_plugins() || !self::consent_plugins());
    }

    /**
     * Ads stopping is a loss of income: say so outside the Site health list too,
     * on AdFlow's screens and on Plugins, where the update was made.
     *
     * @since 1.6.0
     */
    public function held_notice()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!current_user_can('manage_options') || !self::holds_everyone() || !(Simple_Google_Adsense_Admin::is_adflow_screen() || ($screen && 'plugins' === $screen->id))) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
            esc_html__('AdFlow: ads are not loading.', 'simple-google-adsense'),
            esc_html(self::wait_warning(self::settings(), self::unreadable_plugins(), self::consent_plugins())),
            esc_url(Simple_Google_Adsense_Admin::settings_url('privacy')),
            esc_html__('Privacy & consent settings', 'simple-google-adsense')
        );
    }

    /**
     * Settings.
     *
     * @return array consent_mode, wait, region (eea|all), google_message (null until saved)
     * @since 1.4.0
     */
    public static function settings()
    {
        $settings = get_option(self::OPTION_NAME);

        return wp_parse_args(is_array($settings) ? $settings : array(), array(
            'consent_mode' => false,
            'wait' => false,
            'region' => 'eea',
            'google_message' => null,
        ));
    }

    /**
     * Whether AdSense's own consent message is the consent tool here.
     *
     * The message arrives with the AdSense library, so the library has to load
     * for it to appear. Until the owner saves this choice: yes when no consent
     * plugin was found, no when one was.
     *
     * @return bool
     * @since 1.6.0
     */
    public static function uses_google_message()
    {
        $settings = self::settings();

        return null === $settings['google_message'] ? !self::consent_plugins() : !empty($settings['google_message']);
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
     * Detected tools that ask visitors. The WP Consent API plugin only passes
     * answers along: on its own it shows no banner.
     *
     * @return string[] Names.
     * @since 1.6.0
     */
    public static function consent_plugins()
    {
        return array_values(array_diff(self::detected_tools(), array('WP Consent API')));
    }

    /**
     * Detected consent plugins AdFlow has no direct way to read.
     *
     * Their answer only arrives through IAB TCF, the WP Consent API (when the
     * plugin supports it) or a call to window.afxConsent.grant().
     *
     * @return string[] Names.
     * @since 1.6.0
     */
    public static function unreadable_plugins()
    {
        return array_values(array_diff(self::consent_plugins(), array_keys(self::BRIDGES)));
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

        // AMP pages allow no custom scripts; see Simple_Google_Adsense_Amp.
        if ((empty($settings['consent_mode']) && empty($settings['wait'])) || Simple_Google_Adsense_Settings::is_amp()) {
            return;
        }

        $config = array(
            'mode' => !empty($settings['consent_mode']),
            'wait' => !empty($settings['wait']),
            'regions' => 'all' === $settings['region'] ? array() : self::REGIONS,
            // Consent plugins whose cookie may be read before their script has run.
            'tools' => array_values(array_intersect_key(self::BRIDGES, array_flip(self::detected_tools()))),
            // Google's own message comes with the AdSense library, so that loads at once.
            'gm' => self::uses_google_message(),
        );

        // Small and dependency-free; printed inline so it runs before any ad script.
        $script = <<<'JS'
(function(w,d,c){
if(c.mode){w.dataLayer=w.dataLayer||[];w.gtag=w.gtag||function(){w.dataLayer.push(arguments);};
var no={ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',wait_for_update:500};
if(c.regions.length){no.region=c.regions;w.gtag('consent','default',{ad_storage:'granted',ad_user_data:'granted',ad_personalization:'granted',analytics_storage:'granted'});}
w.gtag('consent','default',no);}
var answered=!c.wait,granted=!c.wait,qa=[],qg=[],tcfOn=false,told='',n=0;
function run(q){q.splice(0).forEach(function(f){try{f();}catch(e){}});}
function answer(){if(!answered){answered=true;run(qa);}}
function grant(){answer();if(!granted){granted=true;run(qg);}}
function ck(k){var m=d.cookie.match(new RegExp('(?:^|; )'+k+'=([^;]*)'));return m?m[1]:'';}
function tool(k){return c.tools.indexOf(k)!==-1;}
function has(t){var y=t==='marketing'?'advertisement':'analytics',v;
if(typeof w.wp_has_consent==='function'&&(w.wp_consent_type||w.wp_fallback_consent_type)){return w.wp_has_consent(t);}
if(w.Cookiebot&&w.Cookiebot.consent){return w.Cookiebot.consent[t]===true;}
if(typeof w.cmplz_has_consent==='function'){return w.cmplz_has_consent(t)===true;}
if(tool('complianz')&&ck('cmplz_'+t)){return ck('cmplz_'+t)==='allow';}
if(typeof w.getCkyConsent==='function'){v=w.getCkyConsent();if(v&&v.categories&&v.activeLaw){return v.categories[y]===true&&(v.isUserActionCompleted===true||v.activeLaw==='ccpa');}}
if(tool('cookieyes')&&(v=ck('cookieyes-consent'))){v=decodeURIComponent(v);return /(^|,)action:yes/.test(v)&&new RegExp('(^|,)'+y+':yes').test(v);}
return null;}
function update(user){if(!c.mode){return;}var m=has('marketing'),a;if(null===m){return;}a=has('statistics');var k=(m?'1':'0')+(a?'1':'0');
if(k===told||(!user&&!told&&!m&&!a)){return;}told=k;m=m?'granted':'denied';w.gtag('consent','update',{ad_storage:m,ad_user_data:m,ad_personalization:m,analytics_storage:a?'granted':'denied'});}
function tcf(){w.__tcfapi('addEventListener',2,function(t,s){if(!s||!t){return;}
if(t.gdprApplies===false){grant();return;}
if(t.eventStatus==='tcloaded'||t.eventStatus==='useractioncomplete'){answer();if(t.purpose&&t.purpose.consents&&t.purpose.consents[1]){grant();}}});}
function tick(user){if(!tcfOn&&typeof w.__tcfapi==='function'){tcfOn=true;tcf();}if(!tcfOn&&!granted&&has('marketing')===true){grant();}update(user);}
function poll(){tick();n++;if(n<190&&(c.mode||!granted)){setTimeout(poll,n<40?250:2000);}}
['wp_listen_for_consent_change','cookieyes_consent_update','cmplz_status_change','cmplz_fire_categories'].forEach(function(e){d.addEventListener(e,function(){tick(true);});});
['CookiebotOnConsentReady','CookiebotOnAccept','CookiebotOnDecline'].forEach(function(e){w.addEventListener(e,function(){tick(true);});});
w.afxConsent={ready:function(f){if(answered){f();}else{qa.push(f);}},granted:function(){return answered;},
whenGranted:function(f){if(granted){f();}else{qg.push(f);}},isGranted:function(){return granted;},grant:grant};
var go=function(){if(c.wait&&c.gm){answer();}poll();};
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
            'google_message' => !empty($input['google_message']),
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
        $plugins = self::consent_plugins();
        $unreadable = self::unreadable_plugins();
        ?>
        <form action="options.php" method="post">
            <?php settings_fields('adflow_consent'); ?>

            <?php $A::panel_start(__('Consent for visitors from the EEA, UK and Switzerland', 'simple-google-adsense'), __('Google requires consent before showing personalised ads in these countries. Show the consent message with AdSense\'s own "Privacy & messaging" or a consent plugin; AdFlow makes your ads respect the answer.', 'simple-google-adsense')); ?>

                <?php $A::field_start(__('Your consent tool', 'simple-google-adsense')); ?>
                    <?php if ($plugins) : ?>
                        <p><span class="adflow-pill adflow-pill--<?php echo $unreadable ? 'warning' : 'ok'; ?>"><?php esc_html_e('Found', 'simple-google-adsense'); ?></span> <?php echo esc_html(implode(', ', $plugins)); ?></p>
                    <?php else : ?>
                        <p><span class="adflow-pill adflow-pill--info"><?php esc_html_e('None found', 'simple-google-adsense'); ?></span> <?php esc_html_e('No consent plugin was found. AdSense\'s own European regulations message can ask your visitors instead.', 'simple-google-adsense'); ?></p>
                    <?php endif; ?>
                    <?php self::render_tool_notice($s, $unreadable, $plugins); ?>
                <?php $A::field_end(); ?>

                <?php $A::field_start(__('Load ads after consent', 'simple-google-adsense'), __('Ads stay held until the visitor answers. No timer ever releases them. Your own banners and text ads use no cookies and show straight away.', 'simple-google-adsense')); ?>
                    <?php $A::toggle($name . '[wait]', !empty($s['wait']), __('Wait for the consent tool before loading ads', 'simple-google-adsense')); ?>
                    <p class="description"><?php esc_html_e('AdFlow reads the answer from an IAB TCF message, the WP Consent API, CookieYes, Complianz or Cookiebot. Other networks\' code and Ad Manager slots load only after the visitor agrees to advertising (TCF purpose 1, or "marketing"). The AdSense library loads once a TCF message is answered, because Google then reads the choice itself; with other tools it loads only after the visitor agrees.', 'simple-google-adsense'); ?></p>
                    <p class="description"><?php esc_html_e('AdFlow cannot tell where a visitor is. Visitors outside the EEA, UK and Switzerland wait too, unless your consent tool says consent is not needed for them. On AMP pages AdSense ads are left out while this is on.', 'simple-google-adsense'); ?></p>
                <?php $A::field_end(); ?>

                <?php $A::field_start(__('AdSense\'s own consent message', 'simple-google-adsense'), __('Set up in AdSense under Privacy & messaging. It only reaches the page with the AdSense library.', 'simple-google-adsense')); ?>
                    <?php $A::toggle($name . '[google_message]', self::uses_google_message(), __('I use AdSense\'s own consent message', 'simple-google-adsense')); ?>
                    <p class="description"><?php esc_html_e('On: the AdSense library loads at once so the message can appear, and Google applies the answer to its ads. If the message is not set up in AdSense, nobody is asked and Google\'s library still loads. Off: nothing from Google loads until a consent tool above says yes. Other networks\' code waits in both cases.', 'simple-google-adsense'); ?></p>
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
                    <p class="description"><?php esc_html_e('Choices are read from the WP Consent API, CookieYes, Complianz or Cookiebot ("marketing" for ads, "statistics" for analytics).', 'simple-google-adsense'); ?></p>
                <?php $A::field_end(); ?>

            <?php $A::panel_end(); ?>

            <div class="adflow-savebar">
                <?php submit_button(__('Save changes', 'simple-google-adsense'), 'primary', 'submit', false); ?>
            </div>
        </form>
        <?php
    }

    /**
     * Say plainly what "Load ads after consent" does with this site's tools.
     *
     * @param array $settings Settings.
     * @param string[] $unreadable Plugins AdFlow cannot read.
     * @param string[] $plugins Detected consent plugins.
     * @since 1.6.0
     */
    private static function render_tool_notice($settings, $unreadable, $plugins)
    {
        $message = self::wait_warning($settings, $unreadable, $plugins);

        if ('' !== $message) {
            echo '<p class="adflow-consent-warning">' . esc_html($message) . '</p>';
        } elseif ($plugins) {
            /* translators: %s: plugin names */
            echo '<p class="description">' . esc_html(sprintf(__('AdFlow reads the answer given in %s directly.', 'simple-google-adsense'), implode(', ', $plugins))) . '</p>';
        }

        if ($unreadable) {
            echo '<p class="description">' . wp_kses(__('For another tool, call <code>window.afxConsent.grant()</code> from its "accepted" callback.', 'simple-google-adsense'), array('code' => array())) . '</p>';
        }
    }

    /**
     * The warning, if the current setup can hold ads back for good or release
     * them without anyone being asked.
     *
     * @param array $settings Settings.
     * @param string[] $unreadable Plugins AdFlow cannot read.
     * @param string[] $plugins Detected consent plugins.
     * @return string Empty when there is nothing to warn about.
     * @since 1.6.0
     */
    private static function wait_warning($settings, $unreadable, $plugins)
    {
        if (empty($settings['wait'])) {
            return $unreadable
                ? sprintf(
                    /* translators: %s: plugin names */
                    __('AdFlow cannot read the answer given in %s. If you turn on "Load ads after consent", ads stay held unless that tool uses IAB TCF or the WP Consent API.', 'simple-google-adsense'),
                    implode(', ', $unreadable)
                )
                : '';
        }

        if ($unreadable && !self::uses_google_message()) {
            return sprintf(
                /* translators: %s: plugin names */
                __('AdFlow cannot read the answer given in %s. Ads stay held for every visitor unless that tool uses IAB TCF or the WP Consent API. Turn on one of those in the tool, or switch to CookieYes, Complianz or Cookiebot.', 'simple-google-adsense'),
                implode(', ', $unreadable)
            );
        }

        if (!$plugins && !self::uses_google_message()) {
            return __('Nothing can ask your visitors, so no ads load. Add a consent plugin, or use AdSense\'s own consent message.', 'simple-google-adsense');
        }

        if (!$plugins) {
            return __('No consent plugin: the AdSense library loads at once so that AdSense\'s own message can appear. Set that message up in AdSense, or nobody is asked. Other networks\' code waits until the message is answered.', 'simple-google-adsense');
        }

        return '';
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
        $plugins = self::consent_plugins();
        $warning = self::wait_warning($s, self::unreadable_plugins(), $plugins);
        $parts = array();

        if (!empty($s['wait'])) {
            $parts[] = __('ads load after consent', 'simple-google-adsense');
        }
        if (!empty($s['consent_mode'])) {
            $parts[] = __('Consent Mode v2 is on', 'simple-google-adsense');
        }

        $checks['consent'] = array(
            'status' => '' !== $warning || (!$plugins && !empty($s['consent_mode'])) ? 'warning' : 'ok',
            'label' => __('Consent (EEA/UK)', 'simple-google-adsense'),
            'message' => ucfirst(implode(', ', $parts)) . '. '
                . ('' !== $warning ? $warning : ($tools
                    /* translators: %s: plugin names */
                    ? sprintf(__('Consent tool: %s.', 'simple-google-adsense'), implode(', ', $tools))
                    : __('No consent plugin found - Consent Mode stays "denied" in the EEA unless AdSense\'s own message or another tool updates it.', 'simple-google-adsense'))),
            'action' => Simple_Google_Adsense_Admin::settings_url('privacy'),
            'action_label' => __('Settings', 'simple-google-adsense'),
        );

        return $checks;
    }
}
