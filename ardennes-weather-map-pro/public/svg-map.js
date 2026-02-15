/**
 * Ardennes Weather Map Pro - SVG Map Frontend (V3)
 *
 * Handles day switching (today/tomorrow), period switching (morning/afternoon),
 * dynamic weather data updates, dominant condition detection,
 * and weather background animations.
 *
 * @package Ardennes_Weather_Map_Pro
 */
(function () {
    'use strict';

    /* Current state. */
    var currentDay    = 'today';    // 'today' | 'tomorrow'
    var currentPeriod = 'morning';  // 'morning' | 'afternoon'

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

    function rgbHex(c) {
        return '#' +
            ('0' + c.r.toString(16)).slice(-2) +
            ('0' + c.g.toString(16)).slice(-2) +
            ('0' + c.b.toString(16)).slice(-2);
    }

    /**
     * Produce a URL-safe slug from a city name.
     */
    function citySlug(name) {
        return name
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/(^-|-$)/g, '');
    }

    /* ─── Data key helpers ─── */

    /**
     * Get the data key prefix for the current day+period.
     */
    function dataPrefix() {
        if (currentDay === 'tomorrow') {
            return 'tomorrow_' + currentPeriod;
        }
        return currentPeriod;
    }

    function getTempKey() {
        return dataPrefix() + '_temp';
    }

    function getConditionKey() {
        return dataPrefix() + '_condition';
    }

    /* ─── Core logic ─── */

    function init() {
        var svg = document.querySelector('.awmp-svg-map');
        if (!svg || typeof awmpMapData === 'undefined') {
            return;
        }

        bindDaySwitcher();
        bindPeriodSwitcher();
        updateWeatherData();
        initAnimationParticles();
    }

    /**
     * Update weather data on all city groups for the current day+period.
     */
    function updateWeatherData() {
        var cities   = (awmpMapData && awmpMapData.cities) ? awmpMapData.cities : [];
        var tempKey  = getTempKey();
        var condKey  = getConditionKey();

        // Track conditions for dominant calculation.
        var conditionCounts = {};

        cities.forEach(function (city) {
            var slug  = citySlug(city.name);
            var group = document.getElementById('awmp-city-' + slug);
            if (!group) return;

            var temp      = city[tempKey] || '';
            var condition = city[condKey] || '';

            // Count conditions.
            if (condition) {
                conditionCounts[condition] = (conditionCounts[condition] || 0) + 1;
            }

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

        // Determine dominant condition and update overlay.
        updateDominantCondition(conditionCounts);
    }

    /**
     * Find the most common condition and apply it to the animation overlay.
     */
    function updateDominantCondition(counts) {
        var overlay = document.querySelector('.awmp-weather-overlay');
        if (!overlay) return;

        var dominant = '';
        var maxCount = 0;

        for (var cond in counts) {
            if (counts[cond] > maxCount) {
                maxCount = counts[cond];
                dominant = cond;
            }
        }

        var prev = overlay.getAttribute('data-condition');
        if (prev !== dominant) {
            overlay.setAttribute('data-condition', dominant);
            generateParticles(overlay, dominant);
        }
    }

    /* ─── Animation particles ─── */

    var particlesInitialized = false;

    function initAnimationParticles() {
        var overlay = document.querySelector('.awmp-weather-overlay');
        if (!overlay || particlesInitialized) return;
        particlesInitialized = true;

        var condition = overlay.getAttribute('data-condition');
        if (condition) {
            generateParticles(overlay, condition);
        }
    }

    /**
     * Generate particle elements for weather animations.
     */
    function generateParticles(overlay, condition) {
        // Clear existing particles.
        overlay.innerHTML = '';

        if (!condition) return;

        var count = 0;
        var className = 'awmp-particle';

        // Determine particle count based on condition.
        if (condition === 'pluie' || condition === 'orage') {
            count = 40;
        } else if (condition === 'neige') {
            count = 35;
        } else if (condition === 'pluie-neige') {
            count = 30;
        }
        // ensoleille, eclaircies, couvert, brouillard use CSS only (no particles).

        for (var i = 0; i < count; i++) {
            var span = document.createElement('span');
            span.className = className;
            span.style.left = (Math.random() * 100).toFixed(1) + '%';
            span.style.animationDelay = (Math.random() * 3).toFixed(2) + 's';
            span.style.animationDuration = (1.5 + Math.random() * 2).toFixed(2) + 's';

            // For sleet: alternate rain and snow particles.
            if (condition === 'pluie-neige') {
                span.className = className + (i % 2 === 0 ? ' awmp-particle-rain' : ' awmp-particle-snow');
            }

            overlay.appendChild(span);
        }
    }

    /* ─── Switcher bindings ─── */

    function bindDaySwitcher() {
        var buttons = document.querySelectorAll('.awmp-day-btn');

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var day = this.getAttribute('data-day');
                if (day === currentDay) return;

                currentDay = day;

                buttons.forEach(function (b) { b.classList.remove('active'); });
                this.classList.add('active');

                updateWeatherData();
            });
        });
    }

    function bindPeriodSwitcher() {
        var buttons = document.querySelectorAll('.awmp-period-btn');

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var period = this.getAttribute('data-period');
                if (period === currentPeriod) return;

                currentPeriod = period;

                buttons.forEach(function (b) { b.classList.remove('active'); });
                this.classList.add('active');

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
