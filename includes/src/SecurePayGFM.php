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

final class SecurePayGFM
{
    private static function register_locale()
    {
        add_action(
            'plugins_loaded',
            function () {
                load_plugin_textdomain(
                    'securepaygfm',
                    false,
                    SECUREPAYGFM_PATH.'languages/'
                );
            },
            0
        );
    }

    public static function register_admin_hooks()
    {
        add_action(
            'plugins_loaded',
            function () {
                if (current_user_can(apply_filters('capability', 'manage_options'))) {
                    add_action('all_admin_notices', [__CLASS__, 'callback_compatibility'], \PHP_INT_MAX);
                }
            }
        );

        add_action('gform_loaded', function () {
            GFForms::include_payment_addon_framework();
            require_once __DIR__.'/GFSecurePay.php';
            GFAddOn::register('GFSecurePay');
        });
    }

    private static function is_gfforms_activated()
    {
        return method_exists('GFForms', 'include_payment_addon_framework');
    }

    private static function register_autoupdates()
    {
        if (!\defined('SECUREPAYGFM_AUTOUPDATE_DISABLED') || !SECUREPAYGFM_AUTOUPDATE_DISABLED) {
            add_filter(
                'auto_update_plugin',
                function ($update, $item) {
                    if (SECUREPAYGFM_SLUG === $item->slug) {
                        return true;
                    }

                    return $update;
                },
                \PHP_INT_MAX,
                2
            );
        }
    }

    public static function callback_compatibility()
    {
        if (!self::is_gfforms_activated()) {
            $html = '<div id="securepaygfm-notice" class="notice notice-error is-dismissible">';
            $html .= '<p>'.esc_html__('SecurePay for GravityForms require GravityForms plugin. Please install and activate.', 'securepaygfm').'</p>';
            $html .= '</div>';
            echo $html;
        }
    }

    public static function activate()
    {
        return true;
    }

    public static function deactivate()
    {
        return true;
    }

    public static function uninstall()
    {
        return true;
    }

    public static function register_plugin_hooks()
    {
        register_activation_hook(SECUREPAYGFM_HOOK, [__CLASS__, 'activate']);
        register_deactivation_hook(SECUREPAYGFM_HOOK, [__CLASS__, 'deactivate']);
        register_uninstall_hook(SECUREPAYGFM_HOOK, [__CLASS__, 'uninstall']);
    }

    public static function attach()
    {
        self::register_locale();
        self::register_plugin_hooks();
        self::register_admin_hooks();
        self::register_autoupdates();
    }
}
