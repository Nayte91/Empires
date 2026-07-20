import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/**
 * Promotes a server-rendered `<dialog open>` to the top layer. LiveComponents
 * can only render the `open` attribute (non-modal); showModal() brings native
 * centering, ::backdrop, Esc and `closedby` light-dismiss. The attribute is
 * removed first because, unlike close(), that fires no `close` event — so a
 * `close->live#action` wiring only reacts to real dismissals, not this upgrade.
 */
export default class extends Controller {
    connect() {
        if (this.element.open && !this.element.matches(':modal')) {
            this.element.removeAttribute('open');
            this.element.showModal();
        }
    }
}
