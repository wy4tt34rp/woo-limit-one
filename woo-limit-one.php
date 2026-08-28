<?php
/**
 * Plugin Name: Woo Limit One Purchase
 * Plugin URI: https://appliquemarket.com/
 * Description: Prevents customers from purchasing selected WooCommerce products more than once.
 * Author: Adam Bowen; compatibility update by KO
 * Version: 2.0.0
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * WC requires at least: 9.2
 * WC tested up to: 11.0
 * Text Domain: woo-limit-one
 */

defined( 'ABSPATH' ) || exit;

const WOO_LIMIT_ONE_VERSION      = '2.0.0';
const WOO_LIMIT_ONE_ENABLED_META = 'woo_limit_one_select_dropdown';
const WOO_LIMIT_ONE_MESSAGE_META = 'woo_limit_one_purchased_text';
const WOO_LIMIT_ONE_ORPHAN_ORDERS_TRANSIENT = 'woo_limit_one_orphan_paid_orders_v1';

/**
 * Declare compatibility with WooCommerce features used by this store.
 */
function woo_limit_one_declare_compatibility() {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'woo_limit_one_declare_compatibility' );

/**
 * Add the purchase-limit controls to the product editor.
 */
function woo_limit_one_product_fields() {
	echo '<div class="options_group">';

	woocommerce_wp_select(
		array(
			'id'          => WOO_LIMIT_ONE_ENABLED_META,
			'label'       => __( 'Can only purchase once', 'woo-limit-one' ),
			'description' => __( 'Prevent a customer from purchasing this product again after a paid order.', 'woo-limit-one' ),
			'desc_tip'    => true,
			'options'     => array(
				'0' => __( 'No', 'woo-limit-one' ),
				'1' => __( 'Yes', 'woo-limit-one' ),
			),
		)
	);

	woocommerce_wp_text_input(
		array(
			'id'          => WOO_LIMIT_ONE_MESSAGE_META,
			'label'       => __( 'Purchased text', 'woo-limit-one' ),
			'placeholder' => __( 'Purchased', 'woo-limit-one' ),
			'description' => __( 'Message shown to a customer who has already purchased this product.', 'woo-limit-one' ),
			'desc_tip'    => true,
		)
	);

	echo '</div>';
}
add_action( 'woocommerce_product_options_general_product_data', 'woo_limit_one_product_fields' );

/**
 * Save the product-level purchase-limit controls.
 *
 * WooCommerce verifies the product editor nonce before this hook runs.
 *
 * @param WC_Product $product Product being saved.
 */
