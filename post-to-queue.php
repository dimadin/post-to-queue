<?php
/**
 * The Post to Queue Plugin
 *
 * Stack posts to queue and auto publish them in chosen interval and time frame.
 *
 * @package    Post_to_Queue
 * @subpackage Main
 */

/**
 * Plugin Name: Post to Queue
 * Plugin URI:  http://blog.milandinic.com/wordpress/plugins/post-to-queue/
 * Description: Stack posts to queue and auto publish them in chosen interval and time frame.
 * Author:      Milan Dinić
 * Author URI:  http://blog.milandinic.com/
 * Version:     1.0-beta-1
 * Text Domain: post-to-queue
 * Domain Path: /languages/
 * License:     GPL
 */

/* Exit if accessed directly */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Schedule install cron event on plugin activation.
 *
 * Since class can't be initialized on activation,
 * installation needs to occur on next page load.
 * That's why we schedule single cron event that
 * will be fired on next page load.
 *
 * @since 1.0
 */
function ptq_activation() {
	wp_schedule_single_event( time(), 'ptq_single_event_reschedule' );
}
register_activation_hook( __FILE__, 'ptq_activation' );

/**
 * Unschedule Post to Queue events on deactivation.
 *
 * @since 1.0
 */
function ptq_deactivation() {
	// Since we are unscheduling, we can check any post type, not just ours
	foreach ( get_post_types() as $post_type ) {
		wp_clear_scheduled_hook( "ptq_event_{$post_type}" );
	}

	// Delete queue existence statuses
	delete_transient( 'ptq_queued_existence' );
}
register_deactivation_hook( __FILE__, 'ptq_deactivation' );

/**
 * Initialize a plugin.
 *
 * Load class when all plugins are loaded
 * so that other plugins can overwrite it.
 *
 * @since 1.0
 */
function ptq_instantiate() {
	global $post_to_queue;
	$post_to_queue = new Post_to_Queue();
}
add_action( 'plugins_loaded', 'ptq_instantiate', 15 );

if ( ! class_exists( 'Post_to_Queue' ) ) :
/**
 * Post to Queue main class.
 *
 * Queue and publish posts automatically.
 *
 * @since 1.0
 */
class Post_to_Queue {
	/**
	 * Path to plugin's directory.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @var string
	 */
	public $path;

	/**
	 * URL path to plugin's directory.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @var string
	 */
	public $url_path;

	/**
	 * Plugin's basename.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @var string
	 */
	public $basename;

	/**
	 * Interval between last posted and queued post.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @var int
	 */
	public $interval;

	/**
	 * Should admin class be loaded.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @var bool
	 */
	public $show_admin;

	/**
	 * Should reorder class be loaded.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @var bool
	 */
	public $show_reorder;

	/**
	 * Name of queue post status.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @var string
	 */
	public $status;

	/**
	 * Post types that allow queuing.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @var array
	 */
	public $post_types = array();

	/**
	 * Initialize Post_to_Queue object.
	 *
	 * Set class properties and add main methods to appropriate hooks.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function __construct() {
		/**
		 * Fires before class is initialized.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_before_construct' );

		// Set paths
		$this->path     = rtrim( plugin_dir_path( __FILE__ ), '/' );
		$this->url_path = rtrim( plugin_dir_url(  __FILE__ ), '/' );

		// Set basename
		$this->basename = plugin_basename( __FILE__ );

		// Load translations
		load_plugin_textdomain( 'post-to-queue', false, dirname( $this->basename ) . '/languages' );

		// Set interval
		$this->interval = $this->interval();

		// Set status name
		$this->status = $this->status();

		// Set admin loading statuses
		/**
		 * Filter whether admin class should be loaded.
		 *
		 * @since 1.0
		 *
		 * @param bool $show_admin Whether admin class should be loaded. Default true.
		 */
		$this->show_admin   = apply_filters( 'ptq_show_admin',   true );
		/**
		 * Filter whether reorder class should be loaded.
		 *
		 * @since 1.0
		 *
		 * @param bool $show_admin Wwhether reorder class should be loaded. Default true.
		 */
		$this->show_reorder = apply_filters( 'ptq_show_reorder', true );

		// Register main hooks
		add_action( 'init',                     array( $this, 'init'         )    );
		add_action( 'wp_loaded',                array( $this, 'wp_loaded'    ), 2 );

