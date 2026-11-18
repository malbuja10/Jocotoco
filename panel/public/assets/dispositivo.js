/* Ficha del dispositivo: pestañas y graficas (Chart.js, servido localmente). */
const DISP_ID = document.getElementById('dispId').dataset.id;
const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
const graficas = {};
let rangoActual = { rango: '24h' }, ultima = null;

// ---- Pestañas: conservan ?tab= en la URL ----
document.querySelectorAll('.tabs button').forEach(b => b.addEventListener('click', () => {
  document.querySelectorAll('.tabs button').forEach(x => x.classList.toggle('act', x === b));
  document.querySelectorAll('.tab').forEach(t => t.classList.toggle('act', t.id === 'tab-' + b.dataset.tab));
  const u = new URL(location); u.searchParams.set('tab', b.dataset.tab); history.replaceState(null, '', u);
  Object.values(graficas).forEach(g => g.resize());
}));

function base(unidad, dec) {
  const muted = css('--tinta-3');
  return {
    responsive: true, maintainAspectRatio: false, animation: false, normalized: true,
    interaction: { mode: 'index', intersect: false, axis: 'x' },
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: css('--superficie'), titleColor: css('--tinta'), bodyColor: css('--tinta-2'),
        borderColor: css('--eje'), borderWidth: 1, padding: 10, boxWidth: 8, boxHeight: 8,
        callbacks: { label: c => ` ${c.dataset.label}: ${c.parsed.y == null ? '—' : c.parsed.y.toFixed(dec)} ${unidad}` }
      }
    },
    scales: {
      x: { type: 'time', time: { tooltipFormat: 'yyyy-MM-dd HH:mm', displayFormats: { minute: 'HH:mm', hour: 'dd/MM HH:mm', day: 'dd/MM' } },
           grid: { display: false }, border: { color: css('--eje') }, ticks: { color: muted, maxRotation: 0, autoSkipPadding: 18 } },
      y: { grid: { color: css('--rejilla') }, border: { display: false }, ticks: { color: muted, maxTicksLimit: 6 }, grace: '5%' }
    }
  };
}
const linea = (label, color, extra = {}) => ({ label, data: [], borderColor: color, backgroundColor: color + '1f', fill: 'origin', borderWidth: 2,
  pointRadius: 0, pointHoverRadius: 4, pointHoverBorderWidth: 2, pointHoverBorderColor: css('--superficie'), tension: .25, spanGaps: false, ...extra });
const limite = (y, label) => ({ label, data: [], borderColor: css('--tinta-3'), borderWidth: 1, borderDash: [4, 4], pointRadius: 0, pointHoverRadius: 0, _y: y });

function nueva(id, cfg) { graficas[id]?.destroy(); graficas[id] = new Chart(document.getElementById(id), cfg); return graficas[id]; }

function crearGraficas() {
  const L = ultima?.limites || {};
  const sinLimites = i => i.datasetIndex === 0 || i.dataset._amenaza;
  let o = base('V', 2); o.plugins.tooltip.filter = sinLimites;
  nueva('cVoltaje', { type: 'line', data: { datasets: [linea('Voltaje', css('--s-voltaje')), limite(L.vmin, 'Mínimo'), limite(L.vmax, 'Máximo')] }, options: o });
  o = base('A', 3); o.plugins.tooltip.filter = sinLimites;
  nueva('cCorriente', { type: 'line', data: { datasets: [linea('Corriente', css('--s-corriente')), limite(L.imax, 'Máximo')] }, options: o });
  nueva('cPotencia', { type: 'line', data: { datasets: [linea('Potencia', css('--s-potencia'))] }, options: base('W', 2) });
  nueva('cTemp', { type: 'line', data: { datasets: [linea('CPU', css('--s-cpu'))] }, options: base('°C', 1) });
  o = base('%', 1);
  o.plugins.legend = { display: true, position: 'top', align: 'end', labels: { color: css('--tinta-2'), boxWidth: 10, boxHeight: 2, usePointStyle: false } };
  o.scales.y.min = 0; o.scales.y.max = 100;
  nueva('cUso', { type: 'line', data: { datasets: [linea('CPU', css('--s-cpu'), { fill: false }), linea('RAM', css('--s-ram'), { fill: false })] }, options: o });
  o = base('intervalos', 0);
  o.scales.x = { type: 'category', grid: { display: false }, border: { color: css('--eje') }, ticks: { color: css('--tinta-3'), maxRotation: 0, autoSkipPadding: 8 }, title: { display: true, text: 'Voltaje (V)', color: css('--tinta-3') } };
  o.scales.y.beginAtZero = true; o.scales.y.ticks.precision = 0;
  o.plugins.tooltip.callbacks = { title: i => i[0].label + ' V', label: c => ` ${c.parsed.y} intervalos` };
  nueva('cHisto', { type: 'bar', data: { labels: [], datasets: [{ label: 'Intervalos', data: [], backgroundColor: css('--s-voltaje'), borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'bottom', barPercentage: .92, categoryPercentage: 1 }] }, options: o });
  crearGraficasAmenazas();
  if (ultima) volcar(ultima);
}

