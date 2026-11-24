<?php
/**
 * Plugin Name: WP oEmbed Control
 * Plugin URI: https://github.com/Azanumer/wp-oembed-control
 * Description: Take control of WordPress embeds: disable oEmbed entirely, or cache oEmbed responses so pasted links stop hitting remote providers on every page load. Optional provider allow-list.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-oembed-control
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WOC_VERSION', '1.0.0' );

/**
 * Default settings.
 *
 * mode: 'cache' (default) — keep embeds, cache responses in transients.
 *       'off'   — disable oEmbed completely.
 * ttl:  cache lifetime in seconds (default 24h).
 * allowed_providers: empty = all providers allowed; otherwise only these
 *       host substrings may embed (e.g. youtube.com, vimeo.com).
 */
function woc_default_options() {
    return array(
        'mode'              => 'cache',
        'ttl'               => DAY_IN_SECONDS,
        'allowed_providers' => array(),
    );
}

function woc_get_options() {
    return wp_parse_args( get_option( 'woc_options', array() ), woc_default_options() );
}

/**
 * Apply the chosen mode on every request.
 */
function woc_init() {
    $options = woc_get_options();

    if ( $options['mode'] === 'off' ) {
        woc_disable_oembed();
        return;
    }

    // Cache mode: intercept the oEmbed fetch and serve/refresh a transient.
    add_filter( 'oembed_ttl', 'woc_oembed_ttl', 10, 3 );
    add_filter( 'pre_oembed_result', 'woc_pre_oembed_result', 10, 3 );
    add_filter( 'oembed_result', 'woc_store_oembed_result', 10, 3 );
    add_filter( 'oembed_providers', 'woc_filter_providers' );
}
add_action( 'init', 'woc_init' );

/**
 * Disable oEmbed completely: no discovery links, no wp-embed script,
 * no oEmbed REST route, no URL-to-embed conversion.
 */
function woc_disable_oembed() {
    remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
    remove_action( 'wp_head', 'wp_oembed_add_host_js' );
    remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
    remove_filter( 'pre_oembed_result', 'wp_filter_pre_oembed_result', 10 );
    add_filter( 'embed_oembed_discover', '__return_false' );
    add_filter( 'oembed_providers', '__return_empty_array' );

    // Stop the oEmbed REST route from answering.
    add_filter( 'rest_endpoints', function ( $endpoints ) {
        unset( $endpoints['/oembed/1.0/embed'] );
        return $endpoints;
    } );
}

/**
 * Cache lifetime for oEmbed transients (seconds).
 */
function woc_oembed_ttl( $ttl, $url, $args ) {
    $options = woc_get_options();
    return (int) $options['ttl'];
}

/**
 * Serve a cached oEmbed response before WordPress fetches it.
 *
 * Returning a non-false value from pre_oembed_result short-circuits the
 * remote request entirely.
 */
function woc_pre_oembed_result( $result, $url, $args ) {
    if ( ! woc_provider_allowed( $url ) ) {
        return '';
    }
    $cached = get_transient( woc_cache_key( $url ) );
    return $cached === false ? $result : $cached;
}

/**
 * Store a freshly fetched oEmbed response in a transient.
 */
function woc_store_oembed_result( $result, $data, $url ) {
    if ( $result === false || $result === '' ) {
        return $result;
    }
    if ( ! woc_provider_allowed( $url ) ) {
        return $result;
    }
    $options = woc_get_options();
    set_transient( woc_cache_key( $url ), $result, (int) $options['ttl'] );
    return $result;
}

/**
 * Cache key for a URL. One entry per URL, shared across posts — unlike
 * core's per-post postmeta cache, the same embed fetched in ten posts
 * is stored once.
 */
function woc_cache_key( $url ) {
    return 'woc_' . md5( $url );
}

/**
 * Provider allow-list check. Empty list = every provider allowed.
 * Entries are host substrings, so "youtube.com" also matches
 * "www.youtube.com" and "youtu.be" does NOT (add it separately).
 */
function woc_provider_allowed( $url ) {
    $options = woc_get_options();
    $allowed = array_filter( array_map( 'trim', (array) $options['allowed_providers'] ) );
    if ( empty( $allowed ) ) {
        return true;
    }
    $host = wp_parse_url( $url, PHP_URL_HOST );
    if ( ! $host ) {
        return false;
    }
    $host = strtolower( $host );
    foreach ( $allowed as $entry ) {
        $entry = strtolower( ltrim( $entry, '.' ) );
        if ( $host === $entry || substr( $host, -strlen( '.' . $entry ) ) === '.' . $entry ) {
            return true;
        }
    }
    return false;
}

/**
 * Remove disallowed providers from the oEmbed provider list so their
 * URLs are never even attempted.
 */