		// Add PTQ cron interval
		add_filter( 'cron_schedules',           array( $this, 'add_interval' )    );

		/**
		 * Fires after class is initialized.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_after_construct' );
	}

	/**
	 * Register queue post status and add most of the hooks.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function init() {
		/**
		 * Fires before init method.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_before_init' );

		// Register post status
		$default_post_status_args = array(
			'label'                     => _x( 'Queued', 'post status name', 'post-to-queue' ),
			'label_count'               => _nx_noop( 'Queued <span class="count">(%s)</span>', 'Queued <span class="count">(%s)</span>', 'post status count label', 'post-to-queue' ),
			'protected'                 => true,
			'exclude_from_search'       => true,
			'show_in_admin_status_list' => true,
			'show_in_admin_all_list'    => true
		);
		/**
		 * Filter parameters used when registering queue post status.
		 *
		 * @see register_post_status()
		 *
		 * @since 1.0
		 *
		 * @param array $args {
		 *     The array of parameters used when registering queue post status.
		 *
		 *     @type string $label                     Name of the post status used in UI.
		 *     @type array  $label_count               Array of registered plural strings of post status' name.
		 *     @type bool   $protected                 Defaults to true.
		 *     @type bool   $exclude_from_search       Whether to exclude queued posts from search results. Defaults to true.
		 *     @type bool   $show_in_admin_status_list Whether to include posts in the edit listing for their post type. Defaults to true.
		 *     @type bool   $show_in_admin_all_list    Whether to show in the list of statuses with post counts. Defaults to true.
		 * }
		 */
		$post_status_args = (array) apply_filters( 'ptq_register_post_status_args', $default_post_status_args );
		$post_status_args = wp_parse_args( $post_status_args, $default_post_status_args );

		register_post_status(
			$this->status,
			$post_status_args
		);

		// Add queue order on post saving
		add_action( 'save_post',                       array( $this, 'add_queue_order'         ) );

		// Remove queue order on post trashing
		add_action( 'trashed_post',                    array( $this, 'delete_order'            ) );

		// Cleanup queue existence statuses on saving
		add_action( 'save_post',                       array( $this, 'delete_queued_existence' ) );

		// Schedule reschedule of all events when PTQ settings change
		add_action( 'add_option_ptq_settings',         array( $this, 'schedule_reschedule'     ) );
		add_action( 'update_option_ptq_settings',      array( $this, 'schedule_reschedule'     ) );
		add_action( 'delete_option_ptq_settings',      array( $this, 'schedule_reschedule'     ) );

		// Schedule reschedule of all events when time zone change
		add_action( 'add_option_gmt_offset',           array( $this, 'schedule_reschedule'     ) );
		add_action( 'update_option_gmt_offset',        array( $this, 'schedule_reschedule'     ) );
		add_action( 'delete_option_gmt_offset',        array( $this, 'schedule_reschedule'     ) );
		add_action( 'add_option_timezone_string',      array( $this, 'schedule_reschedule'     ) );
		add_action( 'update_option_timezone_string',   array( $this, 'schedule_reschedule'     ) );
		add_action( 'delete_option_timezone_string',   array( $this, 'schedule_reschedule'     ) );

		// Reschedule all events when single event happens
		add_action( 'ptq_single_event_reschedule',     array( $this, 'reschedule'              ) );

		// Maybe schedule post type event when single event happens
		add_action( 'ptq_single_event_maybe_schedule', array( $this, 'maybe_schedule_event'    ) );

