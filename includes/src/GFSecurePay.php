<?php
/**
 * SecurePay for Gravityforms.
 *
 * @author  SecurePay Sdn Bhd
 * @license GPL-2.0+
 *
 * @see    https://securepay.my
 */
\defined('ABSPATH') || exit;

class GFSecurePay extends GFPaymentAddOn
{
    protected $_version = SECUREPAYGFM_VERSION;
    protected $_min_gravityforms_version = '1.9.3';
    protected $_slug = SECUREPAYGFM_SLUG;
    protected $_full_path = SECUREPAYGFM_FILE;
    protected $_url = 'https://www.securepay.my';
    protected $_title = 'SecurePay for GravityForms';
    protected $_short_title = 'SecurePay';
    protected $_supports_callbacks = true;
    protected $_capabilities = ['gravityforms_securepay', 'gravityforms_securepay_uninstall'];
    protected $_capabilities_settings_page = 'gravityforms_securepay';
    protected $_capabilities_form_settings = 'gravityforms_securepay';
    protected $_capabilities_uninstall = 'gravityforms_securepay_uninstall';
    protected $_enable_rg_autoupgrade = false;
    private static $_instance = null;

    private function spnote($id, $msg)
    {
        GFFormsModel::add_note($id, 0, 'SecurePay', $msg);
    }

    private function response_status($response_params)
    {
        if ((isset($response_params['payment_status']) && 'true' === $response_params['payment_status']) || (isset($response_params['fpx_status']) && 'true' === $response_params['fpx_status'])) {
            return true;
        }

        return false;
    }

    private function is_response_callback($response_params)
    {
        if (isset($response_params['fpx_status'])) {
            return true;
        }

        return false;
    }

    private function sanitize_response()
    {
        $params = [
             'amount',
             'bank',
             'buyer_email',
             'buyer_name',
             'buyer_phone',
             'checksum',
             'client_ip',
             'created_at',
             'created_at_unixtime',
             'currency',
             'exchange_number',
             'fpx_status',
             'fpx_status_message',
             'fpx_transaction_id',
             'fpx_transaction_time',
             'id',
             'interface_name',
             'interface_uid',
             'merchant_reference_number',
             'name',
             'order_number',
             'payment_id',
             'payment_method',
             'payment_status',
             'receipt_url',
             'retry_url',
             'source',
             'status_url',
             'transaction_amount',
             'transaction_amount_received',
             'uid',
             'entry_id',
             'optional',
         ];

        $response_params = [];
        if (isset($_POST)) {
            foreach ($params as $k) {
                if (isset($_POST[$k])) {
                    $response_params[$k] = sanitize_text_field($_POST[$k]);
                }
            }
        }

        if (isset($_GET)) {
            foreach ($params as $k) {
                if (isset($_GET[$k])) {
                    $response_params[$k] = sanitize_text_field($_GET[$k]);
                }
            }
        }

        return $response_params;
    }

    private function redirect_spcallback($is_cancel = false)
    {
        $ru = $is_cancel ? rgget('sprec') : rgget('spref');
        $url = !empty($ru) ? $ru : get_home_url();

        if (!preg_match('@^(https?|//):@', $url)) {
            $url = base64_decode($url);
        }

        if (!preg_match('@^(https?|//):@', $url)) {
            $url = site_url($url);
        }

        if (false !== strpos($url, 'spref')) {
            $url = str_replace('spref', '_spref', $url);
        }

        if (false !== strpos($url, 'sprec')) {
            $url = str_replace('sprec', '_sprec', $url);
        }

        if (!headers_sent()) {
            wp_redirect($url);
            exit;
        }

        $html = "<script>window.location.replace('".$url."');</script>";
        $html .= '<noscript><meta http-equiv="refresh" content="1; url='.$url.'">Redirecting..</noscript>';

        echo wp_kses(
            $html,
            [
                'script' => [],
                'noscript' => [],
                'meta' => [
                    'http-equiv' => [],
                    'content' => [],
                ],
            ]
        );
        exit;
    }

    private function get_bank_list($force = false, $is_sandbox = false)
    {
        $bank_list = $force ? false : get_transient(SECUREPAYGFM_SLUG.'_gffm_gw_banklist');
        $endpoint_pub = $is_sandbox ? SECUREPAYGFM_ENDPOINT_PUBLIC_SANDBOX : SECUREPAYGFM_ENDPOINT_PUBLIC_LIVE;

        if (empty($bank_list)) {
            $remote = wp_remote_get(
                $endpoint_pub.'/banks/b2c?status',
                [
                    'timeout' => 10,
                    'user-agent' => SECUREPAYGFM_SLUG.'/'.SECUREPAYGFM_VERSION,
                    'headers' => [
                        'Accept' => 'application/json',
                        'Referer' => home_url(),
                    ],
                ]
            );

            if (!is_wp_error($remote) && isset($remote['response']['code']) && 200 === $remote['response']['code'] && !empty($remote['body'])) {
                $data = json_decode($remote['body'], true);
                if (!empty($data) && \is_array($data) && !empty($data['fpx_bankList'])) {
                    $list = $data['fpx_bankList'];
                    foreach ($list as $arr) {
                        $status = 1;
                        if (empty($arr['status_format2']) || 'offline' === $arr['status_format1']) {
                            $status = 0;
                        }

                        $bank_list[$arr['code']] = [
                            'name' => $arr['name'],
                            'status' => $status,
                        ];
                    }

                    if (!empty($bank_list) && \is_array($bank_list)) {
                        set_transient(SECUREPAYGFM_SLUG.'_gffm_gw_banklist', $bank_list, 300);
                    }
                }
            }
        }

        return !empty($bank_list) && \is_array($bank_list) ? $bank_list : false;
    }

    private function gefeedmeta($form)
    {
        $feed = GFAPI::get_feeds(null, $form['id'], SECUREPAYGFM_SLUG, true);
        $feed_meta = [];
        if (!empty($feed) && \is_array($feed)) {
            $feed_meta = $feed[0]['meta'];
        }

        return $feed_meta;
    }

    private function is_bank_list($form, &$bank_list = '', &$feed_meta = '')
    {
        $feed_meta = $this->gefeedmeta($form);
        $is_sandbox = false;
        if (!empty($feed_meta['sandbox_mode']) && 1 === (int) $feed_meta['sandbox_mode']) {
            $is_sandbox = true;
        }
        if (!empty($feed_meta['test_mode']) && 1 === (int) $feed_meta['test_mode']) {
            $is_sandbox = true;
        }
        if (!empty($feed_meta['bank_list']) && 1 === (int) $feed_meta['bank_list']) {
            $bank_list = $this->get_bank_list(false, $is_sandbox);

            return !empty($bank_list) && \is_array($bank_list) ? true : false;
        }

        $bank_list = '';

        return false;
    }

