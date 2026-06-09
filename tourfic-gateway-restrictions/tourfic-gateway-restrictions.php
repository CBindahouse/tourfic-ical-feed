<?php
/**
 * Plugin Name: Tourfic Gateway Restrictions
 * Description: Restricts WooCommerce payment gateways based on which Tourfic tours are in the cart. Configure under Settings → Tour Gateway Rules.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'TGR_VERSION', '1.0.0' );
define( 'TGR_OPTION',  'tourfic_gateway_restrictions' );

// ---------------------------------------------------------------------------
// Admin menu & settings page
// ---------------------------------------------------------------------------

add_action( 'admin_menu', 'tgr_admin_menu' );

function tgr_admin_menu() {
	add_options_page(
		'Tour Gateway Rules',
		'Tour Gateway Rules',
		'manage_options',
		'tourfic-gateway-restrictions',
		'tgr_admin_page'
	);
}

function tgr_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Handle save.
	if (
		isset( $_POST['tgr_save'] ) &&
		check_admin_referer( 'tgr_save_restrictions', 'tgr_nonce' )
	) {
		$raw          = isset( $_POST['tgr'] ) && is_array( $_POST['tgr'] ) ? $_POST['tgr'] : array();
		$restrictions = array();

		foreach ( $raw as $post_id => $gateways ) {
			$post_id = (int) $post_id;
			if ( $post_id < 1 || ! is_array( $gateways ) ) {
				continue;
			}
			$restrictions[ $post_id ] = array_map( 'sanitize_text_field', array_keys( $gateways ) );
		}

		update_option( TGR_OPTION, $restrictions );
		echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved.</strong></p></div>';
	}

	$restrictions = get_option( TGR_OPTION, array() );
	$tours        = tgr_get_tours();
	$gateways     = tgr_get_gateways();

	?>
	<div class="wrap">
		<h1>Tour Gateway Rules</h1>
		<p>
			Tick the payment gateways you want to <strong>block</strong> for each tour.
			When a blocked tour is in the cart, those payment options will be hidden at checkout.
			If multiple tours are in the cart, a gateway is blocked if <em>any</em> of them blocks it.
		</p>

		<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
			<div class="notice notice-error"><p>WooCommerce is not active. This plugin requires WooCommerce.</p></div>
		<?php elseif ( empty( $tours ) ) : ?>
			<div class="notice notice-warning"><p>No published Tourfic tours found (post type <code>tf_tours</code>). Create some tours first.</p></div>
		<?php elseif ( empty( $gateways ) ) : ?>
			<div class="notice notice-warning"><p>No WooCommerce payment gateways are configured. Please set up at least one gateway under <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); ?>">WooCommerce → Settings → Payments</a>.</p></div>
		<?php else : ?>

		<form method="post">
			<?php wp_nonce_field( 'tgr_save_restrictions', 'tgr_nonce' ); ?>
			<table class="widefat" style="margin-top:1em">
				<thead>
					<tr>
						<th style="width:260px">Tour</th>
						<?php foreach ( $gateways as $gid => $gateway ) : ?>
							<th style="text-align:center;min-width:100px">
								<?php echo esc_html( $gateway->get_title() ); ?>
								<?php if ( $gateway->enabled !== 'yes' ) : ?>
									<br><span style="font-weight:normal;font-size:11px;color:#888">(disabled)</span>
								<?php endif; ?>
							</th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tours as $tour ) :
						$blocked = $restrictions[ $tour->ID ] ?? array();
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $tour->post_title ); ?></strong><br>
							<span class="description">ID: <?php echo esc_html( $tour->ID ); ?></span>
						</td>
						<?php foreach ( $gateways as $gid => $gateway ) : ?>
							<td style="text-align:center;vertical-align:middle">
								<input
									type="checkbox"
									name="tgr[<?php echo esc_attr( $tour->ID ); ?>][<?php echo esc_attr( $gid ); ?>]"
									value="1"
									<?php checked( in_array( $gid, $blocked, true ) ); ?>
									title="Block '<?php echo esc_attr( $gateway->get_title() ); ?>' for '<?php echo esc_attr( $tour->post_title ); ?>'"
								/>
							</td>
						<?php endforeach; ?>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:1em">
				<input type="submit" name="tgr_save" class="button button-primary" value="Save Rules">
			</p>
		</form>

		<h2 style="margin-top:2em">Testing &amp; troubleshooting</h2>
		<p>
			This plugin reads the tour ID from <code>tf_tours_data[tour_id]</code> in the WooCommerce
			cart item data, which is how this version of Tourfic stores its bookings.
		</p>
		<p>
			<strong>To verify the gateway filter is working:</strong> open a private/incognito browser
			window, add a tour to the cart as a customer, and proceed to checkout. The payment gateways
			blocked for that tour should not appear.
		</p>
		<p>
			The debug button below inspects <em>your own admin cart session</em> — it is only useful if
			you have personally added a tour to your cart while logged in as admin. Customer carts are
			separate PHP sessions and are not accessible here.
		</p>
		<p>
			<a href="<?php echo esc_url( add_query_arg( 'tgr_debug', '1' ) ); ?>" class="button">
				Inspect my (admin) cart session
			</a>
		</p>

		<?php if ( isset( $_GET['tgr_debug'] ) && current_user_can( 'manage_options' ) ) : ?>
			<h3>Admin cart session debug</h3>
			<?php tgr_render_cart_debug(); ?>
		<?php endif; ?>

		<?php endif; ?>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Data helpers
// ---------------------------------------------------------------------------

function tgr_get_tours(): array {
	$posts = get_posts( array(
		'post_type'      => 'tf_tours',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	) );
	return $posts ?: array();
}

function tgr_get_gateways(): array {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return array();
	}
	return WC()->payment_gateways()->payment_gateways() ?: array();
}

/**
 * Extracts the Tourfic tour post ID from a WooCommerce cart item, or returns
 * null if the cart item is not a Tourfic booking.
 *
 * Tourfic stores its booking data under the 'tf_booking' key when calling
 * WC()->cart->add_to_cart(). The inner array contains at minimum 'post_id'.
 * A secondary lookup via product meta ('_tf_tour_id') handles edge cases where
 * some themes/versions attach the post ID differently.
 */
