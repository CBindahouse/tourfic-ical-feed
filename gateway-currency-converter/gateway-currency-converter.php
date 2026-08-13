<?php
/**
 * Plugin Name: Gateway Currency Converter
 * Description: Converts WooCommerce checkout prices to a per-gateway currency. Exchange rates are fetched daily from ExchangeRate-API (free, no key required). Optionally accepts an API key for higher reliability. Configure under Settings → Gateway Currency.
 * Version: 1.0.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'GCC_VERSION',       '1.0.1' );
define( 'GCC_OPT_MAPPINGS',  'gcc_gateway_mappings' );  // [gateway_id => currency_code]
define( 'GCC_OPT_RATES',     'gcc_exchange_rates' );    // {base, rates, updated}
define( 'GCC_OPT_API_KEY',   'gcc_api_key' );
define( 'GCC_CRON_HOOK',     'gcc_daily_rate_update' );

// ---------------------------------------------------------------------------
// Activation / deactivation
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, 'gcc_activate' );
register_deactivation_hook( __FILE__, 'gcc_deactivate' );

function gcc_activate(): void {
	gcc_fetch_and_store_rates();
	if ( ! wp_next_scheduled( GCC_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'daily', GCC_CRON_HOOK );
	}
}

function gcc_deactivate(): void {
	wp_clear_scheduled_hook( GCC_CRON_HOOK );
}

add_action( GCC_CRON_HOOK, 'gcc_fetch_and_store_rates' );

// ---------------------------------------------------------------------------
// Exchange rate fetching
// ---------------------------------------------------------------------------

function gcc_fetch_and_store_rates(): bool {
	if ( ! function_exists( 'get_woocommerce_currency' ) ) {
		return false;
	}

	$base    = gcc_base_currency();
	$api_key = get_option( GCC_OPT_API_KEY, '' );

	$url = $api_key
		? "https://v6.exchangerate-api.com/v6/{$api_key}/latest/{$base}"
		: "https://open.er-api.com/v6/latest/{$base}";

	$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

	if ( is_wp_error( $response ) ) {
		update_option( 'gcc_last_error', $response->get_error_message() );
		return false;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ( $data['result'] ?? '' ) !== 'success' || empty( $data['rates'] ) ) {
		update_option( 'gcc_last_error', 'Unexpected API response: ' . wp_remote_retrieve_body( $response ) );
		return false;
	}

	delete_option( 'gcc_last_error' );
	update_option( GCC_OPT_RATES, array(
		'base'    => $base,
		'rates'   => $data['rates'],
		'updated' => time(),
	) );

	return true;
}

/**
 * Returns the stored rate for $target relative to the WooCommerce base currency,
 * or null if unknown.
 */
function gcc_get_rate( string $target ): ?float {
	$base = gcc_base_currency();

	if ( $target === $base ) {
		return 1.0;
	}

	$stored = get_option( GCC_OPT_RATES, array() );

	if ( empty( $stored['rates'] ) || ( $stored['base'] ?? '' ) !== $base ) {
		return null;
	}

	return isset( $stored['rates'][ $target ] ) ? (float) $stored['rates'][ $target ] : null;
}

/**
 * Reads the WooCommerce base currency directly from the option, bypassing any
 * filters (including our own gcc_filter_currency), to avoid infinite recursion.
 */
function gcc_base_currency(): string {
	return (string) get_option( 'woocommerce_currency', 'EUR' );
}

// ---------------------------------------------------------------------------
// Capture payment method at the earliest possible point in each request
// ---------------------------------------------------------------------------

/**
 * Stores/reads the payment method for the current PHP request.
 * Called at priority 1 on woocommerce_checkout_update_order_review so we parse
 * the serialised form data before WooCommerce does any further processing.
 * Also called from woocommerce_checkout_process for the final order submission.
 */
function gcc_capture_payment_method( string $post_data ): void {
	// WooCommerce checkout.js sends payment_method as BOTH a direct POST field and
	// inside the serialised post_data string. The direct field is set explicitly to
	// the radio button the user ticked. The post_data serialisation can be polluted
	// by gateway plugins (e.g. Peach Payments) that inject a hidden
	// input[name="payment_method"] into the form — those hidden fields appear last
	// in the serialised string and override the user's selection when parse_str()
	// is used. Always prefer the direct POST field.
	$method = '';

	if ( ! empty( $_POST['payment_method'] ) ) {
		$method = sanitize_text_field( wp_unslash( $_POST['payment_method'] ) );
	}

	if ( ! $method ) {
		parse_str( $post_data, $parsed );
		$method = sanitize_text_field( $parsed['payment_method'] ?? '' );
	}

	if ( $method ) {
		gcc_request_gateway( $method );
	}
}
add_action( 'woocommerce_checkout_update_order_review', 'gcc_capture_payment_method', 1 );

