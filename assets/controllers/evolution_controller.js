import { Controller } from '@hotwired/stimulus';

// The HTML legend replaces Chart.js's built-in one (see evolution.html.twig), but click-to-toggle
// a dataset's visibility is the only way to read one empire's curve on a 14-player chart — so this
// controller keeps that behaviour, driven by the chart instance the @symfony/ux-chartjs/chart
// controller hands over on its `chartjs:connect` event (bubbles up from the canvas).
export default class extends Controller {
    onChartConnect({ detail: { chart } }) {
        this.chart = chart;
    }

    toggle({ params: { index }, currentTarget }) {
        if (!this.chart) {
            return;
        }

        const nowVisible = !this.chart.isDatasetVisible(index);
        this.chart.setDatasetVisibility(index, nowVisible);
        this.chart.update();
        currentTarget.toggleAttribute('data-hidden', !nowVisible);
    }
}
