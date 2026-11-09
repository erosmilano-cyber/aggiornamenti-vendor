<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'agv_settings' );
delete_option( 'agv_data' );
delete_option( 'agv_csaf_cache' );
delete_transient( 'agv_refresh_lock' );
delete_transient( 'agv_update_info' );
wp_clear_scheduled_hook( 'agv_refresh_feeds' );
