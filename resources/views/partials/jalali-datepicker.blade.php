{{--
    Jalali date picker — the only one in the app.

    Included once per page (it is @once-guarded); every field that wants it
    just renders the markup pattern below and this script wires it up:

        <div data-jdp>
            <input type="hidden" name="from" value="{{ $iso }}">      ← what the server gets (Gregorian ISO)
            <input type="text" readonly data-jdp-display>              ← what the customer sees (Jalali)
        </div>

    Why hand-written rather than a library: this project has no build step,
    no package.json and no JS dependencies at all (see CLAUDE.md), and the
    CDN allowlist would be a new dependency decision. The conversion below is
    a direct port of App\Support\Rental\Jalali — same algorithm, same leap
    rule — so the calendar the customer clicks and the dates the backend
    parses can never disagree.

    The calendar is Jalali in its LOGIC, not merely in its labels: months are
    real Jalali months of 31/30/29 days, the grid starts on شنبه, and the
    Gregorian value is derived from the Jalali selection, never the reverse.
--}}
@once
@push('styles')
<style>
    .jdp-panel { display: none; }
    .jdp-panel.jdp-open { display: block; }
    .jdp-day:disabled { opacity: .35; cursor: not-allowed; }
</style>
@endpush

@push('scripts')
<script>
(function () {
    'use strict';

    // ── Jalali ⇄ Gregorian ────────────────────────────────────────────────
    // Port of App\Support\Rental\Jalali. Keep the two in step.
    const G_MONTH_OFFSETS = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    const LEAP_BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210,
        1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];

    const div = (a, b) => Math.trunc(a / b);

    function toJalali(gy, gm, gd) {
        const gy2 = gm > 2 ? gy + 1 : gy;
        let days = 355666 + (365 * gy) + div(gy2 + 3, 4) - div(gy2 + 99, 100)
            + div(gy2 + 399, 400) + gd + G_MONTH_OFFSETS[gm - 1];

        let jy = -1595 + (33 * div(days, 12053));
        days %= 12053;
        jy += 4 * div(days, 1461);
        days %= 1461;

        if (days > 365) {
            jy += div(days - 1, 365);
            days = (days - 1) % 365;
        }

        return days < 186
            ? { jy, jm: 1 + div(days, 31), jd: 1 + (days % 31) }
            : { jy, jm: 7 + div(days - 186, 30), jd: 1 + ((days - 186) % 30) };
    }

    function toGregorian(jy, jm, jd) {
        jy += 1595;
        let days = -355668 + (365 * jy) + (div(jy, 33) * 8) + div((jy % 33) + 3, 4)
            + jd + (jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);

        let gy = 400 * div(days, 146097);
        days %= 146097;

        if (days > 36524) {
            days--;
            gy += 100 * div(days, 36524);
            days %= 36524;
            if (days >= 365) days++;
        }

        gy += 4 * div(days, 1461);
        days %= 1461;

        if (days > 365) {
            gy += div(days - 1, 365);
            days = (days - 1) % 365;
        }

        let gd = days + 1;
        const lengths = [31, ((gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0) ? 29 : 28,
            31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        let gm = 1;
        for (const len of lengths) {
            if (gd <= len) break;
            gd -= len;
            gm++;
        }

        return { gy, gm, gd };
    }

    function isJalaliLeap(jy) {
        let jp = LEAP_BREAKS[0], jump = 0, j = 1;
        for (; j < LEAP_BREAKS.length; j++) {
            const jm = LEAP_BREAKS[j];
            jump = jm - jp;
            if (jy < jm) break;
            jp = jm;
        }

        let n = jy - jp;
        if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33;

        let leap = ((n + 1) % 33 - 1) % 4;
        if (leap === -1) leap = 4;

        return leap === 0;
    }

    const monthLength = (jy, jm) => jm <= 6 ? 31 : (jm <= 11 ? 30 : (isJalaliLeap(jy) ? 30 : 29));

    const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const WEEKDAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

    const fa = v => String(v).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const pad = v => String(v).padStart(2, '0');
    const iso = (gy, gm, gd) => `${gy}-${pad(gm)}-${pad(gd)}`;

    /** Saturday = 0, matching the grid's first column. */
    function weekdaySat0(gy, gm, gd) {
        return (new Date(Date.UTC(gy, gm - 1, gd)).getUTCDay() + 1) % 7;
    }

    function isoToJalali(value) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
        if (!m) return null;
        return toJalali(+m[1], +m[2], +m[3]);
    }

    function label(value) {
        const j = isoToJalali(value);
        return j ? `${fa(j.jd)} ${MONTHS[j.jm - 1]} ${fa(j.jy)}` : '';
    }

    const todayIso = () => {
        const now = new Date();
        return iso(now.getFullYear(), now.getMonth() + 1, now.getDate());
    };

    // ── The picker ────────────────────────────────────────────────────────
    class JalaliPicker {
        constructor(root) {
            this.root = root;
            this.input = root.querySelector('input[type="hidden"]');
            this.display = root.querySelector('[data-jdp-display]');
            this.min = root.dataset.jdpMin || todayIso();
            this.max = root.dataset.jdpMax || null;

            this.panel = document.createElement('div');
            this.panel.className = 'jdp-panel absolute z-50 mt-2 w-[286px] bg-white rounded-2xl shadow-xl border border-gray-100 p-3';
            this.panel.setAttribute('role', 'dialog');
            root.appendChild(this.panel);

            const start = isoToJalali(this.input.value) || isoToJalali(this.min) || toJalali(
                new Date().getFullYear(), new Date().getMonth() + 1, new Date().getDate());
            this.viewYear = start.jy;
            this.viewMonth = start.jm;

            this.display.addEventListener('click', () => this.toggle());
            this.display.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.toggle(); }
                if (e.key === 'Escape') this.close();
            });

            document.addEventListener('click', e => {
                if (!root.contains(e.target)) this.close();
            });
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') this.close();
            });

            this.syncDisplay();
        }

        syncDisplay() {
            this.display.value = label(this.input.value);
        }

        setMin(value) {
            this.min = value;
            // A selection that the new floor invalidates cannot be allowed to
            // linger — that is exactly how an end-before-start slips through.
            if (this.input.value && this.input.value < value) {
                this.input.value = '';
                this.syncDisplay();
                this.input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            const j = isoToJalali(value);
            if (j) { this.viewYear = j.jy; this.viewMonth = j.jm; }
            if (this.isOpen()) this.render();
        }

        isOpen() { return this.panel.classList.contains('jdp-open'); }
        toggle() { this.isOpen() ? this.close() : this.open(); }

        open() {
            document.querySelectorAll('.jdp-panel.jdp-open').forEach(p => p.classList.remove('jdp-open'));
            this.render();
            this.panel.classList.add('jdp-open');
        }

        close() { this.panel.classList.remove('jdp-open'); }

        shiftMonth(delta) {
            this.viewMonth += delta;
            if (this.viewMonth > 12) { this.viewMonth = 1; this.viewYear++; }
            if (this.viewMonth < 1) { this.viewMonth = 12; this.viewYear--; }
            this.render();
        }

        render() {
            const jy = this.viewYear, jm = this.viewMonth;
            const first = toGregorian(jy, jm, 1);
            const lead = weekdaySat0(first.gy, first.gm, first.gd);
            const days = monthLength(jy, jm);

            this.panel.innerHTML = '';

            const head = document.createElement('div');
            head.className = 'flex items-center justify-between mb-2';
            head.innerHTML =
                '<button type="button" data-jdp-prev aria-label="ماه قبل" class="w-8 h-8 rounded-lg hover:bg-gray-100 text-gray-500"><i class="fa-solid fa-chevron-right text-xs"></i></button>' +
                `<span class="text-sm font-bold text-gray-800">${MONTHS[jm - 1]} ${fa(jy)}</span>` +
                '<button type="button" data-jdp-next aria-label="ماه بعد" class="w-8 h-8 rounded-lg hover:bg-gray-100 text-gray-500"><i class="fa-solid fa-chevron-left text-xs"></i></button>';
            this.panel.appendChild(head);

            const wd = document.createElement('div');
            wd.className = 'grid grid-cols-7 gap-1 text-center text-[10px] text-gray-400 mb-1';
            wd.innerHTML = WEEKDAYS.map(d => `<div class="py-1">${d}</div>`).join('');
            this.panel.appendChild(wd);

            const grid = document.createElement('div');
            grid.className = 'grid grid-cols-7 gap-1';

            for (let i = 0; i < lead; i++) grid.appendChild(document.createElement('div'));

            for (let d = 1; d <= days; d++) {
                const g = toGregorian(jy, jm, d);
                const value = iso(g.gy, g.gm, g.gd);
                const disabled = (this.min && value < this.min) || (this.max && value > this.max);
                const selected = value === this.input.value;

                const cell = document.createElement('button');
                cell.type = 'button';
                cell.className = 'jdp-day h-9 rounded-lg text-xs transition-colors '
                    + (selected
                        ? 'bg-brandBlue text-white font-bold'
                        : 'text-gray-700 hover:bg-brandLightBlue hover:text-brandBlue');
                cell.textContent = fa(d);
                cell.disabled = disabled;
                cell.dataset.jdpValue = value;
                grid.appendChild(cell);
            }

            this.panel.appendChild(grid);

            head.querySelector('[data-jdp-prev]').addEventListener('click', () => this.shiftMonth(-1));
            head.querySelector('[data-jdp-next]').addEventListener('click', () => this.shiftMonth(1));

            grid.addEventListener('click', e => {
                const cell = e.target.closest('[data-jdp-value]');
                if (!cell || cell.disabled) return;
                this.input.value = cell.dataset.jdpValue;
                this.syncDisplay();
                this.input.dispatchEvent(new Event('change', { bubbles: true }));
                this.close();
            });
        }
    }

    function init(scope) {
        (scope || document).querySelectorAll('[data-jdp]').forEach(root => {
            if (root.dataset.jdpReady) return;
            root.dataset.jdpReady = '1';
            root.__jdp = new JalaliPicker(root);
        });
    }

    // Exposed so a range pair (from/to) can move the end field's floor.
    window.JalaliDatePicker = { init, toJalali, toGregorian, label, monthLength };

    document.addEventListener('DOMContentLoaded', () => init());
})();
</script>
@endpush
@endonce
