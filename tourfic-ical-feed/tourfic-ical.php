<?php
/**
 * Plugin Name: Tourfic iCal Integration
 * Description: Exposes a subscribable iCal feed of Tourfic tour bookings. Configure the feed URL under Settings → Tourfic iCal.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'TICAL_VERSION',            '1.1.0' );
define( 'TICAL_OPTION_TOKEN',       'tourfic_ical_token' );
define( 'TICAL_OPTION_SHOW_EMPTY',  'tourfic_ical_show_empty' );
define( 'TICAL_DAYS_PAST',          30 );

// ---------------------------------------------------------------------------
// Activation
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, 'tical_activate' );

function tical_activate() {
	if ( ! get_option( TICAL_OPTION_TOKEN ) ) {
		update_option( TICAL_OPTION_TOKEN, bin2hex( random_bytes( 16 ) ) );
	}
}

// ---------------------------------------------------------------------------
// Admin menu & page
// ---------------------------------------------------------------------------

add_action( 'admin_menu', 'tical_admin_menu' );

function tical_admin_menu() {
	add_options_page(
		'Tourfic iCal',
		'Tourfic iCal',
		'manage_options',
		'tourfic-ical',
		'tical_admin_page'
	);
}

function tical_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Handle token regeneration.
	if (
		isset( $_POST['tical_regenerate'] ) &&
		check_admin_referer( 'tical_regenerate_token', 'tical_nonce' )
	) {
		update_option( TICAL_OPTION_TOKEN, bin2hex( random_bytes( 16 ) ) );
		echo '<div class="notice notice-success"><p><strong>Token regenerated.</strong> Update your calendar subscription with the new URL below.</p></div>';
	}

	// Handle settings save.
	if (
		isset( $_POST['tical_save_settings'] ) &&
		check_admin_referer( 'tical_save_settings', 'tical_settings_nonce' )
	) {
		update_option( TICAL_OPTION_SHOW_EMPTY, ! empty( $_POST['tical_show_empty'] ) ? 1 : 0 );
		echo '<div class="notice notice-success"><p><strong>Settings saved.</strong></p></div>';
	}

	$token        = get_option( TICAL_OPTION_TOKEN );
	$feed_url     = esc_url( add_query_arg( 'tourfic_ical', $token, home_url( '/' ) ) );
	$nonce_field  = wp_nonce_field( 'tical_regenerate_token', 'tical_nonce', true, false );
	$show_empty   = (bool) get_option( TICAL_OPTION_SHOW_EMPTY, 0 );
	?>
	<div class="wrap">
		<h1>Tourfic iCal Feed</h1>
		<p>Use the URL below to subscribe to the tour bookings calendar. It includes all completed bookings from the past <?php echo TICAL_DAYS_PAST; ?> days and all future events. Each tour-date combination appears as a single calendar event containing all booking details.</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Subscribe URL</th>
				<td>
					<input
						type="text"
						id="tical-feed-url"
						value="<?php echo esc_attr( $feed_url ); ?>"
						class="large-text code"
						readonly
					/>
					<button
						type="button"
						class="button"
						style="margin-top:6px"
						onclick="
							navigator.clipboard.writeText(document.getElementById('tical-feed-url').value)
								.then(function(){ this.textContent='Copied!'; }.bind(this))
								.catch(function(){ alert('Copy failed — please copy manually.'); });
						"
					>Copy URL</button>
					<p class="description">Paste this URL into Google Calendar, Apple Calendar, Thunderbird, or any iCal-compatible app as a subscribed calendar.</p>
				</td>
			</tr>
		</table>

		<hr>

		<h2>Settings</h2>
		<form method="post">
			<?php echo wp_nonce_field( 'tical_save_settings', 'tical_settings_nonce', true, false ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Show available dates with no bookings</th>
					<td>
						<label>
							<input
								type="checkbox"
								name="tical_show_empty"
								value="1"
								<?php checked( $show_empty ); ?>
							/>
							Include a placeholder event for each available tour date that has no bookings yet
						</label>
						<p class="description">When enabled, all dates listed in each tour's availability schedule appear on the calendar as <em>"Tour Name — No Bookings"</em>. As soon as a booking exists for that date the placeholder is replaced automatically.</p>
					</td>
				</tr>
			</table>
			<p><input type="submit" name="tical_save_settings" class="button button-primary" value="Save Settings"></p>
		</form>

		<hr>

		<h2>Regenerate Token</h2>
		<p class="description" style="color:#d63638">⚠ Regenerating the token will immediately break any existing calendar subscriptions. You will need to re-subscribe using the new URL.</p>
		<form method="post">
			<?php echo $nonce_field; ?>
			<p><input type="submit" name="tical_regenerate" class="button button-secondary" value="Regenerate Token"></p>
		</form>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// iCal endpoint
// ---------------------------------------------------------------------------

add_filter( 'query_vars', 'tical_register_query_var' );

function tical_register_query_var( $vars ) {
	$vars[] = 'tourfic_ical';
	return $vars;
}

add_action( 'template_redirect', 'tical_maybe_serve_feed' );

function tical_maybe_serve_feed() {
	$requested_token = get_query_var( 'tourfic_ical' );
	if ( ! $requested_token ) {
		return;
	}

	$stored_token = get_option( TICAL_OPTION_TOKEN );

	if ( ! $stored_token || ! hash_equals( (string) $stored_token, (string) $requested_token ) ) {
		status_header( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		exit( 'Forbidden' );
	}

	$rows   = tical_get_bookings();
	$groups = tical_group_bookings( $rows );

	if ( get_option( TICAL_OPTION_SHOW_EMPTY ) ) {
		$empty = tical_get_available_slots( array_fill_keys( array_keys( $groups ), true ) );
		$groups = array_merge( $groups, $empty );
		uasort( $groups, function ( $a, $b ) {
			$ak = $a['post_id'] . $a['check_in'];
			$bk = $b['post_id'] . $b['check_in'];
			return strcmp( $ak, $bk );
		} );
	}

	tical_output_ical( $groups );
	exit;
}

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------

function tical_get_bookings() {
	global $wpdb;

	$table     = $wpdb->prefix . 'tf_order_data';
	$days_past = TICAL_DAYS_PAST;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT
				tod.id,
				tod.order_id,
				tod.post_id,
				tod.post_type,
				tod.check_in,
				tod.check_out,
				tod.billing_details,
				tod.order_details,
				tod.ostatus,
				tod.order_date,
				p.post_title
			FROM `$table` AS tod
			LEFT JOIN {$wpdb->posts} AS p ON p.ID = tod.post_id
			WHERE tod.ostatus = 'completed'
			  AND tod.check_in >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
			ORDER BY tod.post_id ASC, tod.check_in ASC, tod.order_id ASC",
			$days_past
		),
		ARRAY_A
	);

	return $rows ?: array();
}

function tical_group_bookings( array $rows ) {
	$groups = array();

	foreach ( $rows as $row ) {
		$key = $row['post_id'] . '|' . $row['check_in'];

		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'post_id'       => (int) $row['post_id'],
				'check_in'      => $row['check_in'],
				'check_out'     => tical_normalize_check_out( $row['check_out'], $row['check_in'] ),
				'post_title'    => $row['post_title'] ?: 'Tour #' . $row['post_id'],
				'bookings'      => array(),
				'last_modified' => $row['order_date'],
			);
		}

		$groups[ $key ]['bookings'][] = $row;

		if ( $row['order_date'] > $groups[ $key ]['last_modified'] ) {
			$groups[ $key ]['last_modified'] = $row['order_date'];
		}
	}

	return $groups;
}

/**
 * Normalizes a booking's check_out date. Tourfic stores '0000-00-00' (MySQL
 * zero-date) for single-day tours instead of repeating the check_in date, so
 * treat any empty/zero date as "same as check_in".
 */
