<?php
/**
 * Plugin Name: WC Odoo Sync
 * Description: One-way sync of WooCommerce orders to Odoo 16/17/18/19 as customer invoices (account.move). Works with Odoo.com SaaS and self-hosted. Requires the Accounting app. Configure under WooCommerce → Settings → Odoo Sync.
 * Version:     1.4.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

// ============================================================
// JSON-RPC transport
// ============================================================

/**
 * Low-level JSON-RPC POST. Returns the value of result.*, or WP_Error.
 * Pass $session_id to authenticate as a session cookie.
 */
function wcos_jsonrpc_post( string $url, array $params, ?string $session_id = null ): mixed {
	$headers = [ 'Content-Type' => 'application/json' ];
	if ( $session_id ) {
		$headers['Cookie'] = 'session_id=' . $session_id;
	}

	$r = wp_remote_post( $url, [
		'headers' => $headers,
		'body'    => wp_json_encode( [ 'jsonrpc' => '2.0', 'method' => 'call', 'id' => 1, 'params' => $params ] ),
		'timeout' => 30,
	] );

	if ( is_wp_error( $r ) ) return $r;

	$code = wp_remote_retrieve_response_code( $r );
	if ( $code !== 200 ) return new WP_Error( 'wcos_http', "Odoo returned HTTP $code" );

	$body = json_decode( wp_remote_retrieve_body( $r ), true );
	if ( ! is_array( $body ) ) return new WP_Error( 'wcos_json', 'Invalid JSON from Odoo' );

	if ( isset( $body['error'] ) ) {
		$err  = $body['error'];
		$data = $err['data'] ?? [];
		$msg  = $data['message'] ?? $err['message'] ?? 'Odoo error';
		$type = $data['name'] ?? $data['exception_type'] ?? '';
		if ( $type ) $msg = "[$type] $msg";
		// Clear cached session on expiry so the next call re-authenticates
		if ( stripos( $msg, 'session' ) !== false || stripos( $msg, 'expired' ) !== false ) {
			delete_transient( 'wcos_session' );
		}
		return new WP_Error( 'wcos_rpc', $msg, [ 'raw' => substr( wp_remote_retrieve_body( $r ), 0, 800 ) ] );
	}

	$result = $body['result'] ?? null;

	// Odoo 17+ / SaaS may send session_id only as a Set-Cookie header rather than
	// in the response body. Inject it into the result array so callers can cache it.
	if ( is_array( $result ) && empty( $result['session_id'] ) ) {
		$raw = wp_remote_retrieve_header( $r, 'set-cookie' );
		$raw = is_array( $raw ) ? implode( '; ', $raw ) : (string) $raw;
		if ( preg_match( '/\bsession_id=([^;,\s]+)/', $raw, $m ) ) {
			$result['session_id'] = $m[1];
		}
	}

	return $result;
}

// ============================================================
// Odoo API
// ============================================================

function wcos_cfg(): array {
	$url = rtrim( get_option( 'wcos_odoo_url', '' ), '/' );
	$db  = get_option( 'wcos_odoo_db', '' );

	// Auto-derive db from subdomain when blank (e.g. https://my-website.odoo.com → my-website)
	if ( empty( $db ) && ! empty( $url ) ) {
		$host = (string) parse_url( $url, PHP_URL_HOST );
		$db   = explode( '.', $host )[0] ?? '';
	}

	return [
		'url'             => $url,
		'db'              => $db,
		'user'            => get_option( 'wcos_odoo_username', '' ),
		'key'             => get_option( 'wcos_odoo_api_key', '' ),
		'sync_new'        => get_option( 'wcos_sync_on_new', 'yes' ) === 'yes',
		'sync_status'     => get_option( 'wcos_sync_on_status', 'yes' ) === 'yes',
		'create_products' => get_option( 'wcos_create_products', 'yes' ) === 'yes',
		'debug'           => get_option( 'wcos_debug_log', 'no' ) === 'yes',
	];
}

/**
 * Authenticate and return the session_id string. Cached for 1 hour.
 */
