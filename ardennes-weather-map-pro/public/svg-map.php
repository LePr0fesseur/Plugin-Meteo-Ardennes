<?php
/**
 * SVG Map renderer for Ardennes Weather Map Pro.
 *
 * Generates an inline SVG weather map of the Ardennes department
 * from GeoJSON boundary data and city weather information.
 *
 * @package Ardennes_Weather_Map_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds and returns the inline SVG markup for the weather map.
 */
class AWMP_SVG_Map {

    /* ─── Projection constants (equirectangular with cosine correction) ─── */

    /** Padded minimum longitude. */
    private const LNG_MIN = 3.995;

    /** Padded maximum latitude (SVG y = 0 corresponds to this). */
    private const LAT_MAX = 50.199;

    /** Scale factor: viewBox height / padded latitude range. */
    private const SCALE = 997.5;

    /** Cosine of the center latitude (~49.698 deg) for longitude correction. */
    private const COS_LAT = 0.6468;

    /** SVG viewBox width. */
    private const VB_W = 922;

    /** SVG viewBox height. */
    private const VB_H = 1000;

    /** Transient key for the cached boundary SVG path. */
    private const CACHE_KEY = 'awmp_svg_boundary_path';

    /**
     * Condition slug → icon filename mapping.
     *
     * @var array<string, string>
     */
    private const ICON_FILES = [
        'ensoleille'  => 'sun.svg',
        'eclaircies'  => 'partly-cloudy.svg',
        'couvert'     => 'cloud.svg',
        'brouillard'  => 'fog.svg',
        'pluie'       => 'rain.svg',
        'pluie-neige' => 'rain-snow.svg',
        'neige'       => 'snow.svg',
        'orage'       => 'thunder.svg',
    ];

    /**
     * Per-city label offsets to prevent overlaps.
     *
     * Keys are city slugs. Values: [text-anchor, dx, dy].
     * Default when not listed: ['middle', 0, -30].
     *
     * @var array<string, array{string, int, int}>
     */
    private const LABEL_OFFSETS = [
        'charleville-mezieres'  => [ 'middle', 0, -42 ],
        'sedan'                 => [ 'middle', 0, -42 ],
        'carignan'              => [ 'middle', 0, -42 ],
        'tremblois-les-rocrois' => [ 'middle', 0, -42 ],
        'signy-le-petit'        => [ 'middle', 0, -42 ],
        'les-hautes-rivieres'   => [ 'start', 14, -10 ],
        'givet'                 => [ 'middle', 0, -42 ],
        'revin'                 => [ 'end', -14, -10 ],
        'vaux-les-rubigny'      => [ 'middle', 0, -42 ],
        'saint-germainmont'     => [ 'middle', 0, -42 ],
        'rethel'                => [ 'middle', 0, -42 ],
        'vouziers'              => [ 'middle', 0, -42 ],
        'tailly'                => [ 'start', 14, -10 ],
        'verrieres'             => [ 'end', -14, 6 ],
        'omont'                 => [ 'middle', 0, -42 ],
    ];

    /**
     * Temperature-to-colour stops for the badge background.
     *
     * @var array<int, array{int, int, int, int}>  [ temperature, R, G, B ]
     */
    private const COLOR_STOPS = [
        [ -10, 33, 150, 243 ],   // deep blue
        [   0, 0, 188, 212 ],    // cyan
        [  10, 76, 175, 80 ],    // green
        [  20, 255, 193, 7 ],    // amber
        [  30, 255, 87, 34 ],    // deep orange
        [  40, 244, 67, 54 ],    // red
    ];

    /* ================================================================== */
    /*  Public API                                                        */
    /* ================================================================== */

    /**
     * Display settings (loaded once per render).
     *
     * @var array<string, int>
     */
    private static array $settings = [];

