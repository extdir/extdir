/* stimulusFetch: "lazy" */
import { Controller } from '@hotwired/stimulus';

/*
 * Viewing a screenshot without leaving the page.
 *
 * The thumbnails are real links to the original on the maintainer's forge, and they
 * stay that way: without JavaScript, or on a middle-click, they behave exactly as a
 * link should. This intercepts the plain left-click only, which is the case where
 * being thrown into a new tab showing a bare PNG, no caption, no way back except the
 * browser's own, is worse than staying put.
 *
 * The dialog is the native one, so focus trapping, Escape, and returning focus to the
 * thumbnail afterwards are the browser's job rather than a pile of listeners that
 * handle two thirds of it.
 *
 * Nothing here fetches anything the reader has not already allowed: the gallery is
 * hidden until remote media is consented to, so by the time a thumbnail is clickable
 * its URL has already been loaded once.
 */
export default class extends Controller {
    static targets = ['dialog', 'image', 'caption', 'source', 'prev', 'next'];

    connect() {
        this.shots = Array.from(this.element.querySelectorAll('.shot'));
        this.index = 0;

        // Clicking the backdrop is the gesture people try first, and <dialog> gives
        // no event for it, the click lands on the dialog itself, outside its box.
        this.onBackdrop = (event) => {
            if (event.target === this.dialogTarget) this.dialogTarget.close();
        };
        this.dialogTarget.addEventListener('click', this.onBackdrop);

        this.onKey = (event) => {
            if (!this.dialogTarget.open) return;
            if (event.key === 'ArrowRight') this.next();
            if (event.key === 'ArrowLeft') this.prev();
        };
        this.dialogTarget.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        this.dialogTarget.removeEventListener('click', this.onBackdrop);
        this.dialogTarget.removeEventListener('keydown', this.onKey);
    }

    open(event) {
        // Modified clicks belong to the browser: ctrl/cmd/middle-click means "new tab"
        // and hijacking that is the kind of cleverness people rightly resent.
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;

        const shot = event.currentTarget;
        const index = this.shots.indexOf(shot);
        if (index === -1) return;

        event.preventDefault();
        this.index = index;
        this.render();
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    next() {
        this.index = (this.index + 1) % this.shots.length;
        this.render();
    }

    prev() {
        this.index = (this.index - 1 + this.shots.length) % this.shots.length;
        this.render();
    }

    render() {
        const shot = this.shots[this.index];
        const url = shot.dataset.remoteMediaUrl ?? shot.getAttribute('href');

        this.imageTarget.src = url;
        this.sourceTarget.href = url;
        this.captionTarget.textContent = `${this.index + 1} of ${this.shots.length}`;

        // A single screenshot needs no way to step through screenshots.
        const many = this.shots.length > 1;
        this.prevTarget.hidden = !many;
        this.nextTarget.hidden = !many;
    }
}
