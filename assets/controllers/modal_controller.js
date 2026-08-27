import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static targets = ['dialog'];

    connect() {
        if (this.element.open && !this.element.matches(':modal')) {
            this.element.removeAttribute('open');
            this.element.showModal();
        }
    }

    open = () => this.dialogTarget.showModal();

    close = () => this.dialogTarget.close();
}
