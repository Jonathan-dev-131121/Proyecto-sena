
<?php
session_start();

require_once 'seguridad/conexion.php';
require_once 'seguridad/funciones.php';
require_once 'conf.php';

// Comprobación de sesión y rol
if (!isset($_SESSION['usuario']) || ($_SESSION['tipo'] ?? '') !== 'operario') {
    header("Location: Inicio_de_sesion.html?error=acceso_denegado");
    exit;
}

$pdo = null;
try {
    $pdo = dbConnect(); // función proporcionada en seguridad/conexion.php
} catch (Exception $e) {
    // no DB available — el código seguirá en modo ficticio si procede
}

// Determinar modo únicamente a partir de la sesión/usuario/configuración
$modoFicticio = false;
if (isset($_SESSION['modo_ficticio'])) {
    $modoFicticio = (bool) $_SESSION['modo_ficticio'];
} elseif (isset($_SESSION['usuario']) && $_SESSION['usuario'] === 'ficticio') {
    $modoFicticio = true;
} elseif (defined('MODO_FICTICIO') && MODO_FICTICIO) {
    $modoFicticio = true;
}

// API endpoint: retorna JSON con sensor actual + historia, y acepta POST solo en modo ficticio
if (isset($_GET['api']) && ($_GET['api'] == '1')) {
    header('Content-Type: application/json; charset=utf-8');

    // clamp ayudante
    $clamp = function($v, $min, $max) { return max($min, min($max, $v)); };

    // POST: guardar lectura (solo en modo ficticio, en sesión)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // leer JSON body
        $raw = json_decode(file_get_contents('php://input'), true);
        $nivel = isset($raw['nivel']) ? $raw['nivel'] : null;
        $caudal = isset($raw['caudal']) ? $raw['caudal'] : null;
        $ph = isset($raw['ph']) ? $raw['ph'] : null;

        if ($modoFicticio) {
            $errors = [];
            if ($nivel === null || !is_numeric($nivel)) $errors[] = 'nivel inválido';
            if ($caudal === null || !is_numeric($caudal)) $errors[] = 'caudal inválido';
            if ($ph === null || !is_numeric($ph)) $errors[] = 'ph inválido';

            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'errors' => $errors]);
                exit;
            }

            $nivel = (float)$nivel;
            $caudal = (float)$caudal;
            $ph = (float)$ph;

            $nivel = $clamp($nivel, 0.0, 120.0);
            $caudal = $clamp($caudal, 0.0, 5000.0);
            $ph = round($clamp($ph, 0.0, 14.0), 2);

            if (!isset($_SESSION['sim_sensores'])) {
                $_SESSION['sim_sensores'] = ['nivel' => 0.0, 'caudal' => 0.0, 'ph' => 7.0];
            }
            if (!isset($_SESSION['sim_historia'])) {
                $_SESSION['sim_historia'] = [];
            }

            $_SESSION['sim_sensores']['nivel'] = $nivel;
            $_SESSION['sim_sensores']['caudal'] = $caudal;
            $_SESSION['sim_sensores']['ph'] = $ph;

            $now = date('Y-m-d H:i:s');
            $_SESSION['sim_historia'][] = [
                'ts' => $now,
                'nivel' => $nivel,
                'caudal' => $caudal,
                'ph' => $ph
            ];
            if (count($_SESSION['sim_historia']) > 200) {
                array_splice($_SESSION['sim_historia'], 0, count($_SESSION['sim_historia']) - 200);
            }

            $hist = array_slice($_SESSION['sim_historia'], -12);

            echo json_encode([
                'success' => true,
                'modo' => 'ficticio',
                'sensor' => $_SESSION['sim_sensores'],
                'historia' => $hist
            ]);
            exit;
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'escritura_no_permitida_en_modo_real']);
            exit;
        }
    }

    // GET: devolver datos (si se pide full=1 devuelve el historial completo / limitado)
    $wantFull = (isset($_GET['full']) && $_GET['full'] == '1');

    if ($modoFicticio) {
        if (!isset($_SESSION['sim_sensores'])) {
            $_SESSION['sim_sensores'] = ['nivel' => 75.0, 'caudal' => 400.0, 'ph' => 7.0];
        }
        if (!isset($_SESSION['sim_historia'])) {
            $_SESSION['sim_historia'] = [];
            $now = time();
            $base = $_SESSION['sim_sensores'];
            for ($i = 11; $i >= 0; $i--) {
                $t = $now - ($i * 300);
                $_SESSION['sim_historia'][] = [
                    'ts' => date('Y-m-d H:i:s', $t),
                    'nivel' => (float) max(0, min(120, $base['nivel'] + rand(-4, 4))),
                    'caudal' => (float) max(0, $base['caudal'] + rand(-30, 30)),
                    'ph' => (float) round(max(0, min(14, $base['ph'] + (rand(-10,10)/100))), 2)
                ];
            }
        }

        // optional small simulation on GET non-full
        if (!$wantFull) {
            $s = &$_SESSION['sim_sensores'];
            $s['nivel'] = $clamp($s['nivel'] + rand(-3,3), 0, 120);
            $s['caudal'] = $clamp($s['caudal'] + rand(-25,25), 0, 2000);
            $s['ph'] = round($clamp($s['ph'] + (rand(-20,20)/100), 0, 14), 2);

            $_SESSION['sim_historia'][] = [
                'ts' => date('Y-m-d H:i:s'),
                'nivel' => (float)$s['nivel'],
                'caudal' => (float)$s['caudal'],
                'ph' => (float)$s['ph']
            ];
            if (count($_SESSION['sim_historia']) > 48) array_shift($_SESSION['sim_historia']);
        }

        $histAll = $_SESSION['sim_historia'];
        if ($wantFull) {
            echo json_encode([
                'modo' => 'ficticio',
                'sensor' => $_SESSION['sim_sensores'],
                'historia' => $histAll,
                'count' => count($histAll)
            ]);
        } else {
            $hist = array_slice($histAll, -12);
            echo json_encode([
                'modo' => 'ficticio',
                'sensor' => $_SESSION['sim_sensores'],
                'historia' => $hist
            ]);
        }
        exit;
    } else {
        // modo real
        $result = [
            'modo' => 'real',
            'sensor' => ['nivel'=>0,'caudal'=>0,'ph'=>0,'ts'=>date('Y-m-d H:i:s')],
            'historia' => []
        ];

        if ($pdo instanceof PDO) {
            $tablesToTry = ['lecturas','sensores','sensor_lecturas'];
            // when full requested allow larger limit but protect with cap
            $N = $wantFull ? 1000 : 12;
            foreach ($tablesToTry as $t) {
                try {
                    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = :t LIMIT 1");
                    $stmt->execute([':t' => $t]);
                    if ($stmt->fetchColumn() === false) continue;

                    $sql = "SELECT nivel, caudal, ph, COALESCE(timestamp, ts, created_at, now()) AS ts
                            FROM {$t} ORDER BY id DESC LIMIT :n";
                    $q = $pdo->prepare($sql);
                    $q->bindValue(':n', (int)$N, PDO::PARAM_INT);
                    $q->execute();
                    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                    if ($rows && count($rows) > 0) {
                        $rows = array_reverse($rows);
                        foreach ($rows as $r) {
                            $result['historia'][] = [
                                'ts' => $r['ts'] ?? date('Y-m-d H:i:s'),
                                'nivel' => isset($r['nivel']) ? (float)$r['nivel'] : 0,
                                'caudal' => isset($r['caudal']) ? (float)$r['caudal'] : 0,
                                'ph' => isset($r['ph']) ? (float)$r['ph'] : 0
                            ];
                        }
                        $last = end($rows);
                        $result['sensor'] = [
                            'nivel' => isset($last['nivel']) ? (float)$last['nivel'] : 0,
                            'caudal' => isset($last['caudal']) ? (float)$last['caudal'] : 0,
                            'ph' => isset($last['ph']) ? (float)$last['ph'] : 0,
                            'ts' => $last['ts'] ?? date('Y-m-d H:i:s')
                        ];
                        break;
                    }
                } catch (Exception $e) {
                    // ignorar y probar siguiente tabla
                }
            }
        }

        // append count metadata if full
        if ($wantFull) {
            echo json_encode(array_merge($result, ['count' => count($result['historia'])]));
        } else {
            echo json_encode($result);
        }
        exit;
    }
}

