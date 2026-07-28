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

    /* A dialog stacking several [data-key] panels opens on the one the trigger names, the rest
       staying a scroll away; a dialog with a single body ignores the param and opens as-is. */
    open = ({ params: { key } = {} }) => {
        this.dialogTarget.showModal();
        this.dialogTarget.querySelector(`[data-key="${key}"]`)?.scrollIntoView({ block: 'center' });
    };

    close = () => this.dialogTarget.close();
}
