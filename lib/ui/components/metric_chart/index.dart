// MetricChart component — folder-local barrel.
//
// Canonical atomic-component shape: the recipe, the component, and the
// preview each live in their own dotted-suffix file; this index re-exports
// the public surface (component + chrome recipe + tone -> Color resolver).
// The preview is intentionally NOT re-exported here — `previews:refresh`
// discovers `*.preview.dart` files directly, and the preview must stay out of
// the release barrel.

export 'metric_chart.dart' show MetricChart, metricChartBarIsSeries;
export 'metric_chart.recipe.dart'
    show
        metricChartRecipe,
        metricChartToneColor,
        metricChartAnomalyColor,
        metricChartAxisColor,
        metricChartBorderColor,
        metricChartSurfaceColor;