function tical_normalize_check_out( $check_out, $check_in ) {
	if ( ! $check_out || $check_out === '0000-00-00' ) {
		return $check_in;
	}
	return $check_out;
}

/**
 * Returns placeholder groups for every available tour date that has no booking.
 *
 * @param array $booked_keys Map of "post_id|check_in" keys that already have bookings.
 */
function tical_get_available_slots( array $booked_keys ): array {
	global $wpdb;

	$cutoff = gmdate( 'Y-m-d', strtotime( '-' . TICAL_DAYS_PAST . ' days' ) );

	// Fetch all published posts that have the tf_tours_opt meta (any Tourfic post type).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$posts = $wpdb->get_results(
		"SELECT p.ID, p.post_title, pm.meta_value
		 FROM {$wpdb->posts} AS p
		 INNER JOIN {$wpdb->postmeta} AS pm
		         ON pm.post_id = p.ID AND pm.meta_key = 'tf_tours_opt'
		 WHERE p.post_status = 'publish'",
		ARRAY_A
	);

	$groups = array();

	foreach ( $posts as $post ) {
		$opts  = maybe_unserialize( $post['meta_value'] ) ?: array();
		$avail = isset( $opts['tour_availability'] ) ? json_decode( $opts['tour_availability'], true ) : null;

		if ( ! is_array( $avail ) ) {
			continue;
		}

		foreach ( $avail as $date_key => $slot_data ) {
			// Key format: "YYYY/MM/DD - YYYY/MM/DD"
			if ( ! preg_match( '/^(\d{4}\/\d{2}\/\d{2}) - (\d{4}\/\d{2}\/\d{2})$/', $date_key, $m ) ) {
				continue;
			}

			if ( ( $slot_data['status'] ?? '' ) !== 'available' ) {
				continue;
			}

			$check_in  = str_replace( '/', '-', $m[1] );
			$check_out = str_replace( '/', '-', $m[2] );

			if ( $check_in < $cutoff ) {
				continue;
			}

			$key = $post['ID'] . '|' . $check_in;

			if ( isset( $booked_keys[ $key ] ) ) {
				continue; // Real booking exists — skip placeholder.
			}

			$groups[ $key ] = array(
				'post_id'        => (int) $post['ID'],
				'check_in'       => $check_in,
				'check_out'      => $check_out,
				'post_title'     => $post['post_title'] ?: 'Tour #' . $post['ID'],
				'bookings'       => array(),
				'last_modified'  => gmdate( 'Y-m-d H:i:s' ),
				'is_placeholder' => true,
			);
		}
	}

	return $groups;
}

