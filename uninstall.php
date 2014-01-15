<?php

/**
 * Post to Queue Unistall
 *
 * Code used when the plugin is deleted.
 *
 * @package Post_to_Queue
 * @subpackage Unistall
 */

/* Exit if accessed directly or not in unistall */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;


/*
 * Remove options on uninstallation of plugin.
 *
 * @since 1.0
 */
delete_option( 'ptq_settings' );

/*
After unistallation, there might be left queued posts
but there is no good way to work around them so they
are left untouched.
*/