    /**
     * Render the complete inline SVG.
     *
     * @param array  $cities   Array of city data arrays (from map-display.php).
     * @param string $icons_dir Absolute path to the icons directory.
     * @return string The inline SVG markup.
     */
    public static function render( array $cities, string $icons_dir ): string {
        self::$settings = Ardennes_Weather_Map_Pro::get_display_settings();
        $boundary_path = self::get_boundary_path();
        $icon_symbols  = self::get_icon_symbols( $icons_dir );

        $svg  = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"';
        $svg .= ' viewBox="0 0 ' . self::VB_W . ' ' . self::VB_H . '"';
        $svg .= ' class="awmp-svg-map" role="img" aria-label="Carte météo des Ardennes"';
        $svg .= ' preserveAspectRatio="xMidYMid meet">';

        // ── Defs ──
        $svg .= '<defs>';
        $svg .= self::render_gradients();
        $svg .= self::render_filters();
        $svg .= $icon_symbols;
        $svg .= '</defs>';

        // ── Layer 1: Background ──
        $svg .= '<rect width="' . self::VB_W . '" height="' . self::VB_H . '" fill="url(#awmp-bg-grad)" rx="0"/>';

        // ── Layer 2: Department boundary ──
        $svg .= '<path id="awmp-boundary" d="' . $boundary_path . '"';
        $svg .= ' fill="url(#awmp-dept-grad)" stroke="#1a5276" stroke-width="2.5" stroke-linejoin="round"/>';

        // ── Layer 3–5: Cities ──
        $svg .= '<g id="awmp-cities">';
        foreach ( $cities as $city ) {
            $svg .= self::render_city( $city );
        }
        $svg .= '</g>';

        $svg .= '</svg>';

        return $svg;
    }

    /* ================================================================== */
    /*  Projection helpers                                                */
    /* ================================================================== */

    /**
     * Convert longitude to SVG x coordinate.
     */
    private static function lng_to_x( float $lng ): float {
        return ( $lng - self::LNG_MIN ) * self::COS_LAT * self::SCALE;
    }

    /**
     * Convert latitude to SVG y coordinate (y-axis inverted).
     */
    private static function lat_to_y( float $lat ): float {
        return ( self::LAT_MAX - $lat ) * self::SCALE;
    }

    /* ================================================================== */
    /*  Boundary path                                                     */
    /* ================================================================== */

    /**
     * Build the SVG path `d` attribute from the GeoJSON boundary.
     *
     * Result is cached as a WordPress transient for one week.
     */
    private static function get_boundary_path(): string {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_string( $cached ) && '' !== $cached ) {
            return $cached;
        }

        $geojson_file = AWMP_PLUGIN_DIR . 'assets/geojson/ardennes.geojson';
        if ( ! file_exists( $geojson_file ) ) {
            return '';
        }

        $raw  = file_get_contents( $geojson_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $data = json_decode( $raw, true );
        if ( ! $data ) {
            return '';
        }

        $coords = $data['features'][0]['geometry']['coordinates'][0] ?? [];
        if ( empty( $coords ) ) {
            return '';
        }

        $parts = [];
        foreach ( $coords as $i => $point ) {
            $x = round( self::lng_to_x( (float) $point[0] ), 1 );
            $y = round( self::lat_to_y( (float) $point[1] ), 1 );
            $parts[] = ( 0 === $i ? 'M' : 'L' ) . $x . ',' . $y;
        }
        $parts[] = 'Z';

        $path = implode( ' ', $parts );
        set_transient( self::CACHE_KEY, $path, WEEK_IN_SECONDS );

        return $path;
    }

    /* ================================================================== */
    /*  Icon symbols                                                      */
    /* ================================================================== */

