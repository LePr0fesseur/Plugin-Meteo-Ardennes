<?php
/**
 * Frontend map display for Ardennes Weather Map Pro.
 *
 * @package Ardennes_Weather_Map_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the frontend shortcode rendering and asset loading.
 */
class AWMP_Map_Display {

    /**
     * Whether the shortcode is active on this page load.
     */
    private bool $enqueued = false;

    /**
     * Register frontend assets (loaded conditionally).
     */
    public function register_assets(): void {
        // Leaflet CSS.
        wp_register_style(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
            [],
            '1.9.4'
        );

        // Plugin frontend CSS.
        wp_register_style(
            'awmp-front-style',
            AWMP_PLUGIN_URL . 'public/style.css',
            [ 'leaflet' ],
            AWMP_VERSION
        );

        // Leaflet JS.
        wp_register_script(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
            [],
            '1.9.4',
            true
        );

        // Plugin frontend JS.
        wp_register_script(
            'awmp-front-map',
            AWMP_PLUGIN_URL . 'public/map.js',
            [ 'leaflet' ],
            AWMP_VERSION,
            true
        );
    }

    /**
     * Render the [ardennes_weather_map] shortcode.
     */
    public function render_shortcode(): string {
        // Enqueue assets only when shortcode is used.
        if ( ! $this->enqueued ) {
            wp_enqueue_style( 'leaflet' );
            wp_enqueue_style( 'awmp-front-style' );
            wp_enqueue_script( 'leaflet' );
            wp_enqueue_script( 'awmp-front-map' );
            $this->enqueued = true;
        }

        $cities      = Ardennes_Weather_Map_Pro::get_cities();
        $geojson_url = AWMP_PLUGIN_URL . 'assets/geojson/ardennes.geojson';

        // Condition to icon filename mapping.
        $icon_map = [
            'ensoleille'  => 'sun.svg',
            'eclaircies'  => 'partly-cloudy.svg',
            'couvert'     => 'cloud.svg',
            'brouillard'  => 'fog.svg',
            'pluie'       => 'rain.svg',
            'pluie-neige' => 'rain-snow.svg',
            'neige'       => 'snow.svg',
            'orage'       => 'thunder.svg',
        ];

        // Condition labels.
        $condition_labels = [
            'ensoleille'  => 'Ensoleillé',
            'eclaircies'  => 'Éclaircies',
            'couvert'     => 'Couvert',
            'brouillard'  => 'Brouillard',
            'pluie'       => 'Pluie',
            'pluie-neige' => 'Pluie-Neige',
            'neige'       => 'Neige',
            'orage'       => 'Orage',
        ];

        // Prepare cities data for JS.
        $cities_data = [];
        foreach ( $cities as $city ) {
            $cities_data[] = [
                'name'                => $city->city_name,
                'lat'                 => (float) $city->latitude,
                'lng'                 => (float) $city->longitude,
                'morning_temp'        => $city->morning_temp,
                'morning_condition'   => $city->morning_condition,
                'morning_label'       => $condition_labels[ $city->morning_condition ] ?? '',
                'morning_icon'        => isset( $icon_map[ $city->morning_condition ] )
                    ? AWMP_PLUGIN_URL . 'assets/icons/' . $icon_map[ $city->morning_condition ]
                    : '',
                'afternoon_temp'      => $city->afternoon_temp,
                'afternoon_condition' => $city->afternoon_condition,
                'afternoon_label'     => $condition_labels[ $city->afternoon_condition ] ?? '',
                'afternoon_icon'      => isset( $icon_map[ $city->afternoon_condition ] )
                    ? AWMP_PLUGIN_URL . 'assets/icons/' . $icon_map[ $city->afternoon_condition ]
                    : '',
            ];
        }

        // Get last update time.
        $last_update = get_option( 'awmp_last_weather_update', '' );
        $last_update_display = $last_update
            ? wp_date( 'd/m/Y à H:i', strtotime( $last_update ), new DateTimeZone( 'Europe/Paris' ) )
            : '';

        wp_localize_script( 'awmp-front-map', 'awmpMap', [
            'geojsonUrl' => $geojson_url,
            'cities'     => $cities_data,
            'iconsUrl'   => AWMP_PLUGIN_URL . 'assets/icons/',
        ] );

        ob_start();
        ?>
        <div class="awmp-weather-widget">
            <div class="awmp-header">
                <h2 class="awmp-title">Météo des Ardennes</h2>
                <div class="awmp-period-switcher">
                    <button type="button" class="awmp-period-btn active" data-period="morning">
                        <span class="awmp-period-icon">&#9788;</span> Matin
                    </button>
                    <button type="button" class="awmp-period-btn" data-period="afternoon">
                        <span class="awmp-period-icon">&#9789;</span> Après-midi
                    </button>
                </div>
            </div>
            <div class="awmp-map-container">
                <div id="awmp-map"></div>
            </div>
            <div class="awmp-legend">
                <span class="awmp-legend-item">
                    <img src="<?php echo esc_url( AWMP_PLUGIN_URL . 'assets/icons/sun.svg' ); ?>" alt="" width="20" height="20">
                    Ensoleillé
                </span>
                <span class="awmp-legend-item">
                    <img src="<?php echo esc_url( AWMP_PLUGIN_URL . 'assets/icons/partly-cloudy.svg' ); ?>" alt="" width="20" height="20">
                    Éclaircies
                </span>
                <span class="awmp-legend-item">
                    <img src="<?php echo esc_url( AWMP_PLUGIN_URL . 'assets/icons/cloud.svg' ); ?>" alt="" width="20" height="20">
                    Couvert
                </span>
                <span class="awmp-legend-item">
                    <img src="<?php echo esc_url( AWMP_PLUGIN_URL . 'assets/icons/fog.svg' ); ?>" alt="" width="20" height="20">
                    Brouillard
                </span>
                <span class="awmp-legend-item">
                    <img src="<?php echo esc_url( AWMP_PLUGIN_URL . 'assets/icons/rain.svg' ); ?>" alt="" width="20" height="20">
                    Pluie
                </span>
                <span class="awmp-legend-item">
                    <img src="<?php echo esc_url( AWMP_PLUGIN_URL . 'assets/icons/snow.svg' ); ?>" alt="" width="20" height="20">
                    Neige
                </span>
                <span class="awmp-legend-item">
                    <img src="<?php echo esc_url( AWMP_PLUGIN_URL . 'assets/icons/rain-snow.svg' ); ?>" alt="" width="20" height="20">
                    Pluie-Neige
                </span>
                <span class="awmp-legend-item">
                    <img src="<?php echo esc_url( AWMP_PLUGIN_URL . 'assets/icons/thunder.svg' ); ?>" alt="" width="20" height="20">
                    Orage
                </span>
            </div>
            <?php if ( $last_update_display ) : ?>
                <div class="awmp-footer">
                    <span class="awmp-last-update">Dernière mise à jour : <?php echo esc_html( $last_update_display ); ?></span>
                    <span class="awmp-source-label">Sources : Météo France / Meteo &amp; Radar</span>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