function wcos_session(): string|WP_Error {
	$hit = get_transient( 'wcos_session' );
	if ( $hit ) return $hit;

	$c = wcos_cfg();
	if ( empty( $c['url'] ) || empty( $c['user'] ) || empty( $c['key'] ) ) {
		return new WP_Error( 'wcos_cfg', 'Odoo connection not fully configured — visit WooCommerce → Settings → Odoo Sync' );
	}

	// db is optional: on Odoo.com SaaS the hostname already identifies the database
	$params = array_filter( [
		'db'       => $c['db'] ?: null,
		'login'    => $c['user'],
		'password' => $c['key'],
	], fn( $v ) => $v !== null );

	$result = wcos_jsonrpc_post( $c['url'] . '/web/session/authenticate', $params );

	if ( is_wp_error( $result ) ) return $result;
	if ( empty( $result['uid'] ) || empty( $result['session_id'] ) ) {
		return new WP_Error( 'wcos_auth', 'Authentication failed — check username / API key' );
	}

	set_transient( 'wcos_session', $result['session_id'], HOUR_IN_SECONDS );
	return $result['session_id'];
}

/**
 * Returns the Odoo UID (int), used only for the "Test Connection" display.
 */
function wcos_uid(): int|WP_Error {
	$c = wcos_cfg();
	if ( empty( $c['url'] ) || empty( $c['user'] ) || empty( $c['key'] ) ) {
		return new WP_Error( 'wcos_cfg', 'Odoo connection not fully configured' );
	}

	$params = array_filter( [
		'db'       => $c['db'] ?: null,
		'login'    => $c['user'],
		'password' => $c['key'],
	], fn( $v ) => $v !== null );

	$result = wcos_jsonrpc_post( $c['url'] . '/web/session/authenticate', $params );
	if ( is_wp_error( $result ) ) return $result;
	if ( empty( $result['uid'] ) ) return new WP_Error( 'wcos_auth', 'Authentication failed — check username / API key' );

	if ( ! empty( $result['session_id'] ) ) {
		set_transient( 'wcos_session', $result['session_id'], HOUR_IN_SECONDS );
	}
	return (int) $result['uid'];
}

/**
 * Call any Odoo ORM method via /web/dataset/call_kw.
 * Automatically retries once if the session has expired.
 */
function wcos_call( string $model, string $method, array $args, array $kwargs = [] ): mixed {
	$session = wcos_session();
	if ( is_wp_error( $session ) ) return $session;

	$c   = wcos_cfg();
	// Odoo 17+ requires model and method in the URL path.
	// Including them in the body params too keeps older versions happy.
	$url    = $c['url'] . '/web/dataset/call_kw/' . $model . '/' . $method;
	$params = [
		'model'  => $model,
		'method' => $method,
		'args'   => $args,
		'kwargs' => empty( $kwargs ) ? new stdClass() : $kwargs,
	];

	wcos_log( 'debug', "RPC → $model.$method", [ 'url' => $url ] );

	$result = wcos_jsonrpc_post( $url, $params, $session );

	// On session expiry the transient was already cleared; retry once
	if ( is_wp_error( $result ) ) {
		$raw = $result->get_error_data()['raw'] ?? '';
		wcos_log( 'debug', "RPC ✗ $model.$method: " . $result->get_error_message(), [ 'raw' => $raw ] );
		$fresh = wcos_session();
		if ( ! is_wp_error( $fresh ) && $fresh !== $session ) {
			$result = wcos_jsonrpc_post( $url, $params, $fresh );
		}
	}

	return $result;
}

function wcos_search_read( string $model, array $domain, array $fields, int $limit = 1 ): array|WP_Error {
	$r = wcos_call( $model, 'search_read', [ $domain ], [ 'fields' => $fields, 'limit' => $limit ] );
	return is_wp_error( $r ) ? $r : ( is_array( $r ) ? $r : [] );
}

function wcos_create( string $model, array $vals ): int|WP_Error {
	$r = wcos_call( $model, 'create', [ $vals ] );
	return is_wp_error( $r ) ? $r
		: ( is_int( $r ) ? $r : new WP_Error( 'wcos_create', "Unexpected create() result on $model" ) );
}

