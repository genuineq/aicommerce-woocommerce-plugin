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

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Updater Class
 */
class Updater {

    /**
     * Plugin file: aicommerce/aicommerce.php
     */
    private string $plugin_file;

    /**
     * Plugin slug: aicommerce
     */
    private string $plugin_slug = 'aicommerce';

    /**
     * Remote info.json URL
     */
    private string $update_url = 'https://api.ai.genuineq.com/woocommerce/info.json';

    /**
     * Current plugin version
     */
    private string $version;

    /**
     * Transient cache key
     */
    private string $cache_key = 'aicommerce_update_info';

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_file = plugin_basename( AICOMMERCE_PLUGIN_FILE );
        $this->version     = AICOMMERCE_VERSION;

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_directory_name' ), 10, 4 );
    }

    /**
     * Called every time WordPress checks for plugin updates.
     * Injects our plugin into the update list when a newer version is available.
     *
     * @param object $transient WordPress update transient.
     * @return object
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $remote = $this->fetch_remote_info();
        if ( ! $remote || empty( $remote->version ) ) {
            return $transient;
        }

        if ( version_compare( $this->version, $remote->version, '<' ) ) {
            // Newer version available — add to update list
            $transient->response[ $this->plugin_file ] = (object) array(
                'id'            => $this->plugin_file,
                'slug'          => $this->plugin_slug,
                'plugin'        => $this->plugin_file,
                'new_version'   => $remote->version,
                'url'           => isset( $remote->homepage ) ? $remote->homepage : '',
                'package'       => $remote->download_url,
                'requires'      => isset( $remote->requires )     ? $remote->requires     : '5.8',
                'tested'        => isset( $remote->tested )        ? $remote->tested        : '6.5',
                'requires_php'  => isset( $remote->requires_php )  ? $remote->requires_php  : '7.4',
                'icons'         => isset( $remote->icons )         ? (array) $remote->icons : array(),
                'banners'       => isset( $remote->banners )       ? (array) $remote->banners : array(),
            );

            // Remove from no_update list if present
            unset( $transient->no_update[ $this->plugin_file ] );
        } else {
            // Already up to date
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
     * Called when WordPress displays the plugin details popup ("View Details").
     *
     * @param false|object|array $result  Current result.
     * @param string             $action  API action.
     * @param object             $args    Request args.
     * @return false|object
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || $this->plugin_slug !== $args->slug ) {
            return $result;
        }

        $remote = $this->fetch_remote_info();
        if ( ! $remote ) {
            return $result;
        }

        return (object) array(
            'name'          => isset( $remote->name )         ? $remote->name         : 'AICommerce',
            'slug'          => $this->plugin_slug,
            'version'       => $remote->version,
            'author'        => isset( $remote->author )       ? $remote->author       : '',
            'homepage'      => isset( $remote->homepage )     ? $remote->homepage     : '',
            'requires'      => isset( $remote->requires )     ? $remote->requires     : '5.8',
            'tested'        => isset( $remote->tested )        ? $remote->tested        : '6.5',
            'requires_php'  => isset( $remote->requires_php ) ? $remote->requires_php : '7.4',
            'last_updated'  => isset( $remote->last_updated ) ? $remote->last_updated : '',
            'sections'      => isset( $remote->sections )     ? (array) $remote->sections : array(),
            'download_link' => $remote->download_url,
            'icons'         => isset( $remote->icons )        ? (array) $remote->icons : array(),
            'banners'       => isset( $remote->banners )      ? (array) $remote->banners : array(),
        );
    }

    /**
     * GitHub releases the ZIP with the repo name as the folder.
     * This renames the extracted folder to 'aicommerce' so WordPress
     * replaces the correct directory.
     *
     * @param string      $source        Path to extracted folder.
     * @param string      $remote_source Remote source.
     * @param object      $upgrader      Upgrader instance.
     * @param array       $hook_extra    Extra hook data.
     * @return string
     */
    public function fix_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
        global $wp_filesystem;

        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_file ) {
            return $source;
        }

        $correct_dir = trailingslashit( $remote_source ) . $this->plugin_slug . '/';

        if ( $source !== $correct_dir && $wp_filesystem->is_dir( $source ) ) {
            if ( $wp_filesystem->is_dir( $correct_dir ) ) {
                $wp_filesystem->delete( $correct_dir, true );
            }
            $wp_filesystem->move( $source, $correct_dir );
            return $correct_dir;
        }

        return $source;
    }

    /**
     * Fetches and caches remote info.json.
     * Cache TTL: 12 hours.
     *
     * @return object|null
     */
    private function fetch_remote_info() {
        $cached = get_transient( $this->cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $response = wp_remote_get(
            $this->update_url,
            array(
                'timeout' => 10,
                'headers' => array( 'Accept' => 'application/json' ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ) );

        if ( empty( $data ) || empty( $data->version ) || empty( $data->download_url ) ) {
            return null;
        }

        set_transient( $this->cache_key, $data, 12 * HOUR_IN_SECONDS );

        return $data;
    }
}
