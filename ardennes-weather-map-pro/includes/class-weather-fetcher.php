<?php
/**
 * Weather data fetcher for Ardennes Weather Map Pro.
 *
 * Retrieves weather data from two sources via Open-Meteo APIs:
 * - Météo France (ARPEGE/AROME models) via /v1/meteofrance
 * - Second source (best-match models) via /v1/forecast
 *
 * Generates reference URLs for:
 * - https://meteofrance.com/previsions-meteo-france/{slug}/{code-postal}
 * - https://www.meteoetradar.com/meteo/{slug}/
 *
 * @package Ardennes_Weather_Map_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AWMP_Weather_Fetcher {

    /**
     * Open-Meteo Météo-France API endpoint.
     */
    private const API_METEO_FRANCE = 'https://api.open-meteo.com/v1/meteofrance';

    /**
     * Open-Meteo generic forecast API endpoint.
     */
    private const API_FORECAST = 'https://api.open-meteo.com/v1/forecast';

    /**
     * Morning hours range (07:00 - 11:00).
     */
    private const MORNING_HOURS = [ 7, 8, 9, 10, 11 ];

    /**
     * Afternoon hours range (13:00 - 17:00).
     */
    private const AFTERNOON_HOURS = [ 13, 14, 15, 16, 17 ];

    /**
     * Tomorrow morning hours (offset +24).
     */
    private const TOMORROW_MORNING_HOURS = [ 31, 32, 33, 34, 35 ];

    /**
     * Tomorrow afternoon hours (offset +24).
     */
    private const TOMORROW_AFTERNOON_HOURS = [ 37, 38, 39, 40, 41 ];

    /**
     * WMO weather code to internal condition mapping.
     */
    private const WMO_CONDITIONS = [
        0  => 'ensoleille',
        1  => 'ensoleille',
        2  => 'eclaircies',
        3  => 'couvert',
        45 => 'brouillard',
        48 => 'brouillard',
        51 => 'pluie',
        53 => 'pluie',
        55 => 'pluie',
        56 => 'pluie-neige',
        57 => 'pluie-neige',
        61 => 'pluie',
        63 => 'pluie',
        65 => 'pluie',
        66 => 'pluie-neige',
        67 => 'pluie-neige',
        71 => 'neige',
        73 => 'neige',
        75 => 'neige',
        77 => 'neige',
        80 => 'pluie',
        81 => 'pluie',
        82 => 'pluie',
        85 => 'neige',
        86 => 'neige',
        95 => 'orage',
        96 => 'orage',
        99 => 'orage',
    ];

    /**
     * Fetch and update weather data for all cities.
     *
     * @return array{updated: int, errors: string[]}
     */
    public static function update_all_cities(): array {
        global $wpdb;

        $table_name = $wpdb->prefix . Ardennes_Weather_Map_Pro::TABLE_NAME;
        $cities     = $wpdb->get_results( "SELECT * FROM {$table_name}" );
        $updated    = 0;
        $errors     = [];

        if ( empty( $cities ) ) {
            return [ 'updated' => 0, 'errors' => [ 'Aucune ville en base de données.' ] ];
        }

        foreach ( $cities as $city ) {
            $result = self::fetch_and_store_city( $city );
            if ( true === $result ) {
                $updated++;
            } else {
                $errors[] = sprintf( '%s : %s', $city->city_name, $result );
            }
        }

        update_option( 'awmp_last_weather_update', current_time( 'mysql' ) );

        return [ 'updated' => $updated, 'errors' => $errors ];
    }

    /**
     * Fetch weather data for a single city and store it.
     *
     * @param object $city Database city row.
     * @return true|string True on success, error message on failure.
     */
    private static function fetch_and_store_city( object $city ): bool|string {
        $lat = (float) $city->latitude;
        $lon = (float) $city->longitude;

        // Fetch from Météo France source.
        $mf_data = self::fetch_from_api( self::API_METEO_FRANCE, $lat, $lon, true );
        if ( is_string( $mf_data ) ) {
            return 'Météo France : ' . $mf_data;
        }

        // Fetch from second source.
        $mr_data = self::fetch_from_api( self::API_FORECAST, $lat, $lon, false );
        if ( is_string( $mr_data ) ) {
            return 'Source 2 : ' . $mr_data;
        }

        // Calculate averaged temperatures — today.
        $morning_avg   = self::calculate_average( $mf_data['morning_temp'], $mr_data['morning_temp'] );
        $afternoon_avg = self::calculate_average( $mf_data['afternoon_temp'], $mr_data['afternoon_temp'] );

        // Calculate averaged temperatures — tomorrow.
        $tmr_morning_avg   = self::calculate_average( $mf_data['tomorrow_morning_temp'], $mr_data['tomorrow_morning_temp'] );
        $tmr_afternoon_avg = self::calculate_average( $mf_data['tomorrow_afternoon_temp'], $mr_data['tomorrow_afternoon_temp'] );

        // Get conditions from Météo France only.
        $morning_condition   = $mf_data['morning_condition'];
        $afternoon_condition = $mf_data['afternoon_condition'];

        // Store in database.
        return self::store_weather_data( $city->id, [
            'morning_temp'                 => self::format_temp( $morning_avg ),
            'morning_condition'            => $morning_condition,
            'afternoon_temp'               => self::format_temp( $afternoon_avg ),
            'afternoon_condition'          => $afternoon_condition,
            'temp_mf_morning'              => $mf_data['morning_temp'],
            'temp_mf_afternoon'            => $mf_data['afternoon_temp'],
            'temp_mr_morning'              => $mr_data['morning_temp'],
            'temp_mr_afternoon'            => $mr_data['afternoon_temp'],
            'weather_code_morning'         => $mf_data['morning_code'],
            'weather_code_afternoon'       => $mf_data['afternoon_code'],
            'tomorrow_morning_temp'        => self::format_temp( $tmr_morning_avg ),
            'tomorrow_morning_condition'   => $mf_data['tomorrow_morning_condition'],
            'tomorrow_afternoon_temp'      => self::format_temp( $tmr_afternoon_avg ),
            'tomorrow_afternoon_condition' => $mf_data['tomorrow_afternoon_condition'],
            'temp_mf_tomorrow_morning'     => $mf_data['tomorrow_morning_temp'],
            'temp_mf_tomorrow_afternoon'   => $mf_data['tomorrow_afternoon_temp'],
            'temp_mr_tomorrow_morning'     => $mr_data['tomorrow_morning_temp'],
            'temp_mr_tomorrow_afternoon'   => $mr_data['tomorrow_afternoon_temp'],
            'weather_code_tomorrow_morning'   => $mf_data['tomorrow_morning_code'],
            'weather_code_tomorrow_afternoon' => $mf_data['tomorrow_afternoon_code'],
            'last_weather_update'          => current_time( 'mysql' ),
        ] );
    }

    /**
     * Fetch hourly data from an Open-Meteo API endpoint.
     *
     * @param string $endpoint API base URL.
     * @param float  $lat      Latitude.
     * @param float  $lon      Longitude.
     * @param bool   $with_weather_code Whether to fetch weather_code.
     * @return array{morning_temp: float, afternoon_temp: float, morning_condition: string, afternoon_condition: string, morning_code: int, afternoon_code: int}|string Data array or error message.
     */
    private static function fetch_from_api( string $endpoint, float $lat, float $lon, bool $with_weather_code ): array|string {
        $hourly_params = 'temperature_2m';
        if ( $with_weather_code ) {
            $hourly_params .= ',weather_code';
        }

        $url = add_query_arg( [
            'latitude'      => $lat,
            'longitude'     => $lon,
            'hourly'        => $hourly_params,
            'timezone'      => 'Europe/Paris',
            'forecast_days' => 2,
        ], $endpoint );

        $response = wp_remote_get( $url, [
            'timeout'    => 15,
            'user-agent' => 'ArdWeatherMapPro/' . AWMP_VERSION . ' (WordPress)',
        ] );

        if ( is_wp_error( $response ) ) {
            return $response->get_error_message();
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return sprintf( 'HTTP %d', $code );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( empty( $data['hourly']['temperature_2m'] ) ) {
            return 'Données de température manquantes.';
        }

        $temps = $data['hourly']['temperature_2m'];
        $codes = $with_weather_code ? ( $data['hourly']['weather_code'] ?? [] ) : [];

        // Extract morning values.
        $morning_temps = self::extract_hours( $temps, self::MORNING_HOURS );
        $morning_temp  = ! empty( $morning_temps ) ? array_sum( $morning_temps ) / count( $morning_temps ) : null;

        // Extract afternoon values.
        $afternoon_temps = self::extract_hours( $temps, self::AFTERNOON_HOURS );
        $afternoon_temp  = ! empty( $afternoon_temps ) ? array_sum( $afternoon_temps ) / count( $afternoon_temps ) : null;

        if ( null === $morning_temp || null === $afternoon_temp ) {
            return 'Données horaires insuffisantes.';
        }

        // Extract tomorrow values.
        $tmr_morning_temps  = self::extract_hours( $temps, self::TOMORROW_MORNING_HOURS );
        $tmr_morning_temp   = ! empty( $tmr_morning_temps ) ? array_sum( $tmr_morning_temps ) / count( $tmr_morning_temps ) : $morning_temp;

        $tmr_afternoon_temps = self::extract_hours( $temps, self::TOMORROW_AFTERNOON_HOURS );
        $tmr_afternoon_temp  = ! empty( $tmr_afternoon_temps ) ? array_sum( $tmr_afternoon_temps ) / count( $tmr_afternoon_temps ) : $afternoon_temp;

        $result = [
            'morning_temp'                => round( $morning_temp, 1 ),
            'afternoon_temp'              => round( $afternoon_temp, 1 ),
            'morning_condition'           => '',
            'afternoon_condition'         => '',
            'morning_code'                => 0,
            'afternoon_code'              => 0,
            'tomorrow_morning_temp'       => round( $tmr_morning_temp, 1 ),
            'tomorrow_afternoon_temp'     => round( $tmr_afternoon_temp, 1 ),
            'tomorrow_morning_condition'  => '',
            'tomorrow_afternoon_condition' => '',
            'tomorrow_morning_code'       => 0,
            'tomorrow_afternoon_code'     => 0,
        ];

        if ( $with_weather_code && ! empty( $codes ) ) {
            $morning_codes   = self::extract_hours( $codes, self::MORNING_HOURS );
            $afternoon_codes = self::extract_hours( $codes, self::AFTERNOON_HOURS );

            $morning_dominant   = self::dominant_value( $morning_codes );
            $afternoon_dominant = self::dominant_value( $afternoon_codes );

            $result['morning_condition']   = self::WMO_CONDITIONS[ $morning_dominant ] ?? '';
            $result['afternoon_condition'] = self::WMO_CONDITIONS[ $afternoon_dominant ] ?? '';
            $result['morning_code']        = $morning_dominant;
            $result['afternoon_code']      = $afternoon_dominant;

            // Tomorrow conditions.
            $tmr_morning_codes   = self::extract_hours( $codes, self::TOMORROW_MORNING_HOURS );
            $tmr_afternoon_codes = self::extract_hours( $codes, self::TOMORROW_AFTERNOON_HOURS );

            $tmr_morning_dominant   = self::dominant_value( $tmr_morning_codes );
            $tmr_afternoon_dominant = self::dominant_value( $tmr_afternoon_codes );

            $result['tomorrow_morning_condition']  = self::WMO_CONDITIONS[ $tmr_morning_dominant ] ?? '';
            $result['tomorrow_afternoon_condition'] = self::WMO_CONDITIONS[ $tmr_afternoon_dominant ] ?? '';
            $result['tomorrow_morning_code']       = $tmr_morning_dominant;
            $result['tomorrow_afternoon_code']     = $tmr_afternoon_dominant;
        }

        return $result;
    }

    /**
     * Extract values at specific hour indices from hourly array.
     *
     * @param array $hourly_data Full 24-hour array.
     * @param int[] $hours       Hour indices to extract.
     * @return float[]
     */
    private static function extract_hours( array $hourly_data, array $hours ): array {
        $values = [];
        foreach ( $hours as $h ) {
            if ( isset( $hourly_data[ $h ] ) && null !== $hourly_data[ $h ] ) {
                $values[] = $hourly_data[ $h ];
            }
        }
        return $values;
    }

    /**
     * Get the most frequent value in an array (mode).
     *
     * @param array $values Numeric values.
     * @return int Most frequent value, or 0 if empty.
     */
    private static function dominant_value( array $values ): int {
        if ( empty( $values ) ) {
            return 0;
        }
        $counts = array_count_values( array_map( 'intval', $values ) );
        arsort( $counts );
        return (int) array_key_first( $counts );
    }

    /**
     * Calculate average of two temperature values.
     *
     * @param float $temp1 First source temperature.
     * @param float $temp2 Second source temperature.
     * @return float Averaged temperature rounded to 1 decimal.
     */
    private static function calculate_average( float $temp1, float $temp2 ): float {
        return round( ( $temp1 + $temp2 ) / 2, 1 );
    }

    /**
     * Format a temperature value for display.
     *
     * @param float $temp Temperature in Celsius.
     * @return string Formatted string like "5°C" or "-2°C".
     */
    private static function format_temp( float $temp ): string {
        $rounded = (int) round( $temp );
        return $rounded . '°C';
    }

    /**
     * Store weather data for a city in the database.
     *
     * @param int   $city_id City database ID.
     * @param array $data    Weather data to store.
     * @return true|string True on success, error message on failure.
     */
    private static function store_weather_data( int $city_id, array $data ): bool|string {
        global $wpdb;
        $table_name = $wpdb->prefix . Ardennes_Weather_Map_Pro::TABLE_NAME;

        $result = $wpdb->update(
            $table_name,
            $data,
            [ 'id' => $city_id ],
            [
                '%s', // morning_temp
                '%s', // morning_condition
                '%s', // afternoon_temp
                '%s', // afternoon_condition
                '%f', // temp_mf_morning
                '%f', // temp_mf_afternoon
                '%f', // temp_mr_morning
                '%f', // temp_mr_afternoon
                '%d', // weather_code_morning
                '%d', // weather_code_afternoon
                '%s', // tomorrow_morning_temp
                '%s', // tomorrow_morning_condition
                '%s', // tomorrow_afternoon_temp
                '%s', // tomorrow_afternoon_condition
                '%f', // temp_mf_tomorrow_morning
                '%f', // temp_mf_tomorrow_afternoon
                '%f', // temp_mr_tomorrow_morning
                '%f', // temp_mr_tomorrow_afternoon
                '%d', // weather_code_tomorrow_morning
                '%d', // weather_code_tomorrow_afternoon
                '%s', // last_weather_update
            ],
            [ '%d' ]
        );

        if ( false === $result ) {
            return 'Erreur de base de données.';
        }

        return true;
    }

    /**
     * Generate a slug from a city name (no accents, lowercase, hyphens).
     *
     * @param string $city_name City name.
     * @return string URL-safe slug.
     */
    public static function city_slug( string $city_name ): string {
        $slug = mb_strtolower( $city_name, 'UTF-8' );

        // Replace accented characters.
        $replacements = [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
            'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'é' => 'e',
            'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ÿ' => 'y', 'ç' => 'c', 'ñ' => 'n',
        ];
        $slug = strtr( $slug, $replacements );

        // Replace spaces and special chars with hyphens.
        $slug = preg_replace( '/[^a-z0-9\-]/', '-', $slug );
        $slug = preg_replace( '/-+/', '-', $slug );
        $slug = trim( $slug, '-' );

        return $slug;
    }

    /**
     * Generate the Météo France URL for a city.
     *
     * @param string $city_name   City name.
     * @param string $postal_code Postal code.
     * @return string Full URL.
     */
    public static function get_meteo_france_url( string $city_name, string $postal_code ): string {
        $slug = self::city_slug( $city_name );
        return sprintf(
            'https://meteofrance.com/previsions-meteo-france/%s/%s',
            $slug,
            rawurlencode( $postal_code )
        );
    }

    /**
     * Generate the Meteo & Radar URL for a city.
     *
     * @param string $city_name City name.
     * @return string Full URL.
     */
    public static function get_meteo_radar_url( string $city_name ): string {
        $slug = self::city_slug( $city_name );
        return sprintf(
            'https://www.meteoetradar.com/meteo/%s/',
            $slug
        );
    }

    /**
     * Get a human-readable label for a WMO condition.
     *
     * @param string $condition Internal condition key.
     * @return string French label.
     */
    public static function condition_label( string $condition ): string {
        $labels = [
            'ensoleille'  => 'Ensoleillé',
            'eclaircies'  => 'Éclaircies',
            'couvert'     => 'Couvert',
            'brouillard'  => 'Brouillard',
            'pluie'       => 'Pluie',
            'pluie-neige' => 'Pluie-Neige',
            'neige'       => 'Neige',
            'orage'       => 'Orage',
        ];
        return $labels[ $condition ] ?? '';
    }

    /**
     * WP-Cron callback: fetch weather data for all cities.
     */
    public static function cron_update(): void {
        $result = self::update_all_cities();

        if ( ! empty( $result['errors'] ) ) {
            error_log( 'AWMP Weather Update - Errors: ' . implode( ' | ', $result['errors'] ) );
        }
        error_log( sprintf( 'AWMP Weather Update - %d villes mises à jour.', $result['updated'] ) );
    }
}