function wcos_write( string $model, array $ids, array $vals ): bool|WP_Error {
	$r = wcos_call( $model, 'write', [ $ids, $vals ] );
	return is_wp_error( $r ) ? $r : (bool) $r;
}

// ============================================================
// Sync helpers
// ============================================================

function wcos_log( string $level, string $msg, array $ctx = [] ): void {
	if ( $level === 'debug' && ! wcos_cfg()['debug'] ) return;
	$log   = get_option( 'wcos_log', [] );
	$log[] = [ 'time' => current_time( 'mysql' ), 'level' => $level, 'msg' => $msg, 'ctx' => $ctx ];
	if ( count( $log ) > 200 ) $log = array_slice( $log, -200 );
	update_option( 'wcos_log', $log, false );
}

function wcos_country_id( string $code ): int|false {
	if ( ! $code ) return false;
	$r = wcos_search_read( 'res.country', [ [ 'code', '=', strtoupper( $code ) ] ], [ 'id' ] );
	return ( ! is_wp_error( $r ) && $r ) ? (int) $r[0]['id'] : false;
}

function wcos_state_id( string $state_code, int $country_id ): int|false {
	if ( ! $state_code ) return false;
	$r = wcos_search_read( 'res.country.state', [ [ 'code', '=', $state_code ], [ 'country_id', '=', $country_id ] ], [ 'id' ] );
	return ( ! is_wp_error( $r ) && $r ) ? (int) $r[0]['id'] : false;
}

// Find or create res.partner from order billing data. Returns partner ID or WP_Error.
function wcos_ensure_partner( WC_Order $order ): int|WP_Error {
	$email = $order->get_billing_email();
	$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() )
		?: $email ?: 'WooCommerce Customer';

	$domain = $email ? [ [ 'email', '=', $email ] ] : [ [ 'name', '=', $name ] ];
	$found  = wcos_search_read( 'res.partner', $domain, [ 'id' ] );
	if ( is_wp_error( $found ) ) return $found;

	$country_id = wcos_country_id( $order->get_billing_country() );
	$state_id   = $country_id ? wcos_state_id( $order->get_billing_state(), $country_id ) : false;

	$vals = array_filter( [
		'name'          => $name,
		'email'         => $email,
		'phone'         => $order->get_billing_phone(),
		'street'        => $order->get_billing_address_1(),
		'street2'       => $order->get_billing_address_2(),
		'city'          => $order->get_billing_city(),
		'zip'           => $order->get_billing_postcode(),
		'country_id'    => $country_id ?: null,
		'state_id'      => $state_id ?: null,
		'customer_rank' => 1,
	], fn( $v ) => $v !== null && $v !== false && $v !== '' );

	if ( $found ) {
		$pid = (int) $found[0]['id'];
		wcos_write( 'res.partner', [ $pid ], $vals );
		wcos_log( 'debug', "Updated partner $pid" );
		return $pid;
	}

	$pid = wcos_create( 'res.partner', $vals );
	if ( ! is_wp_error( $pid ) ) wcos_log( 'debug', "Created partner $pid" );
	return $pid;
}

