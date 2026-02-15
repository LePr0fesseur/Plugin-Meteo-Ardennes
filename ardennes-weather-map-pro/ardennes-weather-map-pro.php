<?php
/**
 * Plugin Name: Ardennes Weather Map Pro
 * Plugin URI:  https://github.com/LePr0fesseur/Plugin-Meteo-Ardennes
 * Description: Affiche une carte météo interactive du département des Ardennes (France) avec les conditions matin et après-midi pour chaque ville.
 * Version:     1.0.0
 * Author:      Ardennes Weather Map Pro
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ardennes-weather-map-pro
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AWMP_VERSION', '1.0.0' );
define( 'AWMP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AWMP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AWMP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class.
 */
final class Ardennes_Weather_Map_Pro {

    /**
     * Singleton instance.
     */
    private static ?self $instance = null;

    /**
     * Database table name (without prefix).
     */
    public const TABLE_NAME = 'awmp_cities';

    /**
     * Get singleton instance.
     */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->load_dependencies();
        $this->register_hooks();
    }

    /**
     * Load required files.
     */
    private function load_dependencies(): void {
        require_once AWMP_PLUGIN_DIR . 'admin/admin-page.php';
        require_once AWMP_PLUGIN_DIR . 'public/map-display.php';
    }

    /**
     * Register WordPress hooks.
     */
    private function register_hooks(): void {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

        // Admin hooks.
        if ( is_admin() ) {
            $admin = new AWMP_Admin_Page();
            add_action( 'admin_menu', [ $admin, 'add_menu_page' ] );
            add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_assets' ] );
            add_action( 'wp_ajax_awmp_save_city', [ $admin, 'ajax_save_city' ] );
            add_action( 'wp_ajax_awmp_delete_city', [ $admin, 'ajax_delete_city' ] );
            add_action( 'wp_ajax_awmp_get_cities', [ $admin, 'ajax_get_cities' ] );
        }

        // Frontend hooks.
        $frontend = new AWMP_Map_Display();
        add_shortcode( 'ardennes_weather_map', [ $frontend, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $frontend, 'register_assets' ] );
    }

    /**
     * Plugin activation: create database table and insert default cities.
     */
    public function activate(): void {
        $this->create_table();
        $this->insert_default_cities();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate(): void {
        // Nothing to clean on deactivation; data is preserved.
    }

    /**
     * Create the custom database table.
     */
    private function create_table(): void {
        global $wpdb;

        $table_name      = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            city_name VARCHAR(255) NOT NULL,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            morning_temp VARCHAR(10) DEFAULT '',
            morning_condition VARCHAR(50) DEFAULT '',
            afternoon_temp VARCHAR(10) DEFAULT '',
            afternoon_condition VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'awmp_db_version', AWMP_VERSION );
    }

    /**
     * Insert default Ardennes cities.
     */
    private function insert_default_cities(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Only insert if table is empty.
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
        if ( $count > 0 ) {
            return;
        }

        $default_cities = [
            [ 'Charleville-Mézières', 49.7719, 4.7161 ],
            [ 'Sedan',                49.7023, 4.9478 ],
            [ 'Carignan',             49.6340, 5.1700 ],
            [ 'Tremblois-lès-Rocrois', 49.8960, 4.4880 ],
            [ 'Signy-le-Petit',       49.9060, 4.2820 ],
            [ 'Les Hautes-Rivières',  49.8730, 4.8360 ],
            [ 'Givet',                50.1370, 4.8240 ],
            [ 'Revin',                49.9410, 4.6340 ],
            [ 'Vaux-lès-Rubigny',     49.6180, 4.1060 ],
            [ 'Saint-Germainmont',    49.5310, 4.0560 ],
            [ 'Rethel',               49.5094, 4.3644 ],
            [ 'Vouziers',             49.3980, 4.7010 ],
            [ 'Tailly',               49.4350, 4.9810 ],
            [ 'Verrières',            49.3780, 4.9400 ],
            [ 'Omont',                49.5590, 4.7520 ],
        ];

        foreach ( $default_cities as $city ) {
            $wpdb->insert(
                $table_name,
                [
                    'city_name'           => $city[0],
                    'latitude'            => $city[1],
                    'longitude'           => $city[2],
                    'morning_temp'        => '',
                    'morning_condition'   => '',
                    'afternoon_temp'      => '',
                    'afternoon_condition' => '',
                ],
                [ '%s', '%f', '%f', '%s', '%s', '%s', '%s' ]
            );
        }
    }

    /**
     * Get all cities from the database.
     *
     * @return array<object>
     */
    public static function get_cities(): array {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $results    = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY city_name ASC" );
        return is_array( $results ) ? $results : [];
    }
}

// Boot the plugin.
Ardennes_Weather_Map_Pro::get_instance();