function woc_filter_providers( $providers ) {
    $options = woc_get_options();
    $allowed = array_filter( array_map( 'trim', (array) $options['allowed_providers'] ) );
    if ( empty( $allowed ) ) {
        return $providers;
    }
    $kept = array();
    foreach ( $providers as $pattern => $provider ) {
        // Provider patterns look like "#https?://(www\.)?youtube\.com/watch.*#i"
        // — strip the regex escapes before reading the host out of them.
        $clean   = str_replace( '\\', '', $pattern );
        $host    = '';
        if ( preg_match( '/([a-z0-9-]+(?:\.[a-z0-9-]+)+)/i', $clean, $m ) ) {
            $host = strtolower( $m[1] );
        }
        $ok = false;
        foreach ( $allowed as $entry ) {
            $entry = strtolower( ltrim( $entry, '.' ) );
            if ( $host === $entry || substr( $host, -strlen( '.' . $entry ) ) === '.' . $entry ) {
                $ok = true;
                break;
            }
        }
        if ( $ok ) {
            $kept[ $pattern ] = $provider;
        }
    }
    return $kept;
}

/**
 * Flush all cached oEmbed responses.
 */
function woc_flush_cache() {
    global $wpdb;
    // Transients live in wp_options as _transient_woc_* (+ timeout rows).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_woc\_%' OR option_name LIKE '_transient_timeout_woc\_%'" );
}

function woc_handle_flush() {
    check_admin_referer( 'woc_flush_cache' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to do this.', 'wp-oembed-control' ) );
    }
    woc_flush_cache();
    wp_safe_redirect( add_query_arg( array( 'page' => 'woc-settings', 'woc_flushed' => 1 ), admin_url( 'options-general.php' ) ) );
    exit;
}
add_action( 'admin_post_woc_flush_cache', 'woc_handle_flush' );

/**
 * Settings page under Settings.
 */
function woc_register_menu() {
    add_options_page(
        __( 'oEmbed Control', 'wp-oembed-control' ),
        __( 'oEmbed Control', 'wp-oembed-control' ),
        'manage_options',
        'woc-settings',
        'woc_render_settings_page'
    );
}
add_action( 'admin_menu', 'woc_register_menu' );

function woc_register_settings() {
    register_setting( 'woc_options_group', 'woc_options', array(
        'sanitize_callback' => 'woc_sanitize_options',
    ) );
}
add_action( 'admin_init', 'woc_register_settings' );

function woc_sanitize_options( $input ) {
    $defaults = woc_default_options();
    $output   = $defaults;

    $output['mode'] = ( isset( $input['mode'] ) && $input['mode'] === 'off' ) ? 'off' : 'cache';

    $ttl = isset( $input['ttl'] ) ? (int) $input['ttl'] : $defaults['ttl'];
    $output['ttl'] = $ttl > 0 ? $ttl : $defaults['ttl'];

    $raw       = isset( $input['allowed_providers'] ) ? (string) $input['allowed_providers'] : '';
    $providers = array();
    foreach ( preg_split( '/[\r\n,]+/', $raw ) as $line ) {
        $line = strtolower( trim( $line ) );
        // Accept a bare host or a full URL; keep only the host part.
        if ( strpos( $line, '://' ) !== false ) {
            $line = (string) wp_parse_url( $line, PHP_URL_HOST );
        }
        $line = ltrim( $line, '.' );
        if ( $line !== '' && preg_match( '/^[a-z0-9.-]+$/', $line ) ) {
            $providers[] = $line;
        }
    }
    $output['allowed_providers'] = array_values( array_unique( $providers ) );

    return $output;
}

function woc_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $options = woc_get_options();
    $flushed = isset( $_GET['woc_flushed'] );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'oEmbed Control', 'wp-oembed-control' ); ?></h1>

        <?php if ( $flushed ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Embed cache flushed.', 'wp-oembed-control' ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'woc_options_group' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Mode', 'wp-oembed-control' ); ?></th>
                    <td>
                        <label>
                            <input type="radio" name="woc_options[mode]" value="cache" <?php checked( $options['mode'], 'cache' ); ?>>
                            <?php esc_html_e( 'Cache embeds', 'wp-oembed-control' ); ?>
                        </label><br>
                        <label>
                            <input type="radio" name="woc_options[mode]" value="off" <?php checked( $options['mode'], 'off' ); ?>>
                            <?php esc_html_e( 'Disable oEmbed completely', 'wp-oembed-control' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Disabling removes discovery links, the wp-embed script and the oEmbed REST route — pasted URLs stay plain links.', 'wp-oembed-control' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Cache lifetime', 'wp-oembed-control' ); ?></th>
                    <td>
                        <input type="number" min="60" step="60" name="woc_options[ttl]" value="<?php echo esc_attr( $options['ttl'] ); ?>" class="small-text">
                        <?php esc_html_e( 'seconds', 'wp-oembed-control' ); ?>
                        <p class="description"><?php esc_html_e( 'How long a fetched embed is reused before the provider is asked again. Default: 86400 (24 hours).', 'wp-oembed-control' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Allowed providers', 'wp-oembed-control' ); ?></th>
                    <td>
                        <textarea name="woc_options[allowed_providers]" rows="5" cols="40" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $options['allowed_providers'] ) ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'One host per line, e.g. youtube.com. Leave empty to allow every provider. Disallowed providers never embed and are never fetched.', 'wp-oembed-control' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <hr>
        <h2><?php esc_html_e( 'Embed cache', 'wp-oembed-control' ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'woc_flush_cache' ); ?>
            <input type="hidden" name="action" value="woc_flush_cache">
            <?php submit_button( __( 'Flush embed cache', 'wp-oembed-control' ), 'secondary' ); ?>
        </form>
    </div>
    <?php
}
