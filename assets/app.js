import './stimulus_bootstrap.js';
import './styles/app.css';

/*
 * Turbo gives the site SPA-like navigation without a client-side router: links
 * are fetched and swapped in place, while every page remains a real server-rendered
 * document with its own URL. That matters here — a directory has to stay indexable
 * and shareable, so the interaction quality cannot come at the cost of the URL.
 */
import '@hotwired/turbo';