    public static function get_instance()
    {
        if (null == self::$_instance) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    public function get_path()
    {
        return SECUREPAYGFM_HOOK;
    }

    public function init_frontend()
    {
        parent::init_frontend();

        add_filter('gform_disable_post_creation', [$this, 'frontend_disable_post_creation'], 10, 3);
        add_filter('gform_disable_notification', [$this, 'frontend_disable_notification'], 10, 4);
        add_filter('gform_submit_button', [$this, 'frontend_submit_button'], 10, 2);

        add_action('wp_enqueue_scripts', [$this, 'securepay_scripts']);

        add_filter(
            'gform_form_args',
            function ($args) {
                $args['ajax'] = false;

                return $args;
            },
            \PHP_INT_MAX
        );
    }

    public function frontend_disable_post_creation($is_disabled, $form, $entry)
    {
        $feed = $this->get_payment_feed($entry);
        $submission_data = $this->get_submission_data($feed, $form, $entry);

        if (!$feed || empty($submission_data['payment_amount'])) {
            return $is_disabled;
        }

        return !rgempty('delayPost', $feed['meta']);
    }

    public function frontend_disable_notification($is_disabled, $notification, $form, $entry)
    {
        if ('form_submission' != rgar($notification, 'event')) {
            return $is_disabled;
        }

        $feed = $this->get_payment_feed($entry);
        $submission_data = $this->get_submission_data($feed, $form, $entry);

        if (!$feed || empty($submission_data['payment_amount'])) {
            return $is_disabled;
        }

        $selected_notifications = \is_array(rgar($feed['meta'], 'selectedNotifications')) ? rgar($feed['meta'], 'selectedNotifications') : [];

        return isset($feed['meta']['delayNotification']) && \in_array($notification['id'], $selected_notifications) ? true : $is_disabled;
    }

    public function frontend_submit_button($button, $form)
    {
        $html = '';
        if ($this->is_bank_list($form, $bank_list, $feed_meta)) {
            $image = false;
            if (!empty($feed_meta['fpxbank_logo']) && 1 === (int) $feed_meta['fpxbank_logo']) {
                $image = SECUREPAYGFM_URL.'includes/admin/securepay-bank-alt.png';
            }

            $bank_id = !empty($_POST['buyer_bank_code']) ? sanitize_text_field($_POST['buyer_bank_code']) : false;
            $title = !empty($feed_meta['payment_header']) ? $feed_meta['payment_header'] : esc_html__('Pay with SecurePay', 'securepaygfm');
            $html = '<div class="gform_body spgfmbody">';
            $html .= '<label class="gfield_label" for="buyer_bank_code">'.$title.'</label>';

            if (!empty($image)) {
                $html .= '<img src="'.$image.'" class="spgfmlogo">';
            }

            $html .= '<select name="buyer_bank_code" id="buyer_bank_code">';
            $html .= "<option value=''>Please Select Bank</option>;";
            foreach ($bank_list as $id => $arr) {
                $name = $arr['name'];
                $status = $arr['status'];

                $disabled = empty($status) ? ' disabled' : '';
                $offline = empty($status) ? ' (Offline)' : '';
                $selected = $id === $bank_id ? ' selected' : '';
                $html .= '<option value="'.$id.'"'.$selected.$disabled.'>'.$name.$offline.'</option>';
            }
            $html .= '</select>';

            $html .= '</div>';

            $html .= wp_get_inline_script_tag('if ( "function" === typeof(securepaybankgfm) ) {securepaybankgfm();}', ['id' => SECUREPAYGFM_SLUG.'-bankselect']);
        }

        return $html.$button;
    }

    public function securepay_scripts()
    {
        if (!is_admin()) {
            $version = SECUREPAYGFM_VERSION.'x'.(\defined('WP_DEBUG') && WP_DEBUG ? time() : date('Ymdh'));

            $slug = SECUREPAYGFM_SLUG;
            $url = SECUREPAYGFM_URL;
            $selectid = 'securepayselect2';
            $selectdeps = [];
            if (wp_script_is('select2', 'enqueued')) {
                $selectdeps = ['jquery', 'select2'];
            } elseif (wp_script_is('selectWoo', 'enqueued')) {
                $selectdeps = ['jquery', 'selectWoo'];
            } elseif (wp_script_is($selectid, 'enqueued')) {
                $selectdeps = ['jquery', $selectid];
            }

            if (empty($selectdeps)) {
                wp_enqueue_style($selectid, $url.'includes/admin/min/select2.min.css', null, $version);
                wp_enqueue_script($selectid, $url.'includes/admin/min/select2.min.js', ['jquery'], $version);
                $selectdeps = ['jquery', $selectid];
            }

            wp_enqueue_script($slug, $url.'includes/admin/securepaygfm.js', $selectdeps, $version);

            // remove jquery
            unset($selectdeps[0]);

            wp_enqueue_style($selectid.'-helper', $url.'includes/admin/securepaygfm.css', $selectdeps, $version);
            wp_add_inline_script($slug, 'function securepaybankgfm() { if ( "function" === typeof(securepaygfm_bank_select) ) { securepaygfm_bank_select(jQuery, "'.$url.'includes/admin/bnk/", '.time().', "'.$version.'"); }}');
        }
    }

    public function init_admin()
    {
        parent::init_admin();

        add_action('gform_payment_status', [$this, 'admin_edit_payment_status'], 3, 3);
        add_action('gform_payment_date', [$this, 'admin_edit_payment_date'], 3, 3);
        add_action('gform_payment_transaction_id', [$this, 'admin_edit_payment_transaction_id'], 3, 3);
        add_action('gform_payment_amount', [$this, 'admin_edit_payment_amount'], 3, 3);
        add_action('gform_after_update_entry', [$this, 'admin_update_payment'], 4, 2);
    }

    public function admin_edit_payment_status($payment_status, $form, $entry)
    {
        if ($this->payment_details_editing_disabled($entry)) {
            return $payment_status;
        }

        $payment_string = gform_tooltip('securepay_edit_payment_status', '', true);
        $payment_string .= '<select id="payment_status" name="payment_status">';
        $payment_string .= '<option value="'.$payment_status.'" selected>'.$payment_status.'</option>';
        $payment_string .= '<option value="Paid">Paid</option>';
        $payment_string .= '</select>';

        return $payment_string;
    }

    public function admin_edit_payment_date($payment_date, $form, $entry)
    {
        if ($this->payment_details_editing_disabled($entry)) {
            return $payment_date;
        }

        $payment_date = $entry['payment_date'];
        if (empty($payment_date)) {
            $payment_date = get_the_date('y-m-d H:i:s');
        }

        $input = '<input type="text" id="payment_date" name="payment_date" value="'.$payment_date.'">';

        return $input;
    }

    public function admin_edit_payment_transaction_id($transaction_id, $form, $entry)
    {
        if ($this->payment_details_editing_disabled($entry)) {
            return $transaction_id;
        }

        $input = '<input type="text" id="securepay_transaction_id" name="securepay_transaction_id" value="'.$transaction_id.'">';

        return $input;
    }

    public function admin_edit_payment_amount($payment_amount, $form, $entry)
    {
        if ($this->payment_details_editing_disabled($entry)) {
            return $payment_amount;
        }

        if (empty($payment_amount)) {
            $payment_amount = GFCommon::get_order_total($form, $entry);
        }

        $input = '<input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="'.$payment_amount.'">';

        return $input;
    }

    public function admin_update_payment($form, $entry_id)
    {
        check_admin_referer('gforms_save_entry', 'gforms_save_entry');

        $entry = GFFormsModel::get_lead($entry_id);
        if ($this->payment_details_editing_disabled($entry, 'update')) {
            return;
        }

        $payment_status = rgpost('payment_status');
        if (empty($payment_status)) {
            $payment_status = $entry['payment_status'];
        }

        $payment_amount = GFCommon::to_number(rgpost('payment_amount'));
        $payment_transaction = rgpost('securepay_transaction_id');
        $payment_date = rgpost('payment_date');

        $status_unchanged = $entry['payment_status'] == $payment_status;
        $amount_unchanged = $entry['payment_amount'] == $payment_amount;
        $id_unchanged = $entry['transaction_id'] == $payment_transaction;
        $date_unchanged = $entry['payment_date'] == $payment_date;

        if ($status_unchanged && $amount_unchanged && $id_unchanged && $date_unchanged) {
            return;
        }

        if (empty($payment_date)) {
            $payment_date = get_the_date('y-m-d H:i:s');
        } else {
            $payment_date = date('Y-m-d H:i:s', strtotime($payment_date));
        }

        global $current_user;
        $user_id = 0;
        $user_name = 'SecurePay';
        if ($current_user && $user_data = get_userdata($current_user->ID)) {
            $user_id = $current_user->ID;
            $user_name = $user_data->display_name;
        }

        $entry['payment_status'] = $payment_status;
        $entry['payment_amount'] = $payment_amount;
        $entry['payment_date'] = $payment_date;
        $entry['transaction_id'] = $payment_transaction;

        if (('Paid' === $payment_status || 'Approved' === $payment_status) && !$entry['is_fulfilled']) {
            $action['id'] = $payment_transaction;
            $action['type'] = 'complete_payment';
            $action['transaction_id'] = $payment_transaction;
            $action['amount'] = $payment_amount;
            $action['entry_id'] = $entry['id'];

            $this->complete_payment($entry, $action);
            $this->fulfill_order($entry, $payment_transaction, $payment_amount);
        }

        GFAPI::update_entry($entry);

        // translators: %1$s = payment status
        // translators: %2$s = payment amount
        // translators: %3$s = currency
        // translators: %4$s = payment date
        $note = sprintf(esc_html__("Payment information was manually updated.\nStatus: %1$s\nAmount: %2$s\nTransaction ID: %3$s\nDate: %4$s", 'securepaygfm'), $entry['payment_status'], GFCommon::to_money($entry['payment_amount'], $entry['currency']), $payment_transaction, $entry['payment_date']);

        GFFormsModel::add_note($entry['id'], $user_id, $user_name, $note);
    }

    public function get_payment_field($feed)
    {
        return rgars($feed, 'meta/paymentAmount', 'form_total');
    }

    public function feed_settings_fields()
    {
        $default_settings = parent::feed_settings_fields();

        $fields = [
            [
                'name' => 'test_mode',
                'label' => esc_html__('Test Mode', 'securepaygfm'),
                'type' => 'checkbox',
                'required' => false,
                'choices' => [
                    [
                        'label' => esc_html__('Enable Test mode', 'securepaygfm'),
                        'name' => 'test_mode',
                    ],
                ],
                'tooltip' => '<h6>'.esc_html__('Test Mode', 'securepaygfm').'</h6>'.esc_html__('Enable this option to test without credentials.', 'securepaygfm'),
            ],
            [
                'name' => 'live_token',
                'label' => esc_html__('Live Token', 'securepaygfm'),
                'type' => 'text',
                'class' => 'medium',
                'required' => true,
                'tooltip' => '<h6>'.esc_html__('Your SecurePay Live Token', 'securepaygfm').'</h6>'.esc_html__('Enter the SecurePay Live Token.', 'securepaygfm'),
            ],
            [
                'name' => 'live_checksum',
                'label' => esc_html__('Live Checksum Token', 'securepaygfm'),
                'type' => 'text',
                'class' => 'medium',
                'required' => true,
                'tooltip' => '<h6>'.esc_html__('Your SecurePay Live Checksum Token', 'securepaygfm').'</h6>'.esc_html__('Enter the SecurePay Live Checksum Token.', 'securepaygfm'),
            ],
            [
                'name' => 'live_uid',
                'label' => esc_html__('Live UID', 'securepaygfm'),
                'type' => 'text',
                'class' => 'medium',
                'required' => true,
                'tooltip' => '<h6>'.esc_html__('Your SecurePay Live UID', 'securepaygfm').'</h6>'.esc_html__('Enter the SecurePay Live UID.', 'securepaygfm'),
            ],
            [
                'name' => 'live_partner_uid',
                'label' => esc_html__('Live Partner UID', 'securepaygfm'),
                'type' => 'text',
                'class' => 'medium',
                'required' => false,
                'tooltip' => '<h6>'.esc_html__('Your SecurePay Live Partner UID', 'securepaygfm').'</h6>'.esc_html__('Enter the SecurePay Live Partner UID.', 'securepaygfm'),
            ],
            [
                'name' => 'sandbox_mode',
                'label' => esc_html__('Sandbox Mode', 'securepaygfm'),
                'type' => 'checkbox',
                'required' => false,
                'choices' => [
                    [
                        'label' => esc_html__('Enable Sandbox mode', 'securepaygfm'),
                        'name' => 'sandbox_mode',
                    ],
                ],
                'tooltip' => '<h6>'.esc_html__('Sandbox Mode', 'securepaygfm').'</h6>'.esc_html__('Enable Sandbox for testing purposes.', 'securepaygfm'),
            ],
            [
                'name' => 'sandbox_token',
                'label' => esc_html__('Sandbox Token', 'securepaygfm'),
                'type' => 'text',
                'class' => 'medium',
                'required' => false,
                'tooltip' => '<h6>'.esc_html__('Your SecurePay Sandbox Token', 'securepaygfm').'</h6>'.esc_html__('Enter the SecurePay Sandbox Token.', 'securepaygfm'),
            ],
            [
                'name' => 'sandbox_checksum',
                'label' => esc_html__('Sandbox Checksum Token', 'securepaygfm'),
                'type' => 'text',
                'class' => 'medium',
                'required' => false,
                'tooltip' => '<h6>'.esc_html__('Your SecurePay Sandbox Checksum Token', 'securepaygfm').'</h6>'.esc_html__('Enter the SecurePay Sandbox Checksum Token.', 'securepaygfm'),
            ],
            [
                'name' => 'sandbox_uid',
                'label' => esc_html__('Sandbox UID', 'securepaygfm'),
                'type' => 'text',
                'class' => 'medium',
                'required' => false,
                'tooltip' => '<h6>'.esc_html__('Your SecurePay Sandbox UID', 'securepaygfm').'</h6>'.esc_html__('Enter the SecurePay Sandbox UID.', 'securepaygfm'),
            ],
            [
                'name' => 'sandbox_partner_uid',
                'label' => esc_html__('Sandbox Partner UID', 'securepaygfm'),
                'type' => 'text',
                'class' => 'medium',
                'required' => false,
                'tooltip' => '<h6>'.esc_html__('Your SecurePay Sandbox Partner UID', 'securepaygfm').'</h6>'.esc_html__('Enter the SecurePay Sandbox Partner UID.', 'securepaygfm'),
            ],
            [
                'name' => 'bank_list',
                'label' => esc_html__('Show Bank List', 'securepaygfm'),
                'type' => 'checkbox',
                'required' => false,
                'choices' => [
                    [
                        'label' => esc_html__('SecurePay Supported Banks', 'securepaygfm'),
                        'name' => 'bank_list',
                    ],
                ],
                'tooltip' => '<h6>'.esc_html__('Show Bank List', 'securepaygfm').'</h6>'.esc_html__('SecurePay Supported Banks.', 'securepaygfm'),
            ],
            [
                'name' => 'fpxbank_logo',
                'label' => esc_html__('Show Bank Logo', 'securepaygfm'),
                'type' => 'checkbox',
                'required' => false,
                'choices' => [
                    [
                        'label' => esc_html__('Use supported banks logo', 'securepaygfm'),
                        'name' => 'fpxbank_logo',
                    ],
                ],
                'tooltip' => '<h6>'.esc_html__('Show Bank Logo', 'securepaygfm').'</h6>'.esc_html__('SecurePay Supported Banks Logo.', 'securepaygfm'),
            ],
            [
                'name' => 'payment_header',
                'label' => esc_html__('Bank Selection Header', 'securepaygfm'),
                'type' => 'text',
                'class' => 'medium',
                'required' => false,
                'tooltip' => '<h6>'.esc_html__('Bank Selection Header', 'securepaygfm').'</h6>'.esc_html__('This is the header for bank selection.', 'securepaygfm'),
                'default_value' => esc_html__('Pay with SecurePay', 'securepaygfm'),
            ],
            [
                'label' => esc_html__('Bill Description', 'securepaygfm'),
                'type' => 'textarea',
                'name' => 'bill_description',
                'tooltip' => '<h6>'.esc_html__('SecurePay Bills Description', 'securepaygfm').'</h6>'.esc_html__('Enter your description here. It will displayed on Bill page.', 'securepaygfm'),
                'class' => 'medium merge-tag-support mt-position-right',
                'required' => false,
            ],
        ];

        $default_settings = parent::add_field_after('feedName', $fields, $default_settings);

        $transaction_type = parent::get_field('transactionType', $default_settings);
        unset($transaction_type['choices'][2]);
        $default_settings = $this->replace_field('transactionType', $transaction_type, $default_settings);

        $fields = [
            [
                'name' => 'return_url',
                'label' => esc_html__('Return URL', 'securepaygfm'),
                'type' => 'text',
                'class' => 'medium',
                'required' => false,
                'tooltip' => '<h6>'.esc_html__('Return URL', 'securepaygfm').'</h6>'.esc_html__('Return to this URL after payment complete. Leave blank for default.', 'securepaygfm'),
            ],
            [
                'name' => 'cancel_url',
                'label' => esc_html__('Cancel URL', 'securepaygfm'),
                'type' => 'text',
                'class' => 'medium',
                'required' => false,
                'tooltip' => '<h6>'.esc_html__('Cancel URL', 'securepaygfm').'</h6>'.esc_html__('Return to this URL if payment failed. Leave blank for default.', 'securepaygfm'),
            ],
        ];

        if ($this->get_setting('delayNotification') || !$this->is_gravityforms_supported('1.9.12')) {
            $fields[] = [
                'name' => 'notifications',
                'label' => esc_html__('Notifications', 'securepaygfm'),
                'type' => 'notifications',
                'tooltip' => '<h6>'.esc_html__('Notifications', 'securepaygfm').'</h6>'.esc_html__("Enable this option if you would like to only send out this form's notifications for the 'Form is submitted' event after payment has been received. Leaving this option disabled will send these notifications immediately after the form is submitted. Notifications which are configured for other events will not be affected by this option.", 'securepaygfm'),
            ];
        }

        $form = $this->get_current_form();
        if (GFCommon::has_post_field($form['fields'])) {
            $post_settings = [
                'name' => 'post_checkboxes',
                'label' => esc_html__('Posts', 'securepaygfm'),
                'type' => 'checkbox',
                'tooltip' => '<h6>'.esc_html__('Posts', 'securepaygfm').'</h6>'.esc_html__('Enable this option if you would like to only create the post after payment has been received.', 'securepaygfm'),
                'choices' => [
                    [
                        'label' => esc_html__('Create post only when payment is received.', 'securepaygfm'),
                        'name' => 'delayPost',
                    ],
                ],
            ];

            $fields[] = $post_settings;
        }

        // gform_securepay_add_option_group
        $fields[] = [
            'name' => 'custom_options',
            'label' => '',
            'type' => 'custom',
        ];

        $default_settings = $this->add_field_after('billingInformation', $fields, $default_settings);
        $billing_info = parent::get_field('billingInformation', $default_settings);
        $dt = $billing_info['field_map'];

        foreach ($dt as $n => $k) {
            switch ($k['name']) {
                case 'name':
                case 'mobile':
                case 'address':
                case 'address2':
                case 'city':
                case 'state':
                case 'zip':
                case 'country':
                case 'email':
                    unset($billing_info['field_map'][$n]);
                    break;
            }
        }
        unset($dt);

        array_unshift(
            $billing_info['field_map'],
            [
                'name' => 'name',
                'label' => esc_html__('Name', 'securepaygfm'),
                'required' => false,
            ],
            [
                'name' => 'email',
                'label' => esc_html__('Email', 'securepaygfm'),
                'required' => false,
            ],
            [
                'name' => 'mobile',
                'label' => esc_html__('Mobile Phone Number', 'securepaygfm'),
                'required' => false,
            ],
            [
                'name' => 'reference1_label',
                'label' => esc_html__('Reference #1 Label', 'securepaygfm'),
                'required' => false,
            ],
            [
                'name' => 'reference1',
                'label' => esc_html__('Reference #1', 'securepaygfm'),
                'required' => false,
            ],
            [
                'name' => 'reference2_label',
                'label' => esc_html__('Reference #2 Label', 'securepaygfm'),
                'required' => false,
            ],
            [
                'name' => 'reference2',
                'label' => esc_html__('Reference #2', 'securepaygfm'),
                'required' => false,
            ],
            [
                'name' => 'reference3_label',
                'label' => esc_html__('Reference #3 Label', 'securepaygfm'),
                'required' => false,
            ],
            [
                'name' => 'reference3',
                'label' => esc_html__('Reference #3', 'securepaygfm'),
                'required' => false,
            ],
            [
                'name' => 'reference4_label',
                'label' => esc_html__('Reference #4 Label', 'securepaygfm'),
                'required' => false,
            ],
            [
                'name' => 'reference4',
                'label' => esc_html__('Reference #4', 'securepaygfm'),
                'required' => false,
            ],
            [
                'name' => 'reference5_label',
                'label' => esc_html__('Reference #5 Label', 'securepaygfm'),
                'required' => false,
            ],
            [
                'name' => 'reference5',
                'label' => esc_html__('Reference #5', 'securepaygfm'),
                'required' => false,
            ],
            [
                'name' => 'reference6_label',
                'label' => esc_html__('Reference #6 Label', 'securepaygfm'),
                'required' => false,
            ],
            [
                'name' => 'reference6',
                'label' => esc_html__('Reference #6', 'securepaygfm'),
                'required' => false,
            ],
        );

        $default_settings = parent::replace_field('billingInformation', $billing_info, $default_settings);
        $default_settings = parent::remove_field('setupFee', $default_settings);

        $dt = $default_settings[3]['fields'];
        foreach ($dt as $n => $arr) {
            if ($n > 0) {
                if (!\in_array($default_settings[3]['fields'][$n]['name'], ['return_url', 'cancel_url', 'conditionalLogic'])) {
                    unset($default_settings[3]['fields'][$n]);
                }
            }
        }

        return apply_filters('gform_securepay_feed_settings_fields', $default_settings, $form);
    }

    public function field_map_title()
    {
        return esc_html__('SecurePay Field', 'securepaygfm');
    }

    public function settings_options($field, $echo = true)
    {
        $html = $this->settings_checkbox($field, false);

        if ($echo) {
            echo $html;
        }

        return $html;
    }

    public function settings_custom($field, $echo = true)
    {
        ob_start(); ?>
        <div id='gf_securepay_custom_settings'>
            <?php
            do_action('gform_securepay_add_option_group', $this->get_current_feed(), $this->get_current_form()); ?>
        </div>

        <script type='text/javascript'>
            jQuery(document).ready(function () {
                jQuery('#gf_securepay_custom_settings label.left_header').css('margin-left', '-200px');
            });
        </script>

        <?php

        $html = ob_get_clean();

        if ($echo) {
            echo $html;
        }

        return $html;
    }

    public function settings_notifications($field, $echo = true)
    {
        $checkboxes = [
            'name' => 'delay_notification',
            'type' => 'checkboxes',
            'onclick' => 'ToggleNotifications();',
            'choices' => [
                [
                    'label' => esc_html__("Send notifications for the 'Form is submitted' event only when payment is received.", 'securepaygfm'),
                    'name' => 'delayNotification',
                ],
            ],
        ];

        $html = $this->settings_checkbox($checkboxes, false);

        $html .= $this->settings_hidden(
            [
                'name' => 'selectedNotifications',
                'id' => 'selectedNotifications',
            ],
            false
        );

        $form = $this->get_current_form();
        $has_delayed_notifications = $this->get_setting('delayNotification');
        ob_start(); ?>
        <ul id="gf_securepay_notification_container" style="padding-left:20px; margin-top:10px; <?php echo $has_delayed_notifications ? '' : 'display:none;'; ?>">
            <?php
            if (!empty($form) && \is_array($form['notifications'])) {
                $selected_notifications = $this->get_setting('selectedNotifications');
                if (!\is_array($selected_notifications)) {
                    $selected_notifications = [];
                }

                $notifications = GFCommon::get_notifications('form_submission', $form);

                foreach ($notifications as $notification) {
                    ?>
                    <li class="gf_securepay_notification">
                        <input type="checkbox" class="notification_checkbox" value="<?php echo $notification['id']; ?>" onclick="SaveNotifications();" <?php checked(true, \in_array($notification['id'], $selected_notifications)); ?> />
                        <label class="inline" for="gf_securepay_selected_notifications"><?php echo $notification['name']; ?></label>
                    </li>
                    <?php
                }
            } ?>
        </ul>
        <script type='text/javascript'>
            function SaveNotifications() {
                var notifications = [];
                jQuery('.notification_checkbox').each(function () {
                    if (jQuery(this).is(':checked')) {
                        notifications.push(jQuery(this).val());
                    }
                });
                jQuery('#selectedNotifications').val(jQuery.toJSON(notifications));
            }

            function ToggleNotifications() {

                var container = jQuery('#gf_securepay_notification_container');
                var isChecked = jQuery('#delaynotification').is(':checked');

                if (isChecked) {
                    container.slideDown();
                    jQuery('.gf_securepay_notification input').prop('checked', true);
                }
                else {
                    container.slideUp();
                    jQuery('.gf_securepay_notification input').prop('checked', false);
                }

                SaveNotifications();
            }
        </script>
        <?php

        $html .= ob_get_clean();

        if ($echo) {
            echo $html;
        }

        return $html;
    }

    public function checkbox_input_change_post_status($choice, $attributes, $value, $tooltip)
    {
        $markup = $this->checkbox_input($choice, $attributes, $value, $tooltip);

        $dropdown_field = [
            'name' => 'update_post_action',
            'choices' => [
                ['label' => ''],
                [
                    'label' => esc_html__('Mark Post as Draft', 'securepaygfm'),
                    'value' => 'draft',
                ],
                [
                    'label' => esc_html__('Delete Post', 'securepaygfm'),
                    'value' => 'delete',
                ],
            ],
            'onChange' => "var checked = jQuery(this).val() ? 'checked' : false; jQuery('#change_post_status').attr('checked', checked);",
        ];
        $markup .= '&nbsp;&nbsp;'.$this->settings_select($dropdown_field, false);

        return $markup;
    }

    public function option_choices()
    {
        return false;
    }

    public function save_feed_settings($feed_id, $form_id, $settings)
    {
        $feed = $this->get_feed($feed_id);
        $settings['type'] = $settings['transactionType'];

        $feed['meta'] = $settings;
        $feed = apply_filters('gform_securepay_save_config', $feed);

        delete_transient(SECUREPAYGFM_SLUG.'_gffm_gw_banklist');
        if (!empty($feed['meta']['bank_list']) && 1 === (int) $feed['meta']['bank_list']) {
            $is_sandbox = false;
            if ((!empty($feed['meta']['sandbox_mode']) && 1 === (int) $feed['meta']['sandbox_mode'])
                || (!empty($feed['meta']['test_mode']) && 1 === (int) $feed['meta']['test_mode'])) {
                $is_sandbox = true;
            }

            $this->get_bank_list(true, $is_sandbox);
        }

        if (!empty($feed['meta']['test_mode']) && 1 === (int) $feed['meta']['test_mode']) {
            unset($feed['meta']['sandbox_mode']);
        }

        $is_validation_error = apply_filters('gform_securepay_config_validation', false, $feed);
        if ($is_validation_error) {
            return false;
        }

        $settings = $feed['meta'];

        return parent::save_feed_settings($feed_id, $form_id, $settings);
    }

    public function redirect_url($feed, $submission_data, $form, $entry)
    {
        if (!rgempty('securepay', $_GET) || !rgempty('url', $_POST) || !rgempty('spref', $_GET) || !rgempty('sprec', $_GET)) {
            return false;
        }

        GFAPI::update_entry_property($entry['id'], 'payment_status', 'Processing');

        $feed_meta = $feed['meta'];
        $is_testmode = !empty($feed_meta['test_mode']) && 1 === (int) $feed_meta['test_mode'] ? true : false;
        $is_sandbox = !empty($feed_meta['sandbox_mode']) && 1 === (int) $feed_meta['sandbox_mode'] ? true : false;
        $is_banklist = !empty($feed_meta['bank_list']) && 1 === (int) $feed_meta['bank_list'] ? true : false;

        $return_url = !empty($feed_meta['return_url']) && $feed_meta['return_url'] ? $feed_meta['return_url'] : $_SERVER['REQUEST_URI'];
        $cancel_url = !empty($feed_meta['cancel_url']) && $feed_meta['cancel_url'] ? $feed_meta['cancel_url'] : $_SERVER['REQUEST_URI'];

        $b = 'billingInformation_';

        $int_name = isset($feed_meta[$b.'name']) ? $feed_meta[$b.'name'] : '';
        $int_email = isset($feed_meta[$b.'email']) ? $feed_meta[$b.'email'] : '';
        $int_mobile = isset($feed_meta[$b.'mobile']) ? $feed_meta[$b.'mobile'] : '';
        $int_reference1_label = isset($feed_meta[$b.'reference1_label']) ? $feed_meta[$b.'reference1_label'] : '';
        $int_reference2_label = isset($feed_meta[$b.'reference2_label']) ? $feed_meta[$b.'reference2_label'] : '';
        $int_reference3_label = isset($feed_meta[$b.'reference3_label']) ? $feed_meta[$b.'reference3_label'] : '';
        $int_reference4_label = isset($feed_meta[$b.'reference4_label']) ? $feed_meta[$b.'reference4_label'] : '';
        $int_reference5_label = isset($feed_meta[$b.'reference5_label']) ? $feed_meta[$b.'reference5_label'] : '';
        $int_reference6_label = isset($feed_meta[$b.'reference6_label']) ? $feed_meta[$b.'reference6_label'] : '';

        $int_reference1 = isset($feed_meta[$b.'reference1']) ? $feed_meta[$b.'reference1'] : '';
        $int_reference2 = isset($feed_meta[$b.'reference2']) ? $feed_meta[$b.'reference2'] : '';
        $int_reference3 = isset($feed_meta[$b.'reference3']) ? $feed_meta[$b.'reference3'] : '';
        $int_reference4 = isset($feed_meta[$b.'reference4']) ? $feed_meta[$b.'reference4'] : '';
        $int_reference5 = isset($feed_meta[$b.'reference5']) ? $feed_meta[$b.'reference5'] : '';
        $int_reference6 = isset($feed_meta[$b.'reference6']) ? $feed_meta[$b.'reference6'] : '';

        $buyer_email = isset($entry[$int_email]) ? $entry[$int_email] : '';
        $buyer_phone = isset($entry[$int_mobile]) ? $entry[$int_mobile] : '';
        $buyer_name = isset($entry[$int_name]) ? $entry[$int_name] : '';
        $buyer_bank_code = !empty($_POST['buyer_bank_code']) ? $_POST['buyer_bank_code'] : false;

        if (empty($buyer_phone) && empty($buyer_email)) {
            $buyer_email = 'noreply@securepay.com';
        }

        $total = (string) rgar($submission_data, 'payment_amount');
        $orderid = $entry['id'];

        if ($is_testmode) {
            $checksum = '3faa7b27f17c3fb01d961c08da2b6816b667e568efb827544a52c62916d4771d';
            $token = 'GFVnVXHzGEyfzzPk4kY3';
            $uid = '4a73a364-6548-4e17-9130-c6e9bffa3081';
            $partner_uid = '';
        } else {
            $checksum = $is_sandbox ? $feed_meta['sandbox_checksum'] : $feed_meta['live_checksum'];
            $token = $is_sandbox ? $feed_meta['sandbox_token'] : $feed_meta['live_token'];
            $uid = $is_sandbox ? $feed_meta['sandbox_uid'] : $feed_meta['live_uid'];
            $partner_uid = $is_sandbox ? $feed_meta['sandbox_partner_uid'] : $feed_meta['live_partner_uid'];
        }
        $redirect_url = site_url('/?page=gf_securepay&entry_id='.$entry['id'].'&spref='.base64_encode($return_url).'&sprec='.base64_encode($cancel_url));
        $product_description = mb_substr(GFCommon::replace_variables($feed_meta['bill_description'], $form, $entry), 0, 200);

        if (empty($product_description)) {
            $blog_description = get_bloginfo('description');
            $product_description = !empty($blog_description) ? $blog_description : 'Set your payment description';
        }

        $reference1_label = isset($entry[$int_reference1_label]) ? $entry[$int_reference1_label] : '';
        $reference1 = isset($entry[$int_reference1]) ? $entry[$int_reference1] : '';
        $reference2_label = isset($entry[$int_reference2_label]) ? $entry[$int_reference2_label] : '';
        $reference2 = isset($entry[$int_reference2]) ? $entry[$int_reference2] : '';
        $reference3_label = isset($entry[$int_reference3_label]) ? $entry[$int_reference3_label] : '';
        $reference3 = isset($entry[$int_reference3]) ? $entry[$int_reference3] : '';
        $reference4_label = isset($entry[$int_reference4_label]) ? $entry[$int_reference4_label] : '';
        $reference4 = isset($entry[$int_reference4]) ? $entry[$int_reference4] : '';
        $reference5_label = isset($entry[$int_reference5_label]) ? $entry[$int_reference5_label] : '';
        $reference5 = isset($entry[$int_reference5]) ? $entry[$int_reference5] : '';
        $reference6_label = isset($entry[$int_reference6_label]) ? $entry[$int_reference6_label] : '';
        $reference6 = isset($entry[$int_reference6]) ? $entry[$int_reference6] : '';

        $params = [
            'reference1_label' => mb_substr($reference1_label, 0, 255),
            'reference1' => mb_substr($reference1, 0, 255),
            'reference2_label' => mb_substr($reference2_label, 0, 255),
            'reference2' => mb_substr($reference2, 0, 255),
            'reference3_label' => mb_substr($reference3_label, 0, 255),
            'reference3' => mb_substr($reference3, 0, 255),
            'reference4_label' => mb_substr($reference4_label, 0, 255),
            'reference4' => mb_substr($reference4, 0, 255),
            'reference5_label' => mb_substr($reference5_label, 0, 255),
            'reference5' => mb_substr($reference5, 0, 255),
            'reference6_label' => mb_substr($reference6_label, 0, 255),
            'reference6' => mb_substr($reference6, 0, 255),
        ];

        $calculatesign = "$buyer_email|$buyer_name|$buyer_phone|$redirect_url|$orderid|$product_description|$redirect_url|$total|$uid";
        $sign = hash_hmac('sha256', $calculatesign, $checksum);

        $endpoint = $is_sandbox || $is_testmode ? SECUREPAYGFM_ENDPOINT_SANDBOX : SECUREPAYGFM_ENDPOINT_LIVE;

        $form = '<html><head><title>SecurePay Processing..</title>';
        $form .= '<meta name="robots" content="noindex, nofollow">';
        $form .= '<meta http-equiv="cache-control" content="no-cache, no-store, must-revalidate">';
        $form .= '</head>';
        $form .= '<body onload="document.frm_securepay_payment.submit();">';
        $form .= '<form style="display:none" name="frm_securepay_payment" id="frm_securepay_payment" method="post" action="'.$endpoint.'/payments">';
        $form .= "<input type='hidden' name='order_number' value='".$orderid."'>";
        $form .= "<input type='hidden' name='buyer_name' value='".$buyer_name."'>";
        $form .= "<input type='hidden' name='buyer_email' value='".$buyer_email."'>";
        $form .= "<input type='hidden' name='buyer_phone' value='".$buyer_phone."'>";
        $form .= "<input type='hidden' name='transaction_amount' value='".$total."'>";
        $form .= "<input type='hidden' name='product_description' value='".$product_description."'>";
        $form .= "<input type='hidden' name='callback_url' value='".$redirect_url."'>";
        $form .= "<input type='hidden' name='redirect_url' value='".$redirect_url."'>";
        $form .= "<input type='hidden' name='checksum' value='".$sign."'>";
        $form .= "<input type='hidden' name='token' value='".$token."'>";
        $form .= "<input type='hidden' name='partner_uid' value='".$partner_uid."'>";
        $form .= "<input type='hidden' name='params[reference1_label]' value='".$params['reference1_label']."'>";
        $form .= "<input type='hidden' name='params[reference1]' value='".$params['reference1']."'>";
        $form .= "<input type='hidden' name='params[reference2_label]' value='".$params['reference2_label']."'>";
        $form .= "<input type='hidden' name='params[reference2]' value='".$params['reference2']."'>";
        $form .= "<input type='hidden' name='params[reference3_label]' value='".$params['reference3_label']."'>";
        $form .= "<input type='hidden' name='params[reference3]' value='".$params['reference3']."'>";
        $form .= "<input type='hidden' name='params[reference4_label]' value='".$params['reference4_label']."'>";
        $form .= "<input type='hidden' name='params[reference4]' value='".$params['reference4']."'>";
        $form .= "<input type='hidden' name='params[reference5_label]' value='".$params['reference5_label']."'>";
        $form .= "<input type='hidden' name='params[reference5]' value='".$params['reference5']."'>";
        $form .= "<input type='hidden' name='params[reference6_label]' value='".$params['reference6_label']."'>";
        $form .= "<input type='hidden' name='params[reference6]' value='".$params['reference6']."'>";
        $form .= "<input type='hidden' name='payment_source' value='gravityforms'>";

        if ($is_banklist && !empty($buyer_bank_code)) {
            $form .= "<input type='hidden' name='buyer_bank_code' value='".$buyer_bank_code."'>";
        }

        $form .= '<input type="submit">';
        $form .= '</body></html>';

        exit($form);
    }

    public function callback()
    {
        if (!$this->is_gravityforms_supported()) {
            return false;
        }

        $entry = GFAPI::get_entry(rgget('entry_id'));

        if (is_wp_error($entry)) {
            $this->log_error(__METHOD__.'(): Entry could not be found. Aborting.');

            return false;
        }

        if ('spam' === rgar($entry, 'status')) {
            $this->log_error(__METHOD__.'(): Entry is marked as spam. Aborting.');

            return false;
        }

        $feed = $this->get_payment_feed($entry);

        if (!$feed || !rgar($feed, 'is_active')) {
            $this->log_error(__METHOD__."(): Form no longer is configured with securepay. Form ID: {$entry['form_id']}. Aborting.");

            return false;
        }

        $is_paid = 'Paid' === rgar($entry, 'payment_status') || 'Approved' === rgar($entry, 'payment_status');

        $response_params = $this->sanitize_response();
        if (!empty($response_params) && isset($response_params['order_number'])) {
            $success = $this->response_status($response_params);

            $is_callback = $this->is_response_callback($response_params);
            $callback = $is_callback ? 'Callback' : 'Redirect';
            $note = '';
            $retn = false;

            $receipt_link = !empty($response_params['receipt_url']) ? $response_params['receipt_url'] : '';
            $status_link = !empty($response_params['status_url']) ? $response_params['status_url'] : '';
            $retry_link = !empty($response_params['retry_url']) ? $response_params['retry_url'] : '';

            if ($success) {
                $note = "SecurePay payment successful\n";
                $note .= 'Response from: '.$callback."\n";
                $note .= 'Transaction ID: '.$response_params['merchant_reference_number']."\n";

                if (!empty($receipt_link)) {
                    $note .= 'Receipt link: '.$receipt_link."\n";
                }

                if (!empty($status_link)) {
                    $note .= 'Status link: '.$status_link."\n";
                }

                if ($is_paid) {
                    if (!$is_callback) {
                        $this->add_note($response_params['order_number'], $note);
                        $this->redirect_spcallback();
                        exit;
                    }

                    echo sprintf(esc_html__('This webhook has already been processed (Event Id: %s)', 'gravityforms'), $response_params['merchant_reference_number']);
                    exit;
                }

                return [
                    'id' => $response_params['merchant_reference_number'],
                    'transaction_id' => $response_params['merchant_reference_number'],
                    'amount' => (string) $response_params['transaction_amount'],
                    'entry_id' => $entry['id'],
                    'payment_date' => get_the_date('y-m-d H:i:s'),
                    'type' => 'complete_payment',
                    'payment_method' => 'securepay',
                    'ready_to_fulfill' => !$entry['is_fulfilled'] ? true : false,
                    'note' => $note,
                ];
            }

            $note = "SecurePay payment failed\n";
            $note .= 'Response from: '.$callback."\n";
            $note .= 'Transaction ID: '.$response_params['merchant_reference_number']."\n";

            if (!empty($retry_link)) {
                $note .= 'Retry link: '.$retry_link."\n";
            }

            if (!empty($status_link)) {
                $note .= 'Status link: '.$status_link."\n";
            }

            $this->add_note($response_params['order_number'], $note, 'error');

            if (!$is_callback) {
                $this->redirect_spcallback(true);
                exit;
            }

            return false;
        }

        return false;
    }

    public function get_payment_feed($entry, $form = false)
    {
        $feed = parent::get_payment_feed($entry, $form);
        $fid = isset($entry['form_id']) ? GFAPI::get_form($entry['form_id']) : '';
        $feed = apply_filters('gform_securepay_get_payment_feed', $feed, $entry, $form ?: $fid);

        return $feed;
    }

    public function post_callback($callback_action, $callback_result)
    {
        if (is_wp_error($callback_action) || !$callback_action) {
            return false;
        }

        $entry = GFAPI::get_entry($callback_action['entry_id']);
        $feed = $this->get_payment_feed($entry);
        $transaction_id = rgar($callback_action, 'transaction_id');
        $amount = rgar($callback_action, 'amount');

        $this->fulfill_order($entry, $transaction_id, $amount, $feed);

        do_action('gform_securepay_post_payment_status', $feed, $entry, $transaction_id, $amount);

        if (has_filter('gform_securepay_post_payment_status')) {
            $this->log_debug(__METHOD__.'(): Executing functions hooked to gform_securepay_post_payment_status.');
        }
    }

    public function is_callback_valid()
    {
        if ('gf_securepay' !== rgget('page')) {
            return false;
        }

        return true;
    }

    public function supported_notification_events($form)
    {
        if (!$this->has_feed($form['id'])) {
            return false;
        }

        return [
            'complete_payment' => esc_html__('Payment Completed', 'securepaygfm'),
            'fail_payment' => esc_html__('Payment Failed', 'securepaygfm'),
        ];
    }

    public function fulfill_order(&$entry, $transaction_id, $amount, $feed = null)
    {
        if (!$feed) {
            $feed = $this->get_payment_feed($entry);
        }

        $form = GFFormsModel::get_form_meta($entry['form_id']);
        if (rgars($feed, 'meta/delayPost')) {
            $this->log_debug(__METHOD__.'(): Creating post.');
            $entry['post_id'] = GFFormsModel::create_post($form, $entry);
            $this->log_debug(__METHOD__.'(): Post created.');
        }

        if (rgars($feed, 'meta/delayNotification')) {
            $notifications = $this->get_notifications_to_send($form, $feed);
            GFCommon::send_notifications($notifications, $form, $entry, true, 'form_submission');
        }

        do_action('gform_securepay_fulfillment', $entry, $feed, $transaction_id, $amount);
        if (has_filter('gform_securepay_fulfillment')) {
            $this->log_debug(__METHOD__.'(): Executing functions hooked to gform_securepay_fulfillment.');
        }
    }

    public function get_notifications_to_send($form, $feed)
    {
        $notifications_to_send = [];
        $selected_notifications = rgars($feed, 'meta/selectedNotifications');

        if (\is_array($selected_notifications)) {
            foreach ($form['notifications'] as $notification) {
                if ('form_submission' !== rgar($notification, 'event') || !\in_array($notification['id'], $selected_notifications)) {
                    continue;
                }

                $notifications_to_send[] = $notification['id'];
            }
        }

        return $notifications_to_send;
    }

    public function payment_details_editing_disabled($entry, $action = 'edit')
    {
        if (!$this->is_payment_gateway($entry['id'])) {
            return true;
        }

        $payment_status = rgar($entry, 'payment_status');
        if ('Approved' === $payment_status || 'Paid' === $payment_status || 2 === rgar($entry, 'transaction_type')) {
            return true;
        }

        if ('edit' === $action && 'edit' === rgpost('screen_mode')) {
            return false;
        }

        if ('update' === $action && 'view' === rgpost('screen_mode') && 'update' === rgpost('action')) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        parent::uninstall();
    }
}
