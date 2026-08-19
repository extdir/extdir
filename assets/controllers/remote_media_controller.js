/* stimulusFetch: "eager" */
import { Controller } from '@hotwired/stimulus';

/*
 * Loads extension icons and screenshots from their own forge, and only if the reader
 * has said yes.
 *
 * The site otherwise makes no third-party requests at all, and its privacy policy
 * says so. An icon hot-linked from GitHub would send every visitor's IP to GitHub on
 * every page view and quietly make that statement false — the same shape of problem
 * as embedding Google Fonts, which is why the fonts here are self-hosted.
 *
 * So this is the two-click pattern: nothing is requested until somebody asks, the
 * placeholder names the host they would be contacting, and the answer is remembered
 * so they are asked once rather than on every page.
 *
 * Deliberately not a modal on arrival. The site's whole claim is that there is
 * nothing you must consent to before using it, and a dialog in front of the
 * catalogue would contradict that on the homepage. The ask belongs where the image
 * would be, so anyone who does not care never sees it.
 */
export default class extends Controller {
    static targets = ['slot', 'status', 'enable', 'disable', 'gallery'];

    static values = {
        key: { type: String, default: 'extdir-remote-media' },
    };

    connect() {
        this.sync();
        if (this.allowed()) this.reveal();

        // Turbo swaps the <body> on navigation, so a footer rendered in the "off"
        // state arrives on every page and has to be corrected again.
        this.onAllowed = () => { this.sync(); this.reveal(); };
        window.addEventListener('extdir:remote-media-allowed', this.onAllowed);
    }

    disconnect() {
        window.removeEventListener('extdir:remote-media-allowed', this.onAllowed);
    }

    /* The markup ships in the "off" state, so this only ever has to turn things on.
       Rendering "icons are loading from GitHub" server-side and unsaying it in JS
       would show a false claim to every visitor who never opted in. */
    sync() {
        if (!this.allowed()) return;

        if (this.hasStatusTarget) {
            this.statusTarget.textContent = 'Extension icons and screenshots are loading from their forges.';
        }
        if (this.hasEnableTarget) this.enableTarget.hidden = true;
        if (this.hasDisableTarget) this.disableTarget.hidden = false;
    }

    /** Called by the placeholder button, and by the footer control. */
    allow() {
        this.store(true);
        this.reveal();
        // Other rails and rows on the page are separate controller instances.
        window.dispatchEvent(new CustomEvent('extdir:remote-media-allowed'));
    }

    /* A reload, not just hiding the <img> elements: once the browser has them cached
       the request has already happened, and leaving them on screen while claiming
       they are off would be the same lie in the other direction. */
    revoke() {
        this.store(false);
        window.location.reload();
    }

    reveal() {
        this.galleryTargets.forEach((section) => { section.hidden = false; });

        this.slotTargets.forEach((slot) => {
            const url = slot.dataset.remoteMediaUrl;
            if (!url || slot.querySelector('img')) return;

            const image = new Image();
            image.src = url;
            image.alt = '';
            image.loading = 'lazy';
            image.decoding = 'async';
            image.className = slot.dataset.remoteMediaClass ?? 'ext-icon';

            // A missing or renamed file is ordinary in a decade-old corpus. An icon
            // falls back to the monogram underneath; a screenshot has nothing behind
            // it, so the whole thumbnail goes rather than leaving an empty frame.
            image.addEventListener('error', () => {
                image.remove();
                slot.remove();
                this.hideEmptyGalleries();
            });
            image.addEventListener('load', () => slot.classList.add('has-image'));

            slot.appendChild(image);
        });
    }

    /**
     * A gallery whose every image 404ed is a heading over an empty strip. README
     * links rot, so this is not hypothetical.
     */
    hideEmptyGalleries() {
        this.galleryTargets.forEach((section) => {
            if (0 === section.querySelectorAll('[data-remote-media-url]').length) {
                section.hidden = true;
            }
        });
    }

    allowed() {
        try {
            return localStorage.getItem(this.keyValue) === 'yes';
        } catch {
            return false;
        }
    }

    store(allowed) {
        try {
            if (allowed) {
                localStorage.setItem(this.keyValue, 'yes');
            } else {
                localStorage.removeItem(this.keyValue);
            }
        } catch {
            /* Private mode. The choice still applies to this page view. */
        }
    }
}
