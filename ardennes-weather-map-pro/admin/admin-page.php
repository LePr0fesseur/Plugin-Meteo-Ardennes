<?php
/**
 * Admin page for Ardennes Weather Map Pro.
 *
 * @package Ardennes_Weather_Map_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the WordPress admin interface.
 */
class AWMP_Admin_Page {

    /**
     * Weather conditions available.
     */
    private const CONDITIONS = [
        ''            => '-- Aucune --',
        'ensoleille'  => 'Ensoleillé',
        'eclaircies'  => 'Éclaircies',
        'couvert'     => 'Couvert',
        'brouillard'  => 'Brouillard',
        'pluie'       => 'Pluie',
        'pluie-neige' => 'Pluie-Neige',
        'neige'       => 'Neige',
        'orage'       => 'Orage',
    ];

    /**
     * Register the admin menu page.
     */
    public function add_menu_page(): void {
        add_menu_page(
            'Ardennes Weather Map',
            'Météo Ardennes',
            'manage_options',
            'ardennes-weather-map',
            [ $this, 'render_page' ],
            'dashicons-cloud',
            30
        );
    }

    /**
     * Enqueue admin assets.
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'toplevel_page_ardennes-weather-map' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'awmp-admin-style',
            AWMP_PLUGIN_URL . 'admin/admin-style.css',
            [],
            AWMP_VERSION
        );

        wp_enqueue_script(
            'awmp-admin-script',
            AWMP_PLUGIN_URL . 'admin/admin-script.js',
            [ 'jquery' ],
            AWMP_VERSION,
            true
        );

        wp_localize_script( 'awmp-admin-script', 'awmpAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'awmp_admin_nonce' ),
        ] );
    }

    /**
     * Render the admin page.
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $cities            = Ardennes_Weather_Map_Pro::get_cities();
        $conditions        = self::CONDITIONS;
        $last_update       = get_option( 'awmp_last_weather_update', '' );
        $next_cron         = wp_next_scheduled( Ardennes_Weather_Map_Pro::CRON_HOOK );
        $next_cron_display = $next_cron
            ? wp_date( 'd/m/Y H:i', $next_cron, new DateTimeZone( 'Europe/Paris' ) )
            : 'Non programmé';
        ?>
        <div class="wrap awmp-admin-wrap">
            <h1>
                <span class="dashicons dashicons-cloud"></span>
                Ardennes Weather Map Pro
            </h1>

            <div class="awmp-admin-container">
                <!-- Weather Update Section -->
                <div class="awmp-update-section">
                    <div class="awmp-update-header">
                        <div class="awmp-update-info">
                            <h2><span class="dashicons dashicons-update"></span> Mise à jour météo</h2>
                            <div class="awmp-update-details">
                                <span class="awmp-update-detail">
                                    <strong>Dernière MAJ :</strong>
                                    <?php echo esc_html( $last_update ? wp_date( 'd/m/Y à H:i', strtotime( $last_update ), new DateTimeZone( 'Europe/Paris' ) ) : 'Jamais' ); ?>
                                </span>
                                <span class="awmp-update-detail">
                                    <strong>Prochaine MAJ :</strong>
                                    <?php echo esc_html( $next_cron_display ); ?>
                                </span>
                            </div>
                        </div>
                        <div class="awmp-update-actions">
                            <button type="button" class="button button-primary" id="awmp-manual-update-btn">
                                <span class="dashicons dashicons-download"></span>
                                Mettre à jour maintenant
                            </button>
                        </div>
                    </div>
                    <div id="awmp-update-message"></div>
                    <div id="awmp-update-progress" style="display:none;">
                        <div class="awmp-progress-bar">
                            <div class="awmp-progress-fill"></div>
                        </div>
                        <span class="awmp-progress-text">Récupération des données en cours...</span>
                    </div>
                </div>

                <!-- Sources Info -->
                <div class="awmp-sources-info">
                    <h3>Sources de données</h3>
                    <div class="awmp-sources-grid">
                        <div class="awmp-source">
                            <strong>Météo France</strong>
                            <span class="awmp-source-desc">Modèles ARPEGE/AROME — Températures + conditions</span>
                        </div>
                        <div class="awmp-source">
                            <strong>Meteo &amp; Radar</strong>
                            <span class="awmp-source-desc">Modèle multi-sources — Températures uniquement</span>
                        </div>
                    </div>
                    <p class="awmp-source-note">Les températures affichées sont la <strong>moyenne</strong> des deux sources. Les conditions météo proviennent de Météo France.</p>
                </div>

                <!-- City Form -->
                <div class="awmp-form-section">
                    <h2 id="awmp-form-title">Ajouter une ville</h2>
                    <form id="awmp-city-form" class="awmp-form">
                        <input type="hidden" id="awmp-city-id" name="city_id" value="">

                        <div class="awmp-form-row">
                            <div class="awmp-form-group" style="flex:2;">
                                <label for="awmp-city-name">Nom de la ville</label>
                                <input type="text" id="awmp-city-name" name="city_name" required placeholder="Ex: Charleville-Mézières">
                            </div>
                            <div class="awmp-form-group" style="flex:1;">
                                <label for="awmp-postal-code">Code postal</label>
                                <input type="text" id="awmp-postal-code" name="postal_code" placeholder="Ex: 08000" maxlength="10" pattern="[0-9]{5}">
                            </div>
                        </div>

                        <div class="awmp-form-row">
                            <div class="awmp-form-group">
                                <label for="awmp-latitude">Latitude</label>
                                <input type="number" id="awmp-latitude" name="latitude" step="0.0000001" required placeholder="Ex: 49.7719">
                            </div>
                            <div class="awmp-form-group">
                                <label for="awmp-longitude">Longitude</label>
                                <input type="number" id="awmp-longitude" name="longitude" step="0.0000001" required placeholder="Ex: 4.7161">
                            </div>
                        </div>

                        <fieldset class="awmp-fieldset">
                            <legend>Matin</legend>
                            <div class="awmp-form-row">
                                <div class="awmp-form-group">
                                    <label for="awmp-morning-temp">Température</label>
                                    <input type="text" id="awmp-morning-temp" name="morning_temp" placeholder="Ex: 5°C">
                                </div>
                                <div class="awmp-form-group">
                                    <label for="awmp-morning-condition">Condition</label>
                                    <select id="awmp-morning-condition" name="morning_condition">
                                        <?php foreach ( $conditions as $value => $label ) : ?>
                                            <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset class="awmp-fieldset">
                            <legend>Après-midi</legend>
                            <div class="awmp-form-row">
                                <div class="awmp-form-group">
                                    <label for="awmp-afternoon-temp">Température</label>
                                    <input type="text" id="awmp-afternoon-temp" name="afternoon_temp" placeholder="Ex: 12°C">
                                </div>
                                <div class="awmp-form-group">
                                    <label for="awmp-afternoon-condition">Condition</label>
                                    <select id="awmp-afternoon-condition" name="afternoon_condition">
                                        <?php foreach ( $conditions as $value => $label ) : ?>
                                            <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </fieldset>

                        <div class="awmp-form-actions">
                            <button type="submit" class="button button-primary" id="awmp-submit-btn">
                                Enregistrer la ville
                            </button>
                            <button type="button" class="button" id="awmp-cancel-btn" style="display:none;">
                                Annuler
                            </button>
                        </div>
                        <div id="awmp-form-message"></div>
                    </form>
                </div>

                <!-- Display Settings -->
                <?php
                $display_settings = Ardennes_Weather_Map_Pro::get_display_settings();
                $defaults         = Ardennes_Weather_Map_Pro::get_default_display_settings();
                ?>
                <div class="awmp-form-section">
                    <h2><span class="dashicons dashicons-admin-appearance"></span> Paramètres d'affichage</h2>
                    <p class="awmp-settings-desc">Ajustez la taille des éléments affichés sur la carte SVG.</p>
                    <form id="awmp-display-settings-form" class="awmp-form">
                        <div class="awmp-form-row">
                            <div class="awmp-form-group">
                                <label for="awmp-setting-city-font">Taille nom de ville (px)</label>
                                <input type="range" id="awmp-setting-city-font" name="city_name_font_size"
                                    min="10" max="30" step="1"
                                    value="<?php echo esc_attr( $display_settings['city_name_font_size'] ); ?>">
                                <span class="awmp-range-value" data-default="<?php echo esc_attr( $defaults['city_name_font_size'] ); ?>"><?php echo esc_html( $display_settings['city_name_font_size'] ); ?>px</span>
                            </div>
                            <div class="awmp-form-group">
                                <label for="awmp-setting-temp-font">Taille température (px)</label>
                                <input type="range" id="awmp-setting-temp-font" name="temp_font_size"
                                    min="10" max="30" step="1"
                                    value="<?php echo esc_attr( $display_settings['temp_font_size'] ); ?>">
                                <span class="awmp-range-value" data-default="<?php echo esc_attr( $defaults['temp_font_size'] ); ?>"><?php echo esc_html( $display_settings['temp_font_size'] ); ?>px</span>
                            </div>
                        </div>
                        <div class="awmp-form-row">
                            <div class="awmp-form-group">
                                <label for="awmp-setting-icon-size">Taille icônes météo (px)</label>
                                <input type="range" id="awmp-setting-icon-size" name="icon_size"
                                    min="16" max="60" step="2"
                                    value="<?php echo esc_attr( $display_settings['icon_size'] ); ?>">
                                <span class="awmp-range-value" data-default="<?php echo esc_attr( $defaults['icon_size'] ); ?>"><?php echo esc_html( $display_settings['icon_size'] ); ?>px</span>
                            </div>
                            <div class="awmp-form-group">
                                <label for="awmp-setting-dot-radius">Taille marqueur ville (px)</label>
                                <input type="range" id="awmp-setting-dot-radius" name="dot_radius"
                                    min="3" max="15" step="1"
                                    value="<?php echo esc_attr( $display_settings['dot_radius'] ); ?>">
                                <span class="awmp-range-value" data-default="<?php echo esc_attr( $defaults['dot_radius'] ); ?>"><?php echo esc_html( $display_settings['dot_radius'] ); ?>px</span>
                            </div>
                        </div>
                        <div class="awmp-form-actions">
                            <button type="submit" class="button button-primary" id="awmp-save-settings-btn">
                                Enregistrer les paramètres
                            </button>
                            <button type="button" class="button" id="awmp-reset-settings-btn">
                                Réinitialiser
                            </button>
                        </div>
                        <div id="awmp-settings-message"></div>
                    </form>
                </div>

                <!-- Shortcode Info -->
                <div class="awmp-shortcode-info">
                    <h3>Shortcode</h3>
                    <p>Utilisez ce shortcode pour afficher la carte météo :</p>
                    <code>[ardennes_weather_map]</code>
                </div>

                <!-- Cities Table -->
                <div class="awmp-table-section">
                    <h2>Villes enregistrées (<?php echo count( $cities ); ?>)</h2>
                    <table class="wp-list-table widefat fixed striped" id="awmp-cities-table">
                        <thead>
                            <tr>
                                <th class="column-name">Ville</th>
                                <th class="column-postal">CP</th>
                                <th class="column-morning">Matin</th>
                                <th class="column-afternoon">Après-midi</th>
                                <th class="column-tomorrow-m">Demain M</th>
                                <th class="column-tomorrow-a">Demain AM</th>
                                <th class="column-sources">Sources (MF / MR)</th>
                                <th class="column-update">MAJ</th>
                                <th class="column-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $cities ) ) : ?>
                                <tr class="awmp-no-cities">
                                    <td colspan="9">Aucune ville enregistrée.</td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ( $cities as $city ) : ?>
                                    <tr data-id="<?php echo esc_attr( $city->id ); ?>">
                                        <td class="column-name">
                                            <strong><?php echo esc_html( $city->city_name ); ?></strong>
                                            <div class="awmp-city-links">
                                                <?php if ( ! empty( $city->postal_code ) ) : ?>
                                                    <a href="<?php echo esc_url( AWMP_Weather_Fetcher::get_meteo_france_url( $city->city_name, $city->postal_code ) ); ?>" target="_blank" rel="noopener" title="Météo France">MF</a>
                                                <?php endif; ?>
                                                <a href="<?php echo esc_url( AWMP_Weather_Fetcher::get_meteo_radar_url( $city->city_name ) ); ?>" target="_blank" rel="noopener" title="Meteo &amp; Radar">MR</a>
                                            </div>
                                        </td>
                                        <td class="column-postal">
                                            <?php echo esc_html( $city->postal_code ?: '--' ); ?>
                                        </td>
                                        <td class="column-morning">
                                            <?php if ( $city->morning_temp || $city->morning_condition ) : ?>
                                                <span class="awmp-temp"><?php echo esc_html( $city->morning_temp ); ?></span>
                                                <?php if ( $city->morning_condition ) : ?>
                                                    <span class="awmp-condition"><?php echo esc_html( $conditions[ $city->morning_condition ] ?? $city->morning_condition ); ?></span>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <span class="awmp-empty">--</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="column-afternoon">
                                            <?php if ( $city->afternoon_temp || $city->afternoon_condition ) : ?>
                                                <span class="awmp-temp"><?php echo esc_html( $city->afternoon_temp ); ?></span>
                                                <?php if ( $city->afternoon_condition ) : ?>
                                                    <span class="awmp-condition"><?php echo esc_html( $conditions[ $city->afternoon_condition ] ?? $city->afternoon_condition ); ?></span>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <span class="awmp-empty">--</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="column-tomorrow-m">
                                            <?php
                                            $tmr_m_temp = $city->tomorrow_morning_temp ?? '';
                                            $tmr_m_cond = $city->tomorrow_morning_condition ?? '';
                                            if ( $tmr_m_temp || $tmr_m_cond ) : ?>
                                                <span class="awmp-temp"><?php echo esc_html( $tmr_m_temp ); ?></span>
                                                <?php if ( $tmr_m_cond ) : ?>
                                                    <span class="awmp-condition"><?php echo esc_html( $conditions[ $tmr_m_cond ] ?? $tmr_m_cond ); ?></span>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <span class="awmp-empty">--</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="column-tomorrow-a">
                                            <?php
                                            $tmr_a_temp = $city->tomorrow_afternoon_temp ?? '';
                                            $tmr_a_cond = $city->tomorrow_afternoon_condition ?? '';
                                            if ( $tmr_a_temp || $tmr_a_cond ) : ?>
                                                <span class="awmp-temp"><?php echo esc_html( $tmr_a_temp ); ?></span>
                                                <?php if ( $tmr_a_cond ) : ?>
                                                    <span class="awmp-condition"><?php echo esc_html( $conditions[ $tmr_a_cond ] ?? $tmr_a_cond ); ?></span>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <span class="awmp-empty">--</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="column-sources">
                                            <?php
                                            $has_sources = ! is_null( $city->temp_mf_morning ?? null );
                                            if ( $has_sources ) :
                                                $mf_m = isset( $city->temp_mf_morning ) ? round( $city->temp_mf_morning, 1 ) . '°' : '--';
                                                $mr_m = isset( $city->temp_mr_morning ) ? round( $city->temp_mr_morning, 1 ) . '°' : '--';
                                                $mf_a = isset( $city->temp_mf_afternoon ) ? round( $city->temp_mf_afternoon, 1 ) . '°' : '--';
                                                $mr_a = isset( $city->temp_mr_afternoon ) ? round( $city->temp_mr_afternoon, 1 ) . '°' : '--';
                                            ?>
                                                <span class="awmp-source-temps">
                                                    <span title="Matin: MF / MR">M: <?php echo esc_html( $mf_m . ' / ' . $mr_m ); ?></span>
                                                    <span title="AM: MF / MR">AM: <?php echo esc_html( $mf_a . ' / ' . $mr_a ); ?></span>
                                                </span>
                                            <?php else : ?>
                                                <span class="awmp-empty">--</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="column-update">
                                            <?php
                                            $update_time = $city->last_weather_update ?? null;
                                            if ( $update_time ) :
                                                echo esc_html( wp_date( 'd/m H:i', strtotime( $update_time ), new DateTimeZone( 'Europe/Paris' ) ) );
                                            else :
                                                echo '<span class="awmp-empty">--</span>';
                                            endif;
                                            ?>
                                        </td>
                                        <td class="column-actions">
                                            <button type="button" class="button button-small awmp-edit-btn"
                                                data-city='<?php echo esc_attr( wp_json_encode( $city ) ); ?>'>
                                                Modifier
                                            </button>
                                            <button type="button" class="button button-small button-link-delete awmp-delete-btn"
                                                data-id="<?php echo esc_attr( $city->id ); ?>"
                                                data-name="<?php echo esc_attr( $city->city_name ); ?>">
                                                Supprimer
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Save (create or update) a city.
     */
    public function ajax_save_city(): void {
        check_ajax_referer( 'awmp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permissions insuffisantes.' ] );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . Ardennes_Weather_Map_Pro::TABLE_NAME;

