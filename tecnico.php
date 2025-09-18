<?php
session_start();

require_once 'seguridad/conexion.php';
require_once 'seguridad/funciones.php';
require_once 'conf.php';

// definir helper JSON solo si no existe (evita redeclare)
if (!function_exists('json_out')) {
    function json_out($data) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }
}

// acceso solo técnico
if (!isset($_SESSION['usuario']) || ($_SESSION['tipo'] ?? '') !== 'tecnico') {
    header("Location: Inicio_de_sesion.html?error=acceso_denegado");
    exit;
}

// Conexión DB (puede quedar null y el código caerá a modo ficticio)
$pdo = null;
try {
    $pdo = dbConnect();
} catch (Exception $e) {
    $pdo = null;
}

// determinar modo solo por sesión/usuario/config
$modoFicticio = false;
if (isset($_SESSION['modo_ficticio'])) {
    $modoFicticio = (bool) $_SESSION['modo_ficticio'];
} elseif (isset($_SESSION['usuario']) && $_SESSION['usuario'] === 'ficticio') {
    $modoFicticio = true;
} elseif (defined('MODO_FICTICIO') && MODO_FICTICIO) {
    $modoFicticio = true;
}

// inicializar estructuras de sesión en modo ficticio
if ($modoFicticio) {
    if (!isset($_SESSION['tec_tickets'])) {
        $_SESSION['tec_tickets'] = [
            ['id'=>1,'user'=>'operario1','title'=>'Bomba 1 ruido','desc'=>'Ruido fuerte al arrancar','created'=>date('Y-m-d H:i:s',time()-3600*6),'resolved'=>false],
            ['id'=>2,'user'=>'operario2','title'=>'Fuga filtro A','desc'=>'Goteo en conexión','created'=>date('Y-m-d H:i:s',time()-3600*48),'resolved'=>true,'resolved_at'=>date('Y-m-d H:i:s',time()-3600*24)]
        ];
    }
    if (!isset($_SESSION['tec_failures'])) {
        $_SESSION['tec_failures'] = [
            ['ts'=>date('Y-m-d H:i:s',time()-86400*7),'type'=>'bomba','what'=>'Bomba 1 bloqueo','note'=>'Causa: sello','duration_min'=>120],
            ['ts'=>date('Y-m-d H:i:s',time()-86400*2),'type'=>'filtro','what'=>'Filtro A obstrucción','note'=>'Limpieza realizada','duration_min'=>45],
        ];
    }
    if (!isset($_SESSION['tec_chem'])) {
        // quantities in percentage (0..100)
        $_SESSION['tec_chem'] = [
            'Coagulantes'=>80,
            'Floculantes'=>55,
            'Desinfectantes'=>35,
            'Neutralizantes pH'=>25,
            'Antiespumantes'=>12,
            'Inhibidores corrosión'=>60
        ];
    }
}

