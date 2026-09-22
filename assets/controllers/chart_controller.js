/* stimulusFetch: "lazy" */
import { Controller } from '@hotwired/stimulus';

/*
 * The hover layer for the adoption curves.
 *
 * Only this chart has one. Every other chart on the statistics page is made of boxes
 * with CSS widths, so a plain :hover rule already reveals its value and no script is
 * involved. A line chart cannot do that: the value a reader wants is at an x position,
 * not on any one of the five strokes, and asking someone to land a pointer on a 2px
 * diagonal is asking them to fail.
 *
 * So the crosshair finds the month and the readout lists every version at that month.
 * The pointer never has to touch a line.
 *
 * Nothing here gates a value. Every number is also in the table underneath the chart,
 * which is what a reader without a pointer, without scripting, or using a screen
 * reader gets, and it is the reason this file is allowed to be small.
 *
 *   <section data-controller="chart" data-chart-span-value="24" data-chart-unit-value="extensions">
 */
export default class extends Controller {
    static targets = ['plot', 'line', 'crosshair', 'tooltip'];

    static values = {
        span: Number,
        unit: { type: String, default: 'value' },
    };

    connect() {
        this.series = this.lineTargets.map((line) => ({
            label: line.dataset.version || '',
            points: this.parse(line.dataset.points),
        }));

        // The plot has to take focus for the keyboard path to exist at all. It is not
        // a control, so it announces itself through the table instead of a role.
        this.plotTarget.tabIndex = 0;
        this.month = null;

        this._onMove = (event) => this.showAt(this.monthFromPointer(event));
        this._onLeave = () => this.hide();
        this._onKey = (event) => this.step(event);

        this.plotTarget.addEventListener('pointermove', this._onMove);
        this.plotTarget.addEventListener('pointerleave', this._onLeave);
        this.plotTarget.addEventListener('focus', () => this.showAt(this.month ?? 0));
        this.plotTarget.addEventListener('blur', this._onLeave);
        this.plotTarget.addEventListener('keydown', this._onKey);
    }

    // Turbo swaps the body on every navigation, so a listener left behind here
    // outlives the element it was attached to.
    disconnect() {
        this.plotTarget.removeEventListener('pointermove', this._onMove);
        this.plotTarget.removeEventListener('pointerleave', this._onLeave);
        this.plotTarget.removeEventListener('keydown', this._onKey);
    }

    parse(raw) {
        try {
            const points = JSON.parse(raw || '[]');

            return Array.isArray(points) ? points : [];
        } catch {
            // A malformed payload costs the hover layer, not the chart. The lines are
            // already drawn and the table still holds every number.
            return [];
        }
    }

    /*
     * The month under the pointer, snapped to the nearest one.
     *
     * Snapping is what makes the target big enough to hit. A reader aims at a date on
     * the axis, and with twenty-five months across a card the raw pixel position is
     * never the answer they meant.
     */
    monthFromPointer(event) {
        const box = this.plotTarget.getBoundingClientRect();

        if (box.width === 0) return 0;

        const ratio = (event.clientX - box.left) / box.width;

        return Math.max(0, Math.min(this.spanValue, Math.round(ratio * this.spanValue)));
    }

    step(event) {
        const keys = { ArrowLeft: -1, ArrowRight: 1 };
        const move = keys[event.key];

        if (move === undefined) return;

        event.preventDefault();
        this.showAt(Math.max(0, Math.min(this.spanValue, (this.month ?? 0) + move)));
    }

    showAt(month) {
        this.month = month;

        const ratio = this.spanValue > 0 ? month / this.spanValue : 0;

        const crosshair = this.crosshairTarget;
        const x = ratio * crosshair.viewportElement.viewBox.baseVal.width;
        crosshair.setAttribute('x1', x);
        crosshair.setAttribute('x2', x);
        crosshair.hidden = false;

        this.render(month, ratio);
    }

    hide() {
        this.crosshairTarget.hidden = true;
        this.tooltipTarget.hidden = true;
    }

    render(month, ratio) {
        const tooltip = this.tooltipTarget;
        tooltip.replaceChildren();

        const head = document.createElement('p');
        head.className = 'chart-tooltip-head';
        head.textContent = month === 0 ? 'At launch' : `Month ${month}`;
        tooltip.append(head);

        const list = document.createElement('dl');

        for (const line of this.series) {
            const point = line.points.find((candidate) => candidate.month === month);

            if (!point) continue;

            const term = document.createElement('dt');
            // Labels come out of the database. textContent rather than any string
            // concatenation into markup, because the habit is what protects the day
            // this readout is pointed at something we did not write.
            term.textContent = line.label;

            const value = document.createElement('dd');
            value.textContent = point.value.toLocaleString();

            list.append(term, value);
        }

        tooltip.append(list);
        tooltip.hidden = false;

        // Keep the readout inside the card. Past the midpoint it flips to the left of
        // the crosshair rather than hanging off the edge of a phone screen.
        const box = this.plotTarget.getBoundingClientRect();
        const width = tooltip.offsetWidth;
        const left = ratio * box.width;

        tooltip.style.left = `${Math.max(0, Math.min(box.width - width, left > box.width / 2 ? left - width - 8 : left + 8))}px`;
    }
}