function tgr_get_tour_id_from_cart_item( array $cart_item ): ?int {
	if ( ! empty( $cart_item['tf_tours_data']['tour_id'] ) ) {
		return (int) $cart_item['tf_tours_data']['tour_id'];
	}

	return null;
}

// ---------------------------------------------------------------------------
// Gateway filter — runs on classic checkout and block checkout
// ---------------------------------------------------------------------------

add_filter( 'woocommerce_available_payment_gateways', 'tgr_filter_gateways' );

function tgr_filter_gateways( array $gateways ): array {
	// Only act when WooCommerce has an active cart session.
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $gateways;
	}

	$restrictions = get_option( TGR_OPTION, array() );
	if ( empty( $restrictions ) ) {
		return $gateways;
	}

	// Gather all Tourfic tour IDs present in the cart.
	$tour_ids_in_cart = array();
	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$tour_id = tgr_get_tour_id_from_cart_item( $cart_item );
		if ( $tour_id !== null ) {
			$tour_ids_in_cart[ $tour_id ] = true;
		}
	}

	if ( empty( $tour_ids_in_cart ) ) {
		return $gateways;
	}

	// Collect every gateway blocked by at least one tour in the cart.
	$blocked_gateway_ids = array();
	foreach ( array_keys( $tour_ids_in_cart ) as $tour_id ) {
		if ( ! empty( $restrictions[ $tour_id ] ) ) {
			foreach ( $restrictions[ $tour_id ] as $gid ) {
				$blocked_gateway_ids[ $gid ] = true;
			}
		}
	}

	foreach ( array_keys( $blocked_gateway_ids ) as $gid ) {
		unset( $gateways[ $gid ] );
	}

	return $gateways;
}

// ---------------------------------------------------------------------------
// Debug helper (admin-only)
// ---------------------------------------------------------------------------

function tgr_render_cart_debug() {
	if ( ! function_exists( 'WC' ) ) {
		echo '<p>WooCommerce is not active.</p>';
		return;
	}

	// WooCommerce does not initialise the cart session in the admin — force it.
	if ( function_exists( 'wc_load_cart' ) ) {
		wc_load_cart();
	}

	if ( ! WC()->cart ) {
		echo '<p>WooCommerce cart could not be loaded.</p>';
		return;
	}

	$items = WC()->cart->get_cart();

	if ( empty( $items ) ) {
		echo '<p><em>Your admin cart session is empty.</em> To use this debug tool, add a tour to your cart while logged in as admin, then return here. To test the live gateway filter, use a private browser window as a customer instead.</p>';
		return;
	}

	foreach ( $items as $key => $item ) {
		$tour_id  = tgr_get_tour_id_from_cart_item( $item );
		$detected = $tour_id !== null ? esc_html( $tour_id ) : '<em>not detected</em>';

		// Show all scalar/array keys in the cart item except the WC_Product object.
		$printable = array();
		foreach ( $item as $k => $v ) {
			if ( $k === 'data' ) {
				continue; // WC_Product object — skip.
			}
			$printable[ $k ] = $v;
		}

		echo '<h4 style="margin-bottom:4px">Cart item: <code>' . esc_html( substr( $key, 0, 12 ) ) . '…</code> &mdash; product_id: ' . esc_html( $item['product_id'] ?? '–' ) . ' &mdash; Detected tour ID: ' . $detected . '</h4>';
		echo '<pre style="background:#f6f7f7;padding:10px;overflow:auto;max-height:300px">' . esc_html( print_r( $printable, true ) ) . '</pre>';
	}
}