// ---------------------------------------------------------------------------
// Post meta helpers
// ---------------------------------------------------------------------------

/**
 * Returns the unserialized tf_tours_opt array for a post. Results are cached
 * in a static variable so each post_id is only queried once per request.
 */
function tical_get_post_opts( int $post_id ): array {
	static $cache = array();
	if ( array_key_exists( $post_id, $cache ) ) {
		return $cache[ $post_id ];
	}
	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare(
		"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'tf_tours_opt' LIMIT 1",
		$post_id
	) );
	$cache[ $post_id ] = $raw ? ( maybe_unserialize( $raw ) ?: array() ) : array();
	return $cache[ $post_id ];
}

/**
 * Returns the max_capacity for a specific tour date slot, or null if not found.
 * Uses check_in / check_out in YYYY-MM-DD format.
 */
function tical_get_slot_capacity( int $post_id, string $check_in, string $check_out ): ?int {
	$opts  = tical_get_post_opts( $post_id );
	$avail = isset( $opts['tour_availability'] ) ? json_decode( $opts['tour_availability'], true ) : null;
	if ( ! is_array( $avail ) ) {
		return null;
	}
	$cin  = str_replace( '-', '/', $check_in );
	$cout = str_replace( '-', '/', $check_out );
	$cap  = $avail[ "$cin - $cout" ]['max_capacity'] ?? null;
	return ( $cap !== null && is_numeric( $cap ) ) ? (int) $cap : null;
}

/**
 * Resolves start time and duration for a booking group.
 *
 * Priority for start time:
 *   1. order_details.tour_time on any booking in the group (first non-empty).
 *   2. tour_availability in the post's tf_tours_opt meta, matched by date key.
 *
 * Duration comes from tf_tours_opt duration + duration_time fields.
 *
 * Returns array with keys:
 *   'start_dt'         => DateTime|null  (in UTC, ready for iCal Z format)
 *   'end_dt'           => DateTime|null  (start + duration, or start + 1 hr if no duration)
 *   'is_timed'         => bool
 */