    /**
     * Read the 8 SVG icon files and return them wrapped as `<symbol>` elements.
     */
    private static function get_icon_symbols( string $icons_dir ): string {
        $symbols = '';

        foreach ( self::ICON_FILES as $condition => $filename ) {
            $filepath = rtrim( $icons_dir, '/' ) . '/' . $filename;
            if ( ! file_exists( $filepath ) ) {
                continue;
            }

            $content = file_get_contents( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if ( ! $content ) {
                continue;
            }

            // Extract viewBox from the root <svg>.
            $viewbox = '0 0 64 64';
            if ( preg_match( '/viewBox=["\']([^"\']+)["\']/', $content, $m ) ) {
                $viewbox = $m[1];
            }

            // Strip outer <svg> tags, keeping only inner content.
            $inner = preg_replace( '/<svg[^>]*>/', '', $content );
            $inner = preg_replace( '/<\/svg>/', '', $inner );
            $inner = trim( $inner );

            $symbols .= '<symbol id="awmp-icon-' . esc_attr( $condition ) . '" viewBox="' . esc_attr( $viewbox ) . '">';
            $symbols .= $inner;
            $symbols .= '</symbol>';
        }

        return $symbols;
    }

    /* ================================================================== */
    /*  Gradients & filters                                               */
    /* ================================================================== */

    /**
     * Render SVG gradient definitions.
     */
    private static function render_gradients(): string {
        $svg  = '';

        // Background gradient.
        $svg .= '<linearGradient id="awmp-bg-grad" x1="0%" y1="0%" x2="0%" y2="100%">';
        $svg .= '<stop offset="0%" stop-color="#e8f4fd"/>';
        $svg .= '<stop offset="100%" stop-color="#c5dff0"/>';
        $svg .= '</linearGradient>';

        // Department fill gradient.
        $svg .= '<linearGradient id="awmp-dept-grad" x1="0%" y1="0%" x2="100%" y2="100%">';
        $svg .= '<stop offset="0%" stop-color="#d4e6f1" stop-opacity="0.7"/>';
        $svg .= '<stop offset="100%" stop-color="#eaf2f8" stop-opacity="0.5"/>';
        $svg .= '</linearGradient>';

        return $svg;
    }

    /**
     * Render SVG filter definitions.
     */
    private static function render_filters(): string {
        $svg  = '';

        // Label text shadow.
        $svg .= '<filter id="awmp-shadow" x="-10%" y="-10%" width="130%" height="130%">';
        $svg .= '<feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-color="#000" flood-opacity="0.12"/>';
        $svg .= '</filter>';

        // Temperature badge shadow.
        $svg .= '<filter id="awmp-badge-shadow" x="-15%" y="-15%" width="140%" height="140%">';
        $svg .= '<feDropShadow dx="0" dy="1" stdDeviation="2" flood-color="#000" flood-opacity="0.18"/>';
        $svg .= '</filter>';

        return $svg;
    }

    /* ================================================================== */
    /*  City rendering                                                    */
    /* ================================================================== */

    /**
     * Render a single city group.
     *
     * @param array $city City data array with name, lat, lng, temps, conditions.
     */
    private static function render_city( array $city ): string {
        $name = $city['name'] ?? '';
        $lat  = (float) ( $city['lat'] ?? 0 );
        $lng  = (float) ( $city['lng'] ?? 0 );
        $slug = self::city_slug( $name );

        $x = round( self::lng_to_x( $lng ), 1 );
        $y = round( self::lat_to_y( $lat ), 1 );

        // Morning data rendered by default (JS switches periods/days).
        $temp      = $city['morning_temp'] ?? '';
        $condition = $city['morning_condition'] ?? '';
        $color     = self::temp_to_color( $temp );

        // Dynamic sizes from settings.
        $s           = self::$settings;
        $name_fs     = $s['city_name_font_size'];
        $temp_fs     = $s['temp_font_size'];
        $icon_sz     = $s['icon_size'];
        $dot_r       = $s['dot_radius'];
        $badge_h     = (int) round( $temp_fs * 1.67 );
        $badge_w     = (int) round( $temp_fs * 3.56 );
        $badge_rx    = (int) round( $badge_h / 2 );
        $weather_dy  = $temp_fs;
        $label_dy    = (int) round( $name_fs * 2.33 );

        // Label offset.
        $default_offset = [ 'middle', 0, -$label_dy ];
        $offset = self::LABEL_OFFSETS[ $slug ] ?? $default_offset;
        // Scale the stored offsets proportionally if using named offsets.
        $anchor = $offset[0];
        $ldx    = $offset[1];
        // For side-anchored labels, recalculate dx based on dot radius.
        if ( 'start' === $anchor && isset( self::LABEL_OFFSETS[ $slug ] ) ) {
            $ldx = $dot_r * 2;
        } elseif ( 'end' === $anchor && isset( self::LABEL_OFFSETS[ $slug ] ) ) {
            $ldx = -( $dot_r * 2 );
        }
        $ldy = isset( self::LABEL_OFFSETS[ $slug ] ) && 'middle' !== $anchor ? $offset[2] : -$label_dy;

        $svg  = '<g id="awmp-city-' . esc_attr( $slug ) . '" class="awmp-city"';
        $svg .= ' transform="translate(' . $x . ',' . $y . ')"';
        $svg .= ' data-lat="' . esc_attr( $lat ) . '" data-lng="' . esc_attr( $lng ) . '">';

        // ── City dot ──
        $svg .= '<circle class="awmp-city-dot" cx="0" cy="0" r="' . $dot_r . '"';
        $svg .= ' fill="#1a5276" stroke="#fff" stroke-width="2.5"/>';

        // ── City name label ──
        $svg .= '<text class="awmp-city-name" x="' . $ldx . '" y="' . $ldy . '"';
        $svg .= ' text-anchor="' . $anchor . '"';
        $svg .= ' font-size="' . $name_fs . '" font-weight="700" fill="#1a252f">';
        $svg .= esc_html( $name );
        $svg .= '</text>';

        // ── Weather data group ──
        $svg .= '<g class="awmp-city-weather" transform="translate(0, ' . $weather_dy . ')">';

        // Temperature badge background.
        $bx = (int) round( -$badge_w / 2 );
        $by = (int) round( -$badge_h / 2 );
        $svg .= '<rect class="awmp-temp-bg" x="' . $bx . '" y="' . $by . '" width="' . $badge_w . '" height="' . $badge_h . '"';
        $svg .= ' rx="' . $badge_rx . '" fill="' . esc_attr( $color ) . '" filter="url(#awmp-badge-shadow)"/>';

        // Temperature text.
        $text_y = (int) round( $temp_fs * 0.33 );
        $svg .= '<text class="awmp-temp-text" x="0" y="' . $text_y . '"';
        $svg .= ' text-anchor="middle" font-size="' . $temp_fs . '" font-weight="700" fill="#fff">';
        $svg .= esc_html( $temp ?: '--' );
        $svg .= '</text>';

        // Weather icon.
        $icon_x = (int) round( $badge_w / 2 - 2 );
        $icon_y = (int) round( -$icon_sz / 2 );
        if ( $condition && isset( self::ICON_FILES[ $condition ] ) ) {
            $svg .= '<use class="awmp-weather-icon"';
            $svg .= ' href="#awmp-icon-' . esc_attr( $condition ) . '"';
            $svg .= ' xlink:href="#awmp-icon-' . esc_attr( $condition ) . '"';
            $svg .= ' x="' . $icon_x . '" y="' . $icon_y . '" width="' . $icon_sz . '" height="' . $icon_sz . '"/>';
        } else {
            $svg .= '<use class="awmp-weather-icon" href="" xlink:href=""';
            $svg .= ' x="' . $icon_x . '" y="' . $icon_y . '" width="' . $icon_sz . '" height="' . $icon_sz . '" style="display:none"/>';
        }

        $svg .= '</g>'; // .awmp-city-weather
        $svg .= '</g>'; // .awmp-city

        return $svg;
    }

    /* ================================================================== */
    /*  Temperature colour                                                */
    /* ================================================================== */

    /**
     * Interpolate a colour from the temperature string.
     *
     * @param string $temp_str e.g. "12°C", "-5°C", "".
     * @return string Hex colour string, e.g. "#4CAF50".
     */
    private static function temp_to_color( string $temp_str ): string {
        if ( '' === $temp_str ) {
            return '#78909c'; // neutral grey for no data
        }

        // Extract numeric value.
        $temp = (int) preg_replace( '/[^-\d]/', '', $temp_str );

        $stops = self::COLOR_STOPS;
        $first = $stops[0];
        $last  = $stops[ count( $stops ) - 1 ];

        // Clamp.
        if ( $temp <= $first[0] ) {
            return self::rgb_hex( $first[1], $first[2], $first[3] );
        }
        if ( $temp >= $last[0] ) {
            return self::rgb_hex( $last[1], $last[2], $last[3] );
        }

        // Find surrounding stops and interpolate.
        for ( $i = 0, $len = count( $stops ) - 1; $i < $len; $i++ ) {
            $lo = $stops[ $i ];
            $hi = $stops[ $i + 1 ];

            if ( $temp >= $lo[0] && $temp <= $hi[0] ) {
                $t = ( $temp - $lo[0] ) / ( $hi[0] - $lo[0] );
                $r = (int) round( $lo[1] + $t * ( $hi[1] - $lo[1] ) );
                $g = (int) round( $lo[2] + $t * ( $hi[2] - $lo[2] ) );
                $b = (int) round( $lo[3] + $t * ( $hi[3] - $lo[3] ) );

                return self::rgb_hex( $r, $g, $b );
            }
        }

        return '#78909c';
    }

    /**
     * Convert RGB values to hex string.
     */
    private static function rgb_hex( int $r, int $g, int $b ): string {
        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }

    /* ================================================================== */
    /*  Slug helper                                                       */
    /* ================================================================== */

    /**
     * Generate a URL-safe slug from a city name.
     *
     * Must produce the same output as the JS `citySlug()` function.
     *
     * @param string $name City name (e.g. "Charleville-Mézières").
     * @return string Slug (e.g. "charleville-mezieres").
     */
    public static function city_slug( string $name ): string {
        $slug = mb_strtolower( $name, 'UTF-8' );

        // Decompose accented characters and strip combining marks.
        $slug = normalizer_normalize( $slug, Normalizer::FORM_D );
        $slug = preg_replace( '/[\x{0300}-\x{036f}]/u', '', $slug );

        // Replace non-alphanumeric runs with a single hyphen.
        $slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );

        // Trim leading/trailing hyphens.
        return trim( $slug, '-' );
    }
}
