<?php
/**
 * Course schedule — upcoming LALIA courses, read live from the ERP.
 *
 * Renders the schedule card designed in the Claude Design project
 * "Lalia Course Schedule Mockups" (options 3a and 3b) from the public
 * course-schedule endpoint:
 *
 *   GET https://api.lalia-berlin.com/functions/v1/public-course-schedule
 *
 * The endpoint is unauthenticated by design and already applies the seat
 * mask (an exact count only at three seats or fewer, `null` above that),
 * so nothing here may present `remaining_seats` as a stock figure. See
 * lalia-erp docs/developers/public-course-schedule-api.mdx for the contract.
 *
 * Shortcode: [lalia_course_schedule]
 *
 *   level       Level abbreviation or name ("N3", "Novice 3"), comma
 *               separated for several. Empty renders every level.
 *   layout      auto|single|all. `single` drops the Level column and lets
 *               the heading name the level (design 3a); `all` keeps it
 *               (design 3b). `auto` picks `single` when `level` resolves
 *               to exactly one level.
 *   per_level   auto|first|all. `first` keeps only each level's next
 *               start. `auto` means `all` for a single level, `first`
 *               across levels — so 3a lists a level's upcoming starts and
 *               3b lists one row per level, as the two mockups show.
 *   days        Window passed to the endpoint. Default 60, clamped 1-365.
 *   limit       Maximum rows. 0 (default) means no limit.
 *   title       Heading. Omitted when empty; derived when left unset.
 *   subtitle    Sub-line. Omitted when empty; derived when left unset —
 *               and only when every row agrees on rhythm and length.
 *   cta_text    Button label. Default "Purchase Now".
 *   cta_url     Button target. Empty renders no button; defaults to the
 *               pricing page on a standalone card and to none when inline.
 *   empty_text  Shown when nothing matches. Empty renders nothing.
 *   variant     card|inline. `inline` drops the panel, padding, heading,
 *               sub-line and button so the table can sit inside a card that
 *               already has them — the level containers on /novice-levels/
 *               and its siblings. Each of those is its own #f7f7f7 panel
 *               ending in a Purchase Now button; a second card nested inside
 *               would double the chrome.
 *   class       Extra class on the card.
 *
 * Failure behaviour: the last good payload is kept in an option and served
 * if the endpoint is unreachable. With no payload at all the shortcode
 * renders nothing visible — never an empty table, which would read as
 * "no courses are running" when the truth is "we could not ask".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Lalia_Course_Schedule' ) ) {
	class Lalia_Course_Schedule {

		const SHORTCODE = 'lalia_course_schedule';
		const API_URL   = 'https://api.lalia-berlin.com/functions/v1/public-course-schedule';

		/** Mirrors the endpoint's own Cache-Control: public, max-age=300. */
		const CACHE_TTL = 300;
		/** How long to wait before asking again after a failed fetch. */
		const RETRY_TTL = 60;
		const HTTP_TIMEOUT = 5;

		const TRANSIENT_PREFIX = 'lalia_course_schedule_';
		const FALLBACK_OPTION  = 'lalia_course_schedule_last_good';

		/** Seats are published only at or below this count — see the seat mask. */
		const SEAT_DISCLOSURE_MAX = 3;

		/** Where the standalone card's button points, matching the level pages. */
		const DEFAULT_CTA_URL = 'https://lalia-berlin.com/pricing/';

		/**
		 * Weekday order, with the single letters the design puts in the pills.
		 * Keys are the endpoint's lowercase English day names.
		 */
		const WEEKDAYS = array(
			'monday'    => 'M',
			'tuesday'   => 'T',
			'wednesday' => 'W',
			'thursday'  => 'T',
			'friday'    => 'F',
			'saturday'  => 'S',
			'sunday'    => 'S',
		);

		/** Sentinel for "attribute not supplied", so that title="" can mean "no title". */
		const UNSET_ATT = "\0auto";

		/** @var bool The card's CSS is printed once per request. */
		private static $printed_css = false;

		public static function init() {
			add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		}

		// -------------------------------------------------------------
		// Data
		// -------------------------------------------------------------

		/**
		 * Fetch the schedule, cached. Returns the decoded payload, or null
		 * when the endpoint failed and no usable copy is on hand.
		 *
		 * @param int $days Window size in days.
		 * @return array|null
		 */
		private static function fetch( $days ) {
			$key = self::TRANSIENT_PREFIX . $days;

			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			// A recent failure with nothing to fall back on. Don't re-ask on
			// every render: a page with two cards would double the load on an
			// endpoint that is already struggling.
			if ( get_transient( $key . '_down' ) ) {
				return null;
			}

			$response = wp_remote_get(
				add_query_arg( 'days', $days, self::API_URL ),
				array(
					'timeout' => self::HTTP_TIMEOUT,
					'headers' => array( 'Accept' => 'application/json' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return self::degrade( $days, $response->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return self::degrade( $days, 'HTTP ' . $code );
			}
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) || ! isset( $data['courses'] ) || ! is_array( $data['courses'] ) ) {
				return self::degrade( $days, 'unexpected payload' );
			}

			set_transient( $key, $data, self::CACHE_TTL );
			update_option(
				self::FALLBACK_OPTION,
				array(
					'days'      => $days,
					'stored_at' => time(),
					'data'      => $data,
				),
				false
			);

			return $data;
		}

		/**
		 * Serve the last good payload if we have one for this window.
		 *
		 * @param int    $days   Window size in days.
		 * @param string $reason Why the fetch failed, for the log.
		 * @return array|null
		 */
		private static function degrade( $days, $reason ) {
			$key    = self::TRANSIENT_PREFIX . $days;
			$stored = get_option( self::FALLBACK_OPTION );
			$have   = is_array( $stored )
				&& (int) ( isset( $stored['days'] ) ? $stored['days'] : 0 ) === $days
				&& isset( $stored['data']['courses'] )
				&& is_array( $stored['data']['courses'] );

			error_log(
				sprintf(
					'[lalia] course schedule fetch failed (%s); %s',
					$reason,
					$have
						? sprintf( 'serving copy from %s', gmdate( 'c', (int) $stored['stored_at'] ) )
						: 'no copy available, rendering nothing'
				)
			);

			if ( $have ) {
				set_transient( $key, $stored['data'], self::RETRY_TTL );
				return $stored['data'];
			}
			set_transient( $key . '_down', 1, self::RETRY_TTL );
			return null;
		}

		// -------------------------------------------------------------
		// Rendering
		// -------------------------------------------------------------

		public static function render( $atts ) {
			$atts = shortcode_atts(
				array(
					'level'      => '',
					'layout'     => 'auto',
					'per_level'  => 'auto',
					'days'       => 60,
					'limit'      => 0,
					'title'      => self::UNSET_ATT,
					'subtitle'   => self::UNSET_ATT,
					'cta_text'   => __( 'Purchase Now', 'lalia' ),
					'cta_url'    => self::UNSET_ATT,
					'empty_text' => self::UNSET_ATT,
					'variant'    => 'card',
					'class'      => '',
				),
				$atts,
				self::SHORTCODE
			);

			$days = max( 1, min( 365, (int) $atts['days'] ) );
			$data = self::fetch( $days );
			if ( null === $data ) {
				return "<!-- lalia course schedule: upstream unavailable -->";
			}

			$wanted = self::parse_levels( $atts['level'] );
			$rows   = self::select_rows( $data['courses'], $wanted, $atts );
			$single = self::is_single_layout( $atts, $wanted, $rows );
			$inline = 'inline' === strtolower( (string) $atts['variant'] );

			// Inside an existing card the heading, sub-line and button are
			// already on the page, so the inline variant renders none of them
			// unless a value was passed explicitly.
			$title = self::UNSET_ATT === $atts['title']
				? ( $inline ? '' : self::derive_title( $single, $rows, $atts['level'] ) )
				: $atts['title'];
			$subtitle = self::UNSET_ATT === $atts['subtitle']
				? ( $inline ? '' : self::derive_subtitle( $rows ) )
				: $atts['subtitle'];
			if ( self::UNSET_ATT === $atts['cta_url'] ) {
				$atts['cta_url'] = $inline ? '' : self::DEFAULT_CTA_URL;
			}
			$empty_text = self::UNSET_ATT === $atts['empty_text']
				? __( 'No upcoming courses are scheduled at the moment.', 'lalia' )
				: $atts['empty_text'];

			return self::css() . self::card( $rows, $single, $inline, $title, $subtitle, $empty_text, $atts );
		}

		/** @return string[] Lowercased level abbreviations or names to keep. */
		private static function parse_levels( $raw ) {
			if ( '' === trim( (string) $raw ) ) {
				return array();
			}
			$parts = array_map( 'trim', explode( ',', (string) $raw ) );
			$parts = array_filter( $parts, 'strlen' );
			return array_values( array_map( 'strtolower', $parts ) );
		}

		private static function matches_level( $course, $wanted ) {
			if ( empty( $wanted ) ) {
				return true;
			}
			$level = isset( $course['level'] ) && is_array( $course['level'] ) ? $course['level'] : array();
			$keys  = array(
				strtolower( (string) ( isset( $level['abbreviation'] ) ? $level['abbreviation'] : '' ) ),
				strtolower( (string) ( isset( $level['name'] ) ? $level['name'] : '' ) ),
			);
			return (bool) array_intersect( $wanted, array_filter( $keys, 'strlen' ) );
		}

		/** Stable per-level key: the abbreviation, falling back to the name. */
		private static function level_key( $course ) {
			$level = isset( $course['level'] ) && is_array( $course['level'] ) ? $course['level'] : array();
			foreach ( array( 'abbreviation', 'name' ) as $field ) {
				if ( ! empty( $level[ $field ] ) ) {
					return strtolower( (string) $level[ $field ] );
				}
			}
			return '';
		}

		/**
		 * Filter and trim the course list. The endpoint already sorts by
		 * start date, then start time, then level order — that order is kept.
		 */
		private static function select_rows( $courses, $wanted, $atts ) {
			$per_level = strtolower( (string) $atts['per_level'] );
			if ( ! in_array( $per_level, array( 'first', 'all' ), true ) ) {
				// auto: every start for one level, one start per level otherwise.
				$per_level = ( 1 === count( $wanted ) ) ? 'all' : 'first';
			}
			$limit = max( 0, (int) $atts['limit'] );

			$rows = array();
			$seen = array();
			foreach ( $courses as $course ) {
				if ( ! is_array( $course ) || ! self::matches_level( $course, $wanted ) ) {
					continue;
				}
				if ( 'first' === $per_level ) {
					$key = self::level_key( $course );
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$seen[ $key ] = true;
				}
				$rows[] = $course;
				if ( $limit && count( $rows ) >= $limit ) {
					break;
				}
			}
			return $rows;
		}

		private static function is_single_layout( $atts, $wanted, $rows ) {
			$layout = strtolower( (string) $atts['layout'] );
			if ( 'single' === $layout ) {
				return true;
			}
			if ( 'all' === $layout ) {
				return false;
			}
			if ( empty( $wanted ) ) {
				return false;
			}
			$keys = array();
			foreach ( $rows as $row ) {
				$keys[ self::level_key( $row ) ] = true;
			}
			// Filtered to one level and the data agrees (or is empty, in which
			// case the att is all we have to go on).
			return count( $keys ) <= 1;
		}

		private static function derive_title( $single, $rows, $level_att ) {
			if ( ! $single ) {
				return __( 'Upcoming courses', 'lalia' );
			}
			$name = '';
			if ( ! empty( $rows[0]['level']['name'] ) ) {
				$name = (string) $rows[0]['level']['name'];
			} elseif ( '' !== trim( (string) $level_att ) ) {
				// Nothing scheduled for this level; the attribute is all we know.
				$parts = explode( ',', (string) $level_att );
				$name  = trim( $parts[0] );
			}
			if ( '' === $name ) {
				return __( 'Upcoming courses', 'lalia' );
			}
			/* translators: %s: course level name, e.g. "Novice 3". */
			return sprintf( __( '%s — upcoming courses', 'lalia' ), $name );
		}

		/**
		 * "4 times a week, 50 minutes each session" — but only when every row
		 * agrees. Levels differ in rhythm and lesson length, so one sentence
		 * over a mixed table would simply be wrong.
		 */
		private static function derive_subtitle( $rows ) {
			if ( empty( $rows ) ) {
				return '';
			}
			$per_week = null;
			$minutes  = null;
			foreach ( $rows as $row ) {
				$days = self::course_days( $row );
				$n    = count( $days );
				$m    = self::session_minutes( $row );
				if ( ! $n || null === $m ) {
					return '';
				}
				if ( null === $per_week ) {
					$per_week = $n;
					$minutes  = $m;
				} elseif ( $per_week !== $n || $minutes !== $m ) {
					return '';
				}
			}
			return sprintf(
				/* translators: 1: sessions per week, 2: minutes per session. */
				_n(
					'%1$d time a week, %2$d minutes each session',
					'%1$d times a week, %2$d minutes each session',
					$per_week,
					'lalia'
				),
				$per_week,
				$minutes
			);
		}

		/** @return string[] The course's weekdays, in week order. */
		private static function course_days( $course ) {
			$days = isset( $course['days'] ) && is_array( $course['days'] ) ? $course['days'] : array();
			$days = array_map( 'strtolower', array_map( 'strval', $days ) );
			$days = array_intersect( array_keys( self::WEEKDAYS ), $days );
			return array_values( $days );
		}

		/** @return int|null Session length in minutes. */
		private static function session_minutes( $course ) {
			$start = self::minutes_of_day( isset( $course['start_time'] ) ? $course['start_time'] : null );
			$end   = self::minutes_of_day( isset( $course['end_time'] ) ? $course['end_time'] : null );
			if ( null === $start || null === $end || $end <= $start ) {
				return null;
			}
			return $end - $start;
		}

		private static function minutes_of_day( $time ) {
			if ( ! is_string( $time ) || ! preg_match( '/^(\d{1,2}):(\d{2})/', $time, $m ) ) {
				return null;
			}
			return ( (int) $m[1] ) * 60 + (int) $m[2];
		}

		/** "Morning · 09:00" — the part of day the design shows next to the time. */
		private static function time_label( $course ) {
			$time = isset( $course['start_time'] ) ? (string) $course['start_time'] : '';
			$mins = self::minutes_of_day( $time );
			if ( null === $mins ) {
				return '';
			}
			$hour = (int) floor( $mins / 60 );
			if ( $hour < 12 ) {
				$part = __( 'Morning', 'lalia' );
			} elseif ( $hour < 17 ) {
				$part = __( 'Afternoon', 'lalia' );
			} else {
				$part = __( 'Evening', 'lalia' );
			}
			return $part . ' · ' . substr( $time, 0, 5 );
		}

		/**
		 * "Mon, 7 Sep 2026". Formatted at midday UTC so that no site timezone
		 * offset can drag a date-only value onto the neighbouring day.
		 */
		private static function format_date( $date ) {
			if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return '';
			}
			$utc = new DateTimeZone( 'UTC' );
			$dt  = date_create_immutable( $date . ' 12:00:00', $utc );
			if ( ! $dt ) {
				return '';
			}
			return wp_date( 'D, j M Y', $dt->getTimestamp(), $utc );
		}

		/**
		 * The availability note, or '' when there is nothing to say.
		 *
		 * @return array{text:string,full:bool}|null
		 */
		private static function seats_note( $course ) {
			if ( ! array_key_exists( 'remaining_seats', $course ) || null === $course['remaining_seats'] ) {
				return null;
			}
			$seats = (int) $course['remaining_seats'];
			if ( 0 === $seats ) {
				return array(
					'text' => __( 'Fully booked', 'lalia' ),
					'full' => true,
				);
			}
			// The endpoint publishes a number only at or below the disclosure
			// threshold; anything larger would be a contract change, so say
			// nothing rather than leak a count the mask meant to hide.
			if ( $seats < 0 || $seats > self::SEAT_DISCLOSURE_MAX ) {
				return null;
			}
			return array(
				/* translators: %d: number of remaining places. */
				'text' => sprintf( _n( 'Only %d spot left', 'Only %d spots left', $seats, 'lalia' ), $seats ),
				'full' => false,
			);
		}

		/** Weekday columns to draw: Mon-Fri, extended only if a course needs it. */
		private static function day_columns( $rows ) {
			$columns = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday' );
			foreach ( $rows as $row ) {
				foreach ( self::course_days( $row ) as $day ) {
					if ( ! in_array( $day, $columns, true ) ) {
						$columns[] = $day;
					}
				}
			}
			// Keep week order regardless of the order they were discovered in.
			return array_values( array_intersect( array_keys( self::WEEKDAYS ), $columns ) );
		}

		/** Localised full weekday names, keyed by the endpoint's day names. */
		private static function weekday_names() {
			global $wp_locale;
			$order = array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );
			$names = array();
			foreach ( $order as $index => $key ) {
				$names[ $key ] = ( $wp_locale && method_exists( $wp_locale, 'get_weekday' ) )
					? $wp_locale->get_weekday( $index )
					: ucfirst( $key );
			}
			return $names;
		}

		/**
		 * The card's CSS, printed inline the first time a card renders.
		 *
		 * Not enqueued: Elementor renders widgets during `the_content`, long
		 * after `wp_enqueue_scripts` has run, so an enqueue here lands in the
		 * footer and the table flashes unstyled. The file is a few kilobytes
		 * and there is at most one schedule block per page.
		 */
		private static function css() {
			if ( self::$printed_css ) {
				return '';
			}
			self::$printed_css = true;
			$file = LALIA_PLUGIN_DIR . 'assets/css/course-schedule.css';
			if ( ! is_readable( $file ) ) {
				return '';
			}
			$css = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plugin-local asset, no remote or user input.
			if ( false === $css || '' === trim( $css ) ) {
				return '';
			}
			return "<style id=\"lalia-course-schedule-css\">\n" . $css . "\n</style>\n";
		}

		private static function card( $rows, $single, $inline, $title, $subtitle, $empty_text, $atts ) {
			$classes = array( 'lsched', $single ? 'lsched--single' : 'lsched--all' );
			if ( $inline ) {
				$classes[] = 'lsched--inline';
			}
			if ( '' !== trim( (string) $atts['class'] ) ) {
				$classes[] = trim( (string) $atts['class'] );
			}
			$cta_url  = trim( (string) $atts['cta_url'] );
			$cta_text = trim( (string) $atts['cta_text'] );
			$columns  = self::day_columns( $rows );
			$names    = self::weekday_names();

			ob_start();
			?>
<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
	<?php if ( '' !== trim( (string) $title ) ) : ?>
	<h3 class="lsched__title"><?php echo esc_html( $title ); ?></h3>
	<?php endif; ?>
	<?php if ( '' !== trim( (string) $subtitle ) ) : ?>
	<p class="lsched__subtitle"><?php echo esc_html( $subtitle ); ?></p>
	<?php endif; ?>
	<?php if ( empty( $rows ) ) : ?>
		<?php if ( '' !== trim( (string) $empty_text ) ) : ?>
	<p class="lsched__empty"><?php echo esc_html( $empty_text ); ?></p>
		<?php endif; ?>
	<?php else : ?>
	<div class="lsched__scroll">
		<table class="lsched__table">
			<thead>
				<tr>
					<?php if ( ! $single ) : ?>
					<th scope="col"><?php esc_html_e( 'Level', 'lalia' ); ?></th>
					<?php endif; ?>
					<th scope="col"><?php esc_html_e( 'Starts', 'lalia' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Time', 'lalia' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Days', 'lalia' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $rows as $row ) :
				$days  = self::course_days( $row );
				$note  = self::seats_note( $row );
				$label = array();
				foreach ( $days as $day ) {
					$label[] = $names[ $day ];
				}
				?>
				<tr>
					<?php if ( ! $single ) : ?>
					<td data-label="<?php esc_attr_e( 'Level', 'lalia' ); ?>">
						<span class="lsched__level"><?php echo esc_html( isset( $row['level']['name'] ) ? $row['level']['name'] : '' ); ?></span>
					</td>
					<?php endif; ?>
					<td data-label="<?php esc_attr_e( 'Starts', 'lalia' ); ?>">
						<span class="lsched__date"><?php echo esc_html( self::format_date( isset( $row['start_date'] ) ? $row['start_date'] : '' ) ); ?></span>
						<?php if ( $note ) : ?>
						<span class="lsched__spots<?php echo $note['full'] ? ' lsched__spots--full' : ''; ?>"><?php echo esc_html( $note['text'] ); ?></span>
						<?php endif; ?>
					</td>
					<td data-label="<?php esc_attr_e( 'Time', 'lalia' ); ?>">
						<span class="lsched__time"><?php echo esc_html( self::time_label( $row ) ); ?></span>
					</td>
					<td data-label="<?php esc_attr_e( 'Days', 'lalia' ); ?>">
						<span class="lsched__days" role="img" aria-label="<?php echo esc_attr( implode( ', ', $label ) ); ?>">
							<?php foreach ( $columns as $day ) : ?>
							<span class="lsched__day<?php echo in_array( $day, $days, true ) ? ' lsched__day--on' : ''; ?>" aria-hidden="true"><?php echo esc_html( self::WEEKDAYS[ $day ] ); ?></span>
							<?php endforeach; ?>
						</span>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
		<?php if ( '' !== $cta_url && '' !== $cta_text ) : ?>
	<div class="lsched__actions">
		<a class="lsched__cta" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta_text ); ?></a>
	</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
			<?php
			return (string) ob_get_clean();
		}
	}
}
