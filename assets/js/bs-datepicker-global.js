/**
 * bs-datepicker-global.js
 * Global Nepali (BS) Date Picker initializer for JEMC Production System
 *
 * Uses: Sajanmaharjan Nepali Date Picker v5 + NepaliFunctions
 *       Our own NepalDate.bsToAd() from nepal-date.js as fallback
 *
 * ── HOW TO USE ────────────────────────────────────────────────────────────
 *
 * OPTION 1: Simple pair (BS input + AD hidden/text)
 *   <input class="bs-date"  name="date_nep" data-ad-pair="date_eng">
 *   <input class="ad-date"  name="date_eng" id="date_eng" type="date">
 *
 * OPTION 2: Inside .dual-date wrapper
 *   <div class="dual-date">
 *     <input class="bs-date"  name="date_nep">
 *     <input class="ad-date"  name="date_eng" type="date">
 *   </div>
 *
 * OPTION 3: With display div (read-only AD display)
 *   <input class="bs-date" name="date_nep" data-ad-display="eng_display_id">
 *   <input type="hidden"   name="date_eng" id="eng_display_id">
 *   <div   class="ad-display" id="eng_display_id_label"></div>
 *
 * OPTION 4: Month-only BS (YYYY.MM) for month selectors
 *   <input class="bs-month" name="bs_month" data-ad-month="ad_month_input_id">
 *   <input class="ad-month" type="month"    id="ad_month_input_id">
 *
 * FORMATS:
 *   BS input stores:  YYYY.MM.DD  (e.g. 2082.03.15)  — used by DB
 *   AD input stores:  YYYY-MM-DD  (standard HTML date)
 *
 * ─────────────────────────────────────────────────────────────────────────
 */

