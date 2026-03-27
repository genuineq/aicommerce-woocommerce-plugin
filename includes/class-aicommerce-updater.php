<?php
/**
 * Plugin Auto-Updater
 *
 * Checks for new versions via a remote info.json and integrates
 * with the native WordPress update mechanism.
 *
 * @package AICommerce
 */

namespace AICommerce;

/** Exit if accessed directly. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Updater Class
 */
class Updater {

    /** Plugin file path (e.g. aicommerce/aicommerce.php). */
    private string $plugin_file;

    /** Plugin slug identifier. */
    private string $plugin_slug = 'aicommerce';

    /** Remote URL for update metadata (info.json). */
    private string $update_url = 'https://api.ai.genuineq.com/woocommerce/info.json';

    /** Current plugin version. */
    private string $version;

    /** Transient cache key for storing update info. */
    private string $cache_key = 'aicommerce_update_info';

    /**
     * Constructor.
     *
     * Enables auto-updates only when explicitly allowed via constant.
     * Registers WordPress filters for update checks and plugin info.
     *
     * @return void
     */
    public function __construct() {
        /** Disable updater unless explicitly enabled in wp-config.php. */
        if ( ! defined( 'AICOMMERCE_AUTO_UPDATES' ) || ! AICOMMERCE_AUTO_UPDATES ) {
            return;
        }

        /** Resolve plugin file basename. */
        $this->plugin_file = plugin_basename( AICOMMERCE_PLUGIN_FILE );

        /** Set current plugin version. */
        $this->version     = AICOMMERCE_VERSION;

        /** Hook into WordPress update system. */
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );

        /** Hook into plugin details popup ("View Details"). */
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );

        /** Fix extracted directory name after update. */
        add_filter( 'upgrader_source_selection', array( $this, 'fix_directory_name' ), 10, 4 );
    }

    /**
     * Check for plugin updates.
     *
     * Injects update information into WordPress update transient
     * if a newer version is available.
     *
     * @param object $transient WordPress update transient.
     * @return object
     */
    public function check_for_update( $transient ) {
        /** Skip if no checked plugins. */
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        /** Fetch remote update metadata. */
        $remote = $this->fetch_remote_info();

        /** Skip if remote data is invalid. */
        if ( ! $remote || empty( $remote->version ) ) {
            return $transient;
        }

        /** Compare current version with remote version. */
        if ( version_compare( $this->version, $remote->version, '<' ) ) {

            /** New version available — add to update list. */
            $transient->response[ $this->plugin_file ] = (object) array(
                'id'            => $this->plugin_file,
                'slug'          => $this->plugin_slug,
                'plugin'        => $this->plugin_file,
                'new_version'   => $remote->version,
                'url'           => isset( $remote->homepage ) ? $remote->homepage : '',
                'package'       => $remote->download_url,
                'requires'      => isset( $remote->requires ) ? $remote->requires : '5.8',
                'tested'        => isset( $remote->tested ) ? $remote->tested : '6.5',
                'requires_php'  => isset( $remote->requires_php ) ? $remote->requires_php : '7.4',
                'icons'         => isset( $remote->icons ) ? (array) $remote->icons : array(),
                'banners'       => isset( $remote->banners ) ? (array) $remote->banners : array(),
            );

            /** Remove plugin from no_update list if present. */
            unset( $transient->no_update[ $this->plugin_file ] );

        } else {

            /** Plugin is up to date — add to no_update list. */
            $transient->no_update[ $this->plugin_file ] = (object) array(
                'id'          => $this->plugin_file,
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_file,
                'new_version' => $this->version,
                'url'         => isset( $remote->homepage ) ? $remote->homepage : '',
                'package'     => '',
            );
        }

        return $transient;
    }

    /**
     * Provide plugin information for "View Details" popup.
     *
     * @param false|object|array $result Current result.
     * @param string             $action API action.
     * @param object             $args   Request arguments.
     * @return false|object
     */
    public function plugin_info( $result, $action, $args ) {
        /** Only handle plugin_information requests. */
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        /** Ensure this request is for our plugin. */
        if ( ! isset( $args->slug ) || $this->plugin_slug !== $args->slug ) {
            return $result;
        }

        /** Fetch remote plugin metadata. */
        $remote = $this->fetch_remote_info();

        /** Return original result if remote data is unavailable. */
        if ( ! $remote ) {
            return $result;
        }

        /** Build plugin info response object. */
        return (object) array(
            'name'          => isset( $remote->name ) ? $remote->name : 'AICommerce',
            'slug'          => $this->plugin_slug,
            'version'       => $remote->version,
            'author'        => isset( $remote->author ) ? $remote->author : '',
            'homepage'      => isset( $remote->homepage ) ? $remote->homepage : '',
            'requires'      => isset( $remote->requires ) ? $remote->requires : '5.8',
            'tested'        => isset( $remote->tested ) ? $remote->tested : '6.5',
            'requires_php'  => isset( $remote->requires_php ) ? $remote->requires_php : '7.4',
            'last_updated'  => isset( $remote->last_updated ) ? $remote->last_updated : '',
            'sections'      => isset( $remote->sections ) ? (array) $remote->sections : array(),
            'download_link' => $remote->download_url,
            'icons'         => isset( $remote->icons ) ? (array) $remote->icons : array(),
            'banners'       => isset( $remote->banners ) ? (array) $remote->banners : array(),
        );
    }

    /**
     * Fix extracted plugin directory name.
     *
     * Ensures the plugin folder name matches the expected slug
     * so WordPress correctly replaces the existing plugin.
     *
     * @param string $source
     * @param string $remote_source
     * @param object $upgrader
     * @param array  $hook_extra
     * @return string
     */
    public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
        global $wp_filesystem;

        /** Ensure this update is for our plugin. */
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
            return $source;
        }

        /** Define correct directory path. */
        $correct_dir = trailingslashit( $remote_source ) . $this->plugin_slug . '/';

        /** Rename extracted directory if needed. */
        if ( $source !== $correct_dir && $wp_filesystem->is_dir( $source ) ) {

            /** Remove existing directory if present. */
            if ( $wp_filesystem->is_dir( $correct_dir ) ) {
                $wp_filesystem->delete( $correct_dir, true );
            }

            /** Move extracted directory to correct location. */
            $wp_filesystem->move( $source, $correct_dir );

            return $correct_dir;
        }

        return $source;
    }

    /**
     * Fetch remote update metadata.
     *
     * Retrieves and caches info.json response for 12 hours.
     *
     * @return object|null
     */
    private function fetch_remote_info() {
        /** Attempt to retrieve cached update data. */
        $cached = get_transient( $this->cache_key );

        /** Return cached data if available. */
        if ( false !== $cached ) {
            return $cached;
        }

        /** Perform HTTP request to fetch update metadata. */
        $response = wp_remote_get(
            $this->update_url,
            array(
                'timeout' => 10,
                'headers' => array( 'Accept' => 'application/json' ),
            )
        );

        /** Return null on request error. */
        if ( is_wp_error( $response ) ) {
            return null;
        }

        /** Validate HTTP response code. */
        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        /** Decode JSON response. */
        $data = json_decode( wp_remote_retrieve_body( $response ) );

        /** Validate required fields. */
        if ( empty( $data ) || empty( $data->version ) || empty( $data->download_url ) ) {
            return null;
        }

        /** Cache response for 12 hours. */
        set_transient( $this->cache_key, $data, 12 * HOUR_IN_SECONDS );

        return $data;
    }
}
