<?php
/**
 * Post to Queue Reorder Class
 *
 * Adds drag and drop editor for reordering queued posts.
 * 
 * @package    Post_to_Queue
 * @subpackage Reorder
 */

if ( ! class_exists( 'Post_to_Queue_Reorder' ) ) :
/**
 * Adds drag and drop editor for reordering queued posts.
 * 
 * Based on work by Ryan Hellyer, Ronald Huereca, and Scott Basgaard
 * on plugin Metronet Reorder Posts for Metronet Norge AS.
 *
 * @since 1.0
 */
class Post_to_Queue_Reorder {
	/**
	 * Post type to be reordered
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @var string
	 */
	public $post_type;

	/**
	 * Capability needed to reorder.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @var string
	 */
	public $capability;

	/**
	 * Admin page heading
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @var string
	 */
	public $heading;

	/**
	 * Admin page menu label
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @var string
	 */
	public $menu_label;

	/**
	 * Error message for AJAX saving.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @var string
	 */
	public $error_msg;

	/**
	 * Success message for AJAX saving.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @var string
	 */
	public $success_msg;

	/**
	 * Initialize Post_to_Queue_Reorder object.
	 *
	 * Set class properties and add methods to appropriate hooks.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param Post_to_Queue $ptq  Object of Post_to_Queue class.
	 * @param array         $args Parameters used for setting up a class.
	 */
	public function __construct( Post_to_Queue $ptq, $args = array() ) {
		/**
		 * Fires before class is initialized.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_reorder_before_construct' );

		// Add Post_to_Queue class
		$this->ptq = $ptq;

		/**
		 * Filter parameters used to setup a class.
		 *
		 * @since 1.0
		 *
		 * @param array  $args      The array of parameters used to setup a class.
		 */
		$args = (array) apply_filters( 'ptq_reorder_args', $args );

		// Parse arguments
		$defaults = array(
			'post_type'   => 'post',
			'capability'  => 'publish_posts',
			'order'       => 'ASC',
			'menu_label'  => __( 'Queue Reorder', 'post-to-queue' ),
			'heading'     => __( 'Queue Reorder', 'post-to-queue' ),
			'error_msg'   => __( 'Something wrong happened. Please reload this page and try again.', 'post-to-queue' ),
			'success_msg' => __( 'New queue order was sucessfully saved.', 'post-to-queue' ),
			'post_status' => $this->ptq->status,
			'query_args'  => array()
		);
		extract( wp_parse_args( $args, $defaults ) );

		// Set variables
		$this->post_type   = $post_type;
		$this->capability  = get_post_type_object( $post_type )->cap->publish_posts;
		$this->order       = $order;
		$this->heading     = $heading;
		$this->menu_label  = $menu_label;
		$this->error_msg   = $error_msg;
		$this->success_msg = $success_msg;
		$this->query_args  = $query_args;

		// Add actions
		add_action( 'wp_ajax_ptq-save-reorder', array( $this, 'handle'           ) );
		add_action( 'admin_menu',               array( $this, 'register_screens' ) );

		/**
		 * Fires after class is initialized.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_reorder_after_construct' );
	}


	/**
	 * Handle submission of queued posts.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function handle() {
		/**
		 * Fires before handling of submission of queued posts.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_reorder_before_handle' );

		// Verify nonce value, for security purposes
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ptq-reorder-nonce' ) ) {
			wp_send_json_error();
		}

		// Check if current user has permissions to reorder
		if ( ! current_user_can( $this->capability ) ) {
			wp_send_json_error();
		}

		// Check if IDs were POSTed
		if ( ! isset( $_POST['order'] ) || empty( $_POST['order'] ) ) {
			wp_send_json_error();
		}

		// Get JSON data
		$ids = json_decode( str_replace( "\\", '', $_POST['order'] ) );

		// Check if we received an array
		if ( ! is_array( $ids ) ) {
			wp_send_json_error();
		}

		// Iterate through IDs
		$this->update_posts( $ids );

		/**
		 * Fires after handling of submission of queued posts.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_reorder_after_handle' );

		wp_send_json_success( array( 'msg' => $this->success_msg ) );
	}

	/**
	 * Save order of queued posts.
	 *
	 * Loop through each ID and increment order by 1 
	 * from previous ID, starting from 0;
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param array $ids An array of IDs of queued posts.
	 */
	public function update_posts( $ids ) {
		/**
		 * Fires before reorder save.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_reorder_before_update_posts' );

		$count = 0;

		foreach( $ids as $post_id ) {
			$post_id = absint( $post_id );

			// Update the posts
			$this->ptq->add_order( $post_id, $count );

			// Increase counter
			$count += 1;
		}

		/**
		 * Fires after reorder save.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_reorder_after_update_posts' );
	}

	/**
	 * Enqueue styles to reorder page.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function enqueue_styles() {
		/**
		 * Fires before styles are enqueued.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_reorder_before_enqueue_styles' );

		wp_enqueue_style( 'ptq-reorder', $this->ptq->url_path . '/css/ptq-reorder.css', array(), '1.0' );

		/**
		 * Fires after styles are enqueued.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_reorder_after_enqueue_styles' );
	}

	/**
	 * Enqueue scripts to reorder page
	 *
	 * @since 1.0
	 * @access public
	 */
	public function enqueue_scripts() {
		/**
		 * Fires before scripts are enqueued.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_reorder_before_enqueue_scripts' );

		wp_enqueue_script( 'ptq-reorder', $this->ptq->url_path . '/js/ptq-reorder.js', array( 'jquery-ui-sortable' ), '1.0', true );
		wp_localize_script( 'ptq-reorder', 'ptqReorder', array(
			'nonce'    => wp_create_nonce( 'ptq-reorder-nonce' ),
			'errorMsg' => $this->error_msg
		) );

		/**
		 * Fires after scripts are enqueued.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_reorder_after_enqueue_scripts' );
	}

	/**
	 * Register reorder submenu.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function register_screens() {
		/**
		 * Fires before screens are registered.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_reorder_before_register_screens' );

		$post_type = $this->post_type;

		if ( 'post' != $post_type ) {
			$hook = add_submenu_page(
				'edit.php?post_type=' . $post_type, // Parent slug
				$this->heading,                     // Page title (unneeded since specified directly)
				$this->menu_label,                  // Menu title
				$this->capability,                  // Capability
				'ptq-reorder-' . $post_type,        // Menu slug
				array( $this, 'display_screen' )    // Callback function
			);
		} else {
			$hook = add_posts_page(
				$this->heading,                     // Page title (unneeded since specified directly)
				$this->menu_label,                  // Menu title
				$this->capability,                  // Capability
				'ptq-reorder-' . $post_type,        // Menu slug
				array( $this, 'display_screen' )    // Callback function
			);
		}

		add_action( 'admin_print_styles-'  . $hook, array( $this, 'enqueue_styles'  ) );
		add_action( 'admin_print_scripts-' . $hook, array( $this, 'enqueue_scripts' ) );

		/**
		 * Fires after screens are registered.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_reorder_after_register_screens' );
	}

	/**
	 * Display reorder post row.
	 *
	 * @since 1.0
	 * @access public
	 *
	 * @param WP_Post $post Post object.
	 */
	public function output_row( $post ) {
		/**
		 * Filter reorder post row.
		 *
		 * @since 1.0
		 *
		 * @param string  $post_row Reorder post row.
		 * @param WP_Post $post     Post object.
		 */
		echo apply_filters( 'ptq_reorder_row', '<li id="list_' . $post->ID . '" data-postid="' . $post->ID . '"><div class="ptq-reorder-post-title">' . get_the_title( $post->ID ) . '</div></li>', $post );
	}

	/**
	 * Display reorder screen.
	 *
	 * @since 1.0
	 * @access public
	 */
	public function display_screen() {
		/**
		 * Fires before reorder screen is displayed.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_reorder_before_display_screen' );

		?>
		<div class="wrap">
			<h2>
				<?php echo $this->heading; ?>
				<img src="<?php echo admin_url( 'images/loading.gif' ); ?>" id="ptq-reorder-loading" />
			</h2>
			<div id="ptq-reorder-result"></div>
			<?php
			/**
			 * Fires before reorder list is displayed.
			 *
			 * @since 1.0
			 */
			do_action( 'ptq_reorder_before_list' );
			// Output posts
			$args = array(
				'post_type'      => $this->post_type,
				'posts_per_page' => -1,
				'meta_key'       => '_queue_order',
				'orderby'        => 'meta_value_num',
				'order'          => $this->order,
				'post_status'    => $this->ptq->status,
			);
			$args = wp_parse_args( $args, $this->query_args );
			$posts = get_posts( $args );
			if ( $posts ) :
				?>
				<ul id="ptq-reorder-post-list">
					<?php
					foreach( $posts as $post ) {
						$this->output_row( $post );
					}
					?>
				</ul>
				<?php
			endif;
			/**
			 * Fires after reorder list is displayed.
			 *
			 * @since 1.0
			 */
			do_action( 'ptq_reorder_after_list' );
			?>
		</div><!-- .wrap -->
		<?php

		/**
		 * Fires after reorder screen is displayed.
		 *
		 * @since 1.0
		 */
		do_action( 'ptq_reorder_after_display_screen' );
	}
}
endif;
