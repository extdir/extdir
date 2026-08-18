/* stimulusFetch: "eager" */
import { Controller } from '@hotwired/stimulus';

/*
 * The colour theme control.
 *
 * Three states, and the third one matters: "Auto" follows the operating system and is
 * the default. A two-state toggle traps anyone who tries it — once a value is stored
 * there is no way back to following the machine, so a laptop that switches to dark in
 * the evening would stay light forever because of one curious click months earlier.
 *
 * Restoring the choice is *not* done here. A module from the importmap is deferred,
 * so by the time this runs the page has already painted in the wrong theme; the
 * restore lives in a small synchronous script in <head>. This controller only handles
 * changes, and keeps the buttons' pressed state in step.
 *
 * The preference is kept in localStorage rather than a cookie. That is not a
 * technicality: no cookie means no cookie banner, and it keeps the promise the
 * privacy policy makes. Storing it at all is disclosed there, because a policy that
 * describes an application which does something else is worse than one that says
 * nothing.
 */
export default class extends Controller {
    static targets = ['button'];

    static values = {
        key: { type: String, default: 'extdir-theme' },
    };

    connect() {
        this.render(this.stored() ?? 'system');

        // A machine whose appearance changes during the visit — most do, on a
        // schedule — should be followed while "Auto" is selected.
        this._media = window.matchMedia('(prefers-color-scheme: dark)');
        this._onSystemChange = () => {
            if ((this.stored() ?? 'system') === 'system') this.apply('system');
        };
        this._media.addEventListener('change', this._onSystemChange);
    }

    disconnect() {
        this._media?.removeEventListener('change', this._onSystemChange);
    }

    choose(event) {
        const choice = event.currentTarget.dataset.themeValue;
        if (!choice) return;

        this.store(choice);
        this.apply(choice);
        this.render(choice);
    }

    /**
     * "system" removes the attribute rather than computing a value, which hands the
     * decision back to the CSS media query — one source of truth instead of two that
     * can disagree.
     */
    apply(choice) {
        const root = document.documentElement;

        if (choice === 'light' || choice === 'dark') {
            root.setAttribute('data-theme', choice);
        } else {
            root.removeAttribute('data-theme');
        }
    }

    render(choice) {
        this.buttonTargets.forEach((button) => {
            const pressed = button.dataset.themeValue === choice;
            button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
            button.classList.toggle('is-active', pressed);
        });
    }

    stored() {
        try {
            const value = localStorage.getItem(this.keyValue);

            return value === 'light' || value === 'dark' ? value : null;
        } catch {
            return null;
        }
    }

    store(choice) {
        try {
            // "system" is the absence of a preference, so it clears the key rather
            // than writing a third value nothing else understands.
            if (choice === 'system') {
                localStorage.removeItem(this.keyValue);
            } else {
                localStorage.setItem(this.keyValue, choice);
            }
        } catch {
            /* Storage unavailable. The choice still applies for this page view. */
        }
    }
}