        $city_id             = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;
        $city_name           = isset( $_POST['city_name'] ) ? sanitize_text_field( wp_unslash( $_POST['city_name'] ) ) : '';
        $postal_code         = isset( $_POST['postal_code'] ) ? sanitize_text_field( wp_unslash( $_POST['postal_code'] ) ) : '';
        $latitude            = isset( $_POST['latitude'] ) ? floatval( $_POST['latitude'] ) : 0;
        $longitude           = isset( $_POST['longitude'] ) ? floatval( $_POST['longitude'] ) : 0;
        $morning_temp        = isset( $_POST['morning_temp'] ) ? sanitize_text_field( wp_unslash( $_POST['morning_temp'] ) ) : '';
        $morning_condition   = isset( $_POST['morning_condition'] ) ? sanitize_text_field( wp_unslash( $_POST['morning_condition'] ) ) : '';
        $afternoon_temp      = isset( $_POST['afternoon_temp'] ) ? sanitize_text_field( wp_unslash( $_POST['afternoon_temp'] ) ) : '';
        $afternoon_condition = isset( $_POST['afternoon_condition'] ) ? sanitize_text_field( wp_unslash( $_POST['afternoon_condition'] ) ) : '';

        if ( empty( $city_name ) ) {
            wp_send_json_error( [ 'message' => 'Le nom de la ville est requis.' ] );
        }

