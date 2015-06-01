<?php 
/**
 * Post to Queue Admin Class
 *
 * Load Post to Queue plugin admin area.
 * 
 * @package    Post_to_Queue
 * @subpackage Admin
 */

if ( ! class_exists( 'Post_to_Queue_Admin' ) ) :
/**
 * Load Post to Queue plugin admin area.
 * 
 * Parts of this class are based on work by Tudor Sandu
 * on plugin Automatic Post Scheduler.
 *
 * @since 1.0
 */
class Post_to_Queue_Admin {
	/**
	 * Should edit post screen messages be filtered.
	 *
	 * @since 1.0
	 * @access public
	 * 
	 * @var bool
	 */
	public $filter_messages;

	/**
	 * Initialize Post_to_Queue_Admin object.
	 *
	 * Set class properties and add methods to appropriate hooks.
	 *
	 * @param Post_to_Queue $ptq Object of Post_to_Queue class.
	 */
	public function __construct( Post_to_Queue $ptq ) {
		/**
		 * Fires before class is initialized.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_admin_before_construct' );

		// Add Post_to_Queue class
		$this->ptq = $ptq;

		// Setup properties
		$this->filter_messages = false;

		// Register settings
		add_action( 'admin_init',                  array( $this, 'register_settings'        )        );

		// Add post row actions
		add_action( 'admin_init',                  array( $this, 'post_row_actions_handle'  )        );

		// Register post row post status
		add_filter( 'display_post_states',         array( $this, 'post_states'              ), 10, 2 );

		// Register post row actions handler
		add_filter( 'post_row_actions',            array( $this, 'post_row_actions'         ), 10, 2 );
		add_filter( 'page_row_actions',            array( $this, 'post_row_actions'         ), 10, 2 );

		// Attach checkbox to publish box on edit post page
		add_action( 'post_submitbox_misc_actions', array( $this, 'publish_box'              )        );

		// Save checkbox submission value
		add_filter( 'wp_insert_post_data',         array( $this, 'publish_box_save'         ), 10, 2 );

		// Filter message displayed on edit post screen
		add_filter( 'post_updated_messages',       array( $this, 'filter_updated_messages'  )        );

		// Force filtering of message displayed on edit post screen
		add_filter( 'redirect_post_location',      array( $this, 'force_updated_messages'   )        );

		/**
		 * Fires after class is initialized.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_admin_after_construct' );
	}

	/**
	 * Add 'Add to queue' checkbox on post page in publish box.
	 * 
	 * @since 1.0
	 * @access public
	 */
	public function publish_box() {
		/**
		 * Fires before publish box field.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_admin_before_publish_box' );

		global $post;

		// Don't display for unauthorized users
		if ( ! current_user_can( get_post_type_object( $post->post_type )->cap->publish_posts ) ) {
			return;
		}

		/**
		 * Filter post statuses that shouldn't have publish box field.
		 *
		 * @since 1.0
		 *
		 * @param array $post_statuses Post statuses that shouldn't have publish box field. Default 'publish' and 'future'.
		 */
		$disallowed_post_statuses = (array) apply_filters( 'ptq_disallowed_publish_box_statuses', array( 'publish', 'future' ) );

		// Don't display for disallowed post statuses
		if ( in_array( $post->post_status, $disallowed_post_statuses ) ) {
			return;
		}

		// Don't display for disallowed post types
		if ( ! $this->ptq->can_type_be_queued( $post->post_type ) ) {
			return;
		}

		?>
		<div class="misc-pub-section" id="ptq_queue_post_section">
			<label for="ptq_queue_post"><input type="checkbox" id="ptq_queue_post" name="ptq_queue_post"<?php checked( $post->post_status, $this->ptq->status ); ?> /><?php _e( 'Add to queue', 'post-to-queue' ); ?></label>
		</div>
		<?php

		/**
		 * Fires after publish box field.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_admin_after_publish_box' );
	}

	/**
	 * Handle publish box submission.
	 *
	 * Note that there is no need to check for user's capability
	 * and that order of queued post is added on 'save_post' hook.
	 * 
	 * @since 1.0
	 * @access public
	 * 
	 * @param  array $data    An array of elements that make up a post.
	 * @param  array $postarr Raw HTTP POST array of elements that make up a post.
	 * @return array          Filtered array of elements that make up a post.
	 */
	public function publish_box_save( $data, $postarr ) {
		/**
		 * Fires before publish box field submission is saved.
		 *
		 * @since 1.0
		 *
		 * @param  array $data    An array of elements that make up a post.
		 * @param  array $postarr Raw HTTP POST array of elements that make up a post.
		 */
		do_action( 'ptq_admin_before_publish_box_save', $data, $postarr );

		// Does this post type allows queuing?
		if ( ! $this->ptq->can_type_be_queued( $data['post_type'] ) ) {
			return $data;
		}

		// post_status is definitely set, since we're using sanitized data; no check necessary
		$post_status = $data['post_status'];

		// Check if user pressed 'Publish' button; capability already checked
		if ( 'publish' != $post_status ) {
			return $data;
		}

		// Check if queuing should occur, remove from queue if not
		if ( ! ( isset( $postarr['ptq_queue_post'] ) && $postarr['ptq_queue_post'] ) ) {
			if ( isset( $postarr['ID'] ) && $this->ptq->is_queued( $postarr['ID'] ) ) {
				$this->ptq->delete_order( $postarr['ID'] );
			}

			return $data;
		}

		$data['post_status'] = $this->ptq->status;

		// Force filtering of message displayed on edit post screen
		$this->filter_messages = true;

		// Schedule single event that will maybe schedule event for post type
		$this->ptq->schedule_maybe_schedule( $data['post_type'] );

		/**
		 * Fires after publish box field submission is saved.
		 *
		 * @since 1.0
		 *
		 * @param  array $data    An array of elements that make up a post.
		 * @param  array $postarr Raw HTTP POST array of elements that make up a post.
		 */
		do_action( 'ptq_admin_after_publish_box_save', $data, $postarr );

		return $data;
	}

	/**
	 * Force filtering of message displayed on edit post screen.
	 *
	 * Since message is shown after redirect, GET parameter
	 * is appended to the URL so that filter knows it should run.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  string $location URL where user is redirected after the save.
	 * @return string $location Modified URL where user is redirected after the save.
	 */
	public function force_updated_messages( $location ) {
		if ( $this->filter_messages ) {
			$location = add_query_arg( 'ptq-force-messages', 1, $location );
		}

		return $location;
	}

	/**
	 * Display queued message after save on edit post screen.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  array $messages Post updated messages.
	 * @return array $messages Modified post updated messages.
	 */
	public function filter_updated_messages( $messages ) {
		global $post;

		// Check if filtering was forced
		if ( ! isset( $_GET['ptq-force-messages'] )	|| ( 1 != $_GET['ptq-force-messages'] ) ) {
			return $messages;
		}

		// Is this post queued?
		if ( ! $this->ptq->is_queued( $post ) ) {
			return $messages;
		}

		$messages['post'][6] = sprintf( __( 'Post queued. <a href="%s">Preview post</a>', 'post-to-queue' ), esc_url( get_permalink( $post->ID ) ) );
		$messages['page'][6] = sprintf( __( 'Page queued. <a href="%s">Preview page</a>', 'post-to-queue' ), esc_url( get_permalink( $post->ID ) ) );

		/**
		 * Filter the post updated messages.
		 *
		 * @since 1.0
		 *
		 * @param array $messages Post updated messages.
		 */
		return (array) apply_filters( 'ptq_filter_updated_messages', $messages );
	}

	/**
	 * Register settings fields.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function register_settings() {
		/**
		 * Fires before settings fields are registered.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_admin_before_register_settings' );

		add_settings_field( 'ptq_interval', __( 'Queue Interval',      'post-to-queue' ), array( $this, 'render_interval_settings' ), 'writing' );
		add_settings_field( 'ptq_days',     __( 'Queue Publish Days',  'post-to-queue' ), array( $this, 'render_days_settings'     ), 'writing' );
		add_settings_field( 'ptq_hours',    __( 'Queue Publish Hours', 'post-to-queue' ), array( $this, 'render_hours_settings'    ), 'writing' );

		register_setting( 'writing', 'ptq_settings', array( $this, 'validate_settings' ) );

		/**
		 * Fires after settings fields are registered.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_admin_after_register_settings' );
	}

	/**
	 * Display interval settings field.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function render_interval_settings() {
		/**
		 * Fires before interval settings field.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_admin_before_interval_settings' );

		$interval = $this->ptq->get_option( 'interval', 24 * 60 ); // Default value is one day
		$input = '<input type="text" id="ptq_interval" class="small-text" name="ptq_settings[interval]" value="' . esc_attr( $interval ) . '" />';
		?>
		<label for="ptq_interval">
		<?php /* translators: interval input field */ printf( _x( '%s minutes', 'interval settings field', 'post-to-queue' ), $input ); ?>
		</label>
		<br />
		<span class="description"><?php _e( 'This value defines the interval for the <strong>Post to Queue</strong> plugin.', 'post-to-queue' ); ?></span>
		<?php

		/**
		 * Fires after interval settings field.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_admin_after_interval_settings' );
	}

	/**
	 * Display available days settings field.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function render_days_settings() {
		/**
		 * Fires before days settings field.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_admin_before_days_settings' );

		global $wp_locale;

		$days = $this->ptq->get_option( 'days', range( 0, 6 ) );

		// If no specific days, check all days
		if ( ! $days ) {
			$days = range( 0, 6 );
		}

		for ( $day_index = 0; $day_index <= 6; $day_index++ ) :
			?>
			<label for="ptq-day-<?php echo esc_attr( $day_index ); ?>"><input type="checkbox" value="<?php echo esc_attr( $day_index ); ?>"<?php checked( in_array( $day_index, $days ) ); ?> id="ptq-day-<?php echo esc_attr( $day_index ); ?>" name="ptq_settings[days][]" /><?php echo $wp_locale->get_weekday( $day_index ); ?></label>
			<br />
			<?php
		endfor;
		?>
		<br />
		<span class="description"><?php _e( 'Select days of the week when you want queued posts to be published.', 'post-to-queue' ); ?></span>
		<?php

		/**
		 * Fires after days settings field.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_admin_after_days_settings' );
	}

	/**
	 * Display available hours settings field.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function render_hours_settings() {
		/**
		 * Fires before hours settings field.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_admin_before_hours_settings' );

		$hours = $this->ptq->get_option( 'hours', array( 'start' => '00', 'end' => '00' ) );
		$start = '</label><label for="ptq-hours-start"><select name="ptq_settings[hours][start]" id="ptq-hours-start">';
		for ( $i = 0; $i <= 23; $i++ ) {
				if ( $i < 10 ) {
					$i = '0' . $i;
				}

				$start .= '<option value="' . esc_attr( $i ) . '"' . selected( $i, $hours['start'], false ) . '>' . esc_html( $i ) . '</option>';
		}
		$start .= '</select>';

		$end = '</label><label for="ptq-hours-end"><select name="ptq_settings[hours][end]" id="ptq-hours-end">';
		for ( $i = 0; $i <= 23; $i++ ) {
				if ( $i < 10 ) {
					$i = '0' . $i;
				}

				$end .= '<option value="' . esc_attr( $i ) . '"' . selected( $i, $hours['end'], false ) . '>' . esc_html( $i ) . '</option>';
		}
		$end .= '</select>';
		?>
		<label for="ptq_hours">
			<input name="ptq_hours" type="checkbox" id="ptq_hours" value="1"<?php checked( ( '00' != $hours['start'] ) || ( '00' != $hours['end'] ) ); ?> />
		<?php printf( __( 'Publish queued posts only between %1$s and %2$s', 'post-to-queue' ), $start, $end ); ?></label>
		<br />
		<span class="description"><?php printf( __( 'Select the period of the day when you want queued posts to be published. Current time is <code>%1$s</code>.', 'post-to-queue' ), date_i18n( _x( 'Y-m-d G:i:s', 'current time date format', 'post-to-queue' ) ) ); ?></span>
		<?php

		/**
		 * Fires after hours settings field.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_admin_after_hours_settings' );
	}

	/**
	 * Validate settings fields submission.
	 *
	 * Check if submitted values are in accepted values
	 * and remove those that aren't.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  array $settings     Settings values.
	 * @return array $new_settings Validated settings values.
	 */
	public function validate_settings( $settings ) {
		/**
		 * Fires before settings validation.
		 *
		 * @since 1.0
		 *
		 * @param array $settings Settings values.
		 */
		do_action( 'ptq_admin_before_validate_settings', $settings );

		// Create clean array to make sure nothing bad don't get through
		$new_settings = array();

		// Interval should be positive integer
		if ( isset( $settings['interval'] ) ) {
			$interval = absint( $settings['interval'] );
			if ( $interval ) {
				$new_settings['interval'] = $interval;
			}
		}

		// Days should be 0-6, but not all of them
		if ( isset( $settings['days'] ) && is_array( $settings['days'] ) ) {
			$days = $settings['days'];

			foreach ( $days as $key => $day ) {
				if ( ! in_array( $day, range( 0, 6 ) ) ) {
					unset( $days[$key] );
				}
			}

			$days = array_values( (array) $days );

			if ( $days && ( range( 0, 6 ) != $days ) ) {
				$new_settings['days'] = $days;
			}
		}

		// Hours should be 00-23, different, and only if checked
		if ( isset( $_POST['ptq_hours'] ) && isset( $settings['hours'] ) && isset( $settings['hours']['start'] ) && isset( $settings['hours']['end'] ) ) {
			$start = $settings['hours']['start'];
			$end   = $settings['hours']['end'];

			if ( in_array( $start, range( 00, 23 ) ) && in_array( $end, range( 00, 23 ) ) && ( $start != $end ) ) {
				$new_settings['hours']['start'] = $start;
				$new_settings['hours']['end']   = $end;
			}
		}

		/**
		 * Fires after settings validation.
		 *
		 * @since 1.0
		 *
		 * @param array $settings     Settings values.
		 * @param array $new_settings Validated settings values.
		 */
		do_action( 'ptq_admin_before_validate_settings', $settings, $new_settings );

		return $new_settings;
	}

	/**
	 * Display if post is queued after title on edit posts table row.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  array   $post_states An array of post display states.
	 * @param  WP_Post $_post       Post object.
	 * @return array   $post_states Modified array of post display states.
	 */
	public function post_states( $post_states, $_post ) {
		/**
		 * Fires before post queue state is made.
		 *
		 * @since 1.0
		 *
		 * @param  array   $post_states An array of post display states.
		 * @param  WP_Post $_post       Post object.
		 */
		do_action( 'ptq_admin_before_post_states', $post_states, $_post );

		if ( $this->ptq->status == $_post->post_status ) {
			/* translators: post state */
			$post_states[ $this->ptq->status ] = _x( 'Queued', 'post state', 'post-to-queue' );
		}

		/**
		 * Fires after post queue state is made.
		 *
		 * @since 1.0
		 *
		 * @param  array   $post_states An array of post display states.
		 * @param  WP_Post $_post       Post object.
		 */
		do_action( 'ptq_admin_after_post_states', $post_states, $_post );

		return $post_states;
	}

	/**
	 * Add queue action link on edit posts table row.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param  array   $actions An array of post row action links.
	 * @param  WP_Post $_post   Post object.
	 * @return array   $actions Modified array of post row action links.
	 */
	public function post_row_actions( $actions, $_post ) {
		/**
		 * Fires before post row queue action link is made.
		 *
		 * @since 1.0
		 *
		 * @param  array   $actions An array of post row action links.
		 * @param  WP_Post $_post   Post object.
		 */
		do_action( 'ptq_admin_before_post_row_actions', $actions, $_post );

		/**
		 * Filter post statuses that shouldn't have post row action link.
		 *
		 * @since 1.0
		 *
		 * @param array $post_statuses Post statuses that shouldn't have post row action link. Default 'publish' and 'future'.
		 */
		$disallowed_post_statuses = (array) apply_filters( 'ptq_disallowed_post_row_statuses', array( 'publish', 'future' ) );

		// Don't display for disallowed post statuses
		if ( in_array( $_post->post_status, $disallowed_post_statuses ) ) {
			return $actions;
		}

		// Don't display for unauthorized users
		if ( ! current_user_can( get_post_type_object( $_post->post_type )->cap->publish_posts ) ) {
			return $actions;
		}

		// Setup $args depending on current post type
		if ( $this->ptq->status != $_post->post_status ) {
			$args = array(
				'title' => esc_attr_x( 'Queue this post', 'post row action title', 'post-to-queue' ),
				'link'  => esc_html_x( 'Queue',           'post row action',       'post-to-queue' ),
				'do'    => 'queue'
			);
		} else {
			$args = array(
				'title' => esc_attr_x( 'Unqueue this post', 'post row action title', 'post-to-queue' ),
				'link'  => esc_html_x( 'Unqueue',           'post row action',       'post-to-queue' ),
				'do'    => 'unqueue'
			);
		}

		// Setup URL
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'           => 'post-to-queue',
					'do'               => $args['do'],
					'post_id'          => $_post->ID,
					'_wp_http_referer' => $_SERVER['REQUEST_URI'] 
				),
				admin_url()
			),
			'post-to-queue'
		);

