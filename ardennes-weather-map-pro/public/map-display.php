<?php
/**
 * Frontend map display for Ardennes Weather Map Pro.
 *
 * Renders the [ardennes_weather_map] shortcode using an inline SVG map.
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
        // Plugin frontend CSS (SVG version — no external dependency).
        wp_register_style(
            'awmp-front-style',
            AWMP_PLUGIN_URL . 'public/svg-map.css',
            [],
            AWMP_VERSION
        );

        // Plugin frontend JS (vanilla — no external dependency).
        wp_register_script(
            'awmp-front-map',
            AWMP_PLUGIN_URL . 'public/svg-map.js',
            [],
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
            wp_enqueue_style( 'awmp-front-style' );
            wp_enqueue_script( 'awmp-front-map' );
            $this->enqueued = true;
        }

        $cities = Ardennes_Weather_Map_Pro::get_cities();

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

        // Prepare cities data for both PHP SVG render and JS dynamic updates.
        $cities_data = [];
        foreach ( $cities as $city ) {
            $cities_data[] = [
                'name'                           => $city->city_name,
                'lat'                            => (float) $city->latitude,
                'lng'                            => (float) $city->longitude,
                'morning_temp'                   => $city->morning_temp,
                'morning_condition'              => $city->morning_condition,
                'morning_label'                  => $condition_labels[ $city->morning_condition ] ?? '',
                'afternoon_temp'                 => $city->afternoon_temp,
                'afternoon_condition'            => $city->afternoon_condition,
                'afternoon_label'                => $condition_labels[ $city->afternoon_condition ] ?? '',
                'tomorrow_morning_temp'          => $city->tomorrow_morning_temp ?? '',
                'tomorrow_morning_condition'     => $city->tomorrow_morning_condition ?? '',
                'tomorrow_morning_label'         => $condition_labels[ $city->tomorrow_morning_condition ?? '' ] ?? '',
                'tomorrow_afternoon_temp'        => $city->tomorrow_afternoon_temp ?? '',
                'tomorrow_afternoon_condition'   => $city->tomorrow_afternoon_condition ?? '',
                'tomorrow_afternoon_label'       => $condition_labels[ $city->tomorrow_afternoon_condition ?? '' ] ?? '',
            ];
        }

        // Get last update time.
        $last_update = get_option( 'awmp_last_weather_update', '' );
        $last_update_display = $last_update
            ? wp_date( 'd/m/Y à H:i', strtotime( $last_update ), new DateTimeZone( 'Europe/Paris' ) )
            : '';

        // Pass data to JS for dynamic period switching.
        wp_localize_script( 'awmp-front-map', 'awmpMapData', [
            'cities' => $cities_data,
        ] );

        // Generate the inline SVG map (morning data rendered by default).
        $icons_dir = AWMP_PLUGIN_DIR . 'assets/icons/';
        $svg_html  = AWMP_SVG_Map::render( $cities_data, $icons_dir );

        ob_start();
        ?>
        <div class="awmp-weather-widget">
            <div class="awmp-header">
                <h2 class="awmp-title">Météo des Ardennes</h2>
                <div class="awmp-switchers">
                    <div class="awmp-day-switcher">
                        <button type="button" class="awmp-day-btn active" data-day="today">Aujourd'hui</button>
                        <button type="button" class="awmp-day-btn" data-day="tomorrow">Demain</button>
                    </div>
                    <div class="awmp-period-switcher">
                        <button type="button" class="awmp-period-btn active" data-period="morning">
                            <span class="awmp-period-icon">&#9788;</span> Matin
                        </button>
                        <button type="button" class="awmp-period-btn" data-period="afternoon">
                            <span class="awmp-period-icon">&#9789;</span> Après-midi
                        </button>
                    </div>
                </div>
            </div>
            <div class="awmp-map-container">
                <?php echo $svg_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG generated internally. ?>
                <div class="awmp-weather-overlay" data-condition=""></div>
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