        if ( $latitude < 49.0 || $latitude > 50.5 || $longitude < 3.5 || $longitude > 5.5 ) {
            wp_send_json_error( [ 'message' => 'Les coordonnées ne semblent pas être dans les Ardennes.' ] );
        }

        $allowed_conditions = array_keys( self::CONDITIONS );
        if ( ! in_array( $morning_condition, $allowed_conditions, true ) ) {
            $morning_condition = '';
        }
        if ( ! in_array( $afternoon_condition, $allowed_conditions, true ) ) {
            $afternoon_condition = '';
        }

        $data   = [
            'city_name'           => $city_name,
            'postal_code'         => $postal_code,
            'latitude'            => $latitude,
            'longitude'           => $longitude,
            'morning_temp'        => $morning_temp,
            'morning_condition'   => $morning_condition,
            'afternoon_temp'      => $afternoon_temp,
            'afternoon_condition' => $afternoon_condition,
        ];
        $format = [ '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s' ];

        if ( $city_id > 0 ) {
            $result = $wpdb->update( $table_name, $data, [ 'id' => $city_id ], $format, [ '%d' ] );
            if ( false === $result ) {
                wp_send_json_error( [ 'message' => 'Erreur lors de la mise à jour.' ] );
            }
            wp_send_json_success( [ 'message' => 'Ville mise à jour avec succès.' ] );
        } else {
            $result = $wpdb->insert( $table_name, $data, $format );
            if ( false === $result ) {
                wp_send_json_error( [ 'message' => 'Erreur lors de l\'ajout.' ] );
            }
            wp_send_json_success( [ 'message' => 'Ville ajoutée avec succès.', 'id' => $wpdb->insert_id ] );
        }
    }

    /**
     * AJAX: Delete a city.
     */
    public function ajax_delete_city(): void {
        check_ajax_referer( 'awmp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permissions insuffisantes.' ] );
        }

        $city_id = isset( $_POST['city_id'] ) ? absint( $_POST['city_id'] ) : 0;

        if ( $city_id <= 0 ) {
            wp_send_json_error( [ 'message' => 'ID de ville invalide.' ] );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . Ardennes_Weather_Map_Pro::TABLE_NAME;
        $result     = $wpdb->delete( $table_name, [ 'id' => $city_id ], [ '%d' ] );

        if ( false === $result ) {
            wp_send_json_error( [ 'message' => 'Erreur lors de la suppression.' ] );
        }

        wp_send_json_success( [ 'message' => 'Ville supprimée avec succès.' ] );
    }

    /**
     * AJAX: Get all cities as JSON.
     */
    public function ajax_get_cities(): void {
        check_ajax_referer( 'awmp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permissions insuffisantes.' ] );
        }

        $cities = Ardennes_Weather_Map_Pro::get_cities();
        wp_send_json_success( [ 'cities' => $cities ] );
    }

    /**
     * AJAX: Save display settings.
     */
    public function ajax_save_display_settings(): void {
        check_ajax_referer( 'awmp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permissions insuffisantes.' ] );
        }

        $defaults = Ardennes_Weather_Map_Pro::get_default_display_settings();
        $settings = [];

        foreach ( $defaults as $key => $default_value ) {
            $settings[ $key ] = isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : $default_value;
        }

        // Clamp values to reasonable ranges.
        $settings['city_name_font_size'] = max( 10, min( 30, $settings['city_name_font_size'] ) );
        $settings['temp_font_size']      = max( 10, min( 30, $settings['temp_font_size'] ) );
        $settings['icon_size']           = max( 16, min( 60, $settings['icon_size'] ) );
        $settings['dot_radius']          = max( 3, min( 15, $settings['dot_radius'] ) );

        update_option( 'awmp_display_settings', $settings );

        // Clear SVG boundary cache so settings take effect.
        delete_transient( 'awmp_svg_boundary_path' );

        wp_send_json_success( [ 'message' => 'Paramètres enregistrés avec succès.' ] );
    }

    /**
     * AJAX: Trigger a manual weather data update.
     */
    public function ajax_manual_weather_update(): void {
        check_ajax_referer( 'awmp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permissions insuffisantes.' ] );
        }

        $result = AWMP_Weather_Fetcher::update_all_cities();

        $message = sprintf( '%d ville(s) mise(s) à jour.', $result['updated'] );
        if ( ! empty( $result['errors'] ) ) {
            $message .= ' Erreurs : ' . implode( ' | ', $result['errors'] );
        }

        wp_send_json_success( [
            'message' => $message,
            'updated' => $result['updated'],
            'errors'  => $result['errors'],
        ] );
    }
}
