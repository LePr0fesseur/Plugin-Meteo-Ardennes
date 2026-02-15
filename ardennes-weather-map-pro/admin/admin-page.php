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
        'couvert'     => 'Couvert',
        'neige'       => 'Neige',
        'pluie'       => 'Pluie',
        'pluie-neige' => 'Pluie-Neige',
    ];

    /**
     * Register the admin menu page.
     */
    public function add_menu_page(): void {
        add_menu_page(
            'Ardennes Weather Map',
            'Ardennes Weather Map',
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

        $cities     = Ardennes_Weather_Map_Pro::get_cities();
        $conditions = self::CONDITIONS;
        ?>
        <div class="wrap awmp-admin-wrap">
            <h1>
                <span class="dashicons dashicons-cloud"></span>
                Ardennes Weather Map Pro
            </h1>

            <div class="awmp-admin-container">
                <!-- City Form -->
                <div class="awmp-form-section">
                    <h2 id="awmp-form-title">Ajouter une ville</h2>
                    <form id="awmp-city-form" class="awmp-form">
                        <input type="hidden" id="awmp-city-id" name="city_id" value="">

                        <div class="awmp-form-row">
                            <div class="awmp-form-group">
                                <label for="awmp-city-name">Nom de la ville</label>
                                <input type="text" id="awmp-city-name" name="city_name" required placeholder="Ex: Charleville-Mézières">
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

                <!-- Shortcode Info -->
                <div class="awmp-shortcode-info">
                    <h3>Shortcode</h3>
                    <p>Utilisez ce shortcode pour afficher la carte météo :</p>
                    <code>[ardennes_weather_map]</code>
                </div>

                <!-- Cities Table -->
                <div class="awmp-table-section">
                    <h2>Villes enregistrées</h2>
                    <table class="wp-list-table widefat fixed striped" id="awmp-cities-table">
                        <thead>
                            <tr>
                                <th class="column-name">Ville</th>
                                <th class="column-coords">Coordonnées</th>
                                <th class="column-morning">Matin</th>
                                <th class="column-afternoon">Après-midi</th>
                                <th class="column-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $cities ) ) : ?>
                                <tr class="awmp-no-cities">
                                    <td colspan="5">Aucune ville enregistrée.</td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ( $cities as $city ) : ?>
                                    <tr data-id="<?php echo esc_attr( $city->id ); ?>">
                                        <td class="column-name">
                                            <strong><?php echo esc_html( $city->city_name ); ?></strong>
                                        </td>
                                        <td class="column-coords">
                                            <?php echo esc_html( $city->latitude ); ?>, <?php echo esc_html( $city->longitude ); ?>
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

        $allowed_conditions = [ '', 'couvert', 'neige', 'pluie', 'pluie-neige' ];
        if ( ! in_array( $morning_condition, $allowed_conditions, true ) ) {
            $morning_condition = '';
        }
        if ( ! in_array( $afternoon_condition, $allowed_conditions, true ) ) {
            $afternoon_condition = '';
        }

        $data   = [
            'city_name'           => $city_name,
            'latitude'            => $latitude,
            'longitude'           => $longitude,
            'morning_temp'        => $morning_temp,
            'morning_condition'   => $morning_condition,
            'afternoon_temp'      => $afternoon_temp,
            'afternoon_condition' => $afternoon_condition,
        ];
        $format = [ '%s', '%f', '%f', '%s', '%s', '%s', '%s' ];

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
}