// Final checkout submit — $_POST fields are available directly.
add_action( 'woocommerce_checkout_process', function (): void {
	if ( ! empty( $_POST['payment_method'] ) ) {
		gcc_request_gateway( sanitize_text_field( $_POST['payment_method'] ) );
	}
}, 1 );

/** Gets or sets the request-scoped payment method captured from the checkout form. */
function gcc_request_gateway( ?string $set = null ): string {
	static $method = null;
	if ( $set !== null ) {
		$method = $set;
	}
	return $method ?? '';
}

// ---------------------------------------------------------------------------
// Conversion context
// ---------------------------------------------------------------------------

/**
 * Returns ['currency' => 'MUR', 'rate' => 55.14] when a conversion is active,
 * or null when outside checkout or no mapping is configured for the current gateway.
 *
 * Resolution order for the gateway ID:
 *   1. Value captured from the raw post_data at priority 1 (reliable for AJAX calls).
 *   2. WooCommerce's own chosen_payment_method session (set later by WC itself).
 *   3. First available gateway — fallback for the very first page load before any
 *      AJAX has run and before WC has seeded the session.
 */
function gcc_active_conversion(): ?array {
	static $cache_key = null;
	static $cache_val = null;
	static $resolving = false;

	if ( ! is_checkout() ) {
		return null;
	}

	// Re-entrancy guard: resolving step 3 below instantiates the payment gateways,
	// and gateways such as PayPal call get_woocommerce_currency() from their
	// constructor. That runs back through the woocommerce_currency filter into
	// gcc_filter_currency(), which calls this function again before $gateway_id
	// has been resolved — causing infinite recursion. Bail out on the re-entrant
	// call; at that point no gateway is known yet anyway.
	if ( $resolving ) {
		return null;
	}

	// 1. From the raw checkout form data captured at priority 1.
	$gateway_id = gcc_request_gateway();

	// 2. WooCommerce's session (set by WC after our action fires but before calculate_totals).
	if ( ! $gateway_id && WC()->session ) {
		$gateway_id = (string) ( WC()->session->get( 'chosen_payment_method' ) ?? '' );
	}

	// 3. First available gateway (initial page load with no session yet).
	if ( ! $gateway_id ) {
		$resolving = true;
		$gateways   = WC()->payment_gateways()->get_available_payment_gateways();
		$resolving = false;
		$gateway_id = ! empty( $gateways ) ? (string) array_key_first( $gateways ) : '';
	}

	if ( ! $gateway_id ) {
		return null;
	}

	if ( $cache_key === $gateway_id ) {
		return $cache_val;
	}

	$cache_key = $gateway_id;

	$mappings = get_option( GCC_OPT_MAPPINGS, array() );
	$target   = $mappings[ $gateway_id ] ?? '';

	if ( ! $target ) {
		$cache_val = null;
		return null;
	}

	$rate = gcc_get_rate( $target );

	if ( $rate === null ) {
		$cache_val = null;
		return null;
	}

	$cache_val = array( 'currency' => $target, 'rate' => $rate );
	return $cache_val;
}

// ---------------------------------------------------------------------------
// JavaScript: ensure checkout update fires when payment method changes
// ---------------------------------------------------------------------------

add_action( 'wp_footer', 'gcc_checkout_footer_script' );

function gcc_checkout_footer_script(): void {
	if ( ! is_checkout() ) {
		return;
	}
	?>
	<script id="gcc-checkout-js">
	jQuery(function($){
		$(document).on('change', 'input[name="payment_method"]', function(){
			$(document.body).trigger('update_checkout');
		});
	});
	</script>
	<?php
}

// ---------------------------------------------------------------------------
// Price conversion filters
// ---------------------------------------------------------------------------

add_filter( 'woocommerce_product_get_price',          'gcc_convert_price', 99, 2 );
add_filter( 'woocommerce_product_get_regular_price',  'gcc_convert_price', 99, 2 );
add_filter( 'woocommerce_product_get_sale_price',     'gcc_convert_price', 99, 2 );

