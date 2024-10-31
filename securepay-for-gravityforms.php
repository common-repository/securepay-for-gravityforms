<?php
/**
 * SecurePay for Gravityforms.
 *
 * @author  SecurePay Sdn Bhd
 * @license GPL-2.0+
 *
 * @see    https://securepay.my
 */

/*
 * @wordpress-plugin
 * Plugin Name:         SecurePay for Gravityforms
 * Plugin URI:          https://securepay.my/?utm_source=wp-plugins-gravityforms&utm_campaign=plugin-uri&utm_medium=wp-dash
 * Version:             1.0.12
 * Description:         Accept payment by using SecurePay. A Secure Marketplace Platform for Malaysian.
 * Author:              SecurePay Sdn Bhd
 * Author URI:          https://securepay.my/?utm_source=wp-plugins-gravityforms&utm_campaign=author-uri&utm_medium=wp-dash
 * Requires at least:   5.4
 * Requires PHP:        7.2
 * License:             GPL-2.0+
 * License URI:         http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:         securepaygfm
 * Domain Path:         /languages
 */
if (!\defined('ABSPATH') || \defined('SECUREPAYGFM_FILE')) {
    exit;
}

\define('SECUREPAYGFM_VERSION', '1.0.12');
\define('SECUREPAYGFM_SLUG', 'securepay-for-gravityforms');
\define('SECUREPAYGFM_ENDPOINT_LIVE', 'https://securepay.my/api/v1/');
\define('SECUREPAYGFM_ENDPOINT_SANDBOX', 'https://sandbox.securepay.my/api/v1/');
\define('SECUREPAYGFM_ENDPOINT_PUBLIC_LIVE', 'https://securepay.my/api/public/v1/');
\define('SECUREPAYGFM_ENDPOINT_PUBLIC_SANDBOX', 'https://sandbox.securepay.my/api/public/v1/');

\define('SECUREPAYGFM_FILE', __FILE__);
\define('SECUREPAYGFM_HOOK', plugin_basename(SECUREPAYGFM_FILE));
\define('SECUREPAYGFM_PATH', realpath(plugin_dir_path(SECUREPAYGFM_FILE)).'/');
\define('SECUREPAYGFM_URL', trailingslashit(plugin_dir_url(SECUREPAYGFM_FILE)));

require __DIR__.'/includes/load.php';
SecurePayGFM::attach();