function woo_limit_one_save_product_fields( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	if ( isset( $_POST[ WOO_LIMIT_ONE_ENABLED_META ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$enabled = wc_clean( wp_unslash( $_POST[ WOO_LIMIT_ONE_ENABLED_META ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$product->update_meta_data( WOO_LIMIT_ONE_ENABLED_META, '1' === $enabled ? '1' : '0' );
	}

	if ( isset( $_POST[ WOO_LIMIT_ONE_MESSAGE_META ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$message = sanitize_text_field( wp_unslash( $_POST[ WOO_LIMIT_ONE_MESSAGE_META ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$product->update_meta_data( WOO_LIMIT_ONE_MESSAGE_META, $message );
	}
}
add_action( 'woocommerce_admin_process_product_object', 'woo_limit_one_save_product_fields' );

/**
 * Return the product ID that owns the purchase-limit rule.
 *
 * A variation can carry its own imported rule. Otherwise, it inherits the
 * setting and message from its parent variable product.
 *
 * @param WC_Product|int $product Product object or ID.
 * @return int
 */
function woo_limit_one_get_rule_product_id( $product ) {
	$product = is_numeric( $product ) ? wc_get_product( absint( $product ) ) : $product;

	if ( ! $product instanceof WC_Product ) {
		return 0;
	}

	$product_id = $product->get_id();

	if ( $product->is_type( 'variation' ) ) {
		if ( '1' === get_post_meta( $product_id, WOO_LIMIT_ONE_ENABLED_META, true ) ) {
			return $product_id;
		}

		$parent_id = $product->get_parent_id();
		if ( $parent_id ) {
			return $parent_id;
		}
	}

	return $product_id;
}

/**
 * Determine whether a product is limited to one paid purchase.
 *
 * @param WC_Product|int $product Product object or ID.
 * @return bool
 */
function woo_limit_one_is_limited( $product ) {
	$rule_product_id = woo_limit_one_get_rule_product_id( $product );

	return $rule_product_id && '1' === get_post_meta( $rule_product_id, WOO_LIMIT_ONE_ENABLED_META, true );
}

/**
 * Get the customer-facing message for a limited product.
 *
 * @param WC_Product|int $product Product object or ID.
 * @return string
 */
function woo_limit_one_get_message( $product ) {
	$rule_product_id = woo_limit_one_get_rule_product_id( $product );
	$message         = $rule_product_id ? get_post_meta( $rule_product_id, WOO_LIMIT_ONE_MESSAGE_META, true ) : '';

	return $message ? sanitize_text_field( $message ) : __( 'Purchased', 'woo-limit-one' );
}

/**
 * Return the paid order statuses as stored in WooCommerce lookup tables.
 *
 * @return string[]
 */
function woo_limit_one_get_paid_statuses() {
	return array_map(
		static function ( $status ) {
			$status = sanitize_key( $status );
			return 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
		},
		wc_get_is_paid_statuses()
	);
}

/**
 * Return the canonical order-item meta key for a rule-owning product.
 *
 * @param int $product_id Rule-owning product ID.
 * @return string
 */
function woo_limit_one_get_order_item_meta_key( $product_id ) {
	$product = wc_get_product( absint( $product_id ) );

	return $product instanceof WC_Product && $product->is_type( 'variation' )
		? '_variation_id'
		: '_product_id';
}

/**
 * Log a database lookup failure once per request and fail open for sales.
 *
 * @param string $context Lookup context.
 */
function woo_limit_one_log_database_error( $context ) {
	global $wpdb;

	if ( ! $wpdb->last_error ) {
		return;
	}

	static $logged = array();
	if ( isset( $logged[ $context ] ) ) {
		return;
	}

	$logged[ $context ] = true;
	wc_get_logger()->error(
		'Woo Limit One could not complete a purchase-history database lookup.',
		array(
			'source'  => 'woo-limit-one',
			'context' => sanitize_key( $context ),
		)
	);
}

/**
 * Determine whether HPOS is the authoritative order store.
 *
 * @return bool
 */
function woo_limit_one_hpos_enabled() {
	return class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Resolve every WooCommerce analytics customer ID associated with the supplied
 * account or email. Imported guest orders and account-linked orders can have
 * different customer IDs, so both identifiers are retained.
 *
 * @param string $customer_email Customer billing email.
 * @param int    $user_id        WordPress user ID.
 * @return int[]
 */
function woo_limit_one_get_customer_lookup_ids( $customer_email, $user_id ) {
	global $wpdb;

	$customer_email = sanitize_email( $customer_email );
	$user_id        = absint( $user_id );
	$cache_key      = md5( strtolower( $customer_email ) . '|' . $user_id );
	static $request_cache = array();

	if ( isset( $request_cache[ $cache_key ] ) ) {
		return $request_cache[ $cache_key ];
	}

	$customer_ids = array();

	if ( $user_id ) {
		$customer_ids = array_merge(
			$customer_ids,
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT customer_id FROM {$wpdb->prefix}wc_customer_lookup WHERE user_id = %d",
					$user_id
				)
			)
		);
	}

	if ( $customer_email ) {
		$customer_ids = array_merge(
			$customer_ids,
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT customer_id FROM {$wpdb->prefix}wc_customer_lookup WHERE email = %s",
					$customer_email
				)
			)
		);
	}

	woo_limit_one_log_database_error( 'customer_lookup' );

	$request_cache[ $cache_key ] = array_values(
		array_unique(
			array_filter(
				array_map( 'absint', $customer_ids )
			)
		)
	);

	return $request_cache[ $cache_key ];
}

/**
 * Run the normal indexed purchase-history check. This uses WooCommerce's
 * customer and order statistics tables to identify orders, then verifies the
 * canonical product or variation ID stored on the order item.
 *
 * @param int    $product_id     Product or variation ID to find.
 * @param string $customer_email Customer billing email.
 * @param int    $user_id        WordPress user ID.
 * @return bool
 */
function woo_limit_one_lookup_customer_bought_product( $product_id, $customer_email, $user_id ) {
	global $wpdb;

	$product_id       = absint( $product_id );
	$customer_email   = sanitize_email( $customer_email );
	$user_id          = absint( $user_id );
	$product_meta_key = woo_limit_one_get_order_item_meta_key( $product_id );
	$paid_statuses_sql = "'" . implode( "','", array_map( 'esc_sql', woo_limit_one_get_paid_statuses() ) ) . "'";

	if ( woo_limit_one_hpos_enabled() ) {
		$identity_checks = array();
		if ( $user_id ) {
			$identity_checks[] = array( 'orders.customer_id = %d', $user_id );
		}
		if ( $customer_email ) {
			$identity_checks[] = array( 'orders.billing_email = %s', $customer_email );
		}

		foreach ( $identity_checks as $identity_check ) {
			$sql = "SELECT 1
				FROM {$wpdb->prefix}wc_orders AS orders
				STRAIGHT_JOIN {$wpdb->prefix}woocommerce_order_items AS items
					ON items.order_id = orders.id AND items.order_item_type = 'line_item'
				STRAIGHT_JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS products
					ON products.order_item_id = items.order_item_id
				WHERE orders.type = 'shop_order'
					AND orders.status IN ($paid_statuses_sql)
					AND {$identity_check[0]}
					AND products.meta_key = %s
					AND products.meta_value = %s
				LIMIT 1";

			$result = $wpdb->get_var(
				$wpdb->prepare(
					$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$identity_check[1],
					$product_meta_key,
					(string) $product_id
				)
			);

			if ( $wpdb->last_error ) {
				woo_limit_one_log_database_error( 'hpos_history' );
				return false;
			}

			if ( $result ) {
				return true;
			}
		}

		return false;
	}

	$customer_ids = woo_limit_one_get_customer_lookup_ids( $customer_email, $user_id );
	if ( ! $customer_ids ) {
		return false;
	}

	$customer_ids_sql = implode( ',', array_map( 'absint', $customer_ids ) );

	$sql = "SELECT 1
		FROM {$wpdb->prefix}wc_order_stats AS orders
		STRAIGHT_JOIN {$wpdb->posts} AS order_posts
			ON order_posts.ID = orders.order_id AND order_posts.post_type = 'shop_order'
		STRAIGHT_JOIN {$wpdb->prefix}woocommerce_order_items AS items
			ON items.order_id = orders.order_id AND items.order_item_type = 'line_item'
		STRAIGHT_JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS products
			ON products.order_item_id = items.order_item_id
		WHERE orders.parent_id = 0
			AND orders.customer_id IN ($customer_ids_sql)
			AND order_posts.post_status IN ($paid_statuses_sql)
			AND products.meta_key = %s
			AND products.meta_value = %s
		LIMIT 1";

	$result = $wpdb->get_var(
		$wpdb->prepare(
			$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$product_meta_key,
			(string) $product_id
		)
	);

	if ( $wpdb->last_error ) {
		woo_limit_one_log_database_error( 'legacy_history' );
		return false;
	}

	return (bool) $result;
}

/**
 * Return product IDs from recently paid orders that may not have reached the
 * asynchronous analytics tables yet.
 *
 * @param string $customer_email Customer billing email.
 * @param int    $user_id        WordPress user ID.
 * @return int[]
 */
function woo_limit_one_get_recent_purchased_product_ids( $customer_email, $user_id ) {
	global $wpdb;

	if ( woo_limit_one_hpos_enabled() ) {
		return array();
	}

	$customer_email = sanitize_email( $customer_email );
	$user_id        = absint( $user_id );
	$cache_key      = md5( strtolower( $customer_email ) . '|' . $user_id );
	static $request_cache = array();

	if ( isset( $request_cache[ $cache_key ] ) ) {
		return $request_cache[ $cache_key ];
	}

	$paid_statuses_sql = "'" . implode( "','", array_map( 'esc_sql', woo_limit_one_get_paid_statuses() ) ) . "'";
	$identity           = array();
	$args               = array();

	if ( $user_id ) {
		$identity[] = "( identity.meta_key = '_customer_user' AND identity.meta_value = %d )";
		$args[]     = $user_id;
	}
	if ( $customer_email ) {
		$identity[] = "( identity.meta_key = '_billing_email' AND identity.meta_value = %s )";
		$args[]     = $customer_email;
	}

	if ( ! $identity ) {
		$request_cache[ $cache_key ] = array();
		return array();
	}

	$cutoff = wp_date( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
	$sql    = "SELECT DISTINCT CAST(products.meta_value AS UNSIGNED)
		FROM {$wpdb->posts} AS orders
		STRAIGHT_JOIN {$wpdb->postmeta} AS identity ON identity.post_id = orders.ID
		STRAIGHT_JOIN {$wpdb->prefix}woocommerce_order_items AS items
			ON items.order_id = orders.ID AND items.order_item_type = 'line_item'
		STRAIGHT_JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS products
			ON products.order_item_id = items.order_item_id
		WHERE orders.post_type = 'shop_order'
			AND orders.post_status IN ($paid_statuses_sql)
			AND orders.post_date >= %s
			AND (" . implode( ' OR ', $identity ) . ")
			AND products.meta_key IN ('_product_id', '_variation_id')
			AND products.meta_value != '0'";

	$results = $wpdb->get_col(
		$wpdb->prepare(
			$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			array_merge( array( $cutoff ), $args )
		)
	);

	if ( $wpdb->last_error ) {
		woo_limit_one_log_database_error( 'recent_orders' );
		$request_cache[ $cache_key ] = array();
		return array();
	}

	$request_cache[ $cache_key ] = array_values(
		array_unique(
			array_filter( array_map( 'absint', $results ) )
		)
	);

	return $request_cache[ $cache_key ];
}

/**
 * Get paid orders that are present in analytics order stats but have no
 * customer lookup row. This small imported-order exception list is cached in
 * a database transient and invalidated when order status or analytics data
 * changes.
 *
 * @return int[]
 */
function woo_limit_one_get_orphan_paid_order_ids() {
	global $wpdb;

	if ( woo_limit_one_hpos_enabled() ) {
		return array();
	}

	static $request_cache = null;
	if ( null !== $request_cache ) {
		return $request_cache;
	}

	$cached = get_transient( WOO_LIMIT_ONE_ORPHAN_ORDERS_TRANSIENT );
	if ( is_array( $cached ) ) {
		$request_cache = array_map( 'absint', $cached );
		return $request_cache;
	}

	$paid_statuses_sql = "'" . implode( "','", array_map( 'esc_sql', woo_limit_one_get_paid_statuses() ) ) . "'";
	$request_cache     = array_map(
		'absint',
		$wpdb->get_col(
			"SELECT orders.order_id
			FROM {$wpdb->prefix}wc_order_stats AS orders
			STRAIGHT_JOIN {$wpdb->posts} AS order_posts
				ON order_posts.ID = orders.order_id AND order_posts.post_type = 'shop_order'
			LEFT JOIN {$wpdb->prefix}wc_customer_lookup AS customers
				ON customers.customer_id = orders.customer_id
			WHERE orders.parent_id = 0
				AND order_posts.post_status IN ($paid_statuses_sql)
				AND customers.customer_id IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		)
	);

	if ( $wpdb->last_error ) {
		woo_limit_one_log_database_error( 'orphan_orders' );
		$request_cache = array();
		return array();
	}

	set_transient( WOO_LIMIT_ONE_ORPHAN_ORDERS_TRANSIENT, $request_cache, 6 * HOUR_IN_SECONDS );

	return $request_cache;
}

/**
 * Return product IDs from imported paid orders that have no customer lookup
 * row but still retain a WordPress customer ID or billing email.
 *
 * @param string $customer_email Customer billing email.
 * @param int    $user_id        WordPress user ID.
 * @return int[]
 */
function woo_limit_one_get_orphan_purchased_product_ids( $customer_email, $user_id ) {
	global $wpdb;

	if ( woo_limit_one_hpos_enabled() ) {
		return array();
	}

	$customer_email = sanitize_email( $customer_email );
	$user_id        = absint( $user_id );
	$cache_key      = md5( strtolower( $customer_email ) . '|' . $user_id );
	static $request_cache = array();

	if ( isset( $request_cache[ $cache_key ] ) ) {
		return $request_cache[ $cache_key ];
	}

	$order_ids = woo_limit_one_get_orphan_paid_order_ids();
	if ( ! $order_ids ) {
		$request_cache[ $cache_key ] = array();
		return array();
	}

	$order_ids_sql     = implode( ',', array_map( 'absint', $order_ids ) );
	$paid_statuses_sql = "'" . implode( "','", array_map( 'esc_sql', woo_limit_one_get_paid_statuses() ) ) . "'";
	$identity          = array();
	$args              = array();

	if ( $user_id ) {
		$identity[] = "( identity.meta_key = '_customer_user' AND identity.meta_value = %d )";
		$args[]     = $user_id;
	}
	if ( $customer_email ) {
		$identity[] = "( identity.meta_key = '_billing_email' AND identity.meta_value = %s )";
		$args[]     = $customer_email;
	}

	if ( ! $identity ) {
		$request_cache[ $cache_key ] = array();
		return array();
	}

	$sql = "SELECT DISTINCT CAST(products.meta_value AS UNSIGNED)
		FROM {$wpdb->posts} AS orders
		STRAIGHT_JOIN {$wpdb->postmeta} AS identity ON identity.post_id = orders.ID
		STRAIGHT_JOIN {$wpdb->prefix}woocommerce_order_items AS items
			ON items.order_id = orders.ID AND items.order_item_type = 'line_item'
		STRAIGHT_JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS products
			ON products.order_item_id = items.order_item_id
		WHERE orders.ID IN ($order_ids_sql)
			AND orders.post_type = 'shop_order'
			AND orders.post_status IN ($paid_statuses_sql)
			AND (" . implode( ' OR ', $identity ) . ")
			AND products.meta_key IN ('_product_id', '_variation_id')
			AND products.meta_value != '0'";

	$results = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	if ( $wpdb->last_error ) {
		woo_limit_one_log_database_error( 'orphan_products' );
		$request_cache[ $cache_key ] = array();
		return array();
	}

	$request_cache[ $cache_key ] = array_values(
		array_unique(
			array_filter( array_map( 'absint', $results ) )
		)
	);

	return $request_cache[ $cache_key ];
}

/**
 * Invalidate the imported-order exception list after order or analytics state
 * changes.
 */
function woo_limit_one_clear_orphan_order_cache() {
	delete_transient( WOO_LIMIT_ONE_ORPHAN_ORDERS_TRANSIENT );
}
add_action( 'woocommerce_order_status_changed', 'woo_limit_one_clear_orphan_order_cache', 20, 0 );
add_action( 'woocommerce_order_scheduler_after_import_order', 'woo_limit_one_clear_orphan_order_cache', 20, 0 );

/**
 * Check a customer's paid-order history for a limited product.
 *
 * The core WooCommerce helper aggregates every purchased product through the
 * legacy order postmeta tables. On this store's 345,000-order database that
 * query can exceed 30 seconds, so this function uses indexed analytics identity
 * and order tables with canonical order-item metadata instead.
 *
 * @param WC_Product|int $product        Product object or ID.
 * @param string         $customer_email Customer billing email, when known.
 * @param int|null       $user_id        Customer user ID, or null for current user.
 * @return bool
 */
function woo_limit_one_customer_bought_product( $product, $customer_email = '', $user_id = null ) {
	if ( ! woo_limit_one_is_limited( $product ) ) {
		return false;
	}

	if ( null === $user_id ) {
		$user_id = get_current_user_id();
		if ( $user_id && ! $customer_email ) {
			$current_user   = wp_get_current_user();
			$customer_email = $current_user->user_email;
		}
	}

	$user_id        = absint( $user_id );
	$customer_email = sanitize_email( $customer_email );

	if ( ! $user_id && ! $customer_email ) {
		return false;
	}

	$rule_product_id = woo_limit_one_get_rule_product_id( $product );
	$cache_key       = md5( strtolower( $customer_email ) . '|' . $user_id . '|' . $rule_product_id );
	static $request_cache = array();

	if ( array_key_exists( $cache_key, $request_cache ) ) {
		return $request_cache[ $cache_key ];
	}

	$bought = woo_limit_one_lookup_customer_bought_product( $rule_product_id, $customer_email, $user_id );

	if ( ! $bought ) {
		$bought = in_array(
			$rule_product_id,
			woo_limit_one_get_recent_purchased_product_ids( $customer_email, $user_id ),
			true
		);
	}

	if ( ! $bought ) {
		$bought = in_array(
			$rule_product_id,
			woo_limit_one_get_orphan_purchased_product_ids( $customer_email, $user_id ),
			true
		);
	}

	$request_cache[ $cache_key ] = $bought;

	return $request_cache[ $cache_key ];
}

/**
 * Prevent WooCommerce from presenting a product as purchasable to a logged-in
 * customer who has already bought it.
 *
 * @param bool       $purchasable Existing purchasable state.
 * @param WC_Product $product     Product being checked.
 * @return bool
 */
function woo_limit_one_filter_is_purchasable( $purchasable, $product ) {
	if ( ! $purchasable || ! $product instanceof WC_Product ) {
		return $purchasable;
	}

	if ( is_admin() && ! wp_doing_ajax() ) {
		return $purchasable;
	}

	// Let the dedicated Store API validator provide the configured error.
	if ( WC()->cart && 'store-api' === WC()->cart->cart_context ) {
		return $purchasable;
	}

	return woo_limit_one_customer_bought_product( $product ) ? false : $purchasable;
}
add_filter( 'woocommerce_is_purchasable', 'woo_limit_one_filter_is_purchasable', 20, 2 );

/**
 * Replace the catalog add-to-cart link with the configured purchased message.
 *
 * @param string     $html    Current loop add-to-cart markup.
 * @param WC_Product $product Product represented by the link.
 * @param array      $args    Template arguments.
 * @return string
 */
function woo_limit_one_loop_add_to_cart_link( $html, $product, $args ) {
	unset( $args );

	if ( $product instanceof WC_Product && woo_limit_one_customer_bought_product( $product ) ) {
		return '<span class="woo-limit-one">' . esc_html( woo_limit_one_get_message( $product ) ) . '</span>';
	}

	return $html;
}
add_filter( 'woocommerce_loop_add_to_cart_link', 'woo_limit_one_loop_add_to_cart_link', 20, 3 );

/**
 * Suppress WooCommerce's single-product form for a previous purchaser. This is
 * necessary for variable products, whose template does not exit solely because
 * the parent product is no longer purchasable.
 */
function woo_limit_one_maybe_suppress_single_add_to_cart() {
	global $product;

	if ( $product instanceof WC_Product && woo_limit_one_customer_bought_product( $product ) ) {
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
	}
}
add_action( 'woocommerce_single_product_summary', 'woo_limit_one_maybe_suppress_single_add_to_cart', 29 );

/**
 * Show the configured message on a single-product page after WooCommerce's
 * normal add-to-cart location.
 */
function woo_limit_one_single_product_message() {
	global $product;

	if ( $product instanceof WC_Product && woo_limit_one_customer_bought_product( $product ) ) {
		echo '<p class="woo-limit-one">' . esc_html( woo_limit_one_get_message( $product ) ) . '</p>';
	}
}
add_action( 'woocommerce_single_product_summary', 'woo_limit_one_single_product_message', 31 );

/**
 * Replace a purchased grouped child's quantity control with its message.
 *
 * @param string     $value   Existing quantity-column markup.
 * @param WC_Product $product Grouped child product.
 * @return string
 */
function woo_limit_one_grouped_quantity_column( $value, $product ) {
	if ( $product instanceof WC_Product && woo_limit_one_customer_bought_product( $product ) ) {
		return '<span class="woo-limit-one">' . esc_html( woo_limit_one_get_message( $product ) ) . '</span>';
	}

	return $value;
}
add_filter( 'woocommerce_grouped_product_list_column_quantity', 'woo_limit_one_grouped_quantity_column', 20, 2 );

/**
 * Add one error notice without duplicating it during grouped-product checks.
 *
 * @param WC_Product $product Product blocked from purchase.
 */
function woo_limit_one_add_error_notice( $product ) {
	$message = woo_limit_one_get_error_message( $product );

	if ( ! wc_has_notice( $message, 'error' ) ) {
		wc_add_notice( $message, 'error' );
	}
}

/**
 * Build the validation error shown across classic and Store API requests.
 *
 * @param WC_Product $product Product blocked from purchase.
 * @return string
 */
function woo_limit_one_get_error_message( $product ) {
	return sprintf(
		/* translators: 1: product name, 2: configured purchased message. */
		__( '%1$s cannot be purchased again. %2$s', 'woo-limit-one' ),
		$product->get_name(),
		woo_limit_one_get_message( $product )
	);
}

/**
 * Enforce the rule for classic, AJAX, and grouped-product add-to-cart requests.
 * Store API requests use the dedicated validator below.
 *
 * @param bool  $passed_validation Existing validation result.
 * @param int   $product_id        Product ID.
 * @param float $quantity          Requested quantity.
 * @param int   $variation_id      Variation ID, when applicable.
 * @param array $variation      Variation attributes.
 * @param array $cart_item_data Additional cart item data.
 * @return bool
 */
function woo_limit_one_add_to_cart_validation( $passed_validation, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
	unset( $quantity, $variation, $cart_item_data );

	if ( ! $passed_validation || ( WC()->cart && 'store-api' === WC()->cart->cart_context ) ) {
		return $passed_validation;
	}

	$product = wc_get_product( $variation_id ? $variation_id : $product_id );

	if ( $product instanceof WC_Product && woo_limit_one_customer_bought_product( $product ) ) {
		woo_limit_one_add_error_notice( $product );
		return false;
	}

	return true;
}
add_filter( 'woocommerce_add_to_cart_validation', 'woo_limit_one_add_to_cart_validation', 20, 6 );

/**
 * Enforce the rule on the dedicated WooCommerce Store API add-to-cart path.
 *
 * @param WC_Product $product Product being added.
 * @param array      $request Store API request data.
 * @throws Automattic\WooCommerce\StoreApi\Exceptions\RouteException When blocked.
 */
function woo_limit_one_store_api_add_to_cart_validation( $product, $request ) {
	unset( $request );

	if ( $product instanceof WC_Product && woo_limit_one_customer_bought_product( $product ) ) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			'woo_limit_one_already_purchased',
			woo_limit_one_get_error_message( $product ),
			409
		);
	}
}
add_action( 'woocommerce_store_api_validate_add_to_cart', 'woo_limit_one_store_api_add_to_cart_validation', 20, 2 );

/**
 * Validate products already in the cart after a customer logs in or restores a
 * saved session. The cart is left intact so the customer can review the error.
 */
function woo_limit_one_check_cart_items() {
	if ( ! WC()->cart || ! get_current_user_id() || is_checkout() || 'store-api' === WC()->cart->cart_context ) {
		return;
	}

	$checked_rules = array();

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : false;
		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$rule_product_id = woo_limit_one_get_rule_product_id( $product );
		if ( isset( $checked_rules[ $rule_product_id ] ) ) {
			continue;
		}

		$checked_rules[ $rule_product_id ] = true;
		if ( woo_limit_one_customer_bought_product( $product ) ) {
			woo_limit_one_add_error_notice( $product );
		}
	}
}
add_action( 'woocommerce_check_cart_items', 'woo_limit_one_check_cart_items' );

/**
 * Use the configured message when WooCommerce rejects a non-purchasable item.
 *
 * @param string     $message Existing message.
 * @param WC_Product $product Rejected product.
 * @return string
 */
function woo_limit_one_cannot_purchase_message( $message, $product ) {
	if ( $product instanceof WC_Product && woo_limit_one_customer_bought_product( $product ) ) {
		return woo_limit_one_get_error_message( $product );
	}

	return $message;
}
add_filter( 'woocommerce_cart_product_cannot_be_purchased_message', 'woo_limit_one_cannot_purchase_message', 20, 2 );
add_filter( 'woocommerce_cart_item_removed_message', 'woo_limit_one_cannot_purchase_message', 20, 2 );

/**
 * Add purchase-history errors to Store API cart validation.
 *
 * @param WP_Error $errors Validation errors.
 * @param WC_Cart  $cart   Store API cart.
 */
function woo_limit_one_store_api_cart_errors( $errors, $cart ) {
	if ( ! $errors instanceof WP_Error || ! $cart instanceof WC_Cart || ! get_current_user_id() ) {
		return;
	}

	$checked_rules = array();

	foreach ( $cart->get_cart() as $cart_item ) {
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : false;
		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$rule_product_id = woo_limit_one_get_rule_product_id( $product );
		if ( isset( $checked_rules[ $rule_product_id ] ) ) {
			continue;
		}

		$checked_rules[ $rule_product_id ] = true;
		if ( woo_limit_one_customer_bought_product( $product ) ) {
			$errors->add( 'woo_limit_one_' . $rule_product_id, woo_limit_one_get_error_message( $product ) );
		}
	}
}
add_action( 'woocommerce_store_api_cart_errors', 'woo_limit_one_store_api_cart_errors', 20, 2 );

/**
 * Perform the final Store API checkout check after its billing email has been
 * copied into the customer object.
 *
 * @param WC_Customer $customer Checkout customer.
 * @param mixed       $request  Store API request.
 * @throws Automattic\WooCommerce\StoreApi\Exceptions\RouteException When blocked.
 */
function woo_limit_one_store_api_checkout_customer_validation( $customer, $request ) {
	unset( $request );

	if ( ! $customer instanceof WC_Customer || ! WC()->cart ) {
		return;
	}

	$customer_email = sanitize_email( $customer->get_billing_email() );
	$user_id        = $customer->get_id();
	$checked_rules  = array();

	if ( ! $customer_email && ! $user_id ) {
		return;
	}

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : false;
		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$rule_product_id = woo_limit_one_get_rule_product_id( $product );
		if ( isset( $checked_rules[ $rule_product_id ] ) ) {
			continue;
		}

		$checked_rules[ $rule_product_id ] = true;
		if ( woo_limit_one_customer_bought_product( $product, $customer_email, $user_id ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'woo_limit_one_' . $rule_product_id,
				woo_limit_one_get_error_message( $product ),
				409
			);
		}
	}
}
add_action( 'woocommerce_store_api_checkout_update_customer_from_request', 'woo_limit_one_store_api_checkout_customer_validation', 20, 2 );

/**
 * Enforce the rule for guest customers once checkout supplies a billing email.
 *
 * @param array    $data   Posted checkout data.
 * @param WP_Error $errors Checkout validation errors.
 */
function woo_limit_one_checkout_validation( $data, $errors ) {
	if ( ! WC()->cart || ! $errors instanceof WP_Error ) {
		return;
	}

	$customer_email = isset( $data['billing_email'] ) ? sanitize_email( $data['billing_email'] ) : '';
	$user_id        = get_current_user_id();

	if ( ! $user_id && ! $customer_email ) {
		return;
	}

	$checked_rules = array();

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : false;
		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$rule_product_id = woo_limit_one_get_rule_product_id( $product );
		if ( isset( $checked_rules[ $rule_product_id ] ) ) {
			continue;
		}

		$checked_rules[ $rule_product_id ] = true;
		if ( woo_limit_one_customer_bought_product( $product, $customer_email, $user_id ) ) {
			$errors->add(
				'woo_limit_one_' . $rule_product_id,
				woo_limit_one_get_error_message( $product )
			);
		}
	}
}
add_action( 'woocommerce_after_checkout_validation', 'woo_limit_one_checkout_validation', 20, 2 );