		/**
		 * Fires after init method.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_after_init' );	
	}

	/**
	 * Add cron event hooks and load admin classes.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function wp_loaded() {
		/**
		 * Fires before wp_loaded method.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_before_wp_loaded' );

		// Set post types allowed for queuing
		$this->post_types = $this->post_types();

		// Hook event onto all post types
		foreach ( $this->post_types as $post_type ) {
			add_action( "ptq_event_{$post_type}", array( $this, 'event' ) );
		}

		// Load additional classes for admin only
		if ( is_admin() ) {
			// Hook Admin class
			if ( isset( $this->show_admin ) && $this->show_admin ) {
				$this->maybe_load_admin();
				new Post_to_Queue_Admin( $this );
			}

			// Hook Reorder class
			if ( isset( $this->show_reorder ) && $this->show_reorder ) {
				$this->maybe_load_reorder();
				// Iterate through each specified post type and instantiate it's organiser
				foreach ( $this->post_types as $post_type ) {
					// If nothing queued for post type, continue
					if ( ! $this->are_queued_for_type( $post_type ) ) {
						continue;
					}

					new Post_to_Queue_Reorder(
						$this,
						array( 'post_type' => $post_type )
					);
				}
			}
		}

		// Register plugins action links filter
		add_filter( 'plugin_action_links_' . $this->basename, array( $this, 'action_links' ) );

		/**
		 * Fires after wp_loaded method.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_after_wp_loaded' );
	}

	/**
	 * Get Post to Queue setting.
	 *
	 * If no setting, return requested default value.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  string $name    Name of the setting.
	 * @param  mixed  $default Value returned if no setting.
	 * @return mixed  Value of setting or default value if no setting.
	 */
	public function get_option( $name, $default = '' ) {
		$options = get_option( 'ptq_settings' );

		if ( $options && is_array( $options ) && isset( $options[$name] ) ) {
			return $options[$name];
		} else {
			return $default;
		}
	}

	/**
	 * Get interval that should be used between last posted and queued post.
	 *
	 * Use site setting or a day by default.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @return int $interval Queue interval in seconds. Default one day.
	 */
	public function interval() {
		$interval = absint( $this->get_option( 'interval' ) );
		$interval = $interval * MINUTE_IN_SECONDS; // minutes * seconds
		/**
		 * Filter length of time after last posted post to when next should be posted.
		 *
		 * @since 1.0
		 *
		 * @param int $interval Length of time after last posted post to when next should be posted.
		 */
		$interval = $this->posabsint( apply_filters( 'ptq_interval', $interval ), DAY_IN_SECONDS ); // Verify that value is positive natural number

		return $interval;
	}

	/**
	 * Get name of queue post status.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @return string $status The name of the queue status. Default 'queue'.
	 */
	public function status() {
		/**
		 * Filter the name of the queue post status.
		 *
		 * @since 1.0
		 *
		 * @param string $status The name of the queue status. Default 'queue'.
		 */
		$status = sanitize_key( apply_filters( 'ptq_post_status_name', 'queue' ) );

		return $status;
	}

	/**
	 * Get post types that allow queuing.
	 *
	 * Shouldn't be used before wp_loaded hook
	 * so that all types are registered.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @return array $post_types Post types allowed to be queued. Default all public ones.
	 */
	public function post_types() {
		/**
		 * Filter post types that are allowed to be queued.
		 *
		 * @since 1.0
		 *
		 * @param array $post_types Post types allowed to be queued. Default all public ones.
		 */
		$post_types = (array) apply_filters( 'ptq_post_types', get_post_types( array( 'public' => true ) ) );

		return $post_types;
	}

	/**
	 * Get hours time frame when publishing should happen.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @return array $hours_range Hours when publishing is allowed.
	 */
	public function hours() {
		/**
		 * Filter allowed hours of time frame.
		 *
		 * @since 1.0
		 *
		 * @param array $hours {
		 *     The array of start and end of time frame.
		 *
		 *     @type int $start Beginning of time frame.
		 *     @type int $end   End of time frame.
		 * }
		 */
		if ( ( $hours = apply_filters( 'ptq_hours', $this->get_option( 'hours', array() ) ) )
			&& is_array( $hours )
			&& isset( $hours['start'], $hours['end'] )
			&& ( $start = $hours['start'] )
			&& ( $end = $hours['end'] )
			&& ( in_array( $start, range( '00', '23' ) ) )
			&& ( in_array( $end, range( '00', '23' ) ) )
			&& ( $start != $end )
			) {
			// If end hour is midnight, use it as '24' for proper results
			if ( '00' == $end ) {
				$end = 24;
			}

			// Create array with available hours
			if ( $start < $end ) {
				$hours_range = range( $start, $end - 1 );
			} else {
				$hours_range = array_merge( range( '00', $end - 1 ), range( $start, '23' ) );
			}

			return $hours_range;
		} else {
			return array();
		}
	}

