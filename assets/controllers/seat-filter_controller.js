import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/* Client-side, instant: the seat list is already in the page, so filtering it is a matter of
   hiding rows rather than asking the server for a shorter list. Matches the name or the empire,
   both folded into the row's data-search when it was rendered. */
export default class extends Controller {
    static targets = ['query', 'seat', 'empty'];

    filter = () => {
        const needle = this.queryTarget.value.trim().toLowerCase();
        const matches = (seat) => !needle || seat.dataset.search.includes(needle);

        this.seatTargets.forEach((seat) => seat.toggleAttribute('hidden', !matches(seat)));
        this.emptyTarget.toggleAttribute('hidden', this.seatTargets.some(matches));
    };
}
