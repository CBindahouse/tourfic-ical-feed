<?php
/**
 * Plugin Name: WC Map Shipping
 * Description: WooCommerce shipping method restricted to a map-drawn polygon. No zip codes needed — customers pick their location on a Leaflet map at checkout; only orders inside your drawn area are offered this shipping method. Configure inside WooCommerce → Settings → Shipping → (zone) → Add shipping method → Map Zone Shipping.
 * Version: 1.2.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'WMS_VERSION', '1.1.0' );
define( 'WMS_FILE',    __FILE__ );

// ============================================================
// 1. Register the shipping method class
// ============================================================

add_action( 'woocommerce_shipping_init', 'wms_init_shipping_class' );
add_filter( 'woocommerce_shipping_methods', 'wms_register_shipping_method' );

function wms_register_shipping_method( array $methods ): array {
	$methods['wms_map_shipping'] = 'WMS_Shipping_Method';
	return $methods;
}

function wms_init_shipping_class(): void {

	class WMS_Shipping_Method extends WC_Shipping_Method {

		public function __construct( int $instance_id = 0 ) {
			$this->id                 = 'wms_map_shipping';
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = 'Map Zone Shipping';
			$this->method_description = 'Offer this shipping option only to customers inside a polygon you draw on a map. No zip codes required — customers pick their location on a map at checkout.';
			$this->supports           = [ 'shipping-zones', 'instance-settings' ];
			$this->init();
		}

		public function init(): void {
			$this->init_form_fields();
			$this->init_settings();
			$this->title      = $this->get_option( 'title', 'Local Delivery' );
			$this->tax_status = $this->get_option( 'tax_status' );
			add_action(
				'woocommerce_update_options_shipping_' . $this->id,
				[ $this, 'process_admin_options' ]
			);
		}

		public function init_form_fields(): void {
			$this->instance_form_fields = [
				'title' => [
					'title'    => 'Method title',
					'type'     => 'text',
					'default'  => 'Local Delivery',
					'desc_tip' => 'Name shown to the customer at checkout.',
				],
				'cost' => [
					'title'    => 'Cost',
					'type'     => 'price',
					'default'  => '0',
					'desc_tip' => 'Flat shipping cost for this zone. Set to 0 for free delivery.',
				],
				'tax_status' => [
					'title'   => 'Tax status',
					'type'    => 'select',
					'default' => 'taxable',
					'options' => [
						'taxable' => 'Taxable',
						'none'    => 'Not taxable',
					],
				],
				'polygon' => [
					'title'       => 'Delivery area',
					'type'        => 'wms_polygon_map',
					'description' => 'Select the polygon draw tool (pentagon icon in the toolbar), click each corner of your delivery area, then double-click to close the shape. Click "Save changes" at the bottom of the page to store the polygon.',
					'default'     => '',
				],
			];
		}

		// --------------------------------------------------------
		// Custom field: map polygon editor
		// --------------------------------------------------------

		public function generate_wms_polygon_map_html( string $key, array $data ): string {
			$field_key    = $this->get_field_key( $key );
			$polygon_json = $this->get_option( $key, '' );
			$instance     = (int) $this->instance_id;

			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<label><?php echo esc_html( $data['title'] ); ?></label>
				</th>
				<td class="forminp">
					<p class="description" style="margin-bottom:10px">
						<?php echo esc_html( $data['description'] ); ?>
					</p>
					<div id="wms-admin-map-<?php echo $instance; ?>"
					     style="width:100%;height:460px;border:1px solid #ccc;border-radius:3px;"></div>
					<input type="hidden"
					       id="<?php echo esc_attr( $field_key ); ?>"
					       name="<?php echo esc_attr( $field_key ); ?>"
					       value="<?php echo esc_attr( $polygon_json ); ?>">
					<p style="margin-top:10px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
						<button type="button" class="button"
						        onclick="wmsAdminClearPolygon(<?php echo $instance; ?>)">
							Clear polygon
						</button>
						<span id="wms-status-<?php echo $instance; ?>" style="color:#666;font-size:12px">
							<?php echo $polygon_json ? 'Polygon saved.' : 'No polygon drawn yet.'; ?>
						</span>
					</p>
				</td>
			</tr>
			<script>
			window.wmsAdminMaps = window.wmsAdminMaps || {};
			(function () {
				var inst      = <?php echo $instance; ?>;
				var fieldId   = <?php echo wp_json_encode( $field_key ); ?>;
				var savedPoly = <?php echo $polygon_json ?: 'null'; ?>;

				function boot() {
					if (typeof L === 'undefined' || typeof L.Control.Draw === 'undefined') {
						setTimeout(boot, 150);
						return;
					}
					var el = document.getElementById('wms-admin-map-' + inst);
					if (!el || el._wmsReady) return;
					el._wmsReady = true;

					var map = L.map(el).setView([-25.746, 28.188], 10);

					L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
						attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
						maxZoom: 19,
					}).addTo(map);

					var drawn = new L.FeatureGroup();
					map.addLayer(drawn);

					if (savedPoly && savedPoly.length >= 3) {
						var lls  = savedPoly.map(function (p) { return [p.lat, p.lng]; });
						var poly = L.polygon(lls, {color: '#2271b1', fillOpacity: 0.15}).addTo(drawn);
						map.fitBounds(poly.getBounds(), {padding: [40, 40]});
					}

					var ctrl = new L.Control.Draw({
						edit: { featureGroup: drawn, remove: true },
						draw: {
							polygon:      { shapeOptions: {color: '#2271b1', fillOpacity: 0.15} },
							polyline:     false,
							rectangle:    { shapeOptions: {color: '#2271b1', fillOpacity: 0.15} },
							circle:       false,
							circlemarker: false,
							marker:       false,
						},
					});
					map.addControl(ctrl);

					window.wmsAdminMaps[inst] = { map: map, drawn: drawn, fieldId: fieldId };

					function persist(layer) {
						var pts = layer.getLatLngs()[0];
						if (Array.isArray(pts[0])) pts = pts[0];
						var coords = pts.map(function (ll) { return {lat: ll.lat, lng: ll.lng}; });
						document.getElementById(fieldId).value = JSON.stringify(coords);
						document.getElementById('wms-status-' + inst).textContent =
							'Polygon ready (' + coords.length + ' points). Click "Save changes" to store it.';
					}

					map.on(L.Draw.Event.CREATED, function (e) {
						drawn.clearLayers();
						drawn.addLayer(e.layer);
						persist(e.layer);
					});
					map.on(L.Draw.Event.EDITED, function (e) {
						e.layers.eachLayer(persist);
					});
					map.on(L.Draw.Event.DELETED, function () {
						document.getElementById(fieldId).value = '';
						document.getElementById('wms-status-' + inst).textContent = 'No polygon drawn.';
					});
				}

				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', boot);
				} else {
					boot();
				}
			})();

			window.wmsAdminClearPolygon = function (inst) {
				var m = window.wmsAdminMaps && window.wmsAdminMaps[inst];
				if (!m) return;
				m.drawn.clearLayers();
				document.getElementById(m.fieldId).value = '';
				document.getElementById('wms-status-' + inst).textContent = 'No polygon drawn.';
			};
			</script>
			<?php
			return ob_get_clean();
		}

		public function validate_wms_polygon_map_field( string $key ): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = wp_unslash( $_POST[ $this->get_field_key( $key ) ] ?? '' );
			if ( ! $raw ) {
				return '';
			}
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return '';
			}
			$clean = [];
			foreach ( $decoded as $pt ) {
				if ( isset( $pt['lat'], $pt['lng'] ) ) {
					$clean[] = [
						'lat' => (float) $pt['lat'],
						'lng' => (float) $pt['lng'],
					];
				}
			}
			return count( $clean ) >= 3 ? wp_json_encode( $clean ) : '';
		}

		// --------------------------------------------------------
		// Shipping calculation — the core logic
		// --------------------------------------------------------

		public function calculate_shipping( $package = [] ) {
			$polygon_json = $this->get_option( 'polygon', '' );
			if ( ! $polygon_json ) {
				return;
			}
			$polygon = json_decode( $polygon_json, true );
			if ( ! is_array( $polygon ) || count( $polygon ) < 3 ) {
				return;
			}

			$coords = wms_get_session_coords();
			if ( ! $coords ) {
				return;
			}

			if ( ! wms_point_in_polygon( $coords['lat'], $coords['lng'], $polygon ) ) {
				return;
			}

			$this->add_rate( [
				'id'         => $this->get_rate_id(),
				'label'      => $this->title,
				'cost'       => (float) $this->get_option( 'cost', '0' ),
				'tax_status' => $this->tax_status,
			] );
		}
	}
}

// ============================================================
// 2. Point-in-polygon — ray casting algorithm
// ============================================================

function wms_point_in_polygon( float $lat, float $lng, array $polygon ): bool {
	$n      = count( $polygon );
	$inside = false;
	$j      = $n - 1;

	for ( $i = 0; $i < $n; $i++ ) {
		$xi = (float) $polygon[ $i ]['lat'];
		$yi = (float) $polygon[ $i ]['lng'];
		$xj = (float) $polygon[ $j ]['lat'];
		$yj = (float) $polygon[ $j ]['lng'];

		if ( ( ( $yi > $lng ) !== ( $yj > $lng ) ) &&
		     ( $lat < ( $xj - $xi ) * ( $lng - $yi ) / ( $yj - $yi ) + $xi ) ) {
			$inside = ! $inside;
		}
		$j = $i;
	}

	return $inside;
}

// ============================================================
// 3. Session — store / retrieve customer coordinates
// ============================================================

function wms_get_session_coords(): ?array {
	// Try the WC session first (set by extensionCartUpdate callback).
	if ( function_exists( 'WC' ) && WC()->session ) {
		$lat = WC()->session->get( 'wms_lat' );
		$lng = WC()->session->get( 'wms_lng' );
		if ( $lat !== null && $lng !== null ) {
			return [ 'lat' => (float) $lat, 'lng' => (float) $lng ];
		}
	}

	// Cookie fallback: set by JS on every pin and sent automatically with
	// every browser request — including update-customer (country change),
	// where the Store API session can be initialised before our callback ran.
	if ( ! empty( $_COOKIE['wms_lat'] ) && ! empty( $_COOKIE['wms_lng'] ) ) {
		return [
			'lat' => (float) $_COOKIE['wms_lat'],
			'lng' => (float) $_COOKIE['wms_lng'],
		];
	}

	return null;
}

function wms_set_session_coords( float $lat, float $lng ): void {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return;
	}
	WC()->session->set( 'wms_lat', $lat );
	WC()->session->set( 'wms_lng', $lng );
}

// ============================================================
// 4. When inside a polygon, suppress all non-WMS shipping methods
// ============================================================

// If at least one WMS rate matched (customer is inside a polygon), remove every
// other method in the same package so only the polygon-specific option shows.
// When outside all polygons, no WMS rate is added and other methods show normally.
add_filter( 'woocommerce_package_rates', 'wms_suppress_non_polygon_rates', 20 );

function wms_suppress_non_polygon_rates( array $rates ): array {
	$wms_rates = array_filter( $rates, function ( $rate ) {
		return strpos( $rate->get_id(), 'wms_map_shipping' ) !== false;
	} );

	return ! empty( $wms_rates ) ? $wms_rates : $rates;
}

// ============================================================
// 5. Bust the shipping rate cache when coordinates change
// ============================================================

// WooCommerce caches rates in a transient keyed by a hash of the package.
// Adding the current coords to every package changes the hash when the pin
// moves, forcing WooCommerce to call calculate_shipping() again.
add_filter( 'woocommerce_shipping_packages', 'wms_inject_coords_into_packages' );

function wms_inject_coords_into_packages( array $packages ): array {
	$coords = wms_get_session_coords();
	if ( ! $coords ) {
		return $packages;
	}
	foreach ( $packages as &$package ) {
		$package['wms_lat'] = $coords['lat'];
		$package['wms_lng'] = $coords['lng'];
	}
	return $packages;
}

// ============================================================
// 5. Classic checkout — coordinate capture + rendering
// ============================================================

// Capture coordinates from the serialised AJAX post_data (classic checkout).
add_action( 'woocommerce_checkout_update_order_review', 'wms_capture_coords_from_post_data', 5 );

function wms_capture_coords_from_post_data( string $post_data ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( isset( $_POST['wms_lat'], $_POST['wms_lng'] ) ) {
		wms_set_session_coords( (float) $_POST['wms_lat'], (float) $_POST['wms_lng'] );
		return;
	}
	parse_str( $post_data, $parsed );
	if ( isset( $parsed['wms_lat'], $parsed['wms_lng'] ) ) {
		wms_set_session_coords( (float) $parsed['wms_lat'], (float) $parsed['wms_lng'] );
	}
}

add_action( 'woocommerce_checkout_process', 'wms_capture_coords_on_submit', 1 );

function wms_capture_coords_on_submit(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( isset( $_POST['wms_lat'], $_POST['wms_lng'] ) ) {
		wms_set_session_coords( (float) $_POST['wms_lat'], (float) $_POST['wms_lng'] );
	}
}

// Render map + hidden inputs inside the classic checkout form.
add_action( 'woocommerce_checkout_before_customer_details', 'wms_render_checkout_map' );

function wms_render_checkout_map(): void {
	if ( ! wms_has_any_active_method() ) {
		return;
	}
	$coords    = wms_get_session_coords();
	$saved_lat = $coords ? $coords['lat'] : '';
	$saved_lng = $coords ? $coords['lng'] : '';
	?>
	<div id="wms-checkout-wrapper" style="margin-bottom:30px">
		<h3 style="margin-bottom:4px">Your delivery location</h3>
		<p id="wms-checkout-hint" style="margin:0 0 10px;color:#555;font-size:14px">
			<?php if ( $saved_lat ) : ?>
				Location selected. Click the map to move the pin.
			<?php else : ?>
				Click on the map to mark where you need delivery. Some shipping options are only available within certain areas.
			<?php endif; ?>
		</p>
		<div id="wms-checkout-map"
		     style="width:100%;height:380px;border:1px solid #ddd;border-radius:3px;cursor:crosshair;"></div>
		<input type="hidden" id="wms_lat" name="wms_lat" value="<?php echo esc_attr( $saved_lat ); ?>">
		<input type="hidden" id="wms_lng" name="wms_lng" value="<?php echo esc_attr( $saved_lng ); ?>">
	</div>
	<?php
}

// Classic checkout: validate a location was picked.
add_action( 'woocommerce_checkout_process', 'wms_validate_checkout_coords', 20 );

function wms_validate_checkout_coords(): void {
	if ( ! wms_has_any_active_method() ) {
		return;
	}
	if ( ! wms_get_session_coords() ) {
		wc_add_notice( 'Please select your delivery location on the map before placing your order.', 'error' );
	}
}

// Classic checkout: save coords to order meta.
add_action( 'woocommerce_checkout_create_order', 'wms_save_coords_to_order_classic', 10, 2 );

function wms_save_coords_to_order_classic( WC_Order $order, array $data ): void {
	$coords = wms_get_session_coords();
	if ( $coords ) {
		$order->update_meta_data( '_wms_delivery_lat', $coords['lat'] );
		$order->update_meta_data( '_wms_delivery_lng', $coords['lng'] );
	}
}

// ============================================================
// 5. Block checkout — Store API extensibility
// ============================================================

add_action( 'woocommerce_blocks_loaded', 'wms_blocks_init' );

function wms_blocks_init(): void {
	if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
		woocommerce_store_api_register_update_callback( [
			'namespace' => 'wms-map-shipping',
			'callback'  => function ( array $data ): void {
				if ( isset( $data['lat'], $data['lng'] ) ) {
					wms_set_session_coords( (float) $data['lat'], (float) $data['lng'] );
					// Globally invalidate the shipping transient cache so WooCommerce
					// recalculates rates with the new coordinates in this same request.
					WC_Cache_Helper::get_transient_version( 'shipping', true );
				}
			},
		] );
	}
}

// When the customer changes any address field (e.g. country) in the block checkout,
// bust the shipping cache so WooCommerce re-evaluates our WMS method with the
// session coordinates rather than returning a stale cached result.
add_action( 'woocommerce_store_api_cart_update_customer_from_request', 'wms_bust_cache_on_address_change', 10, 2 );

function wms_bust_cache_on_address_change( $customer, WP_REST_Request $request ): void {
	if ( wms_get_session_coords() ) {
		WC_Cache_Helper::get_transient_version( 'shipping', true );
	}
}

// Block checkout: save coords to order meta + validate on submit.
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'wms_blocks_checkout_order_update', 10, 2 );

function wms_blocks_checkout_order_update( WC_Order $order, WP_REST_Request $request ): void {
	$coords = wms_get_session_coords();

	if ( $coords ) {
		$order->update_meta_data( '_wms_delivery_lat', $coords['lat'] );
		$order->update_meta_data( '_wms_delivery_lng', $coords['lng'] );
		return;
	}

	if ( wms_has_any_active_method() ) {
		// Throw a Store API error to block the order.
		$exception_class = '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException';
		if ( class_exists( $exception_class ) ) {
			throw new $exception_class(
				'wms_no_location',
				'Please select your delivery location on the map before placing your order.',
				400
			);
		}
	}
}

// ============================================================
// 6. Show delivery coordinates on the order admin page
// ============================================================

add_action( 'woocommerce_admin_order_data_after_shipping_address', 'wms_show_coords_in_order_admin' );

function wms_show_coords_in_order_admin( WC_Order $order ): void {
	$html = wms_get_coords_html( $order );
	if ( $html ) {
		echo $html;
	}
}

/**
 * Builds the "Map delivery point" HTML snippet for an order, or '' if the
 * order has no stored coordinates. Shared by the admin order page, the
 * customer-facing order details, and HTML emails.
 */
