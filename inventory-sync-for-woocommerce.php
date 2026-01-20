<?php
/*
 Plugin name: Inventory Sync for WooCommerce
 Description: Allows to synchronize the stock quantity of the products with the same SKUs between two WooCommerce stores.
 Author: Misha Rudrastyh
 Author URI: https://rudrastyh.com
 Version: 2.0.1
 License: GPL v2 or later
 License URI: http://www.gnu.org/licenses/gpl-2.0.html

 Copyright 2023-2026 Misha Rudrastyh ( https://rudrastyh.com )

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
 the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

require __DIR__ . '/includes/WooCommerce/Client.php';
require __DIR__ . '/includes/WooCommerce/HttpClient/BasicAuth.php';
require __DIR__ . '/includes/WooCommerce/HttpClient/HttpClient.php';
require __DIR__ . '/includes/WooCommerce/HttpClient/HttpClientException.php';
require __DIR__ . '/includes/WooCommerce/HttpClient/OAuth.php';
require __DIR__ . '/includes/WooCommerce/HttpClient/Options.php';
require __DIR__ . '/includes/WooCommerce/HttpClient/Request.php';
require __DIR__ . '/includes/WooCommerce/HttpClient/Response.php';

use Automattic\WooCommerce\Client;

if( ! class_exists( 'ISFW_Product_Sync' ) ) {

	class ISFW_Product_Sync{


		function __construct() {

			// order created
			add_action( 'woocommerce_reduce_order_stock', array( $this, 'order_sync' ) );
			// order cancelled
			add_action( 'woocommerce_restore_order_stock', array( $this, 'order_sync' ) );
			// product saved
			add_action( 'save_post', array( __CLASS__, 'product_update' ), 199, 2 );

			// settings pages
			add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
			add_action( 'admin_head', array( $this, 'css' ) );
			add_action( 'woocommerce_settings_products', array( $this, 'output_settings' ), 15 );
			add_filter( 'plugin_action_links', array( $this, 'settings_link' ), 10, 2 );
			// store management
			add_action( 'wp_ajax_isfwgetstores', array( $this, 'get_stores_async' ) );
			add_action( 'wp_ajax_isfwaddstore', array( $this, 'add_store' ) );
			add_action( 'wp_ajax_isfwaddstorewpmu', array( $this, 'add_store_wpmu' ) );
			add_action( 'wp_ajax_isfwremovestore', array( $this, 'remove_store' ) );
			add_action( 'wp_ajax_isfwgetstoreswpmu', array( $this, 'get_stores_async_wpmu' ) );

			// product settings
			add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'product_settings' ) );
			add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_settings' ), 10, 3 );
			// tool
			add_filter( 'woocommerce_debug_tools', array( $this, 'register_sync_tool' ) );
			// notices
			add_action( 'admin_notices', array( $this, 'notices' ) );

		}


		public static function is_excluded( $product_or_variation ) {

			if( 'external' === $product_or_variation->get_type() ) {
				return true;
			}

			return false;

		}


		public static function api_init() {

			$stores = self::get_stores();

			if(
				! empty( $stores )
				&& ! empty( $stores[0][ 'url' ] )
				&& ! empty( $stores[0][ 'login' ] )
				&& ! empty( $stores[0][ 'pwd' ] )
			) {
				$woocommerce = new Client( $stores[0][ 'url' ], $stores[0][ 'login' ], $stores[0][ 'pwd' ], array( 'version' => 'wc/v3', 'timeout' => 30 ) );
			} else {
				$woocommerce = null;
			}

			return $woocommerce;

		}


		public function order_sync( $order ) {

			$items = $order->get_items( array( 'line_item' ) );
			if( ! $items ) {
				return;
			}

			$woocommerce = $this->api_init();

			$items = $this->format_order_items( $items, $woocommerce );
			$this->sync( $items, $woocommerce );

		}


		public static function product_update( $product_id, $post ) {

			if( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if( 'product' !== $post->post_type && 'product_variation' !== $post->post_type ) {
				return;
			}

			if( ! function_exists( 'wc_get_product' ) ) {
				return;
			}

			remove_action( 'save_post', array( __CLASS__, 'product_update' ), 199, 2 );

			$product = wc_get_product( $product_id );

			if( ! $product ) { // null or false
				return;
			}

			self::product_sync( $product );

		}


		public static function product_sync( $product ) {

			if( ! is_a( $product, 'WC_Product' ) && ! is_a( $product, 'WC_Product_Variation' ) ) {
				return;
			}

			if( self::is_excluded( $product ) ) {
				return;
			}

			$woocommerce = self::api_init();

			$items = self::format_product( $product, $woocommerce );
//echo '<pre>';print_r($items);exit;
			self::sync( $items, $woocommerce );

		}


		/*
		 * Formatting functions
		 */
		private function format_order_items( $items, $woocommerce ) {

			/*
				Array(
					products => array( product_id => data, ... ),
					variations => array( product_id => array( variation_id => data, ... ) )
				)
			*/
			$return = array(
				'products' => array(),
				'variations' => array(),
			);

			foreach( $items as $item ) { // both products and variations

				// WC_Product or WC_Product_Variation
				$product = $item->get_product();
				if( ! $product ) {
					continue;
				}

				if( $this->is_excluded( $product ) ) {
					continue;
				}

				if( $parent_id = $product->get_parent_id() ) {
					// variation
					$return[ 'variations' ][ $parent_id ][] = $this->product_data( $product, $woocommerce );
				} else {
					// not variation
					$return[ 'products' ][] = $this->product_data( $product, $woocommerce );
				}

			}

			return $return;

		}


		public static function format_product( $product, $woocommerce ) {

			$return = array(
				'products' => array(),
				'variations' => array(),
			);

			// for variable products we add variations only, it will save us 1 requests I guess
			if( $product->is_type( 'variable' ) ) {

				if( $variation_ids = $product->get_children() ) {
					$parent_id = self::get_id_by_sku( $product->get_sku(), $woocommerce );
					foreach( $variation_ids as $variation_id ) {
						$variation = wc_get_product_object( 'variation', $variation_id );
						if( ! $variation ) {
							continue;
						}
						$return[ 'variations' ][ $parent_id ][] = self::product_data( $variation, $woocommerce );
					}
				}

			} else {

				$return[ 'products' ][] = self::product_data( $product, $woocommerce );

			}

			return $return;

		}


		public static function product_data( $product, $woocommerce ) {
			$sku = get_post_meta( $product->get_id(), '_sku', true );

			return array(
				'id' => self::get_id_by_sku( $sku, $woocommerce ),
				'sku' => $sku,
				'manage_stock' => $product->get_manage_stock(),
				'stock_status' => $product->get_stock_status(),
				'stock_quantity' => $product->get_stock_quantity(),
			);
		}

		public static function get_id_by_sku( $sku, $woocommerce ) {

			// is not enough info or multisite
			if( ! $woocommerce ) {
				return 0;
			}

			try {
				$products = $woocommerce->get( 'products', array(
					'sku' => $sku,
				) );
			} catch ( Exception $e ) {
				//echo $e->getMessage();exit;
				return 0;
			}

			if( empty( $products ) || empty( $products[0]->id ) ) {
				return 0;
			}

			return $products[0]->id;

		}


		/*
		 * Sync functions
		 */
		public static function sync( $items, $woocommerce ) {

			$stores = self::get_stores();
			if( empty( $stores ) ) {
				return;
			}

			$store = reset( $stores );

			if( $store[ 'blog_id' ] ) {

				$id = $store[ 'blog_id' ];
				switch_to_blog( $id );

				if( $items[ 'products' ] ) {
					foreach( $items[ 'products' ] as $item ) {
						$product_id = wc_get_product_id_by_sku( $item[ 'sku' ] );
						$product = wc_get_product( $product_id );
						if( ! $product ) {
							continue;
						}

						$product->set_manage_stock( $item[ 'manage_stock' ] );
						$product->set_stock_status( $item[ 'stock_status' ] );
						$product->set_stock_quantity( $item[ 'stock_quantity' ] );
						$product->save();
					}
				}
				// the same for variations
				if( $items[ 'variations' ] ) {
					foreach( $items[ 'variations' ] as $product_id => $variations ) {
						foreach( $variations as $item ) {
							$variation_id = wc_get_product_id_by_sku( $item[ 'sku' ] );
							$variation = wc_get_product( $variation_id );
							if( ! $variation ) {
								continue;
							}

							$variation->set_manage_stock( $item[ 'manage_stock' ] );
							$variation->set_stock_status( $item[ 'stock_status' ] );
							$variation->set_stock_quantity( $item[ 'stock_quantity' ] );
							$variation->save();
						}
					}
				}

				restore_current_blog();


			} elseif( $store[ 'url' ] && $store[ 'login' ] && $store[ 'pwd' ] ) {

				$url = $store[ 'url' ];
				$login = $store[ 'login' ];
				$pwd = $store[ 'pwd' ];

				// let's generate batch requests for products first
				if( $items[ 'products' ] ) {

					// let's check how many elements are in array
					if( count( $items[ 'products' ] ) > 1 ) {
						// create and run batch here
						try {
							$woocommerce->post( 'products/batch', array(
								'update' => $items[ 'products' ],
							) );
						} catch ( Exception $e ) {
							// TODO logger
						}
					} else {

						// great now we have a product and we have to update its stock!
						$product_id = $items[ 'products' ][0][ 'id' ];
						unset( $items[ 'products' ][0][ 'id' ] );

						try {
							$woocommerce->put( "products/{$product_id}", $items[ 'products' ][0] );
						} catch ( Exception $e ) {
							// TODO logger
						}

					}

				}


				// the same for variations
				if( $items[ 'variations' ] ) {

					foreach( $items[ 'variations' ] as $parent_id => $variations ) {

						if( count( $variations ) > 1 ) {

							try {
								$woocommerce->post(
									"products/{$parent_id}/variations/batch",
									array(
										'update' => $variations,
									)
								);
							} catch (Exception $e) {
								// TODO logger
							}

						} else {

							// great now we have a product and we have to update its stock!
							$variation_id = $variations[0][ 'id' ];
							unset( $variations[0][ 'id' ] );

							try {
								$woocommerce->put( "products/{$parent_id}/variations/{$variation_id}", $variations[0] );
							} catch (Exception $e) {
								// TODO logger
							}

						}

					} // foreach loop for every variation set

				}

			}

		}

		public static function get_stores() {

			$stores = array();

			$store_id = (int) get_option( 'isfw_store_id', 0 );
			// let's start with multisite first
			if( $store_id && is_multisite() ) {
				$store_url = get_blog_option( $store_id, 'siteurl' );
				$store_name = get_blog_option( $store_id, 'blogname' );
				// this site actually exists
				if( $store_url ) {
					$stores[] = array(
						'blog_id' => $store_id,
						'name' => ( $store_name ? $store_name : '–' ),
						'url' => $store_url,
					);
				}
			}

			if( empty( $stores ) ) {
				$url = esc_url( get_option( 'isfw_store_url' ) );
				$name = get_option( 'isfw_store_name', '–' );
				$login = get_option( 'isfw_username' );
				$pwd = get_option( 'isfw_application_password' );
				if( $url && $login && $pwd ) {
					$stores[] = array(
						'blog_id' => 0,
						'name' => $name,
						'url' => $url,
						'login' => $login,
						'pwd' => $pwd,
					);
				}
			}

			return $stores;

		}

		// (original)
		// include plugin scripts
		public function scripts() {
			$screen = get_current_screen();
			if( 'woocommerce_page_wc-settings' !== $screen->id || empty( $_GET[ 'section' ] ) || 'inventory' !== $_GET[ 'section' ] ) {
				return;
			}

			wp_register_script( 'isfw-settings', plugin_dir_url( __FILE__ ) . 'assets/script.js', array( 'jquery' ), filemtime( __DIR__ . '/assets/script.js' ), true );
			wp_localize_script(
				'isfw-settings',
				'isfw_settings',
				array(
					'nonce' => wp_create_nonce( 'stores-actions' ),
					'deleteStoreConfirmText' => __( 'Are you sure you want to remove this store from the list?', 'rudr-simple-inventory-sync' ),
				)
			);
			wp_enqueue_script( 'isfw-settings' );

		}

		// (original)
		// CSS tweaks, for store management table mostly
		public function css() {
			?><style>
			.rudr-isfw-stores-table table.widefat th,
			.rudr-isfw-stores-table table.widefat td{
				padding: 1em;
			}
			.rudr-isfw-remove-store, .rudr-isfw-remove-store:hover {
    		color: #b32d2e;
			}
			.rudr-isfw-add-store{margin-bottom:25px;max-width:40rem;}
			.rudr-isfw-stores-table table.widefat td{
				vertical-align: top;
			}
		 	tr:hover .rudr-isfw-stores-table tr .row-actions{
				position: relative;
			}
			tr:hover .rudr-isfw-stores-table tr:hover .row-actions{
				position: static;
			}</style><?php
		}

		/*
		 * Settings page
		 */
		public function output_settings() {

			// do nothing if it is not our section
			if( empty( $_GET[ 'section' ] ) || 'inventory' !== $_GET[ 'section' ] ) {
				return;
			}

			?>
				<h2 id="inventory-sync"><?php esc_html_e( 'Inventory Sync', 'rudr-simple-inventory-sync' ) ?></h2>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="connection_type"><?php esc_html_e( 'Product connection type', 'rudr-simple-inventory-sync' ) ?></label></th>
							<td>
								<select id="connection_type" name="connection_type" class="wc-enhanced-select">
									<option value="sku"><?php esc_html_e( 'SKU', 'woocommerce' ) ?></option>
									<option value="slug" disabled><?php esc_html_e( 'Slug (Pro)', 'rudr-simple-inventory-sync' ) ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'The entity that will be used to find similar products across connected stores.', 'rudr-simple-inventory-sync' ) ?></p>
							</td>
						</tr>
						<?php
							/*
							 * Stores
							 */
							$this->output_stores();
						?>
					</tbody>
				</table>
			<?php
		}

		// (original)
		// prints store management table
		public function output_stores() {
			?>
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Stores', 'rudr-simple-inventory-sync' ) ?>
					<p class="wc-shipping-zone-help-text"><?php esc_html_e( 'Product stock will be synced automatically with all stores added here.', 'rudr-simple-inventory-sync' ) ?></p>
				</th>
				<td>
					<div class="rudr-isfw-add-store form-wrap">
						<?php if( is_multisite() ) : ?>
							<!-- allow to change a store type, by default – multisite -->
							<div class="form-field" style="margin-top:0.5em;">
								<label>
									<input type="checkbox" id="rudr_is_standalone_store" name="is_standalone_store" />&nbsp;<?php esc_html_e( 'Store is located outside this multisite network', 'rudr-simple-inventory-sync' ) ?>
								</label>
							</div>
							<!-- multisite -->
							<div>
								<div class="form-field">
									<select name="new_blog_id" class="isfw-select-site" data-site__not_in="<?php echo get_current_blog_id() ?>" data-placeholder="<?php esc_attr_e( 'Select a store...', 'rudr-simple-inventory-sync' ) ?>"></select>
								</div>
								<button type="button" id="do_new_multisite" class="components-button is-primary"><?php esc_html_e( 'Add new store', 'rudr-simple-inventory-sync' ) ?></button>
							</div>
						<?php endif; ?>
						<!-- standalone -->
						<div<?php echo ! is_multisite() ? '' : ' style="display:none"' ?>>
							<div class="form-field" style="margin-top:0;">
								<label for="new_site_url"><?php esc_html_e( 'Store Address (URL)', 'rudr-simple-inventory-sync' ) ?></label>
								<input type="text" size="35" id="new_site_url" name="new_site_url" class="input" aria-required="true" placeholder="https://" />
							</div>
							<div class="form-field">
								<label for="new_site_username"><?php esc_html_e( 'Consumer Key', 'rudr-simple-inventory-sync' ) ?></label>
								<input type="text" size="35" id="new_site_username" name="new_site_username" class="input" aria-required="true" placeholder="ck_" />
								<p class="description"><?php echo sprintf( __( '<a href="%s" target="_blank">Read here</a> where to get Consumer Key and Consumer Secret.', 'rudr-simple-inventory-sync' ), 'https://rudrastyh.com/woocommerce/rest-api-create-update-remove-products.html#rest_api_keys' ) ?></p>
							</div>
							<div class="form-field">
								<label for="new_site_pwd"><?php esc_html_e( 'Consumer Secret', 'rudr-simple-inventory-sync' ) ?></label>
								<input type="text" size="35" id="new_site_pwd" name="new_site_pwd" class="input" aria-required="true" placeholder="cs_" />
							</div>
							<button type="button" id="do_new_website" class="components-button is-primary"><?php esc_html_e( 'Add new store', 'rudr-simple-inventory-sync' ) ?></button>
						</div>
					</div>
					<div id="rudr-isfw-stores-notices"></div>
					<!-- table with sites -->
					<div class="rudr-isfw-stores-table" style="margin-top:35px;">
						<table class="wp-list-table widefat fixed striped table-view-list">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Name', 'rudr-simple-inventory-sync' ) ?></th>
									<th scope="col"><?php esc_html_e( 'Store Address (URL)', 'rudr-simple-inventory-sync' ) ?></th>
								</tr>
							</thead>
							<tbody id="the-list">
								<?php
									$stores = $this->get_stores();
									if( $stores ) {
										foreach( $stores as $store ) {
											echo $this->store_tr( $store );
										}
									} else {
										?><tr><td colspan="2"><?php esc_html_e( 'Please add stores you would like to sync the product inventory with.', 'rudr-simple-inventory-sync' ) ?></td></tr><?php
									}
								?>
							</tbody>
							<tfoot>
								<tr>
									<th scope="col"><?php esc_html_e( 'Name', 'rudr-simple-inventory-sync' ) ?></th>
									<th scope="col"><?php esc_html_e( 'Store Address (URL)', 'rudr-simple-inventory-sync' ) ?></th>
								</tr>
							</tfoot>
						</table>
					</div>
					<!-- table with sites end -->
				</td>
			</tr>
			<?php
		}

		// quick settings link
		public function settings_link( $links, $plugin_file_name ){

			if( strpos( $plugin_file_name, basename(__FILE__) ) ) {
				array_unshift(
					$links,
					sprintf(
						'<a href="https://rudrastyh.com/plugins/simple-product-stock-sync-for-woocommerce">%s</a>',
						esc_html__( 'Upgrade to Pro' )
					),
					sprintf(
						'<a href="%s">%s</a>',
						add_query_arg(
							array(
								'page' => 'wc-settings',
								'tab' => 'products',
								'section' => 'inventory',
							),
							'admin.php#inventory-sync'
						),
						esc_html__( 'Settings' )
					)
				);
			}
			return $links;

		}

		/****************/
		/* Placeholders */
		/****************/
		public function product_settings() {

			?>
				<div class="options_group show_if_simple">
					<p class="form-field">
						<label><?php esc_html_e( 'Exclude from sync', 'rudr-simple-inventory-sync' ) ?> (<a href="https://rudrastyh.com/plugins/simple-product-stock-sync-for-woocommerce" target="_blank"><?php esc_html_e( 'Pro', 'rudr-simple-inventory-sync' ) ?></a>)</label>
						<input type="checkbox" disabled="disabled" value="yes" class="checkbox"><span class="description"><?php esc_html_e( 'Don&#8217;t sync stock status and quantity with connected stores', 'rudr-simple-inventory-sync' ) ?></span>
					</p>
				</div>
			<?php

		}

		public function variation_settings( $loop, $variation_data, $post ) {

			?>
				<p class="form-row form-row-full form-field">
					<label><?php esc_html_e( 'Exclude from sync?', 'rudr-simple-inventory-sync' ) ?> (<a href="https://rudrastyh.com/plugins/simple-product-stock-sync-for-woocommerce" target="_blank"><?php esc_html_e( 'Pro', 'rudr-simple-inventory-sync' ) ?></a>)</label>
					<span class="woocommerce-help-tip" tabindex="0" aria-label="<?php esc_attr_e( 'Don&#8217;t sync stock status and quantity with connected stores', 'rudr-simple-inventory-sync' ) ?>" data-tip="<?php esc_attr_e( 'Don&#8217;t sync stock status and quantity with connected stores', 'rudr-simple-inventory-sync' ) ?>"></span>
					<select disabled="disabled" class="select short">
						<option value="no" selected="selected"><?php esc_html_e( 'Do not exclude', 'rudr-simple-inventory-sync' ) ?></option>
						<option value="yes"><?php esc_html_e( 'Exclude', 'rudr-simple-inventory-sync' ) ?></option>
					</select>
				</p>
			<?php

		}

		public function register_sync_tool( $debug_tools ) {

			$sync_tool = array(
				'sps_sync_tool' => array(
					'name'     => __( 'Sync product inventory', 'rudr-simple-inventory-sync' ),
					'button'   => __( 'Start sync', 'rudr-simple-inventory-sync' ),
					'desc' => '',
					'callback' => array( $this, 'start_sync_tool' ),
					'disabled' => true,
					'selector'         => array(
						'description' => '(<a href="https://rudrastyh.com/plugins/simple-product-stock-sync-for-woocommerce" target="_blank">' . esc_html__( 'Pro', 'rudr-simple-inventory-sync' ) . '</a>)&nbsp;' . __( 'This tool will push the stock of all products to a selected store:', 'rudr-simple-inventory-sync' ),
						'class'         => 'wc-product-search',
						'search_action' => 'isfwgetstores',
						'name'          => 'sps_store_id',
						'placeholder'   => esc_attr__( 'Select a store&hellip;', 'rudr-simple-inventory-sync' ),
					),
				)
			);

			$debug_tools = array_slice( $debug_tools, 0, 2, true ) + $sync_tool + array_slice( $debug_tools, 2, NULL, true );

			return $debug_tools;

		}

		public function get_stores_async(){

			check_ajax_referer( 'search-products', 'security' );

			if ( empty( $term ) && isset( $_GET[ 'term' ] ) ) {
				$term = (string) mb_strtolower( wc_clean( wp_unslash( $_GET[ 'term' ] ) ) );
			}

			if ( empty( $term ) ) {
				wp_die();
			}

			$url = esc_url( get_option( 'isfw_store_url' ) );
			$login = get_option( 'isfw_username' );
			$pwd = get_option( 'isfw_application_password' );
			$store_id = (int) get_option( 'isfw_store_id' );

			$found_stores = array();

			if( $url && $login && $pwd && strpos( $url, $term ) ) {
				$found_stores[ $login ] = str_replace( array( 'https://', 'http://' ), '', untrailingslashit( $url ) );
			}

			if( $store_id ) {
				$details = get_blog_details( $site_id );
				$found_stores[ $store_id ] = $details->blogname;
			}

			wp_send_json( $found_stores );

		}

		/*********************/
		/* Stores management */
		/*********************/

		// (original)
		// template for displaying a store row in the table
		public function store_tr( $store ) {

			$tr = '<tr id="store-1" class="rudr-isfw-store">';
			$tr .= '<td>' . ( isset( $store[ 'name' ] ) && $store[ 'name' ] ? esc_html( $store[ 'name' ] ) : '&ndash;' );
			$tr .= '<div class="row-actions"><a href="#" class="rudr-isfw-remove-store">' . esc_html__( 'Delete this store', 'rudr-simple-inventory-sync' ) . '</a></div>';
			$tr .= '</td>';
			$tr .= '<td>' . esc_html( str_replace( array( 'https://', 'http://' ), '', $store[ 'url' ] ) ) . '</td>';
			$tr .= '</tr>';

			return $tr;

		}

		// add a standalone store
		public function add_store() {

			check_ajax_referer( 'stores-actions' );

			if( ! current_user_can( 'manage_options' ) ) {
				die;
			}

			// first of all let's get overall sites array from the options
			$stores = $this->get_stores();

			// 1 store maximum
			if( ! empty( $stores ) ) {
				wp_send_json_error( new WP_Error( 'pro_required', sprintf( __( 'If you need to add more stores, please consider upgrading to the <a href="%s">PRO version</a> of the plugin.', 'rudr-simple-inventory-sync' ), 'https://rudrastyh.com/plugins/simple-product-stock-sync-for-woocommerce' ) ) );
			}

			// just a regular API check
			$url = ! empty( $_POST[ 'url' ] ) ? untrailingslashit( sanitize_url( $_POST[ 'url' ] ) ) : '';
			// replace with HTTPS
			if( 'http' == parse_url( $url, PHP_URL_SCHEME ) && false === strpos( $url, 'local' ) ){
				$url = str_replace( 'http://', 'https://', $url );
			}
			$login = ! empty( $_POST[ 'login' ] ) ? $_POST[ 'login' ] : '';
			$pwd = ! empty( $_POST[ 'pwd' ] ) ? $_POST[ 'pwd' ] : '';

			if( ! $url || ! $login || ! $pwd ) {
				wp_send_json_error( new WP_Error( 'empty_fields', __( 'Please fill all the required fields', 'rudr-simple-inventory-sync' ) ) );
			}

			// let's create an array of a new store at this moment
			$added_store = array(
				'blog_id' => 0,
				'url' => $url,
				'name' => '–',
				'login' => $login,
				'pwd' => $pwd,
			);

			// let's create a default error over here
			$not_added_err = new WP_Error(
				'not_added',
				sprintf(
					__( 'Store is not added. Please check that it has REST API turned on and also double check Store URL, Consumer Key, and Consumer Secret fields. <a href="%s" target="_blank">Read more</a> about this error.', 'rudr-simple-inventory-sync' ),
					'https://rudrastyh.com/support/site-is-not-added'
				)
			);

			$woocommerce = new Client( $url, $login, $pwd, array( 'version' => 'wc/v3', 'timeout' => 30 ) );
			// let's check WooCommerce connection first
			try {
				$woocommerce->get( 'system_status' );
			} catch( Exception $error ) {
				wp_send_json_error( $not_added_err );
			}

			// let's just get a store name
			$request2 = wp_remote_get(
				"{$url}/wp-json",
				array(
					'timeout' => 30,
				)
			);

			if( 'OK' !== wp_remote_retrieve_response_message( $request2 ) ) {
				wp_send_json_error( $not_added_err );
			}

			$body = json_decode( wp_remote_retrieve_body( $request2 ) );

			if( ! $body ) {
				wp_send_json_error( $not_added_err );
			}

			if( ! empty( $body->name ) ) {
				$added_store[ 'name' ] = $body->name;
				update_option( 'isfw_store_name', $body->name );
			}

			update_option( 'isfw_store_url', $url );
			update_option( 'isfw_username', $login );
			update_option( 'isfw_application_password', $pwd );

			wp_send_json_success(
				array(
					'message' => __( 'The store has been added.', 'rudr-simple-inventory-sync' ),
					'tr' => $this->store_tr( $added_store ),
				)
			);

		}

		public function add_store_wpmu() {

			check_ajax_referer( 'stores-actions' );

			if( ! current_user_can( 'manage_options' ) ) {
				die;
			}

			// first of all let's get overall sites array from the options
			$stores = $this->get_stores();

			// 1 store maximum
			if( ! empty( $stores ) ) {
				wp_send_json_error( new WP_Error( 'pro_required', sprintf( __( 'If you need to add more stores, please consider upgrading to the <a href="%s">PRO version</a> of the plugin.', 'rudr-simple-inventory-sync' ), 'https://rudrastyh.com/plugins/simple-product-stock-sync-for-woocommerce' ) ) );
			}

			$store_id = ! empty( $_POST[ 'new_blog_id' ] ) ? absint( $_POST[ 'new_blog_id' ] ) : 0;
			$store_url = get_blog_option( $store_id, 'siteurl' );
			$store_name = get_blog_option( $store_id, 'blogname' );

			if( ! $store_url ) {
				wp_send_json_error( new WP_Error( 'empty_fields', __( 'Unexpected error', 'rudr-simple-inventory-sync' ) ) );
			}

			update_option( 'isfw_store_id', $store_id );

			$added_store = array(
				'blog_id' => $store_id,
				'name' => $store_name,
				'url' => $store_url,
			);

			wp_send_json_success(
				array(
					'message' => __( 'The store has been added.', 'rudr-simple-inventory-sync' ),
					'tr' => $this->store_tr( $added_store ),
				)
			);

		}

		public function remove_store() {

			check_ajax_referer( 'stores-actions' );

			if( ! current_user_can( 'manage_options' ) ) {
				die;
 			}

			delete_option( 'isfw_store_url' );
			delete_option( 'isfw_username' );
			delete_option( 'isfw_application_password' );
			delete_option( 'isfw_store_id' );

			wp_send_json_success(
				array(
					'message' => __( 'No stores have been added yet.', 'rudr-simple-inventory-sync' ),
				)
			);

		}

		// (original)
		// get stores within a multisite network for select2
		public function get_stores_async_wpmu(){

			check_ajax_referer( 'stores-actions' );

			if( ! current_user_can( 'manage_options' ) ) {
				die;
			}

			$search = ! empty( $_GET[ 'q' ] ) ? $_GET[ 'q' ] : '';
			$site__not_in = ! empty( $_GET[ 'site__not_in' ] ) ? array_map( 'trim', explode( ',', $_GET[ 'site__not_in' ] ) ) : array();

			$results = array();
			$stores = get_sites(
				array(
					'search' => $search,
					'site__not_in' => $site__not_in,
					'archived' => 0,
					'deleted' => 0,
					'spam' => 0,
				)
			);
			if( $stores ) {
				foreach( $stores as $store ) {
					$results[] = array(
						'id' => $store->blog_id,
						'text' => $store->blogname
					);
				}
			}
			wp_send_json_success( $results );

		}

		public function notices() {

			$screen = get_current_screen();

			if( 'plugins' !== $screen->id && 'edit-product' !== $screen->id ) {
				return;
			}

			$ck = get_option( 'isfw_username', '' );
			$cs = get_option( 'isfw_application_password', '' );

			if( ! $ck || ! $cs ) {
				return;
			}

			if( 0 === strpos( $ck, 'ck_' ) && 0 === strpos( $cs, 'cs_' ) ) {
				// all good
				return;
			}

			?><div class="notice notice-warning"><p><?php
				printf(
					__( 'Please reconnect your store using WooCommerce REST API credentials (Consumer Key and Secret) <a href="%s">in the plugin settings</a>. Otherwise your inventory may not be synced correctly.', 'rudr-simple-inventory-sync' ),
					add_query_arg(
						array(
							'page' => 'wc-settings',
							'tab' => 'products',
							'section' => 'inventory',
						),
						'admin.php#inventory-sync'
					)
				);
			?></p></div><?php

		}

	}

	new ISFW_Product_Sync();

}