	/**
	 * Get days when publishing should happen.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @return array $days Days represented by numbers when publishing is allowed.
	 */
	public function days() {
		/**
		 * Filter allowed days of time frame.
		 *
		 * @since 1.0
		 *
		 * @param array $days Days represented by numbers that are allowed.
		 */
		$days = (array) apply_filters( 'ptq_days', $this->get_option( 'days', array() ) );

		return array_unique( array_map( 'absint', $days ) );
	}

	/**
	 * Load Reorder class file if not loaded.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function maybe_load_reorder() {
		if ( ! class_exists( 'Post_to_Queue_Reorder' ) ) {
			require_once( $this->path . '/inc/class-post-to-queue-reorder.php' );
		}
	}

	/**
	 * Load Admin class file if not loaded.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function maybe_load_admin() {
		if ( ! class_exists( 'Post_to_Queue_Admin' ) ) {
			require_once( $this->path . '/inc/class-post-to-queue-admin.php' );
		}
	}

	/**
	 * Add action links to plugins page.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  array $links Existing plugin's action links.
	 * @return array $links New plugin's action links.
	 */
	public function action_links( $links ) {
		$links['donate']   = '<a href="http://blog.milandinic.com/donate/">' . __( 'Donate', 'post-to-queue' ) . '</a>';
		$links['settings'] = '<a href="' . admin_url( 'options-writing.php' ) . '">' . _x( 'Settings', 'plugin actions link', 'post-to-queue' ) . '</a>';

		return $links;
	}

	/**
	 * Add custom cron interval.
	 *
	 * Add a 'ptq' interval to the existing set
	 * of intervals.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  array $schedules Existing cron intervals.
	 * @return array $schedules New cron intervals.
	 */
	public function add_interval( $schedules ) {
		$schedules['ptq'] = array(
			'interval' => $this->interval,
			'display'  => __( 'Post to Queue Interval', 'post-to-queue' )
		);

		return $schedules;
	}

	/**
	 * Cron event for post type.
	 *
	 * Call publishing method when cron is run.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function event() {
		/**
		 * Fires before cron event.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_before_event' );

		// Get current action name
		$current_hook = current_filter();

		// Get current post type by subtracting 'ptq_event_'
		$post_type = substr( $current_hook, 10 );

		// If post type exists, publish queued post
		if ( post_type_exists( $post_type ) ) {
			$this->maybe_publish( $post_type );
		}

		/**
		 * Fires after cron event.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_after_event' );
	}

	/**
	 * Publish queued post for post type if in time frame.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param string $post_type Post type that should be published.
	 */
	public function maybe_publish( $post_type ) {
		/**
		 * Fires before publishing.
		 *
		 * @since 1.0
		 *
		 * @param string $post_type Post type that should be published.
		 */
		do_action( 'ptq_before_publish', $post_type );

		// Only proceed if post type exists
		if ( ! post_type_exists( $post_type ) ) {
			return;
		}

		// If now is not it time frame, reschedule
		if ( ! $this->is_in_time_frame( $post_type ) ) {
			$this->schedule_post_type( $post_type );
			return;
		}

		// Get first in order queued post
		$_post = $this->get_one_queued( $post_type );

		// If there are no queued posts of type, clear schedule
		if ( ! $_post ) {
			wp_clear_scheduled_hook( "ptq_event_{$post_type}" );
			return;
		}

		// Clear date when post was queued so that we have real publishing date
		$_post_a = get_post( $_post->ID, ARRAY_A );
		$_post_a['post_date'] = '';
		$_post_a['post_date_gmt'] = '';
		wp_update_post( $_post_a );

		// Publish post
		wp_publish_post( $_post->ID );

		// Remove order number for post
		$this->delete_order( $_post->ID );

		/**
		 * Fires after publishing.
		 *
		 * @since 1.0
		 *
		 * @param string  $post_type Post type that should be published.
		 * @param WP_Post $_post     WP_Post object of published post.
		 */
		do_action( 'ptq_after_publish', $post_type, $_post );
	}

	/**
	 * Get time of last published post of post type.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  string $post_type Post type that is checked.
	 * @return int               Unix time of last published post of post type in GMT.
	 */
	public function get_last_published_time( $post_type ) {
		$posts = get_posts( 'numberposts=1&post_type=' . $post_type );
		if ( $posts ) {
			$_post = array_pop( $posts );
			$time = strtotime( $_post->post_date_gmt );
		} else {
			$time = 0;
		}

		/**
		 * Filter time of last published post of post type.
		 *
		 * @since 1.0
		 *
		 * @param int    $time      Unix time of last published post of post type in GMT.
		 * @param string $post_type Post type that is checked.
		 */
		return absint( apply_filters( 'ptq_last_published_time', $time, $post_type ) );
	}