function wms_get_coords_html( WC_Order $order ): string {
	$lat = $order->get_meta( '_wms_delivery_lat' );
	$lng = $order->get_meta( '_wms_delivery_lng' );
	if ( ! $lat || ! $lng ) {
		return '';
	}
	$osm_url    = wms_get_osm_map_url( $lat, $lng );
	$google_url = wms_get_google_map_url( $lat, $lng );
	return '<p><strong>Map delivery point:</strong> '
		. esc_html( number_format( (float) $lat, 6 ) ) . ', '
		. esc_html( number_format( (float) $lng, 6 ) )
		. ' — <a href="' . esc_url( $osm_url ) . '" target="_blank">View on OpenStreetMap</a>'
		. ' | <a href="' . esc_url( $google_url ) . '" target="_blank">View on Google Maps</a></p>';
}

function wms_get_osm_map_url( $lat, $lng ): string {
	return 'https://www.openstreetmap.org/?mlat=' . urlencode( $lat ) . '&mlon=' . urlencode( $lng ) . '&zoom=16';
}

function wms_get_google_map_url( $lat, $lng ): string {
	return 'https://www.google.com/maps/search/?api=1&query=' . urlencode( $lat ) . ',' . urlencode( $lng );
}

// ============================================================
// 6b. Show delivery coordinates on the customer-facing order details
//     (thank-you page, My Account → Orders → view order)
// ============================================================

