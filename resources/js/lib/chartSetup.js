import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PieController,
    PointElement,
    Tooltip,
} from 'chart.js';

let registered = false;

export function ensureChartJsRegistered() {
    if (registered) {
        return;
    }

    Chart.register(
        LineController,
        LineElement,
        PointElement,
        LinearScale,
        CategoryScale,
        Filler,
        Tooltip,
        Legend,
        BarController,
        BarElement,
        PieController,
        ArcElement,
    );

    registered = true;
}

export { Chart };