function tical_resolve_time_duration( array $group ): array {
	$no_time = array( 'start_dt' => null, 'end_dt' => null, 'is_timed' => false );

	// 1. Try tour_time from each booking's order_details.
	$start_time_str = '';
	foreach ( $group['bookings'] as $booking ) {
		$details = json_decode( $booking['order_details'], true ) ?: array();
		$t       = trim( $details['tour_time'] ?? '' );
		if ( $t !== '' ) {
			$start_time_str = $t;
			break;
		}
	}

	// 2. Fall back to tour_availability in postmeta.
	$opts = tical_get_post_opts( $group['post_id'] );
	if ( $start_time_str === '' && ! empty( $opts['tour_availability'] ) ) {
		$avail = json_decode( $opts['tour_availability'], true );
		if ( is_array( $avail ) ) {
			// Key format used by Tourfic: "YYYY/MM/DD - YYYY/MM/DD"
			$cin  = gmdate( 'Y/m/d', strtotime( $group['check_in'] ) );
			$cout = gmdate( 'Y/m/d', strtotime( $group['check_out'] ) );
			$key  = "$cin - $cout";
			$times = $avail[ $key ]['allowed_time']['time'] ?? array();
			foreach ( (array) $times as $t ) {
				if ( trim( $t ) !== '' ) {
					$start_time_str = trim( $t );
					break;
				}
			}
		}
	}

	if ( $start_time_str === '' ) {
		return $no_time;
	}

	// 3. Parse the start time string into a DateTime using the site timezone,
	//    then convert to UTC for iCal output (no VTIMEZONE block needed).
	$site_tz  = wp_timezone();
	$date_str = $group['check_in']; // YYYY-MM-DD

	// Try common formats: "8:45 AM", "08:45 AM", "08:45"
	$start_dt = DateTime::createFromFormat( 'Y-m-d g:i A', "$date_str $start_time_str", $site_tz );
	if ( ! $start_dt ) {
		$start_dt = DateTime::createFromFormat( 'Y-m-d H:i', "$date_str $start_time_str", $site_tz );
	}
	if ( ! $start_dt ) {
		$start_dt = DateTime::createFromFormat( 'Y-m-d g:i a', "$date_str $start_time_str", $site_tz );
	}
	if ( ! $start_dt ) {
		return $no_time;
	}
	$start_dt->setTimezone( new DateTimeZone( 'UTC' ) );

	// 4. Determine duration in minutes from postmeta.
	$duration_minutes = null;
	$dur_val  = isset( $opts['duration'] ) ? (string) $opts['duration'] : '';
	$dur_unit = strtolower( $opts['duration_time'] ?? '' );
	if ( $dur_val !== '' && is_numeric( $dur_val ) ) {
		$dur = (float) $dur_val;
		if ( strpos( $dur_unit, 'hour' ) !== false || strpos( $dur_unit, 'hr' ) !== false ) {
			$duration_minutes = (int) round( $dur * 60 );
		} elseif ( strpos( $dur_unit, 'day' ) !== false ) {
			$duration_minutes = (int) round( $dur * 1440 );
		} elseif ( strpos( $dur_unit, 'min' ) !== false ) {
			$duration_minutes = (int) round( $dur );
		}
	}

	$end_dt = clone $start_dt;
	$end_dt->modify( '+' . ( $duration_minutes ?? 60 ) . ' minutes' );

	return array(
		'start_dt' => $start_dt,
		'end_dt'   => $end_dt,
		'is_timed' => true,
	);
}

// ---------------------------------------------------------------------------
// iCal output
// ---------------------------------------------------------------------------

/**
 * Counts total visitors across all bookings in a group by counting entries
 * in each booking's visitor_details JSON object.
 */
function tical_count_visitors( array $bookings ): int {
	$total = 0;
	foreach ( $bookings as $booking ) {
		$details  = json_decode( $booking['order_details'], true ) ?: array();
		$visitors = json_decode( $details['visitor_details'] ?? '', true );
		if ( is_array( $visitors ) ) {
			$total += count( $visitors );
		}
	}
	return $total;
}