add_action( 'woocommerce_order_details_after_order_table', 'wms_show_coords_in_order_details' );

function wms_show_coords_in_order_details( WC_Order $order ): void {
	$html = wms_get_coords_html( $order );
	if ( $html ) {
		echo $html;
	}
}

// ============================================================
// 6c. Include delivery coordinates in WooCommerce emails
// ============================================================

add_action( 'woocommerce_email_order_meta', 'wms_show_coords_in_email', 10, 3 );

function wms_show_coords_in_email( WC_Order $order, $sent_to_admin, $plain_text ): void {
	$lat = $order->get_meta( '_wms_delivery_lat' );
	$lng = $order->get_meta( '_wms_delivery_lng' );
	if ( ! $lat || ! $lng ) {
		return;
	}

	$lat_str    = number_format( (float) $lat, 6 );
	$lng_str    = number_format( (float) $lng, 6 );
	$osm_url    = wms_get_osm_map_url( $lat, $lng );
	$google_url = wms_get_google_map_url( $lat, $lng );

	if ( $plain_text ) {
		echo "Map delivery point: {$lat_str}, {$lng_str}\n";
		echo "View on OpenStreetMap: {$osm_url}\n";
		echo "View on Google Maps: {$google_url}\n";
	} else {
		echo '<p><strong>Map delivery point:</strong> '
			. esc_html( $lat_str ) . ', ' . esc_html( $lng_str )
			. ' — <a href="' . esc_url( $osm_url ) . '" target="_blank">View on OpenStreetMap</a>'
			. ' | <a href="' . esc_url( $google_url ) . '" target="_blank">View on Google Maps</a></p>';
	}
}

