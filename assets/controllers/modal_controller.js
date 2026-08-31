import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    connect() {
        if (this.element.open && !this.element.matches(':modal')) {
            this.element.removeAttribute('open');
            this.element.showModal();
        }
    }
}
