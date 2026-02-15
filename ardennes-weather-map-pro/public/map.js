/**
 * Ardennes Weather Map Pro - Frontend Map
 *
 * @package Ardennes_Weather_Map_Pro
 */
(function () {
    'use strict';

    /* Current period: 'morning' or 'afternoon' */
    let currentPeriod = 'morning';

    /* Leaflet map instance */
    let map = null;

    /* Layer group holding city markers */
    let markersLayer = null;

    /* GeoJSON boundary layer */
    let boundaryLayer = null;

    /**
     * Initialize the map.
     */
    function init() {
        const container = document.getElementById('awmp-map');
        if (!container || typeof L === 'undefined' || typeof awmpMap === 'undefined') {
            return;
        }

        /* Centre approximate des Ardennes */
        map = L.map('awmp-map', {
            center: [49.75, 4.65],
            zoom: 9,
            scrollWheelZoom: true,
            zoomControl: true,
            attributionControl: true
        });

        /* Fond de carte OpenStreetMap */
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 13,
            minZoom: 8
        }).addTo(map);

        /* Charger le GeoJSON des frontières */
        loadBoundary();

        /* Créer le layer de marqueurs */
        markersLayer = L.layerGroup().addTo(map);

        /* Afficher les villes */
        renderMarkers();

        /* Gestion des boutons matin / après-midi */
        bindPeriodSwitcher();

        /* Ajuster la taille après rendu */
        setTimeout(function () {
            map.invalidateSize();
        }, 200);
    }

    /**
     * Load GeoJSON boundary and fit map.
     */
    function loadBoundary() {
        fetch(awmpMap.geojsonUrl)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                /* Wrap single Feature into FeatureCollection if needed */
                const geojson = data.type === 'FeatureCollection' ? data : {
                    type: 'FeatureCollection',
                    features: [data]
                };

                boundaryLayer = L.geoJSON(geojson, {
                    style: {
                        color: '#1a5276',
                        weight: 2.5,
                        fillColor: '#d4e6f1',
                        fillOpacity: 0.15,
                        dashArray: ''
                    }
                }).addTo(map);

                /* Fit map to Ardennes boundaries */
                map.fitBounds(boundaryLayer.getBounds(), { padding: [20, 20] });
            })
            .catch(function (err) {
                console.warn('AWMP: Impossible de charger le GeoJSON', err);
            });
    }

    /**
     * Render city markers for the current period.
     */
    function renderMarkers() {
        if (!markersLayer) return;
        markersLayer.clearLayers();

        const cities = awmpMap.cities || [];

        cities.forEach(function (city) {
            const temp      = currentPeriod === 'morning' ? city.morning_temp : city.afternoon_temp;
            const condition = currentPeriod === 'morning' ? city.morning_condition : city.afternoon_condition;
            const label     = currentPeriod === 'morning' ? city.morning_label : city.afternoon_label;
            const iconUrl   = currentPeriod === 'morning' ? city.morning_icon : city.afternoon_icon;

            /* Build marker HTML */
            let iconHtml = '<div class="awmp-marker">';
            iconHtml += '<span class="awmp-marker-name">' + escapeHtml(city.name) + '</span>';

            if (temp || condition) {
                iconHtml += '<span class="awmp-marker-info">';
                if (iconUrl) {
                    iconHtml += '<img src="' + iconUrl + '" class="awmp-marker-icon" alt="' + escapeHtml(label) + '" width="22" height="22">';
                }
                if (temp) {
                    iconHtml += '<span class="awmp-marker-temp">' + escapeHtml(temp) + '</span>';
                }
                iconHtml += '</span>';
            }

            iconHtml += '</div>';

            const divIcon = L.divIcon({
                html: iconHtml,
                className: 'awmp-div-icon',
                iconSize: [120, 50],
                iconAnchor: [60, 50]
            });

            const marker = L.marker([city.lat, city.lng], { icon: divIcon });

            /* Popup */
            let popupContent = '<div class="awmp-popup">';
            popupContent += '<strong>' + escapeHtml(city.name) + '</strong>';
            if (temp) {
                popupContent += '<br>Température : ' + escapeHtml(temp);
            }
            if (label) {
                popupContent += '<br>Condition : ' + escapeHtml(label);
            }
            popupContent += '</div>';

            marker.bindPopup(popupContent);
            markersLayer.addLayer(marker);
        });
    }

    /**
     * Bind period switcher buttons.
     */
    function bindPeriodSwitcher() {
        const buttons = document.querySelectorAll('.awmp-period-btn');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const period = this.getAttribute('data-period');
                if (period === currentPeriod) return;

                currentPeriod = period;

                /* Update active state */
                buttons.forEach(function (b) { b.classList.remove('active'); });
                this.classList.add('active');

                /* Re-render markers */
                renderMarkers();
            });
        });
    }

    /**
     * Escape HTML entities.
     */
    function escapeHtml(text) {
        if (!text) return '';
        const el = document.createElement('span');
        el.textContent = text;
        return el.innerHTML;
    }

    /* Boot */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