// ============================================================
// 7. AJAX — supply zone polygons to the checkout map JS
// ============================================================

add_action( 'wp_ajax_wms_get_zone_polygons',        'wms_ajax_get_zone_polygons' );
add_action( 'wp_ajax_nopriv_wms_get_zone_polygons', 'wms_ajax_get_zone_polygons' );

function wms_ajax_get_zone_polygons(): void {
	$zones_to_check = array_values( WC_Shipping_Zones::get_zones() );
	try {
		$rest = WC_Shipping_Zones::get_zone_by( 'id', 0 );
		if ( $rest ) {
			$zones_to_check[] = $rest->get_data();
		}
	} catch ( Exception $e ) {
		// ignore.
	}

	$result = [];

	foreach ( $zones_to_check as $zone_data ) {
		$zone_id = $zone_data['id'] ?? $zone_data['zone_id'] ?? 0;
		try {
			$zone = new WC_Shipping_Zone( $zone_id );
		} catch ( Exception $e ) {
			continue;
		}
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( $method->id !== 'wms_map_shipping' ) {
				continue;
			}
			$polygon_json = $method->get_option( 'polygon', '' );
			if ( ! $polygon_json ) {
				continue;
			}
			$polygon = json_decode( $polygon_json, true );
			if ( ! is_array( $polygon ) || count( $polygon ) < 3 ) {
				continue;
			}
			$result[] = [
				'label'   => $method->get_option( 'title', 'Delivery Zone' ),
				'polygon' => $polygon,
			];
		}
	}

	wp_send_json_success( $result );
}

