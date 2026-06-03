/**
 * nepal-date.js — BS ↔ AD date conversion + dual date picker
 * Works standalone (no jQuery dependency).
 *
 * Usage:
 *   NepalDate.adToBs('2024-06-29')  → '2081-03-15'
 *   NepalDate.bsToAd('2081-03-15')  → '2024-06-29'
 *   NepalDate.todayBs()             → current date in BS
 *
 * Dual picker:
 *   <input type="text" class="bs-date" name="date_nep" placeholder="YYYY-MM-DD (BS)">
 *   <input type="date"  class="ad-date" name="date_eng">
 *   — changing one auto-updates the other.
 */

const NepalDate = (() => {
    // BS calendar data: index 0 = BS year 2000
    // Each row: [total_days, m1..m12]
    const BS_DATA = [
        [365,30,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,30,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,31,31,32,31,31,31,30,29,30,29,30,30],[365,31,31,32,32,31,30,30,29,30,29,30,30],
        [366,31,32,31,32,31,30,30,30,29,29,30,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,31,31,32,31,31,31,30,29,30,29,30,30],[365,31,31,32,32,31,30,30,29,30,29,30,30],
        [366,31,32,31,32,31,30,30,30,29,29,30,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,31,31,32,31,31,31,30,29,30,29,30,30],[366,31,31,32,32,31,30,30,29,30,29,30,31],
        [366,31,32,31,32,31,30,30,30,29,29,30,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,30,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,30,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [366,31,31,32,32,31,30,30,29,30,29,30,31],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,30,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,30,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,30,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,30,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,30,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [366,31,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,30,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,30,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,30,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,30,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,30,32,31,32,31,30,30,30,29,30,29,31],[365,31,31,32,31,31,31,30,29,30,29,30,30],
        [365,31,31,32,32,31,30,30,29,30,29,30,30],[366,31,32,31,32,31,30,30,30,29,29,30,31],
        [365,30,32,31,32,31,30,30,30,29,30,29,31],
    ];

    // AD date that corresponds to BS 2000-01-01
    const AD_BASE = new Date(1943, 3, 14); // April 14, 1943

    const MONTH_EN = ['','Baisakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin',
                      'Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'];
    const MONTH_NP = ['','बैशाख','जेठ','असार','साउन','भाद्र','असोज',
                      'कार्तिक','मंसिर','पुष','माघ','फाल्गुण','चैत'];

    function _pad(n) { return String(n).padStart(2,'0'); }

    function adToBs(adStr) {
        const [y,m,d] = adStr.split('-').map(Number);
        const ad = new Date(y, m-1, d);
        const base = new Date(1943, 3, 14);
        let totalDays = Math.round((ad - base) / 86400000);

        let bsYear = 2000;
        for (let i = 0; i < BS_DATA.length; i++) {
            if (totalDays < BS_DATA[i][0]) { bsYear = 2000 + i; break; }
            totalDays -= BS_DATA[i][0];
        }

        const yr = BS_DATA[bsYear - 2000];
        let bsMonth = 1;
        for (let m2 = 1; m2 <= 12; m2++) {
            if (totalDays < yr[m2]) { bsMonth = m2; break; }
            totalDays -= yr[m2];
        }
        const bsDay = totalDays + 1;
        return `${bsYear}-${_pad(bsMonth)}-${_pad(bsDay)}`;
    }

    function bsToAd(bsStr) {
        const [bsY, bsM, bsD] = bsStr.split('-').map(Number);
        let days = bsD - 1;
        const yr = BS_DATA[bsY - 2000];
        if (!yr) return '';
        for (let m2 = 1; m2 < bsM; m2++) days += yr[m2];
        for (let i = 0; i < bsY - 2000; i++) days += BS_DATA[i][0];
        const ad = new Date(1943, 3, 14);
        ad.setDate(ad.getDate() + days);
        return `${ad.getFullYear()}-${_pad(ad.getMonth()+1)}-${_pad(ad.getDate())}`;
    }

    function todayBs() {
        const now = new Date();
        return adToBs(`${now.getFullYear()}-${_pad(now.getMonth()+1)}-${_pad(now.getDate())}`);
    }

    function monthNameEn(m) { return MONTH_EN[m] || ''; }
    function monthNameNp(m) { return MONTH_NP[m] || ''; }

    // BS year-month string "YYYY.MM" → display label
    function bsYearMonthLabel(ym) {
        if (!ym) return '';
        const [y, m] = ym.split('.').map(Number);
        return `${MONTH_EN[m]} ${y} (${ym})`;
    }

    // ── Dual Date Picker init ─────────────────────────────────
    function initDualPickers() {
        // For each .bs-date input, find a sibling or associated .ad-date input
        document.querySelectorAll('.bs-date').forEach(bsInput => {
            const adInput = document.querySelector(`[data-ad-for="${bsInput.name}"]`) ||
                            bsInput.closest('.dual-date')?.querySelector('.ad-date') ||
                            bsInput.parentElement?.querySelector('.ad-date') ||
                            document.querySelector(`.ad-date[name="${bsInput.dataset.adName}"]`);

            if (!adInput) return;

            // BS → AD
            bsInput.addEventListener('change', () => {
                const v = bsInput.value.trim();
                if (/^\d{4}-\d{2}-\d{2}$/.test(v)) {
                    try { adInput.value = bsToAd(v); } catch(e) {}
                }
            });

            // AD → BS
            adInput.addEventListener('change', () => {
                const v = adInput.value.trim();
                if (/^\d{4}-\d{2}-\d{2}$/.test(v)) {
                    try { bsInput.value = adToBs(v); } catch(e) {}
                }
            });
        });

        // Also handle .bs-month (YYYY.MM) ↔ .ad-month (YYYY-MM)
        document.querySelectorAll('.bs-month').forEach(bsM => {
            const adM = bsM.closest('.dual-month')?.querySelector('.ad-month') ||
                        document.querySelector(`.ad-month[name="${bsM.dataset.adName}"]`);
            if (!adM) return;

            bsM.addEventListener('change', () => {
                const v = bsM.value; // "2081-03" or "2081.03"
                const norm = v.replace('.','-');
                if (/^\d{4}-\d{2}$/.test(norm)) {
                    try {
                        const ad = bsToAd(norm + '-01');
                        adM.value = ad.substring(0, 7); // YYYY-MM
                    } catch(e) {}
                }
            });

            adM.addEventListener('change', () => {
                const v = adM.value; // "2024-06"
                if (/^\d{4}-\d{2}$/.test(v)) {
                    try {
                        const bs = adToBs(v + '-01');
                        bsM.value = bs.substring(0, 7); // YYYY-MM
                    } catch(e) {}
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', initDualPickers);

    return { adToBs, bsToAd, todayBs, monthNameEn, monthNameNp, bsYearMonthLabel, initDualPickers };
})();