	/**
	 * Get one queued post based on args.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  string  $post_type Post type that is queried.
	 * @param  array   $args      The array of parameters used to query first queued post in order.
	 * @return WP_Post $_post     Post object of post that is result of query.
	 */
	public function get_one_queued( $post_type, $args = array() ) {
		/**
		 * Filter parameters used to query first queued post in order.
		 *
		 * @since 1.0
		 *
		 * @param array  $args      The array of parameters used to query first queued post in order.
		 * @param string $post_type Post type that is queried.
		 */
		$args = (array) apply_filters( 'ptq_get_one_queued_args', $args, $post_type );

		// Parse arguments
		$defaults = array(
			'post_type'      => $post_type,
			'posts_per_page' => 1,
			'meta_key'       => '_queue_order',
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',            // Setting the order of the posts
			'post_status'    => $this->status,    // Post status of posts to be reordered
		);
		$args = wp_parse_args( $args, $defaults );

		// Get post
		$posts = get_posts( $args );
		$_post = array_pop( $posts );

		return $_post;
	}

	/**
	 * Check if now is in selected time frame for post type.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  string $post_type Post type that is checked.
	 * @return bool              Whether now is in selected time frame for post type.
	 */
	public function is_in_time_frame( $post_type ) {
		// Get time of last published post for type
		if ( ( $this->get_last_published_time( $post_type ) + $this->interval ) > time() ) {
			// Now is earlier than next publishing time
			$status = false;
		} else if ( ( $days = $this->days() ) && ! in_array( date_i18n( 'w' ), $days ) ) {
			// Now isn't in selected days
			$status = false;
		} else if ( ( $hours = $this->hours() ) && ! in_array( date_i18n( 'H' ), $hours ) ) {
			// Now isn't in selected hours of the day
			$status = false;
		} else {
			// Everything passed, now is in time frame
			$status = true;
		}

		/**
		 * Filter whether now is in selected time frame for post type.
		 *
		 * @since 1.0
		 *
		 * @param bool   $status    Whether now is in selected time frame for post type.
		 * @param string $post_type Post type that is checked.
		 */
		return (bool) apply_filters( 'ptq_is_in_time_frame', $status, $post_type );
	}

	/**
	 * Check are there queued posts for post type.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  string $post_type Post type that is checked.
	 * @return bool              Whether there are queued posts of post type.
	 */
	public function are_queued_for_type( $post_type ) {
		// Get all statuses
		$statues = $this->queued_existence();

		if ( isset( $statues[$post_type] ) && $statues[$post_type] ) {
			$status = true;
		} else {
			$status = false;
		}

		/**
		 * Filter whether there are queued posts of post type.
		 *
		 * @since 1.0
		 *
		 * @param bool   $status    Whether there are queued posts of post type.
		 * @param string $post_type Post type that is checked.
		 */
		return (bool) apply_filters( 'ptq_are_queued_for_type', $status, $post_type );
	} 

	/**
	 * Get queue existence status for each post type.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @return array The array of statuses of existence of queued posts for each post type.
	 */
	public function queued_existence() {
		// If not cached, get raw
		if ( false === ( $statues = get_transient( 'ptq_queued_existence' ) ) ) {
			$statues = array();

			// Loop through all post types to see are there queued posts
			foreach ( $this->post_types as $post_type ) {
				if ( $this->get_one_queued( $post_type ) ) {
					$statues[$post_type] = true;
				} else {
					$statues[$post_type] = false;
				}
			}

			/**
			 * Filter time until expiration of queue existence statuses cache.
			 *
			 * @since 1.0
			 *
			 * @param int $expire Time in seconds after which queue existence statuses cache expires. Default one hour.
			 */
			$expire = $this->posabsint( apply_filters( 'ptq_queued_existence_expiration', HOUR_IN_SECONDS ), HOUR_IN_SECONDS );

			// Save to cache for an hour by default, or to custom value that is verified positive natural number
			set_transient( 'ptq_queued_existence', $statues, $expire );
		}

		/**
		 * Filter statuses of existence of queued posts for each post type.
		 *
		 * @since 1.0
		 *
		 * @param array $statues The array of statuses of existence of queued posts for each post type.
		 */
		return (array) apply_filters( 'ptq_queued_existence', $statues );
	}