(function () {
    'use strict';

    // ── BS → AD conversion ──────────────────────────────────────
    function bsToAd(bsStr) {
        // Accept both '2082.03.15' and '2082-03-15' and '2082/03/15'
        var norm = bsStr.replace(/[./]/g, '-').trim();
        var parts = norm.split('-');
        if (parts.length < 3) return '';
        var y = parseInt(parts[0], 10);
        var m = parseInt(parts[1], 10);
        var d = parseInt(parts[2], 10);
        if (isNaN(y) || isNaN(m) || isNaN(d)) return '';

        // Use NepaliFunctions from Sajanmaharjan if available
        if (typeof NepaliFunctions !== 'undefined' && NepaliFunctions.BS2AD) {
            try {
                var ad = NepaliFunctions.BS2AD(y + '.' + pad2(m) + '.' + pad2(d));
                if (ad) {
                    var adY = parseInt(ad.year, 10);
                    var adM = parseInt(ad.month, 10);
                    var adD = parseInt(ad.day, 10);
                    if (!isNaN(adY)) return adY + '-' + pad2(adM) + '-' + pad2(adD);
                }
            } catch (e) {}
        }

        // Fallback to our own NepalDate from nepal-date.js
        if (typeof NepalDate !== 'undefined' && NepalDate.bsToAd) {
            try {
                return NepalDate.bsToAd(y + '-' + pad2(m) + '-' + pad2(d));
            } catch (e) {}
        }

        return '';
    }

    // ── AD → BS conversion ──────────────────────────────────────
    function adToBs(adStr) {
        if (!adStr) return '';
        var norm = adStr.trim();
        if (typeof NepaliFunctions !== 'undefined' && NepaliFunctions.AD2BS) {
            try {
                var parts = norm.split('-');
                var bs = NepaliFunctions.AD2BS(parts[0] + '-' + parts[1] + '-' + parts[2]);
                if (bs) return bs.year + '.' + pad2(bs.month) + '.' + pad2(bs.day);
            } catch (e) {}
        }
        if (typeof NepalDate !== 'undefined' && NepalDate.adToBs) {
            try {
                return NepalDate.adToBs(norm).replace(/-/g, '.');
            } catch (e) {}
        }
        return '';
    }

    function pad2(n) { return String(n).padStart(2, '0'); }

    // ── Format BS date nicely for display ───────────────────────
    var BS_MONTHS_NP = ['', 'बैशाख', 'जेठ', 'असार', 'साउन', 'भाद्र', 'असोज',
                        'कार्तिक', 'मंसिर', 'पुष', 'माघ', 'फाल्गुण', 'चैत'];
    var BS_MONTHS_EN = ['', 'Baisakh', 'Jestha', 'Ashadh', 'Shrawan', 'Bhadra', 'Ashwin',
                        'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra'];

    function formatBsDisplay(bsStr) {
        var parts = bsStr.replace(/[./]/g, '-').split('-');
        if (parts.length < 3) return bsStr;
        var m = parseInt(parts[1], 10);
        return parseInt(parts[2], 10) + ' ' + (BS_MONTHS_NP[m] || '') + ' ' + parts[0];
    }

    function formatAdDisplay(adStr) {
        if (!adStr) return '';
        try {
            var d = new Date(adStr + 'T00:00:00');
            return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
        } catch (e) { return adStr; }
    }

    // ── Find paired AD element ───────────────────────────────────
    function findAdTarget(bsInput) {
        var pairId = bsInput.getAttribute('data-ad-pair');
        if (pairId) return document.getElementById(pairId);
        // Look inside .dual-date wrapper
        var wrapper = bsInput.closest('.dual-date, .dual-date-wrap');
        if (wrapper) return wrapper.querySelector('.ad-date, input[type="date"]');
        return null;
    }
    function findAdDisplay(bsInput) {
        var dispId = bsInput.getAttribute('data-ad-display');
        if (dispId) return document.getElementById(dispId);
        var wrapper = bsInput.closest('.dual-date, .dual-date-wrap');
        if (wrapper) return wrapper.querySelector('.ad-display, .eng-display');
        return null;
    }

    // ── Update paired AD fields when BS changes ──────────────────
    function onBsChange(bsInput) {
        var bsVal  = bsInput.value.trim();
        if (!bsVal) return;

        // Normalise stored value to dots
        var stored = bsVal.replace(/[-/]/g, '.');
        bsInput.value = stored;

        var adStr   = bsToAd(stored);
        var adTarget  = findAdTarget(bsInput);
        var adDisplay = findAdDisplay(bsInput);

        if (adTarget) {
            adTarget.value = adStr;
            // Trigger change so other listeners fire
            adTarget.dispatchEvent(new Event('change'));
        }
        if (adDisplay) {
            adDisplay.textContent = adStr ? formatAdDisplay(adStr) : '';
        }

        // Also update data-ad-hidden attribute if present
        var hiddenId = bsInput.getAttribute('data-hidden');
        if (hiddenId) {
            var h = document.getElementById(hiddenId);
            if (h) h.value = adStr;
        }

        // Show BS formatted next to field
        var bsDisp = bsInput.nextElementSibling;
        if (bsDisp && bsDisp.classList.contains('bs-date-label')) {
            bsDisp.textContent = formatBsDisplay(stored);
        }
    }

    // ── Update BS when AD date input changes ─────────────────────
    function onAdChange(adInput) {
        var adVal = adInput.value.trim();
        if (!adVal) return;
        var bsStr = adToBs(adVal);

        // Find paired BS input
        var pairId = adInput.getAttribute('data-bs-pair');
        var bsInput;
        if (pairId) {
            bsInput = document.getElementById(pairId);
        } else {
            var wrapper = adInput.closest('.dual-date, .dual-date-wrap');
            if (wrapper) bsInput = wrapper.querySelector('.bs-date');
        }
        if (bsInput && bsStr) bsInput.value = bsStr;
    }

    // ── Initialize Nepali Date Picker on a single input ──────────
    function initPicker(input) {
        if (input.dataset.ndpInit) return; // already initialised
        input.dataset.ndpInit = '1';

        if (typeof $ !== 'undefined' && $.fn && $.fn.nepaliDatePicker) {
            $(input).nepaliDatePicker({
                ndpYear : true,
                ndpMonth: true,
                ndpDay  : true,
                dateFormat: 'YYYY.MM.DD',
                closeOnDateSelect: true,
                onChange: function () {
                    onBsChange(input);
                }
            });
        } else {
            // No jQuery / picker — just listen to manual input
            input.addEventListener('change', function () { onBsChange(this); });
            input.addEventListener('blur',   function () { onBsChange(this); });
        }

        // Sync on load if value already present
        if (input.value) onBsChange(input);
    }

    // ── Init all bs-date fields ──────────────────────────────────
    function initAll() {
        document.querySelectorAll('input.bs-date, input.ndp-input').forEach(initPicker);

        // AD → BS listeners
        document.querySelectorAll('input.ad-date[data-bs-pair], input[type="date"][data-bs-pair]').forEach(function (adInput) {
            adInput.addEventListener('change', function () { onAdChange(this); });
        });

        // Month-only pickers
        document.querySelectorAll('input.bs-month').forEach(function (inp) {
            inp.addEventListener('change', function () {
                var v = this.value.replace(/[-/]/g, '.');
                this.value = v;
                var pairId = this.getAttribute('data-ad-pair');
                if (!pairId) return;
                var adInp = document.getElementById(pairId);
                if (!adInp) return;
                // Convert YYYY.MM → approximate AD month
                var parts = v.split('.');
                if (parts.length >= 2) {
                    var bsD = bsToAd(parts[0] + '.' + pad2(parseInt(parts[1], 10)) + '.01');
                    if (bsD) adInp.value = bsD.substring(0, 7); // YYYY-MM
                }
            });
        });
    }

    // ── Public API ────────────────────────────────────────────────
    window.BSDatePicker = {
        init      : initAll,
        initOne   : initPicker,
        bsToAd    : bsToAd,
        adToBs    : adToBs,
        formatBs  : formatBsDisplay,
        formatAd  : formatAdDisplay,
    };

    // Auto-init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

})();