function crearGraficasAmenazas() {
  const nodo = document.getElementById('datosAmenazas'); if (!nodo) return;
  const A = JSON.parse(nodo.textContent), col = css('--s-voltaje');
  let o = base('amenazas', 0);
  o.scales.x = { type: 'category', grid: { display: false }, border: { color: css('--eje') }, ticks: { color: css('--tinta-3'), maxRotation: 0, autoSkipPadding: 12, callback: function (v) { return this.getLabelForValue(v).slice(5); } } };
  o.scales.y.beginAtZero = true; o.scales.y.ticks.precision = 0;
  o.plugins.tooltip.callbacks = { label: c => ` ${c.parsed.y} amenazas` };
  nueva('cAmDia', { type: 'bar', data: { labels: A.dia.l, datasets: [{ label: 'Amenazas', data: A.dia.v, backgroundColor: col, borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'bottom', barPercentage: .8 }] }, options: o });
  o = base('amenazas', 0);
  o.indexAxis = 'y'; o.interaction = { mode: 'index', intersect: false, axis: 'y' };
  o.scales = { x: { beginAtZero: true, grid: { color: css('--rejilla') }, border: { display: false }, ticks: { color: css('--tinta-3'), precision: 0 } },
               y: { grid: { display: false }, border: { color: css('--eje') }, ticks: { color: css('--tinta-2') } } };
  o.plugins.tooltip.callbacks = { label: c => ` ${c.parsed.x} amenazas` };
  nueva('cAmTipo', { type: 'bar', data: { labels: A.clase.map(t => t.clase), datasets: [{ label: 'Amenazas', data: A.clase.map(t => t.n), backgroundColor: col, borderRadius: { topRight: 4, bottomRight: 4 }, borderSkipped: 'left', barPercentage: .7 }] }, options: o });
}

// ---- Datos ----
async function cargarSerie() {
  const info = document.getElementById('gInfo');
  try {
    const p = new URLSearchParams({ accion: 'serie', id: DISP_ID, ...rangoActual });
    const j = await (await fetch('api/datos.php?' + p, { credentials: 'same-origin' })).json();
    const primera = !ultima; ultima = j;
    if (primera) crearGraficas(); else volcar(j);
    const min = j.intervalo_seg / 60;
    info.textContent = `${j.desde} → ${j.hasta} · intervalo ${min < 60 ? min + ' min' : (min / 60) + ' h'}`;
  } catch (e) { info.textContent = 'Error al cargar datos'; }
}

const puntos = (serie, campo = 'prom') => serie.map(r => ({ x: r.t * 1000, y: r[campo] }));

