/**
 * Ardennes Weather Map Pro - SVG Map Frontend (V2)
 *
 * Handles period switching and dynamic weather data updates
 * on the inline SVG map.  No external dependencies.
 *
 * @package Ardennes_Weather_Map_Pro
 */
(function () {
    'use strict';

    /* Current display period: 'morning' or 'afternoon'. */
    var currentPeriod = 'morning';

    /* ─── Temperature colour scale (must match PHP COLOR_STOPS) ─── */
    var COLOR_STOPS = [
        { t: -10, r: 33,  g: 150, b: 243 },
        { t:   0, r: 0,   g: 188, b: 212 },
        { t:  10, r: 76,  g: 175, b: 80  },
        { t:  20, r: 255, g: 193, b: 7   },
        { t:  30, r: 255, g: 87,  b: 34  },
        { t:  40, r: 244, g: 67,  b: 54  }
    ];

    /**
     * Interpolate a hex colour from a temperature string.
     *
     * @param {string} tempStr e.g. "12°C", "-5°C", "".
     * @returns {string} Hex colour like "#4caf50".
     */
    function tempToColor(tempStr) {
        if (!tempStr) return '#78909c';

        var temp = parseInt(tempStr, 10);
        if (isNaN(temp)) return '#78909c';

        var stops = COLOR_STOPS;
        if (temp <= stops[0].t) return rgbHex(stops[0]);
        if (temp >= stops[stops.length - 1].t) return rgbHex(stops[stops.length - 1]);

        for (var i = 0; i < stops.length - 1; i++) {
            var lo = stops[i];
            var hi = stops[i + 1];
            if (temp >= lo.t && temp <= hi.t) {
                var f = (temp - lo.t) / (hi.t - lo.t);
                return rgbHex({
                    r: Math.round(lo.r + f * (hi.r - lo.r)),
                    g: Math.round(lo.g + f * (hi.g - lo.g)),
                    b: Math.round(lo.b + f * (hi.b - lo.b))
                });
            }
        }
        return '#78909c';
    }

    /**
     * Convert an {r,g,b} object to a hex string.
     */
    function rgbHex(c) {
        return '#' +
            ('0' + c.r.toString(16)).slice(-2) +
            ('0' + c.g.toString(16)).slice(-2) +
            ('0' + c.b.toString(16)).slice(-2);
    }

    /**
     * Produce a URL-safe slug from a city name.
     * Must match the PHP AWMP_SVG_Map::city_slug() output.
     *
     * @param {string} name e.g. "Charleville-Mézières".
     * @returns {string} Slug e.g. "charleville-mezieres".
     */
    function citySlug(name) {
        return name
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/(^-|-$)/g, '');
    }

    /* ─── Core logic ─── */

    /**
     * Initialize the SVG map interactions.
     */
    function init() {
        var svg = document.querySelector('.awmp-svg-map');
        if (!svg || typeof awmpMapData === 'undefined') {
            return;
        }

        bindPeriodSwitcher();
    }

    /**
     * Update weather data on all city groups for the current period.
     * Performs in-place DOM updates — no SVG rebuild needed.
     */
    function updateWeatherData() {
        var cities = (awmpMapData && awmpMapData.cities) ? awmpMapData.cities : [];

        cities.forEach(function (city) {
            var slug  = citySlug(city.name);
            var group = document.getElementById('awmp-city-' + slug);
            if (!group) return;

            var temp      = currentPeriod === 'morning' ? city.morning_temp      : city.afternoon_temp;
            var condition = currentPeriod === 'morning' ? city.morning_condition  : city.afternoon_condition;

            // Update temperature text.
            var tempText = group.querySelector('.awmp-temp-text');
            if (tempText) {
                tempText.textContent = temp || '--';
            }

            // Update badge colour.
            var tempBg = group.querySelector('.awmp-temp-bg');
            if (tempBg) {
                tempBg.setAttribute('fill', tempToColor(temp));
            }

            // Update weather icon.
            var iconUse = group.querySelector('.awmp-weather-icon');
            if (iconUse) {
                if (condition) {
                    var ref = '#awmp-icon-' + condition;
                    iconUse.setAttribute('href', ref);
                    iconUse.setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', ref);
                    iconUse.style.display = '';
                } else {
                    iconUse.style.display = 'none';
                }
            }
        });
    }

    /**
     * Bind click handlers on the period switcher buttons.
     */
    function bindPeriodSwitcher() {
        var buttons = document.querySelectorAll('.awmp-period-btn');

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var period = this.getAttribute('data-period');
                if (period === currentPeriod) return;

                currentPeriod = period;

                // Toggle active state.
                buttons.forEach(function (b) { b.classList.remove('active'); });
                this.classList.add('active');

                // Update the SVG in place.
                updateWeatherData();
            });
        });
    }

    /* ─── Boot ─── */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
