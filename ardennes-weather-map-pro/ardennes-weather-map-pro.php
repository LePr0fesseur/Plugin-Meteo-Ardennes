<?php
/**
 * Plugin Name: Ardennes Weather Map Pro
 * Plugin URI:  https://github.com/LePr0fesseur/Plugin-Meteo-Ardennes
 * Description: Affiche une carte météo interactive du département des Ardennes (France) avec récupération automatisée des données depuis Météo France et Meteo & Radar.
 * Version:     4.0.0
 * Author:      Ardennes Weather Map Pro
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ardennes-weather-map-pro
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AWMP_VERSION', '4.0.0' );
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
     * DB schema version.
     */
    public const DB_VERSION = '3.0.0';

    /**
     * Cron hook name.
     */
    public const CRON_HOOK = 'awmp_daily_weather_update';

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
        require_once AWMP_PLUGIN_DIR . 'includes/class-weather-fetcher.php';
        require_once AWMP_PLUGIN_DIR . 'admin/admin-page.php';
        require_once AWMP_PLUGIN_DIR . 'public/svg-map.php';
        require_once AWMP_PLUGIN_DIR . 'public/map-display.php';
    }

    /**
     * Register WordPress hooks.
     */
    private function register_hooks(): void {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

        // Check for DB upgrades on admin_init.
        add_action( 'admin_init', [ $this, 'maybe_upgrade_db' ] );

        // Cron hook.
        add_action( self::CRON_HOOK, [ 'AWMP_Weather_Fetcher', 'cron_update' ] );

        // Admin hooks.
        if ( is_admin() ) {
            $admin = new AWMP_Admin_Page();
            add_action( 'admin_menu', [ $admin, 'add_menu_page' ] );
            add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_assets' ] );
            add_action( 'wp_ajax_awmp_save_city', [ $admin, 'ajax_save_city' ] );
            add_action( 'wp_ajax_awmp_delete_city', [ $admin, 'ajax_delete_city' ] );
            add_action( 'wp_ajax_awmp_get_cities', [ $admin, 'ajax_get_cities' ] );
            add_action( 'wp_ajax_awmp_manual_weather_update', [ $admin, 'ajax_manual_weather_update' ] );
            add_action( 'wp_ajax_awmp_save_display_settings', [ $admin, 'ajax_save_display_settings' ] );
        }

        // Frontend hooks.
        $frontend = new AWMP_Map_Display();
        add_shortcode( 'ardennes_weather_map', [ $frontend, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $frontend, 'register_assets' ] );
    }

    /**
     * Plugin activation: create database table, insert default cities, schedule cron.
     */
    public function activate(): void {
        $this->create_table();
        $this->insert_default_cities();
        $this->schedule_cron();
    }

    /**
     * Plugin deactivation: unschedule cron.
     */
    public function deactivate(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Schedule the daily cron event at 06:00 Paris time.
     */
    private function schedule_cron(): void {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }

        // Calculate next 06:00 Paris time.
        $paris_tz = new DateTimeZone( 'Europe/Paris' );
        $now      = new DateTime( 'now', $paris_tz );
        $target   = new DateTime( 'today 06:00', $paris_tz );

        // If 06:00 today has already passed, schedule for tomorrow.
        if ( $now > $target ) {
            $target->modify( '+1 day' );
        }

        wp_schedule_event( $target->getTimestamp(), 'daily', self::CRON_HOOK );
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
            postal_code VARCHAR(10) DEFAULT '',
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            morning_temp VARCHAR(10) DEFAULT '',
            morning_condition VARCHAR(50) DEFAULT '',
            afternoon_temp VARCHAR(10) DEFAULT '',
            afternoon_condition VARCHAR(50) DEFAULT '',
            temp_mf_morning DECIMAL(5,1) DEFAULT NULL,
            temp_mf_afternoon DECIMAL(5,1) DEFAULT NULL,
            temp_mr_morning DECIMAL(5,1) DEFAULT NULL,
            temp_mr_afternoon DECIMAL(5,1) DEFAULT NULL,
            weather_code_morning INT DEFAULT NULL,
            weather_code_afternoon INT DEFAULT NULL,
            tomorrow_morning_temp VARCHAR(10) DEFAULT '',
            tomorrow_morning_condition VARCHAR(50) DEFAULT '',
            tomorrow_afternoon_temp VARCHAR(10) DEFAULT '',
            tomorrow_afternoon_condition VARCHAR(50) DEFAULT '',
            temp_mf_tomorrow_morning DECIMAL(5,1) DEFAULT NULL,
            temp_mf_tomorrow_afternoon DECIMAL(5,1) DEFAULT NULL,
            temp_mr_tomorrow_morning DECIMAL(5,1) DEFAULT NULL,
            temp_mr_tomorrow_afternoon DECIMAL(5,1) DEFAULT NULL,
            weather_code_tomorrow_morning INT DEFAULT NULL,
            weather_code_tomorrow_afternoon INT DEFAULT NULL,
            last_weather_update DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'awmp_db_version', self::DB_VERSION );
    }

    /**
     * Check if DB needs upgrading and run upgrade if needed.
     */
    public function maybe_upgrade_db(): void {
        $current_version = get_option( 'awmp_db_version', '1.0.0' );
        if ( version_compare( $current_version, self::DB_VERSION, '<' ) ) {
            $this->create_table(); // dbDelta handles ALTER TABLE operations.
            $this->update_postal_codes();
        }
    }

    /**
     * Update postal codes for existing cities that don't have one.
     */
    private function update_postal_codes(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $postal_codes = self::get_default_postal_codes();

        foreach ( $postal_codes as $city_name => $postal_code ) {
            $wpdb->update(
                $table_name,
                [ 'postal_code' => $postal_code ],
                [ 'city_name' => $city_name, 'postal_code' => '' ],
                [ '%s' ],
                [ '%s', '%s' ]
            );
        }
    }

    /**
     * Get the default postal codes mapping.
     *
     * @return array<string, string>
     */
    public static function get_default_postal_codes(): array {
        return [
            'Charleville-Mézières'  => '08000',
            'Sedan'                 => '08200',
            'Carignan'              => '08110',
            'Tremblois-lès-Rocrois' => '08150',
            'Signy-le-Petit'        => '08380',
            'Les Hautes-Rivières'   => '08800',
            'Givet'                 => '08600',
            'Revin'                 => '08500',
            'Vaux-lès-Rubigny'      => '08220',
            'Saint-Germainmont'     => '08190',
            'Rethel'                => '08300',
            'Vouziers'              => '08400',
            'Tailly'                => '08240',
            'Verrières'             => '08390',
            'Omont'                 => '08430',
        ];
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

        $postal_codes = self::get_default_postal_codes();

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
                    'postal_code'         => $postal_codes[ $city[0] ] ?? '',
                    'latitude'            => $city[1],
                    'longitude'           => $city[2],
                    'morning_temp'        => '',
                    'morning_condition'   => '',
                    'afternoon_temp'      => '',
                    'afternoon_condition' => '',
                ],
                [ '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s' ]
            );
        }
    }

    /**
     * Default display settings.
     *
     * @return array<string, int>
     */
    public static function get_default_display_settings(): array {
        return [
            'city_name_font_size' => 18,
            'temp_font_size'      => 18,
            'icon_size'           => 34,
            'dot_radius'          => 7,
        ];
    }

    /**
     * Get current display settings (merged with defaults).
     *
     * @return array<string, int>
     */
    public static function get_display_settings(): array {
        $defaults = self::get_default_display_settings();
        $saved    = get_option( 'awmp_display_settings', [] );
        return wp_parse_args( $saved, $defaults );
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