// API: acciones para frontend
if (isset($_GET['api']) && $_GET['api'] == '1') {
    $action = $_GET['action'] ?? 'status';

    // listar tickets
    if ($action === 'tickets') {
        if ($modoFicticio) {
            json_out(['modo'=>'ficticio','tickets'=>$_SESSION['tec_tickets']]);
        } else {
            $out = ['modo'=>'real','tickets'=>[]];
            if ($pdo instanceof PDO) {
                try {
                    $stmt = $pdo->query("SELECT id, usuario AS user, titulo AS title, descripcion AS descr, creado AS created, resuelto AS resolved, resuelto_at AS resolved_at FROM tickets ORDER BY id DESC LIMIT 500");
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $r) {
                        $out['tickets'][] = [
                            'id'=> $r['id'],
                            'user'=> $r['user'],
                            'title'=> $r['title'],
                            'desc'=> $r['descr'],
                            'created'=> $r['created'],
                            'resolved'=> (bool)$r['resolved'],
                            'resolved_at'=> $r['resolved_at'] ?? null
                        ];
                    }
                } catch (Exception $e) { /* ignorar */ }
            }
            json_out($out);
        }
    }

    // marcar ticket resuelto (POST)
    if ($action === 'resolve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = $raw['id'] ?? null;
        $note = trim($raw['note'] ?? '');
        if ($id === null) json_out(['success'=>false,'error'=>'id_missing']);
        if ($modoFicticio) {
            foreach ($_SESSION['tec_tickets'] as &$t) {
                if ($t['id'] == $id) {
                    $t['resolved'] = true;
                    $t['resolved_at'] = date('Y-m-d H:i:s');
                    if ($note) $t['resolution_note'] = $note;
                    json_out(['success'=>true,'ticket'=>$t]);
                }
            }
            json_out(['success'=>false,'error'=>'ticket_not_found']);
        } else {
            if ($pdo instanceof PDO) {
                try {
                    $q = $pdo->prepare("UPDATE tickets SET resuelto = 1, resuelto_at = NOW(), nota_resolucion = :note WHERE id = :id");
                    $q->execute([':note'=>$note,':id'=>$id]);
                    json_out(['success'=>true]);
                } catch (Exception $e) {
                    json_out(['success'=>false,'error'=>'db_error']);
                }
            } else {
                json_out(['success'=>false,'error'=>'no_db']);
            }
        }
    }

    // fallos / historial
    if ($action === 'failures') {
        if ($modoFicticio) {
            json_out(['modo'=>'ficticio','failures'=>$_SESSION['tec_failures']]);
        } else {
            $out = ['modo'=>'real','failures'=>[]];
            if ($pdo instanceof PDO) {
                try {
                    $stmt = $pdo->query("SELECT ts, tipo AS type, descripcion AS what, nota AS note, duracion_min AS duration_min FROM averias ORDER BY ts DESC LIMIT 1000");
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $r) {
                        $out['failures'][] = $r;
                    }
                } catch (Exception $e) { }
            }
            json_out($out);
        }
    }

    // quimicos: devolver historial o estado actual. parametros: full=1 devuelve todo (real limitado)
    if ($action === 'chemicals') {
        $wantFull = (isset($_GET['full']) && $_GET['full']=='1');
        if ($modoFicticio) {
            // simular movimiento leve cuando no se pide full
            if (!$wantFull) {
                foreach ($_SESSION['tec_chem'] as $k=>$v) {
                    $delta = rand(-3,2);
                    $nv = max(0, min(100, $v + $delta));
                    $_SESSION['tec_chem'][$k] = $nv;
                }
            }
            json_out(['modo'=>'ficticio','chem'=>$_SESSION['tec_chem']]);
        } else {
            $out = ['modo'=>'real','chem'=>[]];
            if ($pdo instanceof PDO) {
                try {
                    // tabla esperada: chem_levels (name, percent, ts)
                    $stmt = $pdo->query("SELECT name, percent, ts FROM chem_levels ORDER BY ts DESC");
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $r) {
                        // keep latest per name
                        if (!isset($out['chem'][$r['name']]) || strtotime($out['chem'][$r['name']]['ts']) < strtotime($r['ts'])) {
                            $out['chem'][$r['name']] = ['percent'=> (float)$r['percent'], 'ts'=>$r['ts']];
                        }
                    }
                    // flatten to simple name->percent mapping
                    $map = [];
                    foreach ($out['chem'] as $name=>$v) $map[$name] = $v['percent'];
                    json_out(['modo'=>'real','chem'=>$map]);
                } catch (Exception $e) { /* ignore */ }
            }
            json_out($out);
        }
    }

    // default status
    json_out(['modo'=>$modoFicticio ? 'ficticio':'real','ok'=>true]);
}

// --- RENDER HTML UI below ---
$initialChem = $modoFicticio ? $_SESSION['tec_chem'] : [];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel Técnico — Gestión de averías y químicos</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
  .badge-threshold { min-width: 90px; text-align:center; }
  .table-fixed { max-height:50vh; overflow:auto; display:block; }
  .chem-label { font-weight:600; }
