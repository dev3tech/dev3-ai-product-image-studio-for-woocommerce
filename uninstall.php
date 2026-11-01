<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiImageStudio
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3is_settings = get_option( 'dev3is_settings', array() );
if ( empty( $dev3is_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3is_results', 'dev3is_usage', 'dev3is_core_cache', 'dev3is_core_jobs', 'dev3is_core_embeddings' ) as $dev3is_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3is_table}" );
}
foreach ( array( 'dev3is_settings', 'dev3is_recipes', 'dev3is_db_version' ) as $dev3is_option ) {
	delete_option( $dev3is_option );
}
delete_transient( 'dev3is_models' );
wp_unschedule_hook( 'dev3is_run_job' );
wp_unschedule_hook( 'dev3is_daily' );
// Approved and pending result images stay in the media library: they may be used by products.
delete_post_meta_by_key( '_dev3is_draft' );
delete_post_meta_by_key( '_dev3is_audit' );