function tical_output_ical( array $groups ) {
	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Content-Disposition: inline; filename="tourfic-bookings.ics"' );
	header( 'Cache-Control: no-cache, must-revalidate' );

	$domain = wp_parse_url( home_url(), PHP_URL_HOST );
	$now    = gmdate( 'Ymd\THis\Z' );

	$lines = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//Tourfic iCal Integration//EN',
		'CALSCALE:GREGORIAN',
		'METHOD:PUBLISH',
		'X-WR-CALNAME:Tourfic Bookings',
		'X-WR-TIMEZONE:UTC',
	);

	foreach ( $groups as $group ) {
		$check_in  = $group['check_in'];
		$check_out = $group['check_out'];

		$last_mod     = gmdate( 'Ymd\THis\Z', strtotime( $group['last_modified'] ) );
		$uid          = 'tourfic-' . $group['post_id'] . '-' . $check_in . '@' . $domain;
		$is_holder = ! empty( $group['is_placeholder'] );
		$capacity  = tical_get_slot_capacity( $group['post_id'], $check_in, $check_out );
		$cap_str   = $capacity !== null ? (string) $capacity : '?';

		if ( $is_holder ) {
			$summary     = '[0/' . $cap_str . '] ' . $group['post_title'];
			$description = 'No bookings yet for this date.';
		} else {
			$visitor_count = tical_count_visitors( $group['bookings'] );
			$summary       = '[' . $visitor_count . '/' . $cap_str . '] ' . $group['post_title'];
			$description   = tical_format_description( $group['bookings'] );
		}

		$timing = tical_resolve_time_duration( $group );

		if ( $timing['is_timed'] ) {
			$dtstart_prop = 'DTSTART:' . $timing['start_dt']->format( 'Ymd\THis\Z' );
			$dtend_prop   = 'DTEND:'   . $timing['end_dt']->format( 'Ymd\THis\Z' );
		} else {
			// All-day fallback. DTEND is exclusive so always check_out + 1 day.
			$dtstart_prop = 'DTSTART;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $check_in ) );
			$dtend_prop   = 'DTEND;VALUE=DATE:'   . gmdate( 'Ymd', strtotime( $check_out . ' +1 day' ) );
		}

		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . $uid;
		$lines[] = $dtstart_prop;
		$lines[] = $dtend_prop;
		$lines[] = 'SUMMARY:' . tical_escape( $summary );
		$lines[] = 'DESCRIPTION:' . tical_escape( $description );
		$lines[] = 'LAST-MODIFIED:' . $last_mod;
		$lines[] = 'DTSTAMP:' . $now;
		$lines[] = 'END:VEVENT';
	}

	$lines[] = 'END:VCALENDAR';

	// Fold and output with CRLF line endings per RFC 5545.
	foreach ( $lines as $line ) {
		echo tical_fold( $line ) . "\r\n";
	}
}

// ---------------------------------------------------------------------------
// Description formatter
// ---------------------------------------------------------------------------