// Returns [ 'id' => int, 'uom' => int ] or WP_Error.
function wcos_ensure_product( WC_Order_Item_Product $item ): array|WP_Error {
	$product = $item->get_product();
	$sku     = $product ? $product->get_sku() : '';
	$name    = html_entity_decode( wp_strip_all_tags( $item->get_name() ) );

	foreach ( array_filter( [
		$sku  ? [ 'default_code', '=', $sku ] : null,
		      [ 'name', '=', $name ],
	] ) as $clause ) {
		$r = wcos_search_read( 'product.product', [ $clause ], [ 'id', 'uom_id' ] );
		if ( ! is_wp_error( $r ) && $r ) {
			return [ 'id' => (int) $r[0]['id'], 'uom' => (int) ( $r[0]['uom_id'][0] ?? 1 ) ];
		}
	}

	if ( ! wcos_cfg()['create_products'] ) {
		return new WP_Error( 'wcos_no_prod', "No Odoo product for '$name' (SKU: $sku)" );
	}

	$tmpl_vals = array_filter( [
		'name'         => $name,
		'default_code' => $sku ?: null,
		'type'         => 'consu',
		'sale_ok'      => true,
		'purchase_ok'  => false,
	], fn( $v ) => $v !== null );

	$tmpl = wcos_create( 'product.template', $tmpl_vals );
	if ( is_wp_error( $tmpl ) ) return $tmpl;

	$r = wcos_search_read( 'product.product', [ [ 'product_tmpl_id', '=', $tmpl ] ], [ 'id', 'uom_id' ] );
	if ( is_wp_error( $r ) || ! $r ) {
		return new WP_Error( 'wcos_prod', "Could not retrieve product.product for template $tmpl" );
	}

	wcos_log( 'info', "Created Odoo product '$name' (template $tmpl)" );
	return [ 'id' => (int) $r[0]['id'], 'uom' => (int) ( $r[0]['uom_id'][0] ?? 1 ) ];
}

// Returns the Odoo product.product ID for the shipping service product.
function wcos_shipping_product_id(): int|WP_Error {
	$cached = get_transient( 'wcos_ship_pid' );
	if ( $cached ) return (int) $cached;

	$r = wcos_search_read( 'product.product', [ [ 'name', '=', 'WooCommerce Shipping' ] ], [ 'id' ] );
	if ( ! is_wp_error( $r ) && $r ) {
		set_transient( 'wcos_ship_pid', $r[0]['id'], DAY_IN_SECONDS );
		return (int) $r[0]['id'];
	}

	$tmpl = wcos_create( 'product.template', [ 'name' => 'WooCommerce Shipping', 'type' => 'service', 'sale_ok' => true ] );
	if ( is_wp_error( $tmpl ) ) return $tmpl;

	$r = wcos_search_read( 'product.product', [ [ 'product_tmpl_id', '=', $tmpl ] ], [ 'id' ] );
	if ( is_wp_error( $r ) || ! $r ) return new WP_Error( 'wcos_ship', 'Could not create WooCommerce Shipping product' );

	set_transient( 'wcos_ship_pid', $r[0]['id'], DAY_IN_SECONDS );
	return (int) $r[0]['id'];
}

// Replaces all lines on a draft Odoo invoice with current WC order items + shipping.
function wcos_sync_lines( WC_Order $order, int $odoo_invoice_id ): void {
	$existing = wcos_search_read( 'account.move.line', [ [ 'move_id', '=', $odoo_invoice_id ], [ 'display_type', '=', 'product' ] ], [ 'id' ], 200 );
	if ( ! is_wp_error( $existing ) && $existing ) {
		wcos_call( 'account.move.line', 'unlink', [ array_column( $existing, 'id' ) ] );
	}

	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) continue;

		$prod = wcos_ensure_product( $item );
		if ( is_wp_error( $prod ) ) {
			wcos_log( 'error', $prod->get_error_message(), [ 'order' => $order->get_id() ] );
			continue;
		}

		$qty   = (float) $item->get_quantity();
		$price = $qty > 0 ? round( (float) $item->get_total() / $qty, 6 ) : 0.0;

		wcos_create( 'account.move.line', [
			'move_id'    => $odoo_invoice_id,
			'product_id' => $prod['id'],
			'name'       => html_entity_decode( wp_strip_all_tags( $item->get_name() ) ),
			'quantity'   => $qty,
			'price_unit' => $price,
			'tax_ids'    => [ [ 5, 0, 0 ] ], // clear Odoo default taxes — WC handles tax separately
		] );
	}

	$shipping = (float) $order->get_shipping_total();
	if ( $shipping > 0 ) {
		$ship_pid = wcos_shipping_product_id();
		if ( ! is_wp_error( $ship_pid ) ) {
			wcos_create( 'account.move.line', [
				'move_id'    => $odoo_invoice_id,
				'product_id' => $ship_pid,
				'name'       => $order->get_shipping_method() ?: 'Shipping',
				'quantity'   => 1,
				'price_unit' => $shipping,
				'tax_ids'    => [ [ 5, 0, 0 ] ],
			] );
		}
	}
}

