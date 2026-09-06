import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['field'];

    field() {
        this.fieldTarget.focus();
    }
}
