/* stimulusFetch: "lazy" */
import { Controller } from '@hotwired/stimulus';

/*
 * Arrow buttons for a horizontal card rail.
 *
 * An enhancement, never the mechanism. The rail is a native scroll container, so it
 * already works with a trackpad, shift+wheel, touch, and the keyboard by tabbing
 * through the cards inside it. This adds pointer affordances for people who have
 * none of those to hand — and removes them again when the rail is not overflowing,
 * because an arrow that scrolls nothing is worse than no arrow.
 */
export default class extends Controller {
    static targets = ['track', 'prev', 'next'];

    connect() {
        this._sync = this.sync.bind(this);
        this.trackTarget.addEventListener('scroll', this._sync, { passive: true });

        // Card count changes with viewport width, so overflow has to be re-checked
        // rather than decided once on load.
        this._observer = new ResizeObserver(this._sync);
        this._observer.observe(this.trackTarget);

        this.sync();
    }

    disconnect() {
        this.trackTarget.removeEventListener('scroll', this._sync);
        this._observer?.disconnect();
    }

    prev() {
        this.scrollBy(-1);
    }

    next() {
        this.scrollBy(1);
    }

    scrollBy(direction) {
        // Roughly one screen of cards, minus a sliver so the card at the edge stays
        // partly visible — that overlap is what tells a reader the rail continues.
        const step = Math.max(240, this.trackTarget.clientWidth * 0.8);

        this.trackTarget.scrollBy({
            left: step * direction,
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
    }

    sync() {
        const track = this.trackTarget;
        // A pixel of tolerance: sub-pixel layout means scrollLeft rarely reaches the
        // exact maximum, which would leave the next arrow enabled at the end forever.
        //
        // The start needs more than a pixel. The track carries left padding so focus
        // rings are not clipped, and the first item's snap point sits after it — a
        // rail scrolled fully left reports that padding, measured here as 4, never 0.
        // Reading the computed value beats hardcoding it, since the padding comes
        // from the spacing scale and moves with it.
        const startInset = parseFloat(getComputedStyle(track).paddingLeft) || 0;
        const atStart = track.scrollLeft <= startInset + 1;
        const atEnd = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;
        const overflows = track.scrollWidth > track.clientWidth + 1;

        this.prevTarget.hidden = !overflows || atStart;
        this.nextTarget.hidden = !overflows || atEnd;
    }
}
