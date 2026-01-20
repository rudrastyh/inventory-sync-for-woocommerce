=== Inventory Sync for WooCommerce ===
Contributors: rudrastyh
Tags: woocommerce, woocommerce stock, shared stock, stock sync, stock management
Requires at least: 3.1
Tested up to: 6.9
Stable tag: 2.0
Requires PHP: 5.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Allows to sync the stock quantity of products with the same SKU between two WooCommerce stores.

== Description ==

Inventory Sync for WooCommerce allows to sync the stock of the products with the same SKUs between two WooCommerce stores.

= Features =

✅ Allows to sync not only **Stock quantity**, but also **Stock Status** and **Stock Management** checkbox value.
✅ Product variations are supported (must have the same SKU as well).
✅ Instantly syncs stock changes when a product is purchased or edited via WordPress or an order is refunded or cancelled.
✅ Two-directional product stock sync is supported.
✅ Works with both regular WordPress sites and Multisite networks.

= Pro features =

✅ **An unlimited number** of WooCommerce stores is supported.
✅ Allows you to exclude specific products (or only variations within specific products) from the sync.
✅ SKU or Slug product connection type (can be helpful when not every product on your store has an SKU, or when they have duplicated SKUs).
✅ Asynchronous syncing (significant performance boost when an order with a lot of products is placed).
✅ REST API requests are packed and sent in batches with the PHP Requests library, which gives another performance boost in every scenario; here is [the benchmark](https://rudrastyh.com/wordpress/send-multiple-rest-api-requests.html#benchmark).

🚀 [Upgrade to Pro](https://rudrastyh.com/plugins/simple-product-stock-sync-for-woocommerce)

== Installation ==

= Automatic Install =

1. Log into your WordPress dashboard and go to Plugins &rarr; Add New
2. Search for "Inventory Sync for WooCommerce"
3. Click "Install Now" under the "Inventory Sync for WooCommerce" plugin
4. Click "Activate Now"

= Manual Install =

1. Download the plugin from the download button on this page
2. Unzip the file, and upload the resulting `inventory-sync-for-woocommerce` folder to your `/wp-content/plugins` directory
3. Log into your WordPress dashboard and go to Plugins
4. Click "Activate" under the "Inventory Sync for WooCommerce" plugin

== Frequently Asked Questions ==

= Does it work on localhost? =
Yes. The inventory sync is going to work great between localhost websites or from the localhost to a remote site. In that case, you would either need to use application passwords instead of WooCommerce REST API credentials or simply move to the [PRO version](https://rudrastyh.com/plugins/simple-product-stock-sync-for-woocommerce) of the plugin. Do not forget though that in order to create an application password on the localhost you need to set `WP_ENVIRONMENT_TYPE` to `local` in your `wp-config.php` file.

= Does it support two-directional inventory sync? =
Yes. But in this case you need to install the plugin on both sites and add each one in the plugin settings.

= Can this plugin sync other product information?
This lite version of the plugin can only sync Stock quantity, Stock Status and Stock Management checkbox. In the [pro version](https://rudrastyh.com/plugins/simple-product-stock-sync-for-woocommerce) of the plugin you can also include some other basic product information like prices with a hook (you can find it in the documentation).

However, if you'd like to sync all WooCommerce product information (product images, variations, and so on), take a look at my other plugins which are developed specifically for that purpose:

- [Multisite Product Sync](https://rudrastyh.com/woocommerce/multisite-product-sync.html) for WooCommerce multisite installations
- [Product Sync](https://rudrastyh.com/woocommerce/product-sync-with-multiple-stores.html) for standalone WooCommerce stores

== Screenshots ==
1. Inventory sync happens automatically and plugin doesn't even have any settings except the REST API authentication data of a site you are about to sync stock info with.
2. Stock status, Stock management and Quantity are the fields that will be synced.

== Changelog ==

= 2.0 =
* Added: The free version now allows you to sync inventory between subsites within a WordPress Multisite network
* UI improvements (the latest UI changes made in the PRO version of the plugin are now available in the free version)

= 1.3 =
* The plugin now uses Consumer key and Consumer secret instead of WordPress application passwords

= 1.2.1 =
* Minor UI improvements

= 1.2 =
* Added support for cancelled and refunded orders

= 1.1 =
* Bug fixes

= 1.0 =
* Initial release
