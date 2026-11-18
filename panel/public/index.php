<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
layout_inicio('Dispositivos', 'inicio');
?>
<h1>Dispositivos remotos</h1>
<p class="sub">Estado de conexión y última lectura de cada equipo. Se actualiza cada 30 s · <span id="act" class="muted"></span></p>

<div class="kpis" id="kpis"></div>

<div class="panel-cab">
  <div class="filtros">
    <input type="search" id="buscar" placeholder="Buscar por nombre, identificador o ubicación…">
    <div class="seg" id="filtroEstado">
      <button data-e="" class="act">Todos</button>
      <button data-e="en-linea">En línea</button>
      <button data-e="inactivo">Inactivos</button>
      <button data-e="desconectado">Desconectados</button>
    </div>
  </div>
  <div class="seg"><button id="vTarj" class="act">Tarjetas</button><button id="vTabla">Tabla</button></div>
</div>

<div id="lista" class="grid-disp"><div class="vacio">Cargando…</div></div>
<div id="tabla" class="panel" hidden><div class="tabla-cont"><table>
  <thead><tr><th>Dispositivo</th><th>Ubicación</th><th>Estado</th><th class="num">Voltaje (V)</th><th class="num">Corriente (A)</th><th class="num">Potencia (W)</th><th class="num">CPU (°C)</th><th class="num">Amenazas sin atender</th><th>Última conexión</th></tr></thead>
  <tbody id="tbody"></tbody></table></div></div>
<?php layout_fin(['assets/index.js']);