// Drives the Odoo invoice into the state implied by the WC status.
function wcos_apply_status( int $odoo_invoice_id, string $wc_status, string $odoo_state ): void {
	$want_posted   = $wc_status === 'completed';
	$want_cancelled = in_array( $wc_status, [ 'cancelled', 'refunded', 'failed' ], true );

	if ( $want_posted && $odoo_state !== 'posted' ) {
		wcos_call( 'account.move', 'action_post', [ [ $odoo_invoice_id ] ] );
	}

	if ( $want_cancelled && $odoo_state !== 'cancel' ) {
		// Must be in draft to cancel; reset from posted if needed
		if ( $odoo_state === 'posted' ) {
			wcos_call( 'account.move', 'button_draft', [ [ $odoo_invoice_id ] ] );
		}
		wcos_call( 'account.move', 'button_cancel', [ [ $odoo_invoice_id ] ] );
	}
}

// ============================================================
// Main sync
// ============================================================

function wcos_sync_order( int $order_id ): bool {
	$order = wc_get_order( $order_id );
	if ( ! $order || empty( wcos_cfg()['url'] ) ) return false;

	wcos_log( 'info', "Syncing WC #$order_id", [ 'status' => $order->get_status() ] );

	$partner_id = wcos_ensure_partner( $order );
	if ( is_wp_error( $partner_id ) ) {
		wcos_log( 'error', 'Partner: ' . $partner_id->get_error_message() );
		$order->add_order_note( 'Odoo sync failed (partner): ' . $partner_id->get_error_message() );
		return false;
	}

	$wc_ref          = 'WC-' . $order_id;
	$odoo_invoice_id = (int) $order->get_meta( '_wcos_odoo_order_id', true );
	$odoo_state      = 'draft';

	// Verify stored Odoo ID still resolves
	if ( $odoo_invoice_id ) {
		$r = wcos_search_read( 'account.move', [ [ 'id', '=', $odoo_invoice_id ] ], [ 'id', 'state' ] );
		if ( is_wp_error( $r ) || ! $r ) {
			$odoo_invoice_id = 0;
		} else {
			$odoo_state = $r[0]['state'];
		}
	}

	// Fallback: search by WC reference in case meta was lost
	if ( ! $odoo_invoice_id ) {
		$r = wcos_search_read( 'account.move', [ [ 'ref', '=', $wc_ref ], [ 'move_type', '=', 'out_invoice' ] ], [ 'id', 'state' ] );
		if ( ! is_wp_error( $r ) && $r ) {
			$odoo_invoice_id = (int) $r[0]['id'];
			$odoo_state      = $r[0]['state'];
		}
	}

	if ( ! $odoo_invoice_id ) {
		$vals = array_filter( [
			'move_type'   => 'out_invoice',
			'partner_id'  => $partner_id,
			'ref'         => $wc_ref,
			'narration'   => $order->get_customer_note(),
			'invoice_date' => $order->get_date_created()?->date( 'Y-m-d' ),
		], fn( $v ) => $v !== null && $v !== '' );

		$odoo_invoice_id = wcos_create( 'account.move', $vals );
		if ( is_wp_error( $odoo_invoice_id ) ) {
			wcos_log( 'error', 'Create invoice: ' . $odoo_invoice_id->get_error_message() );
			$order->add_order_note( 'Odoo sync failed (create invoice): ' . $odoo_invoice_id->get_error_message() );
			return false;
		}

		$order->update_meta_data( '_wcos_odoo_order_id', $odoo_invoice_id );
		$order->save();
		wcos_log( 'info', "Created Odoo invoice $odoo_invoice_id for WC #$order_id" );
	}

	// Only touch lines while the invoice is still a draft
	if ( $odoo_state === 'draft' ) {
		wcos_sync_lines( $order, $odoo_invoice_id );
	}

	wcos_apply_status( $odoo_invoice_id, $order->get_status(), $odoo_state );

	$order->add_order_note( "Synced to Odoo — invoice ID: $odoo_invoice_id" );
	wcos_log( 'info', "WC #$order_id → Odoo invoice $odoo_invoice_id OK" );
	return true;
}