</style>
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Panel Técnico — <?php echo htmlspecialchars($_SESSION['usuario'],ENT_QUOTES,'UTF-8'); ?></h4>
    <div>
      <span class="badge <?php echo $modoFicticio ? 'bg-secondary' : 'bg-success'; ?> me-2">
        <?php echo $modoFicticio ? 'MODO FICTICIO' : 'MODO REAL (BD)'; ?>
      </span>
      <a href="cerrar_sesion.php" class="btn btn-outline-secondary btn-sm">Cerrar sesión</a>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header">Tickets de usuarios</div>
        <div class="card-body">
          <div class="d-grid gap-2 mb-2">
            <button id="refreshTickets" class="btn btn-sm btn-outline-primary">Refrescar</button>
          </div>
          <div class="table-responsive table-fixed">
            <table class="table table-sm">
              <thead><tr><th>ID</th><th>Título</th><th>Usuario</th><th>Estado</th><th>Acción</th></tr></thead>
              <tbody id="ticketsBody">
                <tr><td colspan="5" class="text-center small text-muted">Cargando...</td></tr>
              </tbody>
            </table>
          </div>
          <div class="mt-2 small text-muted">Marque como resuelto para cerrar ticket.</div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-header">Historial de averías (bomba / filtro / válvulas)</div>
        <div class="card-body">
          <div class="table-responsive table-fixed">
            <table class="table table-sm">
              <thead><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Duración (min)</th></tr></thead>
              <tbody id="failuresBody">
                <tr><td colspan="4" class="text-center small text-muted">Cargando...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-header">Niveles de químicos <small class="text-muted">(30% Atención · 10% Alarma)</small></div>
        <div class="card-body">
          <canvas id="chemChart" height="220"></canvas>
          <div id="chemList" class="mt-3"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- modal ver ticket detalle -->
  <div class="modal fade" id="modalTicket" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Ticket</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div><strong id="tTitle"></strong></div>
          <div class="small text-muted" id="tMeta"></div>
          <p id="tDesc" class="mt-2"></p>
          <div class="mb-2"><label class="form-label small">Nota de resolución (opcional)</label><textarea id="resNote" class="form-control" rows="3"></textarea></div>
          <div id="tMsg" class="small text-muted" style="display:none"></div>
        </div>
        <div class="modal-footer">
          <button id="btnResolve" class="btn btn-success">Marcar resuelto</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