function tical_format_description( array $bookings ) {
	$parts = array();

	foreach ( $bookings as $booking ) {
		$billing  = json_decode( $booking['billing_details'], true ) ?: array();
		$details  = json_decode( $booking['order_details'], true ) ?: array();

		$first_name = $billing['billing_first_name'] ?? '';
		$last_name  = $billing['billing_last_name'] ?? '';
		$email      = $billing['billing_email'] ?? '';
		$phone      = $billing['billing_phone'] ?? '';

		$adult   = html_entity_decode( $details['adult'] ?? '', ENT_HTML5, 'UTF-8' );
		$child   = html_entity_decode( $details['child'] ?? '', ENT_HTML5, 'UTF-8' );
		$infants = html_entity_decode( $details['infants'] ?? '', ENT_HTML5, 'UTF-8' );
		$total   = html_entity_decode( $details['total_price'] ?? '', ENT_HTML5, 'UTF-8' );
		$status  = ucfirst( $booking['ostatus'] ?? '' );
		$booked  = $booking['order_date'] ? gmdate( 'Y-m-d H:i', strtotime( $booking['order_date'] ) ) : '';

		$block = "BOOKING #{$booking['order_id']} | Status: {$status}\n";
		$block .= "Customer: {$first_name} {$last_name}";
		if ( $email ) $block .= " | Email: {$email}";
		if ( $phone ) $block .= " | Phone: {$phone}";
		$block .= "\n";
		$block .= "Adults: {$adult} | Children: {$child} | Infants: {$infants} | Total: \u{20AC}{$total}\n";
		if ( $booked ) $block .= "Booked: {$booked}\n";

		// Visitor details — double-encoded JSON string inside order_details.
		$visitor_json = $details['visitor_details'] ?? '';
		if ( $visitor_json ) {
			$visitors = json_decode( $visitor_json, true );
			if ( is_array( $visitors ) ) {
				foreach ( $visitors as $num => $v ) {
					$fname  = $v['firstname'] ?? '';
					$lname  = $v['familyname'] ?? '';
					$dob    = $v['tf_dob'] ?? '';
					$age    = $v['age'] ?? '';
					$nid    = $v['tf_nid'] ?? '';
					$nat    = strtoupper( $v['nationality'] ?? '' );
					$wa     = $v['Whatsapp'] ?? '';
					$height = $v['height'] ?? '';
					$weight = $v['weight'] ?? '';
					$addr   = $v['muraddress'] ?? '';
					$allerg = $v['allergies'] ?? '';
					$other  = $v['mediother'] ?? '';

					$gender  = tical_decode_values( (array) ( $v['gender'] ?? array() ),  tical_swim_map()['gender'] );
					$swim    = tical_decode_values( (array) ( $v['swim'] ?? array() ),    tical_swim_map()['swim'] );
					$meal    = tical_decode_values( (array) ( $v['meal'] ?? array() ),    tical_swim_map()['meal'] );
					$medical = tical_decode_values( (array) ( $v['medical'] ?? array() ), tical_swim_map()['medical'] );
					$risk    = tical_decode_values( (array) ( $v['risk'] ?? array() ),    tical_swim_map()['risk'] );

					$block .= "\nVISITOR {$num}: {$fname} {$lname}\n";
					$block .= "  DOB: {$dob}";
					if ( $age )    $block .= " | Age: {$age}";
					if ( $gender ) $block .= " | Gender: {$gender}";
					$block .= "\n";
					if ( $nid )    $block .= "  NID: {$nid}";
					if ( $nat )    $block .= " | Nationality: {$nat}";
					if ( $wa )     $block .= " | Whatsapp: {$wa}";
					if ( $nid || $nat || $wa ) $block .= "\n";
					if ( $height || $weight ) {
						$block .= "  Height: {$height}cm | Weight: {$weight}kg\n";
					}
					if ( $swim || $meal ) {
						$block .= "  Swim: " . ( $swim ?: '—' ) . " | Meal: " . ( $meal ?: '—' ) . "\n";
					}
					$block .= "  Medical: " . ( $medical ?: '—' ) . "\n";
					$block .= "  Allergies: " . ( $allerg ?: '—' ) . " | Other medical: " . ( $other ?: '—' ) . "\n";
					if ( $addr ) $block .= "  Address: {$addr}\n";
					if ( $risk ) $block .= "  Risk: {$risk}\n";
				}
			}
		}

		$parts[] = $block;
	}

	return implode( "\n---\n", $parts );
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function tical_swim_map() {
	return array(
		'swim'    => array(
			'modswim'  => 'Moderate Swimmer',
			'noswim'   => 'Non-Swimmer',
			'goodswim' => 'Good Swimmer',
		),
		'meal'    => array(
			'nonage' => 'No Age Restriction',
			'veg'    => 'Vegetarian',
			'halal'  => 'Halal',
			'nonveg' => 'Non-Vegetarian',
		),
		'gender'  => array(
			'male'   => 'Male',
			'female' => 'Female',
		),
		'medical' => array(
			'mediheart' => 'Heart Condition',
			'nomedic'   => 'No Medical Conditions',
			'hernia'    => 'Hernia',
		),
		'risk'    => array(
			'acceptrisk' => 'Accept Risk',
		),
	);
}

/**
 * Decodes an array of coded values to human-readable strings, joined by ", ".
 * Unknown codes are passed through as-is.
 */
function tical_decode_values( array $codes, array $map ) {
	$decoded = array();
	foreach ( $codes as $code ) {
		$decoded[] = $map[ $code ] ?? $code;
	}
	return implode( ', ', array_filter( $decoded ) );
}

/**
 * Escapes special characters for iCal text properties per RFC 5545.
 * Converts literal newlines to the iCal \n escape sequence.
 */
function tical_escape( $text ) {
	$text = str_replace( '\\', '\\\\', $text );
	$text = str_replace( ';', '\;', $text );
	$text = str_replace( ',', '\,', $text );
	$text = str_replace( "\r\n", '\n', $text );
	$text = str_replace( "\n", '\n', $text );
	$text = str_replace( "\r", '\n', $text );
	return $text;
}

/**
 * Folds a single iCal content line at 75 octets per RFC 5545 §3.1.
 * Continuation lines begin with a single space.
 */
function tical_fold( $line ) {
	// Work in bytes (mb_strcut handles multi-byte safely).
	$result = '';
	$first  = true;

	while ( strlen( $line ) > 75 ) {
		if ( $first ) {
			$result .= mb_strcut( $line, 0, 75 );
			$line    = mb_strcut( $line, 75 );
			$first   = false;
		} else {
			$result .= "\r\n " . mb_strcut( $line, 0, 74 );
			$line    = mb_strcut( $line, 74 );
		}
	}

	return $result . ( $first ? $line : "\r\n " . $line );
}