// Deletes the linked Odoo invoice when a WC order is trashed/deleted — only if it's still a Draft.
function wcos_handle_order_deleted( int $order_id ): void {
	if ( ! wcos_cfg()['sync_status'] ) return;

	$order            = wc_get_order( $order_id );
	$odoo_invoice_id  = $order ? (int) $order->get_meta( '_wcos_odoo_order_id', true ) : 0;
	if ( ! $odoo_invoice_id ) return;

	$r = wcos_search_read( 'account.move', [ [ 'id', '=', $odoo_invoice_id ] ], [ 'id', 'state' ] );
	if ( is_wp_error( $r ) || ! $r ) return;

	if ( $r[0]['state'] !== 'draft' ) {
		wcos_log( 'info', "WC #$order_id deleted — Odoo invoice $odoo_invoice_id left untouched (state: {$r[0]['state']})" );
		return;
	}

	$ok = wcos_call( 'account.move', 'unlink', [ [ $odoo_invoice_id ] ] );
	if ( is_wp_error( $ok ) ) {
		wcos_log( 'error', "WC #$order_id deleted — failed to delete Odoo invoice $odoo_invoice_id: " . $ok->get_error_message() );
	} else {
		wcos_log( 'info', "WC #$order_id deleted — removed draft Odoo invoice $odoo_invoice_id" );
	}
}

// ============================================================
// WooCommerce hooks
// ============================================================

add_action( 'woocommerce_checkout_order_created', function( WC_Order $order ): void {
	if ( wcos_cfg()['sync_new'] ) wcos_sync_order( $order->get_id() );
} );

add_action( 'woocommerce_order_status_changed', function( int $order_id ): void {
	if ( wcos_cfg()['sync_status'] ) wcos_sync_order( $order_id );
} );

add_action( 'woocommerce_trash_order', 'wcos_handle_order_deleted' );
add_action( 'woocommerce_delete_order', 'wcos_handle_order_deleted' );

// ============================================================
// Admin: manual sync button on order edit page
// ============================================================

add_action( 'woocommerce_order_item_add_action_buttons', function( WC_Order $order ): void {
	$oid   = $order->get_meta( '_wcos_odoo_order_id', true );
	$label = $oid ? "Re-sync to Odoo (#{$oid})" : 'Sync to Odoo';
	echo '<button type="button" class="button wcos-sync-btn" data-order="' . esc_attr( $order->get_id() ) . '">'
		. esc_html( $label ) . '</button>';
} );

add_action( 'wp_ajax_wcos_sync', function(): void {
	check_ajax_referer( 'wcos_manual', 'nonce' );
	if ( ! current_user_can( 'edit_shop_orders' ) ) wp_die( -1 );
	$ok = wcos_sync_order( (int) ( $_POST['order_id'] ?? 0 ) );
	$ok ? wp_send_json_success( 'Synced to Odoo.' )
		: wp_send_json_error( 'Sync failed — check the Odoo Sync log.' );
} );