function volcar(j) {
  const x0 = (j.voltaje[0]?.t ?? j.corriente[0]?.t ?? 0) * 1000;
  const x1 = ((j.voltaje.at(-1)?.t ?? j.corriente.at(-1)?.t ?? 0)) * 1000;
  const fijarLimites = g => g.data.datasets.forEach(d => { if (d._y != null && !d._amenaza) d.data = x0 ? [{ x: x0, y: d._y }, { x: x1, y: d._y }] : []; });

  const gv = graficas.cVoltaje;
  gv.data.datasets[0].data = puntos(j.voltaje); fijarLimites(gv);
  gv.data.datasets = gv.data.datasets.filter(d => !d._amenaza);
  if (j.eventos.length && j.voltaje.length) {
    const ymin = Math.min(...j.voltaje.map(r => r.prom));
    gv.data.datasets.push({ label: 'Amenaza', _amenaza: true, type: 'scatter', showLine: false,
      data: j.eventos.map(e => ({ x: e.t * 1000, y: ymin, clase: e.clase, conf: e.confianza })),
      pointStyle: 'triangle', pointRadius: 6, pointHoverRadius: 8, backgroundColor: css('--critico'), borderColor: css('--superficie'), borderWidth: 1.5 });
  }
  gv.options.plugins.tooltip.callbacks.label = c => c.dataset._amenaza
    ? ` ▲ ${c.raw.clase}${c.raw.conf != null ? ' (' + (c.raw.conf * 100).toFixed(0) + ' %)' : ''}` : ` Voltaje: ${c.parsed.y?.toFixed(2)} V`;
  gv.update('none');

  const gi = graficas.cCorriente; gi.data.datasets[0].data = puntos(j.corriente); fijarLimites(gi); gi.update('none');
  const gp = graficas.cPotencia; gp.data.datasets[0].data = puntos(j.potencia); gp.update('none');
  const gt = graficas.cTemp; gt.data.datasets[0].data = puntos(j.estado, 'cpu_temp'); gt.update('none');
  const gu = graficas.cUso; gu.data.datasets[0].data = puntos(j.estado, 'cpu'); gu.data.datasets[1].data = puntos(j.estado, 'ram'); gu.update('none');

  // Estadisticas bajo cada grafica
  const st = (s, u, d) => s && s.n ? `<span>Mín <b>${s.min.toFixed(d)} ${u}</b></span><span>Prom <b>${s.prom.toFixed(d)} ${u}</b></span><span>Máx <b>${s.max.toFixed(d)} ${u}</b></span>${s.desv != null ? `<span>σ <b>${s.desv.toFixed(d)}</b></span>` : ''}<span>${s.n} lecturas</span>` : '<span>Sin datos en el rango</span>';
  document.getElementById('sVoltaje').innerHTML = st(j.stats.voltaje, 'V', 2);
  document.getElementById('sCorriente').innerHTML = st(j.stats.corriente, 'A', 3);
  document.getElementById('sPotencia').innerHTML = j.stats.potencia ? st({ ...j.stats.potencia, desv: null, n: j.stats.potencia.n }, 'W', 2).replace(/lecturas/, 'intervalos') : '<span>Requiere voltaje y corriente en el mismo intervalo</span>';

  const sv = j.stats.voltaje, si = j.stats.corriente;
  document.getElementById('resumenRango').innerHTML = `
    <div class="kpi"><div class="et">Lecturas en el rango</div><div class="val">${sv.n + si.n}</div><div class="det">${sv.n} de voltaje · ${si.n} de corriente</div></div>
    <div class="kpi"><div class="et">Voltaje promedio</div><div class="val">${sv.n ? sv.prom.toFixed(2) : '—'} <small>V</small></div><div class="det">${j.fuera_de_rango.voltaje} lecturas fuera de rango</div></div>
    <div class="kpi"><div class="et">Corriente pico</div><div class="val">${si.n ? si.max.toFixed(3) : '—'} <small>A</small></div><div class="det">${j.fuera_de_rango.corriente} sobre el máximo</div></div>
    <div class="kpi"><div class="et">Energía estimada</div><div class="val">${j.energia_wh.toFixed(2)} <small>Wh</small></div><div class="det">potencia × tiempo por intervalo</div></div>
    <div class="kpi"><div class="et">Amenazas en el rango</div><div class="val">${j.eventos.length}</div><div class="det">marcadas con ▲ en voltaje</div></div>`;

  // Histograma de los promedios de voltaje
  const gh = graficas.cHisto, vs = j.voltaje.map(r => r.prom);
  if (vs.length) {
    const mn = Math.min(...vs), mx = Math.max(...vs);
    const paso = [0.05, 0.1, 0.2, 0.25, 0.5, 1].find(p => (mx - mn) / p <= 24) || 1;
    const ini = Math.floor(mn / paso) * paso, nb = Math.max(1, Math.floor((mx - ini) / paso) + 1);
    const cub = new Array(nb).fill(0); vs.forEach(v => cub[Math.min(nb - 1, Math.floor((v - ini) / paso + 1e-9))]++);
    gh.data.labels = cub.map((_, i) => (ini + i * paso).toFixed(2)); gh.data.datasets[0].data = cub;
  } else { gh.data.labels = []; gh.data.datasets[0].data = []; }
  gh.update('none');

  document.getElementById('tUlt').innerHTML = j.ultimas.length ? j.ultimas.map(r => `<tr><td class="nowrap">${esc(r.fecha)}</td>
    <td class="num">${fmt(r.voltaje)}</td><td class="num">${fmt(r.corriente, 3)}</td><td class="num">${fmt(r.potencia)}</td>
    <td>${r.alerta ? '<span class="alerta-el">⚠ ' + esc(r.alerta) + '</span>' : '<span class="muted">Normal</span>'}</td></tr>`).join('')
    : '<tr><td colspan="5" class="vacio">Sin lecturas en el rango</td></tr>';
}

// ---- Controles ----
document.querySelectorAll('#rangos button').forEach(b => b.addEventListener('click', () => {
  document.querySelectorAll('#rangos button').forEach(x => x.classList.toggle('act', x === b));
  rangoActual = { rango: b.dataset.r }; cargarSerie();
}));
document.getElementById('gAplicar').addEventListener('click', () => {
  const d = document.getElementById('gDesde').value, h = document.getElementById('gHasta').value;
  if (!d && !h) return;
  document.querySelectorAll('#rangos button').forEach(x => x.classList.remove('act'));
  rangoActual = { desde: d, hasta: h }; cargarSerie();
});
setInterval(() => { if (document.getElementById('gAuto').checked && rangoActual.rango && !document.hidden) cargarSerie(); }, 60000);
document.addEventListener('tema-cambiado', crearGraficas);
matchMedia('(prefers-color-scheme: dark)').addEventListener('change', crearGraficas);
cargarSerie();