function gcc_convert_price( $price, $product ) {
	if ( $price === '' || $price === false || $price === null ) {
		return $price;
	}
	$conv = gcc_active_conversion();
	if ( ! $conv ) {
		return $price;
	}
	return (string) round( (float) $price * $conv['rate'], wc_get_price_decimals() );
}

// Shipping rates.
add_filter( 'woocommerce_package_rates', 'gcc_convert_shipping_rates', 99 );

function gcc_convert_shipping_rates( array $rates ): array {
	$conv = gcc_active_conversion();
	if ( ! $conv ) {
		return $rates;
	}
	$decimals = wc_get_price_decimals();
	foreach ( $rates as $rate ) {
		$rate->cost = (string) round( (float) $rate->cost * $conv['rate'], $decimals );
		foreach ( $rate->taxes as $k => $tax ) {
			$rate->taxes[ $k ] = (string) round( (float) $tax * $conv['rate'], $decimals );
		}
	}
	return $rates;
}

// Currency symbol / code — drives the symbol shown and the currency stored on the order.
add_filter( 'woocommerce_currency', 'gcc_filter_currency', 99 );

function gcc_filter_currency( string $currency ): string {
	$conv = gcc_active_conversion();
	return $conv ? $conv['currency'] : $currency;
}

// ---------------------------------------------------------------------------
// Record conversion on the order
// ---------------------------------------------------------------------------

add_action( 'woocommerce_checkout_create_order', 'gcc_save_order_meta', 10, 2 );

function gcc_save_order_meta( WC_Order $order, array $data ): void {
	$conv = gcc_active_conversion();
	if ( ! $conv ) {
		return;
	}
	$order->update_meta_data( '_gcc_base_currency',   gcc_base_currency() );
	$order->update_meta_data( '_gcc_target_currency', $conv['currency'] );
	$order->update_meta_data( '_gcc_exchange_rate',   $conv['rate'] );
}

// ---------------------------------------------------------------------------
// Admin menu & settings page
// ---------------------------------------------------------------------------

add_action( 'admin_menu', 'gcc_admin_menu' );

function gcc_admin_menu(): void {
	add_options_page(
		'Gateway Currency',
		'Gateway Currency',
		'manage_options',
		'gcc-gateway-currency',
		'gcc_admin_page'
	);
}

