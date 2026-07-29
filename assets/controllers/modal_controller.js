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

    /* One dialog holds every [data-key] panel, so their contents are rendered once rather than
       once per trigger — but it opens showing only the panel the trigger named. The rest are not
       a scroll away, they are not shown at all. A dialog with a single body opens as-is. */
    open = ({ params: { key } = {} }) => {
        const panels = [...this.dialogTarget.querySelectorAll('[data-key]')];

        panels.forEach((panel) => panel.toggleAttribute('data-current', panel.dataset.key === key));
        this.dialogTarget.toggleAttribute('data-scoped', undefined !== key && panels.length > 0);

        this.dialogTarget.showModal();
    };

    close = () => this.dialogTarget.close();

    /* Light dismiss, for a sheet whose own box is the scrim: a click that misses every card and
       every control landed on the scrim, so it means "away". Opt-in per dialog — a form in a
       modal must not close because someone missed a field. */
    dismiss = (event) => {
        if (!event.target.closest('figure, button')) {
            this.dialogTarget.close();
        }
    };
}
