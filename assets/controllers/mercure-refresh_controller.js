import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

// REFACTOR-WHEN: 4+ mercure-refresh instances on one page → share a single multi-topic EventSource
export default class extends Controller {
    static values = {
        topic: String,
    }

    async connect() {
        this.disconnecting = false;
        this.component = await getComponent(this.element);

        if (this.disconnecting) {
            return;
        }

        this.eventSource = new EventSource('/.well-known/mercure?topic=' + encodeURIComponent(this.topicValue));
        this.eventSource.onmessage = () => this.component.render();
        this.eventSource.onopen = () => this.onOpen();
    }

    // The hub keeps no history, so signals sent while the connection was down are lost: a
    // reconnection has to resync. The first open needs nothing — the page was just rendered.
    onOpen() {
        if (this.wasOpen) {
            this.component.render();
        }

        this.wasOpen = true;
    }

    disconnect() {
        this.disconnecting = true;
        this.eventSource?.close();
    }
}
