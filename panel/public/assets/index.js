let datos = [], filtroE = '';
const umbrales = { online: 0, inactivo: 0 };

async function cargar() {
  try {
    const j = await (await fetch('api/datos.php?accion=resumen', { credentials: 'same-origin' })).json();
    datos = j.dispositivos;
    document.getElementById('act').textContent = 'Actualizado: ' + j.servidor;
    umbrales.online = j.umbrales.online_min; umbrales.inactivo = j.umbrales.inactivo_h;
    pintar();
  } catch (e) { document.getElementById('act').textContent = 'Error al actualizar'; }
}
function pintar() {
  const c = s => datos.filter(d => d.estado === s).length;
  const pend = datos.reduce((a, d) => a + d.amenazas_pendientes, 0);
  document.getElementById('kpis').innerHTML = `
    <div class="kpi"><div class="et">Dispositivos</div><div class="val">${datos.length}</div><div class="det">registrados</div></div>
    <div class="kpi"><div class="et">En línea</div><div class="val">${c('en-linea')}</div><div class="det">conexión en los últimos ${umbrales.online} min</div></div>
    <div class="kpi"><div class="et">Inactivos / desconectados</div><div class="val">${c('inactivo')} <small>/</small> ${c('desconectado') + c('sin-datos')}</div><div class="det">sin conexión &gt; ${umbrales.online} min / &gt; ${umbrales.inactivo} h</div></div>
    <div class="kpi"><div class="et">Amenazas sin atender</div><div class="val">${pend}</div><div class="det">en todos los dispositivos</div></div>
    <div class="kpi"><div class="et">Alertas eléctricas</div><div class="val">${datos.filter(d => d.alerta).length}</div><div class="det">voltaje o corriente fuera de rango</div></div>`;
  const q = document.getElementById('buscar').value.toLowerCase();
  const lista = datos.filter(d => (!filtroE || d.estado === filtroE || (filtroE === 'desconectado' && d.estado === 'sin-datos'))
    && (!q || [d.nombre, d.id, d.ubicacion].join(' ').toLowerCase().includes(q)));
  const url = d => 'dispositivo.php?id=' + encodeURIComponent(d.id);
  document.getElementById('lista').innerHTML = lista.length ? lista.map(d => `
    <a class="disp" href="${url(d)}">
      <div class="disp-cab"><div><div class="disp-nombre">${esc(d.nombre)}</div><div class="disp-cod">${esc(d.id)}${d.ubicacion ? ' · ' + esc(d.ubicacion) : ''}</div></div>
        <span class="estado ${d.estado}">${esc(d.estado_txt)}</span></div>
      <div class="disp-med">
        <div>Voltaje<b>${fmt(d.voltaje)} V</b></div><div>Corriente<b>${fmt(d.corriente, 3)} A</b></div><div>Potencia<b>${fmt(d.potencia)} W</b></div>
      </div>
      <div class="disp-pie"><span>${d.alerta ? '<span class="alerta-el">⚠ ' + esc(d.alerta) + '</span>' : (d.voltaje === null ? '<span class="muted">Sin lecturas eléctricas</span>' : 'Parámetros normales')}</span>
        <span>${d.amenazas_pendientes ? '<span class="chip nivel-alta"><span class="ic">▲</span>' + d.amenazas_pendientes + ' sin atender</span>' : '<span class="muted">' + d.amenazas + ' amenazas</span>'}</span></div>
      <div class="disp-pie muted"><span>Última conexión: ${esc(d.ultima_conexion || '—')}</span><span>${esc(d.hace)}</span></div>
    </a>`).join('') : '<div class="vacio">' + (datos.length ? 'No hay dispositivos que coincidan.' : 'Aún no hay dispositivos: aparecen al primer envío autenticado al API.') + '</div>';
  document.getElementById('tbody').innerHTML = lista.map(d => `<tr>
    <td><a href="${url(d)}">${esc(d.nombre)}</a><div class="muted pequeno">${esc(d.id)}</div></td>
    <td>${esc(d.ubicacion || '—')}</td><td><span class="estado ${d.estado}">${esc(d.estado_txt)}</span></td>
    <td class="num">${fmt(d.voltaje)}</td><td class="num">${fmt(d.corriente, 3)}</td><td class="num">${fmt(d.potencia)}</td><td class="num">${fmt(d.cpu_temp, 1)}</td>
    <td class="num">${d.amenazas_pendientes}</td><td>${esc(d.hace)}${d.alerta ? ' · <span class="alerta-el">⚠ ' + esc(d.alerta) + '</span>' : ''}</td></tr>`).join('');
}
document.getElementById('buscar').addEventListener('input', pintar);
document.querySelectorAll('#filtroEstado button').forEach(b => b.onclick = () => {
  document.querySelectorAll('#filtroEstado button').forEach(x => x.classList.toggle('act', x === b));
  filtroE = b.dataset.e; pintar();
});
function setVista(v) {
  document.getElementById('lista').hidden = v !== 'tarj'; document.getElementById('tabla').hidden = v === 'tarj';
  document.getElementById('vTarj').classList.toggle('act', v === 'tarj'); document.getElementById('vTabla').classList.toggle('act', v !== 'tarj');
  try { localStorage.setItem('vista', v); } catch (e) {}
}
document.getElementById('vTarj').onclick = () => setVista('tarj');
document.getElementById('vTabla').onclick = () => setVista('tabla');
try { if (localStorage.getItem('vista') === 'tabla') setVista('tabla'); } catch (e) {}
cargar(); setInterval(cargar, 30000);
