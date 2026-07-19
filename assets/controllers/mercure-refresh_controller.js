import { Controller } from '@hotwired/stimulus';
import { getComponent } from '@symfony/ux-live-component';

// REFACTOR-WHEN: 4+ mercure-refresh instances on one page → share a single multi-topic EventSource
export default class extends Controller {
    static values = {
        topic: String,
        events: Array,
    }

    async connect() {
        this.component = await getComponent(this.element);
        this.eventSource = new EventSource('/.well-known/mercure?topic=' + encodeURIComponent(this.topicValue));
        this.eventSource.onmessage = (event) => this.onMessage(event);
    }

    onMessage(event) {
        if (!this.hasEventsValue || this.eventsValue.length === 0) {
            this.component.render();

            return;
        }

        let name;
        try {
            name = JSON.parse(event.data).event;
        } catch {
            this.component.render();

            return;
        }

        if (this.eventsValue.includes(name)) {
            this.component.render();
        }
    }

    disconnect() {
        this.eventSource.close();
    }
}
