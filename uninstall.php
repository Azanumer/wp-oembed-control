<?php
/**
 * Uninstall WP oEmbed Control.
 *
 * Removes the plugin's option and flushes its embed cache transients.
 * Core's own per-post oEmbed postmeta is left alone — WordPress manages it.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'woc_options' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_woc\_%' OR option_name LIKE '_transient_timeout_woc\_%'" );