	/**
	 * Delete queue existence statuses.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function delete_queued_existence() {
		delete_transient( 'ptq_queued_existence' );
	}

	/**
	 * Check does post type allows queuing.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  string $post_type Post type that is checked.
	 * @return bool              Whether post type allows queuing.
	 */
	public function can_type_be_queued( $post_type ) {
		if ( in_array( $post_type, $this->post_types ) ) {
			$status = true;
		} else {
			$status = false;
		}

		/**
		 * Filter whether post type allows queuing.
		 *
		 * @since 1.0
		 *
		 * @param bool   $status    Whether post type allows queuing.
		 * @param string $post_type Post type that is checked.
		 */
		return (bool) apply_filters( 'ptq_can_type_be_queued', $status, $post_type );
	}

	/**
	 * Check is post queued.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  int|WP_Post $post Post ID or post object.
	 * @return bool              Whether post is queued.
	 */
	public function is_queued( $post ) {
		if ( ! $post = get_post( $post ) ) {
			return false;
		}

		if ( $post->post_status == $this->status ) {
			$status = true;
		} else {
			$status = false;
		}

		/**
		 * Filter whether post is queued.
		 *
		 * @since 1.0
		 *
		 * @param bool    $status Whether post is queued.
		 * @param WP_Post $post   Post object of post that is checked.
		 */
		return (bool) apply_filters( 'ptq_is_queued', $status, $post );
	}

