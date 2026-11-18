// Tema claro/oscuro (preferencia por visitante)
(function () {
  const raiz = document.documentElement;
  try { const t = localStorage.getItem('tema'); if (t) raiz.dataset.theme = t; } catch (e) {}
  const b = document.getElementById('tema');
  if (b) b.addEventListener('click', () => {
    const oscuro = raiz.dataset.theme ? raiz.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
    raiz.dataset.theme = oscuro ? 'light' : 'dark';
    try { localStorage.setItem('tema', raiz.dataset.theme); } catch (e) {}
    document.dispatchEvent(new Event('tema-cambiado'));
  });
})();
function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function fmt(n, d = 2) { return n === null || n === undefined || n === '' ? '—' : Number(n).toFixed(d); }
const CSRF = document.querySelector('meta[name="csrf"]')?.content || '';
// Solo una pista de audio a la vez
document.addEventListener('play', e => document.querySelectorAll('audio').forEach(a => a !== e.target && a.pause()), true);
// Botones "Marcar atendida" (lista de detecciones)
document.addEventListener('click', async e => {
  const b = e.target.closest('.btn-atender');
  if (!b) return;
  const atender = b.dataset.atendida !== '1';
  const fd = new FormData();
  fd.append('accion', 'revisar'); fd.append('evento', b.dataset.id); fd.append('atendida', atender ? '1' : '0'); fd.append('csrf', CSRF);
  b.disabled = true;
  try {
    const j = await (await fetch('api/accion.php', { method: 'POST', body: fd, credentials: 'same-origin' })).json();
    if (j.ok) {
      b.dataset.atendida = j.atendida ? '1' : '0';
      b.textContent = j.atendida ? '✓ Atendida' : 'Marcar atendida';
      b.closest('tr')?.classList.toggle('atendida', !!j.atendida);
      const q = b.closest('tr')?.querySelector('.revisor'); if (q) q.textContent = j.atendida ? (j.por + ' · ' + j.en) : '';
    } else alert(j.error || 'No se pudo guardar');
  } finally { b.disabled = false; }
});