// ============================================================
// 8. Enqueue scripts & styles
// ============================================================

add_action( 'admin_enqueue_scripts', 'wms_admin_enqueue' );

function wms_admin_enqueue( string $hook ): void {
	if ( $hook !== 'woocommerce_page_wc-settings' ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ( $_GET['tab'] ?? '' ) !== 'shipping' ) {
		return;
	}
	wms_enqueue_leaflet( 'admin' );
}

add_action( 'wp_enqueue_scripts', 'wms_frontend_enqueue' );

function wms_frontend_enqueue(): void {
	if ( ! is_checkout() || ! wms_has_any_active_method() ) {
		return;
	}
	wms_enqueue_leaflet( 'frontend' );

	// Register a dedicated handle so we can declare wc-blocks-checkout as a dependency.
	// This fixes the WooCommerce "accessed without dependency" warning and ensures our
	// script can reliably access wc.blocksCheckout.extensionCartUpdate on block checkout.
	$deps = [ 'wms-leaflet' ];
	if ( wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
		$deps[] = 'wc-blocks-checkout';
	}
	wp_register_script( 'wms-checkout', false, $deps, WMS_VERSION, true );
	wp_enqueue_script( 'wms-checkout' );
	wp_add_inline_script( 'wms-checkout', wms_checkout_js() );
}

