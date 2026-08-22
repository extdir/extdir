/* stimulusFetch: "lazy" */
import { Controller } from '@hotwired/stimulus';

/*
 * Reveals the operator's postal address after a deliberate wait.
 *
 * The address is not in the page source. A button starts a countdown, and only
 * when it finishes does the browser fetch the address from a rate-limited JSON
 * endpoint. The countdown resets if the tab is backgrounded, via the Page
 * Visibility API, which covers tab switches, minimising, and mobile app
 * switching alike.
 *
 * None of this is a security control, anyone can read the endpoint. It raises
 * the cost of bulk address harvesting from "parse the HTML" to "run a browser
 * and wait", which is the whole of the ambition.
 *
 *   <div data-controller="reveal"
 *        data-reveal-url-value="{{ path('imprint_contact_details') }}"
 *        data-reveal-duration-seconds-value="60">
 */
export default class extends Controller {
    static targets = ['trigger', 'progress', 'bar', 'label', 'content', 'error'];

    static values = {
        url: String,
        durationSeconds: { type: Number, default: 60 },
    };

    initialize() {
        this._aborted = false;
        this._completed = false;
        this._visibilityHandler = null;
    }

    disconnect() {
        this._aborted = true;
        this.removeVisibilityListener();
    }

    async start(event) {
        event.preventDefault();
        this.triggerTarget.disabled = true;
        this.triggerTarget.hidden = true;
        this.errorTarget.hidden = true;
        this.progressTarget.hidden = false;
        this._aborted = false;
        this._completed = false;

        this._visibilityHandler = () => {
            if (document.hidden && !this._completed) {
                this.handleVisibilityLoss();
            }
        };
        document.addEventListener('visibilitychange', this._visibilityHandler);

        const finished = await this.runProgress();
        if (this._aborted || !finished) return;

        // Past this point the wait has been served, so backgrounding the tab no
        // longer cancels anything, there is only the fetch left to do.
        this._completed = true;
        this.removeVisibilityListener();

        await this.fetchAndRender();
    }

    runProgress() {
        return new Promise(resolve => {
            const total = this.durationSecondsValue * 1000;
            const start = performance.now();

            const tick = (now) => {
                if (this._aborted) return resolve(false);
                const elapsed = now - start;
                this.barTarget.style.width = `${Math.min(100, (elapsed / total) * 100)}%`;
                const remaining = Math.max(0, Math.ceil((total - elapsed) / 1000));
                this.labelTarget.textContent = remaining > 0
                    ? `${remaining}s remaining, keep this tab open`
                    : 'Almost there...';
                if (elapsed < total) {
                    requestAnimationFrame(tick);
                } else {
                    resolve(true);
                }
            };

            requestAnimationFrame(tick);
        });
    }

    handleVisibilityLoss() {
        this._aborted = true;
        this.removeVisibilityListener();
        this.barTarget.style.width = '0%';
        this.labelTarget.textContent = '';
        this.progressTarget.hidden = true;
        this.errorTarget.textContent =
            'The countdown reset because this tab lost focus. It only runs while the tab is '
            + 'active, which is what deters automated collection. You can start it again below.';
        this.errorTarget.hidden = false;
        this.triggerTarget.hidden = false;
        this.triggerTarget.disabled = false;
    }

    removeVisibilityListener() {
        if (this._visibilityHandler) {
            document.removeEventListener('visibilitychange', this._visibilityHandler);
            this._visibilityHandler = null;
        }
    }

    async fetchAndRender() {
        try {
            const response = await fetch(this.urlValue, {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin',
            });

            if (!response.ok) {
                this.showError(response.status === 429
                    ? 'Too many requests from this network. Wait an hour, or email us, the '
                      + 'address above reaches a person either way.'
                    : `The address could not be loaded (HTTP ${response.status}).`);
                return;
            }

            this.render(await response.json());
        } catch {
            this.showError('The address could not be loaded. Check your connection and try again.');
        }
    }

    render(data) {
        this.progressTarget.hidden = true;

        // textContent on a detached node, so a value that ever contains markup is
        // escaped rather than parsed. The data is ours, but the endpoint is the
        // kind of thing that gets reused later for something less trusted.
        const escape = (value) => {
            const node = document.createElement('div');
            node.textContent = String(value ?? '');
            return node.innerHTML;
        };

        this.contentTarget.innerHTML = `
            <address>
                ${escape(data.name)}<br>
                ${escape(data.street)}<br>
                ${escape(data.postalCity)}<br>
                ${escape(data.country)}
            </address>
        `;
        this.contentTarget.hidden = false;
    }

    showError(message) {
        this.progressTarget.hidden = true;
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = false;
        this.triggerTarget.hidden = false;
        this.triggerTarget.disabled = false;
    }
}
