// Ergebnisseite: Auswahl der Befragung, Kennzahlen und Diagramme (amCharts 4)

const resultsSelector = document.querySelector('[data-results-selector]');
if (resultsSelector) {
    resultsSelector.querySelector('select').addEventListener('change', () => resultsSelector.requestSubmit());
    resultsSelector.querySelector('button').hidden = true;
}

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Kennzahlen zählen beim Laden kurz hoch
document.querySelectorAll('[data-countup]').forEach((element) => {
    const target = Number(element.dataset.countup);
    if (reduceMotion || !Number.isFinite(target)) return;
    const suffix = element.dataset.suffix ?? '';
    const sign = element.hasAttribute('data-sign');
    const format = (value) => {
        const rounded = Math.round(value);
        return (rounded > 0 && sign ? '+' : rounded < 0 ? '−' : '') + Math.abs(rounded).toLocaleString('de-DE') + suffix;
    };
    const start = performance.now();
    const duration = 1200;
    const step = (now) => {
        const progress = Math.min(1, (now - start) / duration);
        const eased = 1 - Math.pow(1 - progress, 3);
        element.textContent = format(target * eased);
        if (progress < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
});

// Farbwelt: Bewertungen laufen von Korallrot über Sand zum myFeedback-Grün
const PALETTE = {
    bad: '#d2553f',
    low: '#e8894f',
    mid: '#e6c15c',
    high: '#93c048',
    good: '#3d7d0e',
    brand: '#346c09',
    brandLight: '#a9d27c',
    ink: '#192921',
    muted: '#647068',
};
const SENTIMENT = [PALETTE.bad, PALETTE.low, PALETTE.mid, PALETTE.high, PALETTE.good];

function mix(a, b, t) {
    const pa = a.match(/\w\w/g).map((h) => parseInt(h, 16));
    const pb = b.match(/\w\w/g).map((h) => parseInt(h, 16));
    return '#' + pa.map((v, i) => Math.round(v + (pb[i] - v) * t).toString(16).padStart(2, '0')).join('');
}

// Farbe für Stufe i von n (0 = schlecht, n-1 = gut)
function sentimentColor(i, n) {
    if (n <= 1) return PALETTE.brand;
    const position = (i / (n - 1)) * (SENTIMENT.length - 1);
    const lower = Math.floor(position);
    const upper = Math.min(SENTIMENT.length - 1, lower + 1);
    return mix(SENTIMENT[lower], SENTIMENT[upper], position - lower);
}

function npsColor(value) {
    return value <= 6 ? PALETTE.bad : value <= 8 ? PALETTE.mid : PALETTE.good;
}

if (window.am4core && window.am4charts) {
    // Lizenz-Einstellung wie in der bisherigen Anwendung
    am4core.options.commercialLicense = true;
    am4core.options.autoDispose = true;

    // Entspricht dem amCharts-Theme „animated“ (nicht als eigene Datei vorhanden)
    if (!reduceMotion) {
        const is = (object, name) => {
            const type = am4core.registry.registeredClasses[name];
            return type != null && object instanceof type;
        };
        am4core.useTheme((object) => {
            if (is(object, 'SpriteState')) object.transitionDuration = 400;
            if (is(object, 'Component')) {
                object.rangeChangeDuration = 500;
                object.interpolationDuration = 500;
                object.sequencedInterpolation = false;
            }
            if (is(object, 'Chart')) {
                object.defaultState.transitionDuration = 2000;
                object.hiddenState.transitionDuration = 1000;
            }
            if (is(object, 'Tooltip')) {
                object.animationDuration = 400;
                object.defaultState.transitionDuration = 400;
                object.hiddenState.transitionDuration = 400;
            }
            if (is(object, 'Series')) {
                object.defaultState.transitionDuration = 1000;
                object.hiddenState.transitionDuration = 700;
                object.hiddenState.properties.opacity = 1;
                object.showOnInit = true;
            }
            if (is(object, 'PercentSeries')) object.hiddenState.properties.opacity = 0;
            if (is(object, 'Slice') || is(object, 'Column')) {
                object.defaultState.transitionDuration = 700;
                object.hiddenState.transitionDuration = 1000;
                object.hiddenState.properties.opacity = 1;
            }
        });
    }

    const base = (chart, title) => {
        chart.fontFamily = getComputedStyle(document.body).fontFamily;
        chart.fontSize = 13;
        if (title) chart.readerTitle = title;
        if (window.am4lang_de_DE) chart.language.locale = am4lang_de_DE;
        chart.numberFormatter.numberFormat = '#,###.#';
        return chart;
    };
    const quietAxis = (axis) => {
        axis.renderer.grid.template.strokeOpacity = 0.07;
        axis.renderer.labels.template.fill = am4core.color(PALETTE.muted);
        return axis;
    };

    // Auswahlfragen: liegende Balken, nach Häufigkeit sortiert, Anteil direkt am Balken
    function bars(container, result) {
        const data = [...result.data].sort((a, b) => b.count - a.count)
            .map((item, index) => ({ ...item, pct: Math.round(item.percent), color: index === 0 ? PALETTE.brand : mix(PALETTE.brandLight, PALETTE.brand, 0.25) }));
        container.style.height = Math.max(180, data.length * 46 + 20) + 'px';
        const chart = base(am4core.create(container, am4charts.XYChart), result.title);
        chart.data = data;
        chart.padding(4, 48, 4, 0);
        const categories = chart.yAxes.push(new am4charts.CategoryAxis());
        categories.dataFields.category = 'label';
        categories.renderer.inversed = true;
        categories.renderer.grid.template.disabled = true;
        categories.renderer.minGridDistance = 10;
        categories.renderer.labels.template.fill = am4core.color(PALETTE.ink);
        categories.renderer.labels.template.wrap = true;
        categories.renderer.labels.template.maxWidth = Math.min(240, container.clientWidth * 0.42);
        categories.renderer.labels.template.textAlign = 'end';
        const values = chart.xAxes.push(new am4charts.ValueAxis());
        values.min = 0;
        values.max = Math.max(...data.map((d) => d.percent), 1) * 1.15;
        values.strictMinMax = true;
        values.renderer.labels.template.disabled = true;
        values.renderer.grid.template.disabled = true;
        const series = chart.series.push(new am4charts.ColumnSeries());
        series.dataFields.categoryY = 'label';
        series.dataFields.valueX = 'percent';
        series.columns.template.propertyFields.fill = 'color';
        series.columns.template.strokeOpacity = 0;
        series.columns.template.height = am4core.percent(68);
        series.columns.template.column.cornerRadius(0, 6, 0, 6);
        series.columns.template.tooltipText = '{categoryY}: [bold]{count}[/] Antworten';
        series.sequencedInterpolation = true;
        const label = series.bullets.push(new am4charts.LabelBullet());
        label.label.text = '{pct} %';
        label.label.horizontalCenter = 'left';
        label.label.dx = 8;
        label.label.fontWeight = '700';
        label.label.fill = am4core.color(PALETTE.ink);
        label.label.truncate = false;
        label.label.hideOversized = false;
    }

    // Skalen (Sterne, Aufwand, NPS 0–10): Säulen in den Bewertungsfarben
    function columns(container, result) {
        const n = result.data.length;
        const data = result.data.map((item, index) => ({
            ...item,
            color: result.metric === 'nps' ? npsColor(Number(item.numeric)) : sentimentColor(index, n),
        }));
        container.style.height = '240px';
        const chart = base(am4core.create(container, am4charts.XYChart), result.title);
        chart.data = data;
        chart.padding(18, 8, 0, 0);
        const categories = chart.xAxes.push(new am4charts.CategoryAxis());
        categories.dataFields.category = 'label';
        categories.renderer.grid.template.disabled = true;
        categories.renderer.minGridDistance = 10;
        categories.renderer.labels.template.fill = am4core.color(PALETTE.ink);
        const values = quietAxis(chart.yAxes.push(new am4charts.ValueAxis()));
        values.min = 0;
        values.maxPrecision = 0;
        values.extraMax = 0.15;
        values.renderer.labels.template.disabled = true;
        const series = chart.series.push(new am4charts.ColumnSeries());
        series.dataFields.categoryX = 'label';
        series.dataFields.valueY = 'count';
        series.columns.template.propertyFields.fill = 'color';
        series.columns.template.strokeOpacity = 0;
        series.columns.template.width = am4core.percent(72);
        series.columns.template.column.cornerRadius(6, 6, 0, 0);
        series.columns.template.tooltipText = '{categoryX}: [bold]{valueY}[/] Antworten ({percent.formatNumber("#.0")} %)';
        series.sequencedInterpolation = true;
        const label = series.bullets.push(new am4charts.LabelBullet());
        label.label.text = '{valueY}';
        label.label.dy = -10;
        label.label.fontSize = 12;
        label.label.fill = am4core.color(PALETTE.muted);
        label.label.hideOversized = false;
        label.label.truncate = false;
    }

    // Sterne: Ring mit Durchschnitt in der Mitte
    function donut(container, result) {
        container.style.height = '230px';
        const chart = base(am4core.create(container, am4charts.PieChart), 'Verteilung der Sterne');
        const n = result.data.length;
        chart.data = result.data.map((item, index) => ({
            label: item.label + (item.label === '1' ? ' Stern' : ' Sterne'),
            count: item.count,
            color: sentimentColor(index, n),
        })).reverse();
        chart.innerRadius = am4core.percent(62);
        const series = chart.series.push(new am4charts.PieSeries());
        series.dataFields.value = 'count';
        series.dataFields.category = 'label';
        series.slices.template.propertyFields.fill = 'color';
        series.slices.template.stroke = am4core.color('#fff');
        series.slices.template.strokeWidth = 2;
        series.slices.template.tooltipText = '{category}: [bold]{value}[/] ({value.percent.formatNumber("#")} %)';
        series.labels.template.disabled = true;
        series.ticks.template.disabled = true;
        series.hiddenState.properties.startAngle = -90;
        series.hiddenState.properties.endAngle = -90;
        const center = chart.seriesContainer.createChild(am4core.Label);
        center.text = '[bold font-size:28px]' + result.center + '[/]\n[font-size:12px]' + '★ Durchschnitt';
        center.textAlign = 'middle';
        center.horizontalCenter = 'middle';
        center.verticalCenter = 'middle';
        center.fill = am4core.color(PALETTE.ink);
    }

    // NPS: Tacho von −100 bis +100
    function gauge(container, result) {
        container.style.height = '230px';
        const chart = base(am4core.create(container, am4charts.GaugeChart), 'Net Promoter Score');
        chart.innerRadius = am4core.percent(78);
        chart.startAngle = 180;
        chart.endAngle = 360;
        chart.padding(28, 24, 0, 24);
        const axis = chart.xAxes.push(new am4charts.ValueAxis());
        axis.min = -100;
        axis.max = 100;
        axis.strictMinMax = true;
        axis.renderer.minGridDistance = 50;
        axis.renderer.grid.template.disabled = true;
        axis.renderer.ticks.template.disabled = true;
        axis.renderer.labels.template.radius = 22;
        axis.renderer.labels.template.fontSize = 11;
        axis.renderer.labels.template.fill = am4core.color(PALETTE.muted);
        [[-100, 0, PALETTE.bad], [0, 30, PALETTE.mid], [30, 70, PALETTE.high], [70, 100, PALETTE.good]].forEach(([from, to, color]) => {
            const range = axis.axisRanges.create();
            range.value = from;
            range.endValue = to;
            range.axisFill.fillOpacity = 1;
            range.axisFill.fill = am4core.color(color);
            range.axisFill.zIndex = -1;
            range.grid.disabled = true;
        });
        const hand = chart.hands.push(new am4charts.ClockHand());
        hand.fill = am4core.color(PALETTE.ink);
        hand.stroke = am4core.color(PALETTE.ink);
        hand.pin.radius = 9;
        hand.startWidth = 8;
        hand.radius = am4core.percent(92);
        hand.value = reduceMotion ? result.value : -100;
        if (!reduceMotion) {
            chart.events.on('ready', () => hand.showValue(result.value, 1800, am4core.ease.elasticOut));
        }
    }

    // NPS: ein Balken Kritiker · Unentschieden · Befürworter
    function npsStack(container, result) {
        container.style.height = '100px';
        const chart = base(am4core.create(container, am4charts.XYChart), 'Kritiker, Unentschiedene und Befürworter');
        const total = Math.max(1, result.answered);
        chart.data = [{
            row: 'NPS',
            negative: 100 * result.groups.negative / total,
            neutral: 100 * result.groups.neutral / total,
            positive: 100 * result.groups.positive / total,
        }];
        chart.padding(0, 4, 0, 4);
        const categories = chart.yAxes.push(new am4charts.CategoryAxis());
        categories.dataFields.category = 'row';
        categories.renderer.labels.template.disabled = true;
        categories.renderer.grid.template.disabled = true;
        const values = chart.xAxes.push(new am4charts.ValueAxis());
        values.min = 0;
        values.max = 100;
        values.strictMinMax = true;
        values.renderer.labels.template.disabled = true;
        values.renderer.grid.template.disabled = true;
        chart.legend = new am4charts.Legend();
        chart.legend.position = 'bottom';
        chart.legend.contentAlign = 'left';
        chart.legend.markers.template.width = 12;
        chart.legend.markers.template.height = 12;
        chart.legend.labels.template.fontSize = 12;
        [
            ['negative', 'Kritiker (0–6)', PALETTE.bad, result.groups.negative],
            ['neutral', 'Unentschieden (7–8)', PALETTE.mid, result.groups.neutral],
            ['positive', 'Befürworter (9–10)', PALETTE.good, result.groups.positive],
        ].forEach(([field, name, color, count], index, all) => {
            const series = chart.series.push(new am4charts.ColumnSeries());
            series.name = name;
            series.dataFields.valueX = field;
            series.dataFields.categoryY = 'row';
            series.stacked = true;
            series.fill = am4core.color(color);
            series.stroke = am4core.color('#fff');
            series.strokeWidth = 2;
            series.columns.template.height = am4core.percent(100);
            series.columns.template.tooltipText = name + ': [bold]' + count + '[/] Antworten (' + Math.round(100 * count / total) + ' %)';
            if (index === 0) series.columns.template.column.cornerRadius(8, 0, 8, 0);
            if (index === all.length - 1) series.columns.template.column.cornerRadius(0, 8, 0, 8);
            const label = series.bullets.push(new am4charts.LabelBullet());
            label.locationX = 0.5;
            label.label.text = Math.round(100 * count / total) + ' %';
            label.label.fill = am4core.color(field === 'neutral' ? PALETTE.ink : '#fff');
            label.label.fontWeight = '700';
        });
    }

    // Antworten pro Tag
    function timeline(container, result) {
        container.style.height = '170px';
        const chart = base(am4core.create(container, am4charts.XYChart), 'Antworten pro Tag');
        chart.data = result.data.map((row) => ({ day: new Date(row.day + 'T12:00:00'), responses: row.responses }));
        chart.padding(10, 8, 0, 0);
        const dates = quietAxis(chart.xAxes.push(new am4charts.DateAxis()));
        dates.baseInterval = { timeUnit: 'day', count: 1 };
        dates.renderer.grid.template.disabled = true;
        dates.renderer.minGridDistance = 50;
        dates.dateFormats.setKey('day', 'dd.MM.');
        dates.periodChangeDateFormats.setKey('day', 'dd.MM.');
        if (chart.data.length === 1) {
            dates.min = chart.data[0].day.getTime() - 3 * 86400000;
            dates.max = chart.data[0].day.getTime() + 3 * 86400000;
        }
        const values = quietAxis(chart.yAxes.push(new am4charts.ValueAxis()));
        values.min = 0;
        values.maxPrecision = 0;
        values.renderer.minGridDistance = 30;
        const series = chart.series.push(new am4charts.ColumnSeries());
        series.dataFields.dateX = 'day';
        series.dataFields.valueY = 'responses';
        series.fill = am4core.color(PALETTE.brandLight);
        series.columns.template.strokeOpacity = 0;
        series.columns.template.width = am4core.percent(60);
        series.columns.template.column.cornerRadius(4, 4, 0, 0);
        series.columns.template.tooltipText = '{dateX.formatDate("dd.MM.yyyy")}: [bold]{valueY}[/] Antworten';
        series.sequencedInterpolation = true;
        // Laufende Summe als Linie
        let sum = 0;
        chart.data.forEach((row) => { row.total = (sum += row.responses); });
        const totals = chart.yAxes.push(new am4charts.ValueAxis());
        totals.min = 0;
        totals.renderer.opposite = true;
        totals.renderer.grid.template.disabled = true;
        totals.renderer.labels.template.fill = am4core.color(PALETTE.brand);
        totals.maxPrecision = 0;
        const line = chart.series.push(new am4charts.LineSeries());
        line.dataFields.dateX = 'day';
        line.dataFields.valueY = 'total';
        line.yAxis = totals;
        line.stroke = am4core.color(PALETTE.brand);
        line.strokeWidth = 2.5;
        line.tensionX = 0.85;
        line.tooltipText = 'Insgesamt: [bold]{valueY}[/]';
        const dot = line.bullets.push(new am4charts.CircleBullet());
        dot.circle.radius = 3.5;
        dot.circle.fill = am4core.color(PALETTE.brand);
        dot.circle.stroke = am4core.color('#fff');
        chart.cursor = new am4charts.XYCursor();
        chart.cursor.lineY.disabled = true;
        chart.cursor.behavior = 'none';
    }

    const builders = { bars, columns, donut, gauge, 'nps-stack': npsStack, timeline };

    document.querySelectorAll('[data-result-chart]').forEach((source) => {
        const container = document.getElementById(source.dataset.resultChart);
        try {
            const result = JSON.parse(source.textContent);
            const build = builders[result.kind];
            if (!container || !build) return;
            container.hidden = false;
            build(container, result);
        } catch (error) {
            if (container) container.hidden = true;
            console.warn('Die Grafik konnte nicht geladen werden. Die Werte stehen in der Ergebnistabelle.', error);
        }
    });
}