function wms_enqueue_leaflet( string $context ): void {
	$ver = '1.9.4';
	$cdn = 'https://unpkg.com/leaflet@' . $ver;

	wp_enqueue_style( 'wms-leaflet', $cdn . '/dist/leaflet.css', [], $ver );
	wp_enqueue_script( 'wms-leaflet', $cdn . '/dist/leaflet.js', [], $ver, true );

	if ( $context === 'admin' ) {
		$dv  = '1.0.4';
		$dcd = 'https://unpkg.com/leaflet-draw@' . $dv;
		wp_enqueue_style( 'wms-leaflet-draw', $dcd . '/dist/leaflet.draw.css', [ 'wms-leaflet' ], $dv );
		wp_enqueue_script( 'wms-leaflet-draw', $dcd . '/dist/leaflet.draw.js', [ 'wms-leaflet' ], $dv, true );
	}
}

// ============================================================
// 9. Checkout JavaScript — works for both classic and block checkout
// ============================================================

function wms_checkout_js(): string {
	$ajax_url  = esc_url( admin_url( 'admin-ajax.php' ) );
	$coords    = wms_get_session_coords();
	$saved_lat = $coords ? (float) $coords['lat'] : 'null';
	$saved_lng = $coords ? (float) $coords['lng'] : 'null';

	return <<<JS
(function () {
    var savedLat = {$saved_lat};
    var savedLng = {$saved_lng};
    var ajaxUrl  = '{$ajax_url}';

    // Last coords the customer pinned — kept in sync so we can re-send them
    // whenever the address changes (e.g. country selection) without requiring
    // the customer to click the map again.
    var lastLat = savedLat;
    var lastLng = savedLng;

    // ----------------------------------------------------------------
    // Persist coords in a session cookie so every Store API request
    // (including update-customer triggered by country changes) carries them.
    // ----------------------------------------------------------------
    function pinCoords(lat, lng) {
        lastLat = lat; lastLng = lng;
        document.cookie = 'wms_lat=' + lat + '; path=/; SameSite=Strict';
        document.cookie = 'wms_lng=' + lng + '; path=/; SameSite=Strict';
    }

    // ----------------------------------------------------------------
    // Shared: initialise Leaflet on a given map container element.
    // onLocationPicked(lat, lng) is called whenever the customer
    // places or moves the marker.
    // ----------------------------------------------------------------
    function initLeafletMap(mapEl, onLocationPicked) {
        if (typeof L === 'undefined') { setTimeout(function(){ initLeafletMap(mapEl, onLocationPicked); }, 100); return; }
        if (mapEl._wmsReady) return;
        mapEl._wmsReady = true;

        var initLat  = (savedLat !== null) ? savedLat : -25.746;
        var initLng  = (savedLng !== null) ? savedLng : 28.188;
        var initZoom = (savedLat !== null) ? 14 : 8;

        var map = L.map(mapEl).setView([initLat, initLng], initZoom);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            maxZoom: 19,
        }).addTo(map);

        var marker = null;
        if (savedLat !== null && savedLng !== null) {
            marker = L.marker([savedLat, savedLng], {draggable: true}).addTo(map);
            marker.on('dragend', function (e) { onLocationPicked(e.target.getLatLng().lat, e.target.getLatLng().lng); });
        }

        // Load and display zone polygons.
        fetch(ajaxUrl + '?action=wms_get_zone_polygons')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success || !res.data.length) return;
                var bounds = [];
                res.data.forEach(function (zone) {
                    var lls = zone.polygon.map(function (p) { return [p.lat, p.lng]; });
                    L.polygon(lls, {color: '#2271b1', fillColor: '#2271b1', fillOpacity: 0.08, weight: 2})
                        .addTo(map)
                        .bindTooltip(zone.label, {sticky: true});
                    lls.forEach(function (ll) { bounds.push(ll); });
                });
                if (savedLat === null && bounds.length) {
                    map.fitBounds(bounds, {padding: [30, 30]});
                }
            })
            .catch(function () {});

        map.on('click', function (e) {
            if (marker) {
                marker.setLatLng(e.latlng);
            } else {
                marker = L.marker(e.latlng, {draggable: true}).addTo(map);
                marker.on('dragend', function (ev) {
                    onLocationPicked(ev.target.getLatLng().lat, ev.target.getLatLng().lng);
                });
            }
            onLocationPicked(e.latlng.lat, e.latlng.lng);
        });
    }

    // ----------------------------------------------------------------
    // Reverse geocode a lat/lng and fill shipping address fields.
    // Uses Nominatim (OpenStreetMap) — free, no API key required.
    // ----------------------------------------------------------------
    function reverseGeocodeAndFill(lat, lng, mode, hintEl) {
        fetch(
            'https://nominatim.openstreetmap.org/reverse?lat=' + lat + '&lon=' + lng + '&format=json',
            { headers: { 'Accept-Language': 'en' } }
        )
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var a = data.address || {};
            var road  = a.road || a.pedestrian || a.footway || a.path || '';
            var addr1 = a.house_number ? a.house_number + ' ' + road : road;
            var city  = a.city || a.town || a.village || a.municipality || a.county || '';
            var addr  = {
                address_1: addr1.trim(),
                city:      city,
                state:     a.state || a.region || '',
                postcode:  a.postcode || '',
                country:   (a.country_code || '').toUpperCase(),
            };

            if (mode === 'block') {
                fillBlockShipping(addr);
            } else {
                fillClassicShipping(addr);
            }

            if (hintEl && addr1) {
                hintEl.textContent = 'Location set: ' + (data.display_name || addr1) + '. Drag the pin or click to adjust.';
            }
        })
        .catch(function () {
            if (hintEl) hintEl.textContent = 'Location selected. Please check the shipping address below.';
        });
    }

    // Block checkout: fill shipping address fields after a short delay so the
    // extensionCartUpdate server response (which resets the WC store) has settled.
    function fillBlockShipping(addr) {
        setTimeout(function () {
            // Primary: dispatch to the WC data store — React components re-render.
            // Try both action names that exist across WooCommerce Blocks versions.
            if (typeof wp !== 'undefined' && wp.data) {
                var dispatch = wp.data.dispatch('wc/store/cart');
                if (dispatch) {
                    if (typeof dispatch.setShippingAddress === 'function') {
                        dispatch.setShippingAddress(addr);
                    } else if (typeof dispatch.updateCustomerData === 'function') {
                        dispatch.updateCustomerData({ shipping_address: addr });
                    }
                }
            }

            // Supplement: native-setter trick for React-controlled text inputs.
            // Block checkout IDs are "shipping-{field_name}" — underscores are preserved,
            // NOT converted to hyphens (address_1 → shipping-address_1, not address-1).
            ['address_1', 'city', 'postcode'].forEach(function (field) {
                if (!addr[field]) return;
                var el = document.getElementById('shipping-' + field);
                if (!el) return;
                var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
                setter.call(el, addr[field]);
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }, 600);
    }

    // Classic checkout: set field values and trigger WC update.
    function fillClassicShipping(addr) {
        setInputVal('#shipping_address_1', addr.address_1);
        setInputVal('#shipping_city',      addr.city);
        setInputVal('#shipping_postcode',  addr.postcode);
        if (addr.country && typeof jQuery !== 'undefined') {
            jQuery('#shipping_country').val(addr.country).trigger('change');
            // Wait for the state field to re-render after the country change.
            setTimeout(function () { setInputVal('#shipping_state', addr.state); }, 400);
        } else {
            setInputVal('#shipping_state', addr.state);
        }
    }

    function setInputVal(selector, value) {
        if (!value) return;
        var el = document.querySelector(selector);
        if (!el) return;
        el.value = value;
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // ----------------------------------------------------------------
    // Classic checkout — map div injected by PHP, coords in hidden inputs.
    // ----------------------------------------------------------------
    function bootClassic() {
        var mapEl = document.getElementById('wms-checkout-map');
        if (!mapEl) return false;

        initLeafletMap(mapEl, function (lat, lng) {
            pinCoords(lat, lng);
            document.getElementById('wms_lat').value = lat;
            document.getElementById('wms_lng').value = lng;

            var hint = document.getElementById('wms-checkout-hint');
            if (hint) hint.textContent = 'Looking up address…';

            if (typeof jQuery !== 'undefined') {
                jQuery(document.body).trigger('update_checkout');
            }

            reverseGeocodeAndFill(lat, lng, 'classic', hint);
        });
        return true;
    }

    // ----------------------------------------------------------------
    // Send coordinates to the server via extensionCartUpdate.
    // Polls until wc.blocksCheckout is available (it loads async).
    // ----------------------------------------------------------------
    function sendCoordsToServer(lat, lng, attempt) {
        if (typeof wc !== 'undefined' && wc.blocksCheckout && wc.blocksCheckout.extensionCartUpdate) {
            wc.blocksCheckout.extensionCartUpdate({
                namespace: 'wms-map-shipping',
                data: { lat: lat, lng: lng },
            });
            return;
        }
        if (attempt < 20) {
            setTimeout(function () { sendCoordsToServer(lat, lng, attempt + 1); }, 200);
        }
    }

    // ----------------------------------------------------------------
    // Block checkout — inject a map section before the checkout block,
    // send coords via extensionCartUpdate so the Store API recalculates
    // shipping server-side.
    // ----------------------------------------------------------------
    function bootBlock() {
        // Selectors that WooCommerce block checkout uses (varies by WC version).
        var block = document.querySelector('.wp-block-woocommerce-checkout, .wc-block-checkout');
        if (!block) return false;
        if (document.getElementById('wms-block-checkout-wrapper')) return true; // already injected

        var wrapper = document.createElement('div');
        wrapper.id = 'wms-block-checkout-wrapper';
        wrapper.style.cssText = 'margin-bottom:28px';
        wrapper.innerHTML =
            '<h3 style="margin-bottom:4px">Your delivery location</h3>' +
            '<p id="wms-block-hint" style="margin:0 0 10px;color:#555;font-size:14px">' +
                (savedLat !== null
                    ? 'Location selected. Click the map to move the pin.'
                    : 'Click on the map to mark where you need delivery. Some shipping options are only available within certain areas.') +
            '</p>' +
            '<div id="wms-block-map" style="width:100%;height:380px;border:1px solid #ddd;border-radius:3px;cursor:crosshair;"></div>';

        block.parentNode.insertBefore(wrapper, block);

        var mapEl = document.getElementById('wms-block-map');

        initLeafletMap(mapEl, function (lat, lng) {
            pinCoords(lat, lng);
            var hint = document.getElementById('wms-block-hint');
            if (hint) hint.textContent = 'Looking up address…';
            sendCoordsToServer(lat, lng, 0);
            reverseGeocodeAndFill(lat, lng, 'block', hint);
        });

        return true;
    }

    // ----------------------------------------------------------------
    // Boot — try classic first; if not found, watch for the block checkout
    // to finish rendering (React renders it asynchronously).
    // ----------------------------------------------------------------
    function boot() {
        if (typeof L === 'undefined') { setTimeout(boot, 100); return; }

        if (bootClassic()) return;

        if (bootBlock()) return;

        // Block checkout hasn't rendered yet — observe DOM until it appears.
        var observer = new MutationObserver(function () {
            if (bootBlock()) observer.disconnect();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
JS;
}

// ============================================================
// 10. Helper — check if any WMS shipping method is active
// ============================================================

function wms_has_any_active_method(): bool {
	static $result = null;
	if ( $result !== null ) {
		return $result;
	}

	$zones_to_check = array_values( WC_Shipping_Zones::get_zones() );
	try {
		$rest = WC_Shipping_Zones::get_zone_by( 'id', 0 );
		if ( $rest ) {
			$zones_to_check[] = $rest->get_data();
		}
	} catch ( Exception $e ) {
		// ignore.
	}

	foreach ( $zones_to_check as $zone_data ) {
		$zone_id = $zone_data['id'] ?? $zone_data['zone_id'] ?? 0;
		try {
			$zone = new WC_Shipping_Zone( $zone_id );
		} catch ( Exception $e ) {
			continue;
		}
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( $method->id === 'wms_map_shipping' ) {
				$result = true;
				return true;
			}
		}
	}

	$result = false;
	return false;
}