</div> <!-- container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  const API = 'tecnico.php?api=1';
  const modoFict = '<?php echo $modoFicticio ? '1' : '0'; ?>' === '1';
  const THRESH_ATT = 30; // atención
  const THRESH_ALARM = 10; // alarma

  // chart init
  const ctx = document.getElementById('chemChart').getContext('2d');
  let chemChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: [],
      datasets: [{
        label: 'Nivel (%)',
        data: [],
        backgroundColor: [],
        borderColor: '#1f2937',
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      animation: { duration: 800 },
      scales: {
        y: { beginAtZero: true, max: 100, title: { display: true, text: '%' } }
      },
      plugins: { legend:{ display:false } }
    }
  });

  // helpers
  function colorForPercent(p) {
    if (p <= THRESH_ALARM) return 'rgba(220,53,69,0.9)'; // danger
    if (p <= THRESH_ATT) return 'rgba(255,193,7,0.9)'; // warning
    return 'rgba(25,135,84,0.9)'; // success
  }

  // tickets
  async function loadTickets() {
    const tbody = document.getElementById('ticketsBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center small text-muted">Cargando...</td></tr>';
    try {
      const res = await fetch(API + '&action=tickets');
      if (!res.ok) throw 0;
      const data = await res.json();
      const t = data.tickets || [];
      if (!t.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center small text-muted">Sin tickets</td></tr>';
        return;
      }
      tbody.innerHTML = t.map(row => {
        const estado = row.resolved ? '<span class="badge bg-success">Resuelto</span>' : '<span class="badge bg-danger">Abierto</span>';
        const acc = row.resolved ? '' : `<button data-id="${row.id}" class="btn btn-sm btn-outline-success openTicket">Abrir</button>`;
        return `<tr>
          <td>${row.id}</td>
          <td>${escapeHtml(row.title)}</td>
          <td>${escapeHtml(row.user)}</td>
          <td>${estado}</td>
          <td>${acc}</td>
        </tr>`;
      }).join('');
      // attach open handlers
      document.querySelectorAll('.openTicket').forEach(btn => {
        btn.addEventListener('click', function(){
          const id = this.dataset.id;
          openTicket(id);
        });
      });
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center small text-danger">Error al cargar</td></tr>';
    }
  }

  // abrir modal ticket (buscar en lista)
  let currentTicket = null;
  async function openTicket(id) {
    try {
      const res = await fetch(API + '&action=tickets');
      const data = await res.json();
      const found = (data.tickets || []).find(x => String(x.id) === String(id));
      if (!found) return;
      currentTicket = found;
      document.getElementById('tTitle').textContent = found.title;
      document.getElementById('tMeta').textContent = `Usuario: ${found.user} · Creado: ${found.created}`;
      document.getElementById('tDesc').textContent = found.desc || '';
      document.getElementById('resNote').value = '';
      document.getElementById('tMsg').style.display = 'none';
      const modalEl = document.getElementById('modalTicket');
      const modal = new bootstrap.Modal(modalEl);
      modal.show();
    } catch (e) {}
  }

  // resolver ticket
  document.getElementById('btnResolve').addEventListener('click', async function(){
    if (!currentTicket) return;
    const note = document.getElementById('resNote').value;
    this.disabled = true;
    document.getElementById('tMsg').style.display = 'block';
    document.getElementById('tMsg').textContent = 'Guardando...';
    try {
      const res = await fetch(API + '&action=resolve', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ id: currentTicket.id, note })
      });
      const data = await res.json();
      if (data.success) {
        document.getElementById('tMsg').textContent = 'Ticket marcado como resuelto.';
        loadTickets();
        setTimeout(()=>{ const modal = bootstrap.Modal.getInstance(document.getElementById('modalTicket')); if (modal) modal.hide(); }, 900);
      } else {
        document.getElementById('tMsg').textContent = 'Error: ' + (data.error||'desconocido');
      }
    } catch (e) {
      document.getElementById('tMsg').textContent = 'Error de red.';
    }
    this.disabled = false;
  });

  // failures
  async function loadFailures() {
    const tbody = document.getElementById('failuresBody');
    tbody.innerHTML = '<tr><td colspan="4" class="text-center small text-muted">Cargando...</td></tr>';
    try {
      const res = await fetch(API + '&action=failures');
      const data = await res.json();
      const f = data.failures || [];
      if (!f.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center small text-muted">Sin registros</td></tr>';
        return;
      }
      tbody.innerHTML = f.map(r => `<tr>
        <td>${escapeHtml(r.ts ?? '')}</td>
        <td>${escapeHtml(r.type ?? '')}</td>
        <td>${escapeHtml(r.what ?? r.descripcion ?? '')}</td>
        <td>${escapeHtml((r.duration_min ?? r.duracion_min ?? '')+'')}</td>
      </tr>`).join('');
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="4" class="text-center small text-danger">Error al cargar</td></tr>';
    }
  }

  // chemicals
  async function loadChemicals() {
    try {
      const res = await fetch(API + '&action=chemicals');
      if (!res.ok) return;
      const data = await res.json();
      const chem = data.chem || {};
      const names = Object.keys(chem);
      const values = names.map(n => parseFloat(chem[n] || 0));
      chemChart.data.labels = names;
      chemChart.data.datasets[0].data = values;
      chemChart.data.datasets[0].backgroundColor = values.map(v => colorForPercent(v));
      chemChart.update();
      const list = document.getElementById('chemList');
      list.innerHTML = names.map(n => {
        const v = chem[n];
        const cls = (v <= THRESH_ALARM) ? 'badge bg-danger' : (v <= THRESH_ATT ? 'badge bg-warning text-dark' : 'badge bg-success');
        return `<div class="d-flex justify-content-between align-items-center mb-1">
          <div class="chem-label">${escapeHtml(n)}</div>
          <div><span class="${cls} badge-threshold">${v}%</span></div>
        </div>`;
      }).join('');
    } catch (e) { console.debug(e); }
  }

  // escape helper
  function escapeHtml(s){ return (s===null||s===undefined)?'':String(s).replace(/[&<>"']/g, function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];}); }

  // events
  document.getElementById('refreshTickets').addEventListener('click', loadTickets);

  // initial loads
  loadTickets();
  loadFailures();
  loadChemicals();

  // si modo ficticio, animar quimicos cada 4s; en modo real refresco más lento
  if (modoFict) {
    setInterval(loadChemicals, 4000);
  } else {
    setInterval(loadChemicals, 30000);
  }

})();
</script>
</body>
</html>