// Si no es una solicitud API, renderizar la página HTML a continuación
$initialLabels = [];
$initialNivel = [];
$initialCaudal = [];
$initialPh = [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Panel Operario — Planta</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <link href="css/comun.css" rel="stylesheet">
    <link href="css/estilos.css" rel="stylesheet">
    <style>
        .card-subtitle { font-size: .85rem; }
        .badge { min-width: 72px; text-align:center; }
        .table-fixed { max-height: 60vh; overflow:auto; display:block; }
    </style>
</head>
<body class="bg-light operario-page has-hero">
<div class="container-fluid operario-wrap">
    <div class="container operario-inner">
    <div class="operario-top d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <i class="bi bi-droplet-fill text-info" style="font-size:28px"></i>
            <div class="ms-2">
                <div class="operario-title mb-0">Panel Operario</div>
                <div class="small text-muted"><?php echo htmlspecialchars($_SESSION['usuario'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>

        <div class="d-flex align-items-center">
            <span id="modoBadge" class="me-3 badge <?php echo $modoFicticio ? 'status-att text-dark' : 'status-ok text-white'; ?>">
                <?php echo $modoFicticio ? 'MODO FICTICIO' : 'MODO REAL (BD)'; ?>
            </span>
            <a href="cerrar_sesion.php" class="btn btn-outline-primary btn-sm ms-2"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
        </div>
    </div>

    <div class="row g-3 mt-3">
        <div class="col-12 col-md-6 col-lg-3">
            <div class="card glass h-100">
                        <div class="card-body metric-nivel">
                            <h6 class="card-title">Nivel de tanque</h6>
                            <div class="metric-row">
                                <div class="metric-icon">
                                    <!-- droplet icon -->
                                    <svg viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M8 .5s5 4.5 5 8.5A5 5 0 0 1 3 9C3 5 8 .5 8 .5z"/></svg>
                                </div>
                                <div id="nivelValue" class="display-6">--%</div>
                            </div>
                            <p class="card-text text-muted">Lectura en tiempo real</p>
                        </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-lg-3">
            <div class="card glass h-100">
                <div class="card-body metric-caudal">
                    <h6 class="card-title">Caudal entrada</h6>
                    <div class="metric-row">
                        <div class="metric-icon">
                            <!-- pipe icon -->
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M3 12h4v2H3v-2zm6-6h8v2H9V6zm0 6h10v2H9v-2zM3 6h4v2H3V6z"/></svg>
                        </div>
                        <div id="caudalValue" class="display-6">-- m³/h</div>
                    </div>
                    <p class="card-text text-muted">m³ por hora</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-lg-3">
            <div class="card glass h-100">
                <div class="card-body metric-ph">
                    <h6 class="card-title">pH</h6>
                    <div class="metric-row">
                        <div class="metric-icon">
                            <!-- flask icon -->
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M7 2v2h10V2H7zm6 14v4H11v-4H7l5-8 5 8h-4z"/></svg>
                        </div>
                        <div id="phValue" class="display-6">--</div>
                    </div>
                    <p class="card-text text-muted">Estado químico</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-lg-3">
            <div class="card glass h-100">
                <div class="card-body d-flex flex-column">
                    <h6 class="card-title">Acciones</h6>
                    <div class="mt-auto">
                        <button id="btnRegistro" class="btn btn-primary w-100 mb-2" data-bs-toggle="modal" data-bs-target="#modalRegistro"><i class="bi bi-journal-plus"></i> Registrar</button>
                        <button id="btnHistFull" class="btn btn-outline-secondary w-100" data-bs-toggle="modal" data-bs-target="#modalHistFull"><i class="bi bi-clock-history"></i> Histórico</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel: chart + estado equipos -->
    <div class="row mt-3">
        <div class="col-lg-8">
            <div class="chart-card">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0">Gráfica / Histórico</h5>
                    <div class="small text-muted">Últimas lecturas</div>
                </div>
                <div>
                    <canvas id="chartFlow" height="140"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="status-card">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Estado por equipos</h6>
                    <small class="text-muted">Indicadores</small>
                </div>
                <div class="status-list">
                        <div class="status-item d-flex justify-content-between align-items-center">
                            <div>Bomba 1</div>
                            <div><span id="bombaBadge" class="badge status-ok">—</span></div>
                        </div>
                        <div class="status-item d-flex justify-content-between align-items-center">
                            <div>Filtro A</div>
                            <div><span id="filtroBadge" class="badge status-ok">—</span></div>
                        </div>
                        <div class="status-item d-flex justify-content-between align-items-center">
                            <div>Sensor pH</div>
                            <div><span id="phBadge" class="badge status-ok">—</span></div>
                        </div>
                </div>

                <div class="small text-muted mt-3">
                    Lectura — Nivel: <span id="nivelText">--</span>% · Caudal: <span id="caudalText">--</span> m³/h · pH: <span id="phText">--</span>
                </div>
            </div>
        </div>
    </div>
    </div>
</div>

<!-- Modal: registro manual (se guarda en sesión si modo ficticio) -->
<div class="modal fade" id="modalRegistro" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form id="registroForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Registrar lectura</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
          <div class="mb-2">
              <label class="form-label small">Nivel (%)</label>
              <input id="mNivel" name="nivel" type="number" step="0.1" min="0" max="120" class="form-control" required>
          </div>
          <div class="mb-2">
              <label class="form-label small">Caudal (m³/h)</label>
              <input id="mCaudal" name="caudal" type="number" step="0.1" min="0" max="5000" class="form-control" required>
          </div>
          <div class="mb-2">
              <label class="form-label small">pH</label>
              <input id="mPh" name="ph" type="number" step="0.01" min="0" max="14" class="form-control" required>
          </div>
          <div id="modalMsg" class="small text-muted" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button id="modalSaveBtn" type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: histórico completo -->
<div class="modal fade" id="modalHistFull" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Histórico completo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
          <div id="histNotice" class="mb-2 small text-muted"></div>
          <div class="table-responsive table-fixed">
            <table id="histTable" class="table table-sm table-striped mb-0">
                <thead>
                    <tr><th>Fecha / Hora</th><th>Nivel (%)</th><th>Caudal (m³/h)</th><th>pH</th></tr>
                </thead>
                <tbody></tbody>
            </table>
          </div>
      </div>
      <div class="modal-footer">
        <button id="downloadCsv" type="button" class="btn btn-outline-secondary btn-sm">Descargar CSV</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const API = 'operario.php?api=1';
    const POLL_MS = 5000;

    // Chart init
    const ctx = document.getElementById('chartFlow').getContext('2d');
    window.chartFlow = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($initialLabels); ?>,
            datasets: [
                { label: 'Nivel (%)', data: <?php echo json_encode($initialNivel); ?>, borderColor: 'rgba(14,165,164,0.9)', backgroundColor: 'rgba(14,165,164,0.12)', yAxisID: 'y' },
                { label: 'Caudal (m³/h)', data: <?php echo json_encode($initialCaudal); ?>, borderColor: 'rgba(6,182,212,0.9)', backgroundColor: 'rgba(6,182,212,0.08)', yAxisID: 'y1' },
                { label: 'pH', data: <?php echo json_encode($initialPh); ?>, borderColor: 'rgba(249,115,22,0.95)', backgroundColor: 'rgba(249,115,22,0.06)', yAxisID: 'y2' }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { display: true },
                y: { type: 'linear', position: 'left', min: 0, max: 100, title: { display: true, text: 'Nivel (%)' } },
                y1: { type: 'linear', position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Caudal (m³/h)' } },
                y2: { display: false }
            },
            plugins: { legend: { position: 'top' } }
        }
    });

    function setBadge(id, label, classes) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = label;
        el.className = 'badge ' + classes;
    }

    function evaluateBadges(sensor, history) {
        const nivel = parseFloat(sensor.nivel ?? 0);
        const caudal = parseFloat(sensor.caudal ?? 0);
        const ph = parseFloat(sensor.ph ?? 0);

        let bombaLabel, bombaClass;
        if (caudal > 720) {
            bombaLabel = 'Alarma'; bombaClass = 'status-alarm';
        } else if (nivel > 100) {
            bombaLabel = 'Alarma'; bombaClass = 'status-alarm';
        } else if (nivel >= 80) {
            bombaLabel = 'Atención'; bombaClass = 'status-att';
        } else {
            if (nivel < 10 || caudal < 20) {
                bombaLabel = 'Alarma'; bombaClass = 'status-alarm';
            } else {
                bombaLabel = 'OK'; bombaClass = 'status-ok';
            }
        }

        const DROP_ALARMA = 25, DROP_ATENCION = 8, CAUDAL_BAJO = 50;
    let filtroLabel = 'OK', filtroClass = 'status-ok';
        if (Array.isArray(history) && history.length >= 2) {
            const last = history[history.length - 1];
            const prev = history[history.length - 2];
            const prevCaudal = parseFloat(prev.caudal ?? 0);
            const lastCaudal = parseFloat(last.caudal ?? 0);
            if (prevCaudal > 0) {
                const drop = ((prevCaudal - lastCaudal) / prevCaudal) * 100;
                if (drop > DROP_ALARMA) { filtroLabel = 'Alarma'; filtroClass = 'status-alarm'; }
                else if (drop > DROP_ATENCION) { filtroLabel = 'Atención'; filtroClass = 'status-att'; }
            } else {
                if (lastCaudal < CAUDAL_BAJO) { filtroLabel = 'Atención'; filtroClass = 'status-att'; }
            }
        } else {
            if (caudal < CAUDAL_BAJO) { filtroLabel = 'Atención'; filtroClass = 'status-att'; }
        }

        let phLabel, phClass;
    if (ph < 6.5 || ph > 8.5) { phLabel = 'Alarma'; phClass = 'status-alarm'; }
    else if (ph < 6.8 || ph > 7.6) { phLabel = 'Atención'; phClass = 'status-att'; }
    else { phLabel = 'OK'; phClass = 'status-ok'; }

        setBadge('bombaBadge', bombaLabel, bombaClass);
        setBadge('filtroBadge', filtroLabel, filtroClass);
        setBadge('phBadge', phLabel, phClass);

        const nt = document.getElementById('nivelText');
        const ct = document.getElementById('caudalText');
        const pt = document.getElementById('phText');
        if (nt) nt.textContent = isNaN(nivel) ? '--' : nivel;
        if (ct) ct.textContent = isNaN(caudal) ? '--' : caudal;
        if (pt) pt.textContent = isNaN(ph) ? '--' : ph;
    }

    // Small helper to update UI classes (mode badge and value alarms)
    function applyUiStates(sensor) {
        const modoEl = document.getElementById('modoBadge');
        if (modoEl) {
            // use explicit classes for CSS file
            if (<?php echo $modoFicticio ? 'true' : 'false'; ?>) {
                modoEl.classList.remove('bg-real');
                modoEl.classList.add('bg-ficticio');
            } else {
                modoEl.classList.remove('bg-ficticio');
                modoEl.classList.add('bg-real');
            }
        }

        // set alarm class based on evaluateBadges decisions (simple thresholds)
        const nivel = parseFloat(sensor.nivel ?? 0);
        const caudal = parseFloat(sensor.caudal ?? 0);
        const ph = parseFloat(sensor.ph ?? 0);

        const nv = document.getElementById('nivelValue');
        const cv = document.getElementById('caudalValue');
        const pv = document.getElementById('phValue');

        // clear
        [nv, cv, pv].forEach(el=>{ if(el){ el.classList.remove('alarm-active'); el.classList.remove('live-warn'); el.classList.remove('live-alarm'); el.classList.remove('live-ok'); }});

        if (nv) {
            if (nivel > 100 || nivel < 10) { nv.classList.add('alarm-active','live-alarm'); }
            else if (nivel >= 80) { nv.classList.add('live-warn'); }
            else { nv.classList.add('live-ok'); }
        }
        if (cv) {
            if (caudal > 720 || caudal < 20) { cv.classList.add('alarm-active','live-alarm'); }
            else if (caudal < 50) { cv.classList.add('live-warn'); }
            else { cv.classList.add('live-ok'); }
        }
        if (pv) {
            if (ph < 6.5 || ph > 8.5) { pv.classList.add('alarm-active','live-alarm'); }
            else if (ph < 6.8 || ph > 7.6) { pv.classList.add('live-warn'); }
            else { pv.classList.add('live-ok'); }
        }
    }

    // Cargar datos una vez (o con polling en modo ficticio)
    async function fetchDataOnce() {
        try {
            const res = await fetch(API, { cache: 'no-store' });
            if (!res.ok) return;
            const data = await res.json();
            if (data.error) return;

            const s = data.sensor || {};
            const h = data.historia || [];

            document.getElementById('nivelValue').textContent = (s.nivel ?? '--') + '%';
            document.getElementById('caudalValue').textContent = (s.caudal ?? '--') + ' m³/h';
            document.getElementById('phValue').textContent = (s.ph ?? '--');

            const labels = h.map(r => r.ts ? new Date(r.ts).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) : '');
            const niv = h.map(r => r.nivel ?? 0);
            const cau = h.map(r => r.caudal ?? 0);
            const phs = h.map(r => r.ph ?? 0);

            if (window.chartFlow) {
                window.chartFlow.data.labels = labels;
                window.chartFlow.data.datasets[0].data = niv;
                window.chartFlow.data.datasets[1].data = cau;
                window.chartFlow.data.datasets[2].data = phs;
                window.chartFlow.update();
            }

            evaluateBadges(s, h);
        } catch (e) {
            console.debug(e);
        }
    }

    <?php if ($modoFicticio): ?>
    fetchDataOnce();
    setInterval(fetchDataOnce, POLL_MS);
    <?php else: ?>
    fetchDataOnce();
    <?php endif; ?>

    // Enviar lectura desde modal; solo se guardará si servidor está en modo ficticio
    document.getElementById('registroForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('modalSaveBtn');
        const msg = document.getElementById('modalMsg');
        btn.disabled = true;
        msg.style.display = 'block';
        msg.textContent = 'Guardando...';

        const nivel = parseFloat(document.getElementById('mNivel').value);
        const caudal = parseFloat(document.getElementById('mCaudal').value);
        const ph = parseFloat(document.getElementById('mPh').value);

        try {
            const res = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ nivel, caudal, ph })
            });
            const data = await res.json();
            if (res.ok && data && data.success) {
                const s = data.sensor || {};
                const h = data.historia || [];
                if (s && Object.keys(s).length) {
                    document.getElementById('nivelValue').textContent = (s.nivel ?? '--') + '%';
                    document.getElementById('caudalValue').textContent = (s.caudal ?? '--') + ' m³/h';
                    document.getElementById('phValue').textContent = (s.ph ?? '--');
                    evaluateBadges(s, h);
                }
                if (h.length && window.chartFlow) {
                    const labels = h.map(r => r.ts ? new Date(r.ts).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) : '');
                    const niv = h.map(r => r.nivel ?? 0);
                    const cau = h.map(r => r.caudal ?? 0);
                    const phs = h.map(r => r.ph ?? 0);
                    window.chartFlow.data.labels = labels;
                    window.chartFlow.data.datasets[0].data = niv;
                    window.chartFlow.data.datasets[1].data = cau;
                    window.chartFlow.data.datasets[2].data = phs;
                    window.chartFlow.update();
                }
                msg.textContent = 'Guardado.';
            } else {
                msg.textContent = (data && data.error) ? data.error : 'Error al guardar.';
            }
        } catch (err) {
            msg.textContent = 'Error de red.';
        }

        btn.disabled = false;
        setTimeout(()=>{ msg.style.display='none'; }, 2000);
        const modalEl = document.getElementById('modalRegistro');
        const modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
    });

    // Histórico completo: cargar al abrir modal
    const modalHistEl = document.getElementById('modalHistFull');
    modalHistEl.addEventListener('show.bs.modal', async function () {
        const tbody = document.querySelector('#histTable tbody');
        const notice = document.getElementById('histNotice');
        tbody.innerHTML = '<tr><td colspan="4">Cargando...</td></tr>';
        notice.textContent = '';

        try {
            const res = await fetch(API + '&full=1', { cache: 'no-store' });
            if (!res.ok) {
                tbody.innerHTML = '<tr><td colspan="4">Error al cargar histórico.</td></tr>';
                return;
            }
            const data = await res.json();
            const h = data.historia || [];
            const count = data.count ?? h.length;
            if (count === 0) {
                tbody.innerHTML = '<tr><td colspan="4">No hay registros.</td></tr>';
                notice.textContent = 'Modo: ' + (data.modo ?? 'desconocido');
                return;
            }
            // render rows
            const rows = h.map(it => {
                const ts = it.ts ?? '';
                const nivel = (it.nivel ?? '').toString();
                const caudal = (it.caudal ?? '').toString();
                const ph = (it.ph ?? '').toString();
                return `<tr><td>${ts}</td><td>${nivel}</td><td>${caudal}</td><td>${ph}</td></tr>`;
            }).join('');
            tbody.innerHTML = rows;
            notice.textContent = 'Modo: ' + (data.modo ?? 'desconocido') + ' · Registros: ' + count + (count > 1000 ? ' (mostrando hasta límite)' : '');
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="4">Error de red.</td></tr>';
        }
    });

    // Descargar CSV
    document.getElementById('downloadCsv').addEventListener('click', function () {
        const rows = Array.from(document.querySelectorAll('#histTable tbody tr'));
        if (!rows.length) return;
        const csv = ['"ts","nivel","caudal","ph"'].concat(rows.map(tr => {
            const cells = Array.from(tr.children).map(td => td.textContent.replace(/"/g,'""'));
            return '"' + cells.join('","') + '"';
        })).join('\r\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'historial_completo.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    });

})();
</script>
</body>
</html>