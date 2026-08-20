/* stimulusFetch: "eager" */
import { Controller } from '@hotwired/stimulus';

/*
 * Collapses the filter column on narrow screens.
 *
 * The column is long — around forty links — and on a phone it stacks above the
 * results, so the first extension started more than a screen and a half down. On a
 * directory whose whole purpose is browsing extensions, that is the wrong thing to
 * put first.
 *
 * The <details> ships open in the markup, so a browser with no JavaScript keeps the
 * behaviour it has today: everything visible, nothing hidden behind a control that
 * would never work. This closes it only where the length is a problem, and only
 * until somebody opens it.
 *
 * Once a reader touches the toggle their choice stands for the rest of the page,
 * including across a rotation — a filter panel that reopened or slammed shut on
 * every resize would be worse than either state.
 */
export default class extends Controller {
    static targets = ['drawer'];

    connect() {
        this.query = window.matchMedia('(max-width: 900px)');
        this.touched = false;

        this.onToggle = () => { this.touched = true; };
        this.drawerTarget.addEventListener('toggle', this.onToggle);

        this.apply = () => {
            if (!this.touched) this.drawerTarget.open = !this.query.matches;
        };

        // The initial state is set by an inline script during parsing — doing it
        // here instead moved the results 590px after first paint, which was most of
        // a failing CLS score. This controller only handles what happens afterwards:
        // a rotation, or a window being resized across the breakpoint.
        this.query.addEventListener('change', this.apply);
    }

    disconnect() {
        this.query.removeEventListener('change', this.apply);
        this.drawerTarget.removeEventListener('toggle', this.onToggle);
    }
}
