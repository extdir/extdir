/* stimulusFetch: "lazy" */
import { Controller } from '@hotwired/stimulus';

/*
 * Keyboard navigation for the results list.
 *
 *   /  or  ⌘K / Ctrl-K   focus the search field
 *   j / k  or  ↓ / ↑     move the selection down and up
 *   Enter                open the selected extension
 *   Escape               leave the field, or clear the selection
 *
 * The primary reader here is an agency developer comparing dozens of packages, for
 * whom reaching for the mouse to move one row down is the slow path. The bindings are
 * the ones that muscle memory already holds from GitHub, Gmail and every file
 * manager, so there is nothing to learn.
 *
 * Selection is deliberately not focus. Moving DOM focus through forty rows makes a
 * screen reader announce each one in full, which is unusable; instead the selected
 * row is marked with aria-selected inside a listbox and announced through a live
 * region, and Enter follows its link.
 */
export default class extends Controller {
    static targets = ['search', 'row'];

    connect() {
        this._index = -1;
        this._onKey = this.handleKey.bind(this);
        document.addEventListener('keydown', this._onKey);
    }

    disconnect() {
        document.removeEventListener('keydown', this._onKey);
    }

    handleKey(event) {
        const typing = this.isTypingContext(event.target);

        // ⌘K works while typing — it is how you get back to the field from anywhere.
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            this.focusSearch();
            return;
        }

        if (typing) {
            if (event.key === 'Escape') event.target.blur();
            return;
        }

        // A bare modifier combination belongs to the browser, not to us.
        if (event.altKey || event.metaKey || event.ctrlKey) return;

        switch (event.key) {
            case '/':
                event.preventDefault();
                this.focusSearch();
                break;
            case 'j':
            case 'ArrowDown':
                event.preventDefault();
                this.move(1);
                break;
            case 'k':
            case 'ArrowUp':
                event.preventDefault();
                this.move(-1);
                break;
            case 'Enter':
                this.open();
                break;
            case 'Escape':
                this.clear();
                break;
        }
    }

    /**
     * Anywhere a keystroke means a character rather than a command. Without this,
     * typing "javascript" into the search box would page the list around underneath.
     */
    isTypingContext(element) {
        if (!element) return false;
        const tag = element.tagName;

        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || element.isContentEditable;
    }

    focusSearch() {
        if (!this.hasSearchTarget) return;
        this.searchTarget.focus();
        this.searchTarget.select();
    }

    move(delta) {
        const rows = this.rowTargets;
        if (rows.length === 0) return;

        this._index = Math.max(0, Math.min(rows.length - 1, this._index + delta));
        this.render(rows);
    }

    render(rows) {
        rows.forEach((row, i) => {
            const selected = i === this._index;
            row.classList.toggle('is-selected', selected);
            row.setAttribute('aria-selected', selected ? 'true' : 'false');
        });

        const current = rows[this._index];
        if (!current) return;

        current.scrollIntoView({ block: 'nearest' });

        // Announced rather than focused, so a screen reader hears the name of the row
        // the selection moved to without the entire row being read out again.
        const title = current.querySelector('.row-title');
        if (title) this.announce(title.textContent.trim());
    }

    announce(message) {
        let region = document.getElementById('shortcut-status');

        if (!region) {
            region = document.createElement('div');
            region.id = 'shortcut-status';
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            region.className = 'visually-hidden';
            document.body.appendChild(region);
        }

        region.textContent = message;
    }

    open() {
        const current = this.rowTargets[this._index];
        if (!current) return;

        const link = current.querySelector('.row-title a');
        if (link) link.click();
    }

    clear() {
        this._index = -1;
        this.rowTargets.forEach((row) => {
            row.classList.remove('is-selected');
            row.setAttribute('aria-selected', 'false');
        });
    }
}
