import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['dialog']

    initialize() {window.addEventListener('modal:close', () => this.dialogTarget.close());}

    open = () => this.dialogTarget.showModal();
    close = () => this.dialogTarget.close();
}
