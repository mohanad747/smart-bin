<?php
// =====================================================================
// Operator dashboard - one page (usability requirements 1 and 2).
// The page itself is static; assets/dashboard.js fills it from
// api/status.php every 5 seconds without reloading.
// =====================================================================

require __DIR__ . '/lib/auth.php';
require_login();

$page_title = 'Smart Bin - Dashboard';
$active     = 'dash';
$extra_head = '<link rel="stylesheet" href="assets/leaflet/leaflet.css">';
require __DIR__ . '/lib/header.php';
?>
<main>
  <!-- Hazard banner: red FIRE / amber VAPOUR / calm green line -->
  <div id="banner"><div class="banner calm">Loading…</div></div>

  <!-- Summary tiles: the whole system in four numbers -->
  <section id="tiles" class="tiles"></section>

  <section class="grid-top">
    <div class="panel">
      <div class="panel-head">
        <h2>Bin map</h2>
        <span class="right small muted">
          <span class="chip ok">Below nearly full</span>
          <span class="chip nearly">Nearly full</span>
          <span class="chip full">Full</span>
          <span class="chip FIRE">🔥 Fire</span>
        </span>
      </div>
      <div id="map"></div>
      <div id="mapNote" class="map-note"></div>
    </div>

    <div class="panel">
      <div class="panel-head"><h2>Priority collection list</h2></div>
      <p class="small muted" style="margin-top:-6px">Soonest to be full first, then by fill level.</p>
      <ol id="priority" class="priority"></ol>
    </div>
  </section>

  <!-- With 1 or 2 bins the cards sit beside the charts (class "few", set by dashboard.js) -->
  <div id="mid">
  <section class="panel">
    <div class="panel-head">
      <h2>Bins</h2>
      <span class="right status-line" id="statusLine">Connecting…</span>
    </div>
    <div id="cards" class="cards"></div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Last 6 hours: <span id="chartBin">-</span></h2>
      <span class="right small muted">Click a bin card, map marker or list row to change</span></div>
    <div class="charts">
      <div><h3 class="small muted">Fill level (%)</h3><div class="chart-box"><canvas id="fillChart"></canvas></div></div>
      <div><h3 class="small muted">Temperature (°C)</h3><div class="chart-box"><canvas id="tempChart"></canvas></div></div>
    </div>
  </section>
  </div>

  <section class="panel">
    <div class="panel-head">
      <h2>Event log</h2>
      <label class="right" style="margin:0; min-width:180px">
        <select id="eventFilter"><option value="">All bins</option></select>
      </label>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr>
          <th>Type</th><th>Bin</th><th>Started</th><th>Ended</th><th>Duration</th>
          <th>Peak temp</th><th>Peak gas</th><th>Alert</th><th>Acknowledged</th><th>Note</th>
        </tr></thead>
        <tbody id="events"><tr class="empty"><td colspan="10">Loading…</td></tr></tbody>
      </table>
    </div>
  </section>
</main>

<script src="assets/chart.umd.min.js"></script>
<script src="assets/leaflet/leaflet.js"></script>
<script src="assets/dashboard.js?v=<?= filemtime(__DIR__ . '/assets/dashboard.js') ?>"></script>
</body>
</html>
