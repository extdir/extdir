/* stimulusFetch: "lazy" */
import { Controller } from '@hotwired/stimulus';

/*
 * One-click copy, with the confirmation shown on the button that was pressed.
 *
 * Ported from the llmcloudhub project, with the toast removed. That version flashed a
 * message in a fixed corner of the page; here the buttons sit in a list of forty
 * rows, and a confirmation somewhere else on screen leaves the reader unsure which
 * row they actually copied. Confirming in place answers that by construction.
 *
 *   <button class="copy-btn"
 *           data-controller="clipboard"
 *           data-action="clipboard#copy"
 *           data-clipboard-text="composer require acme/plugin">Copy</button>
 */
export default class extends Controller {
    static values = {
        text: String,
        // Long enough to be noticed while scanning, short enough not to look stuck.
        confirmFor: { type: Number, default: 1600 },
    };

    disconnect() {
        clearTimeout(this._timeout);
    }

    async copy(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const text = this.hasTextValue ? this.textValue : button.dataset.clipboardText;
        if (!text) return;

        const original = button.getAttribute('aria-label') || button.textContent.trim();

        try {
            await navigator.clipboard.writeText(text);
            this.confirm(button, 'Copied', original);
        } catch {
            // Denied permission, or an insecure context. Saying so beats silence,
            // which reads as a broken button.
            this.confirm(button, 'Press ⌘C', original);
        }
    }

    confirm(button, message, original) {
        button.classList.add('is-copied');
        button.setAttribute('aria-label', message);

        const label = button.querySelector('[data-copy-label]');
        if (label) label.textContent = message;

        clearTimeout(this._timeout);
        this._timeout = setTimeout(() => {
            button.classList.remove('is-copied');
            button.setAttribute('aria-label', original);
            if (label) label.textContent = 'Copy';
        }, this.confirmForValue);
    }
}
