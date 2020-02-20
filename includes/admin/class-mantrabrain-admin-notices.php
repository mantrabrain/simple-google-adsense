<?php
defined('ABSPATH') || exit;
if (!class_exists('Mantrabrain_Product_Admin_Notices')) {

    class Mantrabrain_Product_Admin_Notices
    {
        private $notice_transient_id = 'mantrabrain_product_admin_notice';

        private $notice_transient_status_id = 'mantrabrain_product_admin_notice_statuses';

        private $is_production_mode = true;

        public function __construct()
        {
            add_action('admin_init', array($this, 'init'), 10);

            add_action('admin_notices', array($this, 'notice'), 11);

        }

        public function notice()
        {
            $mantrabrain_product_admin_notice = get_transient($this->notice_transient_id);

            $mantrabrain_product_admin_notice = is_array($mantrabrain_product_admin_notice) ? $mantrabrain_product_admin_notice : array();

            $this->check_dismiss($mantrabrain_product_admin_notice);

            $mantrabrain_product_admin_notice_status = get_transient($this->notice_transient_status_id);

            $mantrabrain_product_admin_notice_status = is_array($mantrabrain_product_admin_notice_status) ? $mantrabrain_product_admin_notice_status : array();

            foreach ($mantrabrain_product_admin_notice as $notice) {

                $id = isset($notice['id']) ? $notice['id'] : '';

                if (!isset($mantrabrain_product_admin_notice_status[$id])) {

                    $this->show_notice($notice, $id);

                } else if (isset($mantrabrain_product_admin_notice_status[$id]) && 'disable' !== $mantrabrain_product_admin_notice_status[$id]) {

                    $this->show_notice($notice, $id);
                }


            }

        }

        public function show_notice($notice, $id = '')
        {
            if ($id == '') {
                return;
            }
            if ($notice['content']) {


                $encoded_id = base64_encode($id);

                $nonce = wp_create_nonce('Mantrabrain_Product_Admin_Notices');

                global $wp;

                $dismiss_url = add_query_arg(
                    array(
                        'action' => 'mb_product_notice_dismiss',
                        '_nonce' => $nonce,
                        'id' => $encoded_id
                    )
                    , $wp->request);


                echo '<div class="notice" style="position:relative;border-color: #4285f4;border-left-width: 5px;">';
                echo wp_kses($notice['content'], array(
                    'p' => array(
                        'style' => array()
                    ),
                    'a' => array(
                        'href' => array(),
                        'target' => array(),
                        'style' => array(),
                        'title' => array(),
                    ),
                    'img' => array(
                        'src' => array(),
                        'title' => array(),
                        'style' => array()
                    ),
                    'strong' => array(
                        'title' => array(),
                        'style' => array()
                    ),
                ));
                echo '<a style="text-decoration: none;" href="' . esc_url($dismiss_url) . '" type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></a>';
                echo '</div>';
            }

        }

        public function init()
        {

            $mantrabrain_product_admin_notice = get_transient($this->notice_transient_id);

            $expire_time = $this->is_production_mode ? DAY_IN_SECONDS : 8;

            if (is_null($mantrabrain_product_admin_notice) || '' == $mantrabrain_product_admin_notice) {

                $mantrabrain_product_admin_notice = $this->get_content();

                //DAY_IN_SECONDS
                set_transient($this->notice_transient_id, $mantrabrain_product_admin_notice, $expire_time);
            }


        }

        public function check_dismiss($mantrabrain_product_admin_notice)
        {
            $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

            $nonce = isset($_GET['_nonce']) ? sanitize_text_field($_GET['_nonce']) : '';

            $id = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '';

            if ('' !== $id && '' !== $nonce && '' !== $action && $action == 'mb_product_notice_dismiss') {

                $nonce_action = 'Mantrabrain_Product_Admin_Notices';

                $verify = wp_verify_nonce($nonce, $nonce_action);
                if ($verify) {

                    $valid_id = base64_decode($id);


                    $this->close_notice($mantrabrain_product_admin_notice, $valid_id);
                }

            }
        }

        public function close_notice($mantrabrain_product_admin_notice, $closed_notice_id = '')
        {

            $valid_notice_status = array();

            $mantrabrain_product_admin_notice_status = get_transient($this->notice_transient_status_id);

            $mantrabrain_product_admin_notice_status = is_array($mantrabrain_product_admin_notice_status) ? $mantrabrain_product_admin_notice_status : array();

            foreach ($mantrabrain_product_admin_notice as $notice) {

                $notice_id = isset($notice['id']) ? $notice['id'] : '';

                if ($notice_id == $closed_notice_id) {

                    $valid_notice_status[$notice_id] = 'disable';
                } else {

                    $valid_notice_status[$notice_id] = isset($mantrabrain_product_admin_notice_status[$notice_id]) ? $mantrabrain_product_admin_notice_status[$notice_id] : 'enable';
                }

            }

            set_transient($this->notice_transient_status_id, $valid_notice_status);

        }

        public function get_content()
        {
            $file_path = 'https://wpyatri.com/public/notices.json';
            $content = $this->file_get_contents($file_path);

            $content_array = array();

            try {
                $content_array = json_decode($content, true);

            } catch (Exception $e) {

            }
            return $content_array;
        }

        function file_get_contents($file)
        {

            $response_data = file_get_contents($file);

            if (empty($response_data) || !$response_data) {

                $response = wp_remote_get($file);

                $response_data = wp_remote_retrieve_body($response);
            }
            return $response_data;
        }
    }

    new Mantrabrain_Product_Admin_Notices();
}

