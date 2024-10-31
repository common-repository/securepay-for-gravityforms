=== SecurePay For GravityForms ===
Contributors: SecurePay
Tags: payment gateway, payment platform, Malaysia, online banking, fpx
Requires at least: 5.4
Tested up to: 6.3
Requires PHP: 7.2
Stable tag: 1.0.12
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.html

SecurePay payment platform plugin for Gravity Forms.

== Description ==

Install this plugin to extends the [Gravity Forms](https://www.gravityforms.com/) plugin to accept payments with the [SecurePay Payment Platform](https://www.securepay.my/?utm_source=wp-plugins-gravityforms&utm_campaign=author-uri&utm_medium=wp-dash) for Malaysians.

If you have any questions or suggestions about this plugin, please contact us directly through email at **hello@securepay.my** . Our friendly team will gladly reply as soon as possible.

Other Integrations:

- [SecurePay For WooCommerce](https://wordpress.org/plugins/securepay/)
- [SecurePay For WPJobster](https://wordpress.org/plugins/securepay-for-wpjobster/)
- [SecurePay For WPForms](https://wordpress.org/plugins/securepay-for-wpforms/)
- [SecurePay For Restrict Content Pro](https://wordpress.org/plugins/securepay-for-restrictcontentpro)
- [SecurePay For Paid Memberships Pro](https://wordpress.org/plugins/securepay-for-paidmembershipspro)
- [SecurePay For GiveWP](https://wordpress.org/plugins/securepay-for-givewp)

== Installation ==

Make sure that you already have GravityFroms plugin installed and activated.

**Step 1:**

- Login to your *WordPress Dashboard*
- Go to **Plugins > Add New**
- Search **SecurePay for GravityForms**

**Step 2:**

- **Activate** the plugin through the 'Plugins' screen in WordPress.

**Step 3:**

- Create a new form **Forms > New Frorm**
- Click **Forms -> your-new-form**
- Click **Settings -> SecurePay**
- Click **Add New**

**Step 4:**

- Fill in your **Token, Checksum Token, UID Token**. You can retrieve your credentials from your SecurePay account.
- Click **Save** to save changes.

Contact us through email hello@securepay.my if you have any questions or comments about this plugin.


== Changelog ==
= 1.0.12 (28-08-2021) =
- Fixed: invalid SECUREPAYGFM_SLUG constant.

= 1.0.11 (28-08-2021) =
- Fixed: missing SECUREPAYGFM_VERSION constant.

= 1.0.10 (25-08-2021) =
- Fixed: bank list select script.
- Fixed: handle bank image not exists.

= 1.0.9 (10-08-2021) =
- Added test mode
- Fixed return_url, cancel_url hash

= 1.0.8 (26-07-2021) =
- Updated Affin bank logo

= 1.0.7 (26-07-2021) =
- Fixed select option.

= 1.0.6 (26-07-2021) =
- Added default bank option to "please select bank".
- Tested up to: 5.8

= 1.0.5 (22-04-2021) =
- Fixed GFSecurePay::get_payment_feed -> Deprecate left-associative ternary operator in PHP 7.4.

= 1.0.4 (23-03-2021) =
- Fixed invalid reference3 variable.

= 1.0.3 (17-03-2021) =
- Fixed redirect_spcallback().

= 1.0.2 (16-03-2021) =
- Added reference 1-6.
- Fixed typo.

= 1.0.1 (16-03-2021) =
- Public release.

= 1.0.0 (12-03-2021) =
- Initial release.