	/**
	 * Schedule event for post type.
	 *
	 * Reschedule previous event and schedule new
	 * based on last published date for post type,
	 * chosen interval between posts, and time frame.
	 *
	 * This method took almost as much time to write as
	 * everything else in this plugin. This is nth iteration
	 * and previous where either to resource heavy or inefficient.
	 * Another problem was solving GMT and local time issue.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param string $post_type Post type that is scheduled.
	 */
	public function schedule_post_type( $post_type ) {
		/**
		 * Fires before scheduling.
		 *
		 * @since 1.0
		 *
		 * @param string $post_type Post type that should be scheduled.
		 */
		do_action( 'ptq_before_schedule_post_type', $post_type );

		// If not queued for post type, unshedule event and leave
		if ( ! $this->are_queued_for_type( $post_type ) ) {
			wp_clear_scheduled_hook( "ptq_event_{$post_type}" );
			return;
		}

		// Get days and hours
		$days  = $this->days();
		$hours = $this->hours();

		// Get the time when next queued post of type should be published
		// It's a sum of last published post for type plus interval
		$next_time = $this->get_last_published_time( $post_type ) + $this->interval;

		// If there is one range, create other
		if ( $days && ! $hours ) {
			$hours = range( '00', '23' );
		} else if ( $hours && ! $days ) {
			$days = range( 0, 6 );
		}		

		/*
		This should work like this:
		- check if current day and hour are available
		 - if available, use that time; break
		- check if current day is available and there are still hours in day
		 - loop through rest hours and check each if available
		  - if available, get Unix time of that hour; break
		- check if there are still days in week
		 - loop through rest days and check each if available
		  - if available, get Unix time of first available hour of that day; break
		- get first available day of next week
		 - get Unix time of first available hour of that day; break
		*/
		if ( $days && $hours ) {
			$next_time_tmp = false;

			// Get day and hour of available time
			$day_of_next_time  = $this->date_i18n( 'w', $next_time );
			$hour_of_next_time = $this->date_i18n( 'H', $next_time );

			// First check current day & current hour 
			if ( in_array( $day_of_next_time, $days ) && in_array( $hour_of_next_time, $hours ) ) {
				// This is the time, do nothing more
				$next_time_tmp = $next_time;
			} else if ( in_array( $day_of_next_time, $days ) && ( $hour_of_next_time <= 23 ) ) {
				// This day is available but are there available hours on that day?
				$hour = false;
				for ( $i = $hour_of_next_time; $i <= 23; $i++ ) {
					if ( $i < 10 ) {
						$i = '0' . $i;
					}

					// Check if current hour is available, and stop loop if it is
					if ( in_array( $i, $hours ) ) {
						$hour = $i;
						break;
					}
				}

				// If we got first available hour, just calculate exact time
				if ( strlen( $hour ) > 0 ) {
					// Calculate difference between now and available hour
					$diff_hour = $hour - $hour_of_next_time;

					// Get number of seconds passed since round hour for now
					$passed_since_round_hour = ( $this->date_i18n( 'i', $next_time ) * MINUTE_IN_SECONDS ) + $this->date_i18n( 's', $next_time );

					// Time is: round hour of available time + difference in hours
					$next_time_tmp = ( $next_time - $passed_since_round_hour ) + ( $diff_hour * HOUR_IN_SECONDS );
				}
			}

			// If we didn't find time, it should be first available hour in first available day
			if ( ! $next_time_tmp ) {
				$day = false;

				// First check if there are any rest days this week
				if ( $day_of_next_time <= 6 ) {
					for ( $i = $day_of_next_time; $i <= 6; $i++ ) {
						// Check if current hour is available, and stop loop if it is
						if ( in_array( $i, $days ) ) {
							$day = $i;
							break;
						}
					}
				}

				// If found day in this week, use it
				if ( strlen( $day ) ) {
					// Calculate difference between today and available day
					$diff_day = $day - $day_of_next_time;
				} else {
					// Otherwise, day is first day of next week
					$day = $days[0];

					// If not Sunday, add plus one
					if ( 0 < $day_of_next_time ) {
						$day = $day + 1;
					} else {
						// If Sunday, use as 6
						$day_of_next_time = 6;
					}

					// Calculate difference between today and available day
					$diff_day = ( 6 - $day_of_next_time ) + $day;
				}

				// Get number of seconds passed since midnight for today
				$passed_since_midnight = ( $this->date_i18n( 'G', $next_time ) * HOUR_IN_SECONDS ) + ( $this->date_i18n( 'i', $next_time ) * MINUTE_IN_SECONDS ) + $this->date_i18n( 's', $next_time );

				// Time is: midnight of today + difference in days + hour
				$next_time_tmp = ( $next_time - $passed_since_midnight ) + ( $diff_day * DAY_IN_SECONDS ) + ( $hours[0] * HOUR_IN_SECONDS );
			}

			$next_time = $next_time_tmp;
		}

		// Remove the old recurring event and set up a new one
		wp_clear_scheduled_hook( "ptq_event_{$post_type}" );
		wp_schedule_event( $next_time, 'ptq', "ptq_event_{$post_type}" );

		/**
		 * Fires after scheduling.
		 *
		 * @since 1.0
		 *
		 * @param string $post_type Post type that was scheduled.
		 */
		do_action( 'ptq_after_schedule_post_type', $post_type );
	}

	/**
	 * Add queue order for post.
	 *
	 * Get order of last queued post in order
	 * and increase it by one.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param int|WP_Post $post Post ID or post object.
	 */
	public function add_queue_order( $post ) {
		/**
		 * Fires before adding queue order.
		 *
		 * @since 1.0
		 *
		 * @param int|WP_Post $post Post ID or post object.
		 */
		do_action( 'ptq_before_add_queue_order', $post );

		// Get post object
		if ( ! $post = get_post( $post ) ) {
			return;
		}

		// Does this post type allows queuing?
		if ( ! $this->can_type_be_queued( $post->post_type ) ) {
			return;
		}

		// Is this post queued?
		if ( ! $this->is_queued( $post ) ) {
			return;
		}

		// Does this post already have order?
		if ( $this->get_order( $post->ID ) ) {
			return;
		}

		// Get last post from queue
		$last_queued = $this->get_one_queued( $post->post_type, array( 'order' => 'DESC' ) );

		// If no posts in queue, use 0, otherwise, last + 1
		if ( ! $last_queued ) {
			$count = 0;
		} else {
			$count = $this->get_order( $last_queued->ID ) + 1;
		}

		// Save post's order
		$this->add_order( $post->ID, $count );

		/**
		 * Fires before adding queue order.
		 *
		 * @since 1.0
		 *
		 * @param WP_Post $post Post object.
		 */
		do_action( 'ptq_after_add_queue_order', $post );
	}