		$actions['post_to_queue'] = "<a title='" . $args['title'] . "' href='" . $url . "'>" . $args['link'] . '</a>';

		/**
		 * Fires after post row queue action link is made.
		 *
		 * @since 1.0
		 *
		 * @param  array   $actions An array of post row action links.
		 * @param  WP_Post $_post   Post object.
		 */
		do_action( 'ptq_admin_after_post_row_actions', $actions, $_post );

		return $actions;
	}

	/**
	 * Handle post row action link submission.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function post_row_actions_handle() {
		/**
		 * Fires before post row action submission is saved.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_admin_before_post_row_actions_handle' );

		// Check if this action can be done
		if ( ! isset( $_GET['action'] )
			|| ( 'post-to-queue' != $_GET['action'] )
			|| ! isset( $_GET['do'] )
			|| ( ! in_array( $_GET['do'], array( 'queue', 'unqueue' ) ) )
			|| ! isset( $_GET['post_id'] )
			|| ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( $_GET['_wpnonce'], 'post-to-queue' )
			) {
			return;
		}

		// Check if this post exists
		$_post = get_post( $_GET['post_id'], ARRAY_A );
		if ( empty( $_post ) ) {
			return;
		}

		// Check can user change status
		if ( ! current_user_can( get_post_type_object( $_post['post_type'] )->cap->publish_posts ) ) {
			return;
		}

		/**
		 * Filter the post status when post is unqueued from post row action.
		 *
		 * @since 1.0
		 *
		 * @param string $post_status Post status when post is unqueued from post row action. Default 'draft'.
		 */
		$unqueued_post_status = sanitize_key( apply_filters( 'ptq_default_post_row_status', 'draft' ) );

		// Get new status
		$status = ( 'queue' == $_GET['do'] ) ? $this->ptq->status : $unqueued_post_status;

		// Update post status
		$_post['post_status'] = $status;
		wp_update_post( $_post );

		// Maybe schedule event for post type
		$this->ptq->maybe_schedule_event( $_post['post_type'] );

		// Remove order if unqueued
		if ( $status != $this->ptq->status ) {
			$this->ptq->delete_order( $_post['ID'] );
		}

		/**
		 * Fires after post row action submission is saved.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_admin_after_post_row_actions_handle' );

		// Redirect to previous page
		wp_safe_redirect( wp_get_referer() );
		exit;
	}
}
endif;