add_action( 'wp_ajax_wcos_test_connection', function(): void {
	check_ajax_referer( 'wcos_test', 'nonce' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( -1 );
	$uid = wcos_uid();
	is_wp_error( $uid )
		? wp_send_json_error( $uid->get_error_message() )
		: wp_send_json_success( "Connected! (Odoo UID: $uid)" );
} );

add_action( 'admin_footer', function(): void {
	$screen = get_current_screen();

	if ( $screen && in_array( $screen->id, [ 'shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
		$nonce = wp_create_nonce( 'wcos_manual' );
		?>
		<script>
		document.addEventListener('click', (e) => {
			const btn = e.target.closest('.wcos-sync-btn');
			if (!btn) return;
			e.preventDefault();
			btn.disabled = true;
			btn.textContent = 'Syncing…';
			const fd = new FormData();
			fd.append('action', 'wcos_sync');
			fd.append('nonce', <?php echo wp_json_encode( $nonce ); ?>);
			fd.append('order_id', btn.dataset.order);
			fetch(ajaxurl, { method: 'POST', body: fd })
				.then(r => r.json())
				.then(d => {
					btn.textContent = d.success ? 'Synced!' : 'Failed';
					setTimeout(() => location.reload(), 1200);
				});
		});
		</script>
		<?php
	}

	if ( ( $_GET['tab'] ?? '' ) === 'odoo_sync' && empty( $_GET['section'] ) ) {
		$nonce = wp_create_nonce( 'wcos_test' );
		?>
		<script>
		document.getElementById('wcos-test-btn')?.addEventListener('click', function () {
			const btn = this, result = document.getElementById('wcos-test-result');
			btn.disabled = true;
			result.textContent = 'Testing…';
			result.style.color = '';
			const fd = new FormData();
			fd.append('action', 'wcos_test_connection');
			fd.append('nonce', <?php echo wp_json_encode( $nonce ); ?>);
			fetch(ajaxurl, { method: 'POST', body: fd })
				.then(r => r.json())
				.then(d => {
					result.style.color = d.success ? 'green' : '#b00';
					result.textContent = d.data;
					btn.disabled = false;
				});
		});
		</script>
		<?php
	}
} );

// ============================================================
// WooCommerce settings tab
// ============================================================

add_filter( 'woocommerce_settings_tabs_array', function( array $tabs ): array {
	$tabs['odoo_sync'] = 'Odoo Sync';
	return $tabs;
}, 50 );

add_action( 'woocommerce_sections_odoo_sync', function(): void {
	$cur  = $_GET['section'] ?? '';
	$secs = [ '' => 'Settings', 'log' => 'Sync Log' ];
	echo '<ul class="subsubsub">';
	$first = true;
	foreach ( $secs as $id => $label ) {
		$url = esc_url( add_query_arg(
			[ 'page' => 'wc-settings', 'tab' => 'odoo_sync', 'section' => $id ],
			admin_url( 'admin.php' )
		) );
		$cls = ( $cur === $id ) ? ' class="current"' : '';
		if ( ! $first ) echo ' | ';
		echo "<li><a href='$url'$cls>" . esc_html( $label ) . '</a></li>';
		$first = false;
	}
	echo '</ul><br class="clear">';
} );

add_action( 'woocommerce_settings_tabs_odoo_sync', function(): void {
	if ( ( $_GET['section'] ?? '' ) === 'log' ) {
		wcos_render_log();
	} else {
		woocommerce_admin_fields( wcos_settings_fields() );
	}
} );

// woocommerce_settings_save_{tab} is the hook modern WC fires on save.
// woocommerce_update_settings_tabs_{tab} is the older equivalent; register both so
// whichever WooCommerce version is running will trigger the save.
$_wcos_save = static function (): void {
	static $done = false;
	if ( $done ) return;
	$done = true;
	woocommerce_update_options( wcos_settings_fields() );
	delete_transient( 'wcos_session' );
};
add_action( 'woocommerce_settings_save_odoo_sync', $_wcos_save );
add_action( 'woocommerce_update_settings_tabs_odoo_sync', $_wcos_save );

add_action( 'woocommerce_admin_field_wcos_test_btn', function(): void {
	echo '<tr><th></th><td>'
		. '<button type="button" id="wcos-test-btn" class="button">Test Connection</button>'
		. '<span id="wcos-test-result" style="margin-left:10px;line-height:30px"></span>'
		. '</td></tr>';
} );

function wcos_settings_fields(): array {
	return [
		[ 'title' => 'Odoo Connection', 'type' => 'title', 'id' => 'wcos_s_conn' ],
		[
			'title'   => 'Odoo URL',
			'id'      => 'wcos_odoo_url',
			'type'    => 'text',
			'desc'    => 'e.g. <code>https://my-website.odoo.com</code>',
			'css'     => 'min-width:360px',
			'default' => '',
		],
		[
			'title'   => 'Database Name',
			'id'      => 'wcos_odoo_db',
			'type'    => 'text',
			'desc'    => 'For <code>my-website.odoo.com</code> enter <code>my-website</code>. Leave blank to auto-detect from the URL subdomain.',
			'default' => '',
		],
		[
			'title'   => 'Username / Email',
			'id'      => 'wcos_odoo_username',
			'type'    => 'text',
			'default' => '',
		],
		[
			'title'   => 'API Key / Password',
			'id'      => 'wcos_odoo_api_key',
			'type'    => 'password',
			'desc'    => 'Create an API key in Odoo: Settings → Technical → API Keys (recommended over a plain password)',
			'default' => '',
		],
		[ 'type' => 'wcos_test_btn' ],
		[ 'type' => 'sectionend', 'id' => 'wcos_s_conn' ],

		[ 'title' => 'Sync Options', 'type' => 'title', 'id' => 'wcos_s_sync' ],
		[
			'title'   => 'Sync on new order',
			'id'      => 'wcos_sync_on_new',
			'type'    => 'checkbox',
			'default' => 'yes',
		],
		[
			'title'   => 'Sync on status change',
			'id'      => 'wcos_sync_on_status',
			'type'    => 'checkbox',
			'default' => 'yes',
		],
		[
			'title'   => 'Auto-create missing products in Odoo',
			'id'      => 'wcos_create_products',
			'type'    => 'checkbox',
			'desc'    => 'When no match is found by SKU or name, create the product in Odoo automatically',
			'default' => 'yes',
		],
		[
			'title'   => 'Debug logging',
			'id'      => 'wcos_debug_log',
			'type'    => 'checkbox',
			'desc'    => 'Log all sync steps (not just errors) to the Sync Log',
			'default' => 'no',
		],
		[ 'type' => 'sectionend', 'id' => 'wcos_s_sync' ],
	];
}

// ============================================================
// Sync Log page
// ============================================================

function wcos_render_log(): void {
	if ( isset( $_GET['wcos_clear'] ) && check_admin_referer( 'wcos_clear' ) ) {
		delete_option( 'wcos_log' );
		wp_redirect( add_query_arg( [ 'page' => 'wc-settings', 'tab' => 'odoo_sync', 'section' => 'log', 'cleared' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}
	if ( isset( $_GET['cleared'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Log cleared.</p></div>';
	}

	$log = array_reverse( get_option( 'wcos_log', [] ) );
	echo '<h2>Sync Log</h2>';

	if ( ! $log ) {
		echo '<p>No log entries yet. Entries appear here when orders are synced.</p>';
		return;
	}

	$clear_url = wp_nonce_url(
		add_query_arg( [ 'page' => 'wc-settings', 'tab' => 'odoo_sync', 'section' => 'log', 'wcos_clear' => '1' ], admin_url( 'admin.php' ) ),
		'wcos_clear'
	);
	echo '<p><a href="' . esc_url( $clear_url ) . '" class="button">Clear log</a></p>';
	echo '<table class="widefat striped"><thead><tr><th style="width:160px">Time</th><th style="width:70px">Level</th><th>Message</th><th>Detail</th></tr></thead><tbody>';

	foreach ( $log as $e ) {
		$color = match ( $e['level'] ) {
			'error'   => '#b00',
			'warning' => '#a60',
			default   => 'inherit',
		};
		$detail = '';
		if ( ! empty( $e['ctx'] ) ) {
			$parts = [];
			foreach ( $e['ctx'] as $k => $v ) {
				$parts[] = '<b>' . esc_html( $k ) . ':</b> ' . esc_html( is_array( $v ) ? json_encode( $v ) : (string) $v );
			}
			$detail = implode( '<br>', $parts );
		}
		printf(
			'<tr><td>%s</td><td style="color:%s;font-weight:600">%s</td><td>%s</td><td style="font-size:11px;font-family:monospace;word-break:break-all">%s</td></tr>',
			esc_html( $e['time'] ),
			esc_attr( $color ),
			esc_html( strtoupper( $e['level'] ) ),
			esc_html( $e['msg'] ),
			$detail
		);
	}

	echo '</tbody></table>';
}