function gcc_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		echo '<div class="wrap"><h1>Gateway Currency Converter</h1><div class="notice notice-error"><p>WooCommerce is not active.</p></div></div>';
		return;
	}

	// Save settings.
	if ( isset( $_POST['gcc_save'] ) && check_admin_referer( 'gcc_save', 'gcc_nonce' ) ) {
		update_option( GCC_OPT_API_KEY, sanitize_text_field( $_POST['gcc_api_key'] ?? '' ) );

		$raw      = isset( $_POST['gcc_map'] ) && is_array( $_POST['gcc_map'] ) ? $_POST['gcc_map'] : array();
		$mappings = array();
		foreach ( $raw as $gid => $currency ) {
			$currency = sanitize_text_field( $currency );
			if ( $currency ) {
				$mappings[ sanitize_text_field( $gid ) ] = $currency;
			}
		}
		update_option( GCC_OPT_MAPPINGS, $mappings );

		echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved.</strong></p></div>';
	}

	// Manual rate refresh.
	if ( isset( $_POST['gcc_refresh'] ) && check_admin_referer( 'gcc_refresh', 'gcc_refresh_nonce' ) ) {
		if ( gcc_fetch_and_store_rates() ) {
			echo '<div class="notice notice-success is-dismissible"><p>Exchange rates refreshed successfully.</p></div>';
		} else {
			$err = get_option( 'gcc_last_error', 'Unknown error.' );
			echo '<div class="notice notice-error is-dismissible"><p>Failed to fetch rates: ' . esc_html( $err ) . '</p></div>';
		}
	}

	$api_key    = get_option( GCC_OPT_API_KEY, '' );
	$mappings   = get_option( GCC_OPT_MAPPINGS, array() );
	$gateways   = gcc_get_all_gateways();
	$currencies = get_woocommerce_currencies();
	$stored     = get_option( GCC_OPT_RATES, array() );
	$base       = gcc_base_currency();
	$updated    = ! empty( $stored['updated'] )
		? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stored['updated'] )
		: 'Never';
	?>
	<div class="wrap">
		<h1>Gateway Currency Converter</h1>
		<p>
			Select a target currency for each payment gateway. When a customer picks that gateway at
			checkout, all prices (products and shipping) are multiplied by the live exchange rate and
			displayed — and charged — in the target currency. Orders are saved with the converted
			amounts and currency code.
		</p>

		<form method="post">
			<?php wp_nonce_field( 'gcc_save', 'gcc_nonce' ); ?>

			<h2>Gateway → Currency mappings</h2>
			<p>Store base currency: <strong><?php echo esc_html( $base ); ?></strong>. Leave a gateway blank to keep the base currency for that gateway.</p>

			<?php if ( empty( $gateways ) ) : ?>
				<p>No WooCommerce payment gateways found.</p>
			<?php else : ?>
				<table class="widefat" style="max-width:640px">
					<thead>
						<tr><th>Payment Gateway</th><th>Convert checkout to</th></tr>
					</thead>
					<tbody>
					<?php foreach ( $gateways as $gid => $gateway ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $gateway->get_title() ); ?></strong>
								<?php if ( $gateway->enabled !== 'yes' ) : ?>
									<span style="color:#888;font-size:11px"> (disabled)</span>
								<?php endif; ?>
							</td>
							<td>
								<select name="gcc_map[<?php echo esc_attr( $gid ); ?>]" style="min-width:260px">
									<option value="">— No conversion (keep <?php echo esc_html( $base ); ?>)</option>
									<?php foreach ( $currencies as $code => $name ) : ?>
										<?php if ( $code === $base ) continue; ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $mappings[ $gid ] ?? '', $code ); ?>>
											<?php echo esc_html( "{$code} — {$name}" ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 style="margin-top:2em">API settings</h2>
			<p>
				Rates come from <a href="https://www.exchangerate-api.com/" target="_blank">ExchangeRate-API</a>
				via their free open endpoint — <strong>no API key required</strong> for daily updates (1,500 free
				requests/month). For a dedicated key with guaranteed uptime, sign up free at
				<a href="https://app.exchangerate-api.com/sign-up" target="_blank">exchangerate-api.com</a>
				and paste it below. Leave blank to use the shared open endpoint.
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gcc_api_key">API Key (optional)</label></th>
					<td>
						<input
							type="text"
							id="gcc_api_key"
							name="gcc_api_key"
							value="<?php echo esc_attr( $api_key ); ?>"
							class="regular-text code"
							placeholder="e.g. 1a2b3c4d5e6f7a8b9c0d…"
						/>
					</td>
				</tr>
			</table>

			<p><input type="submit" name="gcc_save" class="button button-primary" value="Save Settings"></p>
		</form>

		<hr>

		<h2>Exchange rates</h2>
		<p>Last updated: <strong><?php echo esc_html( $updated ); ?></strong>. Rates refresh automatically every 24 hours.</p>

		<?php
		$active_targets = array_unique( array_filter( array_values( get_option( GCC_OPT_MAPPINGS, array() ) ) ) );
		if ( ! empty( $active_targets ) && ! empty( $stored['rates'] ) ) :
		?>
		<table class="widefat" style="max-width:480px;margin-bottom:1em">
			<thead>
				<tr>
					<th>Target currency</th>
					<th>Rate (1&nbsp;<?php echo esc_html( $stored['base'] ); ?>&nbsp;=)</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $active_targets as $target ) : ?>
				<tr>
					<td><?php echo esc_html( $target ); ?> &mdash; <?php echo esc_html( $currencies[ $target ] ?? $target ); ?></td>
					<td>
					<?php
					if ( isset( $stored['rates'][ $target ] ) ) {
						echo esc_html( number_format( $stored['rates'][ $target ], 4 ) );
					} else {
						echo '<em style="color:#d63638">Not found in last fetch</em>';
					}
					?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php elseif ( empty( $active_targets ) ) : ?>
			<p>Configure at least one gateway mapping above to see rates here.</p>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'gcc_refresh', 'gcc_refresh_nonce' ); ?>
			<p><input type="submit" name="gcc_refresh" class="button button-secondary" value="Refresh Rates Now"></p>
		</form>
	</div>
	<?php
}

function gcc_get_all_gateways(): array {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return array();
	}
	return WC()->payment_gateways()->payment_gateways() ?: array();
}
