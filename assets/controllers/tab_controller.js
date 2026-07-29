import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */

/* Every panel is already in the page, so switching tabs is a repaint, not a request. The links
   stay real links: without this controller they navigate to the same tab the server would have
   rendered, which is also what a middle-click or a shared URL still does. */
export default class extends Controller {
    static targets = ['link'];

    show = (event) => {
        const { panel } = event.params;

        event.preventDefault();
        this.element.dataset.tab = panel;
        this.linkTargets.forEach((link) => {
            /* 'page' is the token that means it; an empty aria-current reads as false. */
            if (link.dataset.tabPanelParam === panel) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });

        /* replace, not push: back leaves the dashboard rather than walking the tabs the
           operator flicked through while the phone went round the table. */
        const url = new URL(window.location.href);
        url.searchParams.set('tab', panel);
        window.history.replaceState(null, '', url);
    };
}