	/**
	 * Add post's queue order number.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param int $post_id Post ID that should be ordered.
	 * @param int $count   New queue order of post.
	 */
	public function add_order( $post_id, $count ) {
		update_post_meta( $post_id, '_queue_order', $count );
	}

	/**
	 * Remove post's queue order number.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param int $post_id Post ID that should be unordered.
	 */
	public function delete_order( $post_id ) {
		delete_post_meta( $post_id, '_queue_order' );
	}

	/**
	 * Get post's queue order number.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param int $post_id Post ID that should be queued.
	 */
	public function get_order( $post_id ) {
		return get_post_meta( $post_id, '_queue_order', true );
	}

	/**
	 * Schedule cron event if not scheduled for post type.
	 *
	 * Check if there is already a scheduled event
	 * for post type, and schedule if there isn't.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param string $post_type Post type that should be scheduled.
	 */
	public function maybe_schedule_event( $post_type ) {
		/**
		 * Fires before trying to schedule event.
		 *
		 * @since 1.0
		 *
		 * @param string $post_type Post type that should be scheduled.
		 */
		do_action( 'ptq_before_maybe_schedule_event', $post_type );

		if ( wp_next_scheduled( "ptq_event_{$post_type}" ) ) {
			return;
		}

		$this->schedule_post_type( $post_type );

		/**
		 * Fires after trying to schedule event.
		 *
		 * @since 1.0
		 *
		 * @param string $post_type Post type that was scheduled.
		 */
		do_action( 'ptq_after_maybe_schedule_event', $post_type );
	}

	/**
	 * Reschedule all cron events.
	 *
	 * Loop through each post type and force scheduling
	 * that will use new settings.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function reschedule() {
		/**
		 * Fires before rescheduling all post types.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_before_reschedule' );

		foreach ( $this->post_types as $post_type ) {
			$this->schedule_post_type( $post_type );
		}

		/**
		 * Fires after rescheduling all post types.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_after_reschedule' );
	}

	/**
	 * Schedule single event that will force reschedule on next page load.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function schedule_reschedule() {
		/**
		 * Fires before scheduling reschedule single event.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_before_schedule_reschedule' );

		wp_schedule_single_event( time(), 'ptq_single_event_reschedule' );

		/**
		 * Fires after scheduling reschedule single event.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_after_schedule_reschedule' );
	}

	/**
	 * Schedule single event that will force maybe schedule on next page load.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param string $post_type Post type that will maybe be scheduled.
	 */
	public function schedule_maybe_schedule( $post_type ) {
		/**
		 * Fires before scheduling maybe schedule single event.
		 *
		 * @since 1.0
		 *
		 * @param string $post_type Post type that will maybe be scheduled.
		 */
		do_action( 'ptq_before_schedule_reschedule' );

		wp_schedule_single_event( time(), 'ptq_single_event_maybe_schedule', array( $post_type ) );

		/**
		 * Fires after scheduling maybe schedule single event.
		 *
		 * @since 1.0
		 *
		 * @param string $post_type Post type that will maybe be scheduled.
		 */
		do_action( 'ptq_after_schedule_reschedule' );
	}

	/**
	 * Get positive natural number.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  mixed $maybeint Data you wish to have converted to a positive integer.
	 * @param  int   $default  Number returned if $maybeint is a zero.
	 * @return int   $num      Positive natural number.
	 */
	public function posabsint( $maybeint, $default ) {
		$num = absint( $maybeint );
		$num = ( $num && $num > 0 ) ? $num : $default;

		return $num;
	}

	/**
	 * Retrieve the date in localized format.
	 *
	 * If $unixtimestamp is passed, converts it from GMT time
	 * into the correct format for the site, otherwise
	 * use date_i18n() function.
	 *
	 * Reason for this is that date_i18n() returns GMT time
	 * when $unixtimestamp is passed.
	 *
	 * @link http://wordpress.stackexchange.com/a/107258
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  string $dateformatstring Format to display the date.
	 * @param  int    $unixtimestamp    Optional. Unix timestamp.
	 * @return string                   The date, localized.
	 */
	public function date_i18n( $dateformatstring, $unixtimestamp = false ) {
		if ( false === $unixtimestamp ) {
			return date_i18n( $dateformatstring );
		} else {
			return get_date_from_gmt( date( 'Y-m-d H:i:s', $unixtimestamp ), $dateformatstring );
		}
	}	
}
endif;
