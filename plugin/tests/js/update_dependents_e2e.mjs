// Prueba de «Actualizar todos los contenidos dependientes de esta versión»
// (js/update_dependents.js) en Chrome headless contra un servidor simulado.
//
// Uso (desde plugin/): node tests/js/update_dependents_e2e.mjs
// Requiere google-chrome. No necesita Moodle: reproduce el aviso y los
// botones de materials.php y las respuestas de update_dependents.php.
import { spawn } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { createServer } from 'node:http'

const SP = mkdtempSync(join(tmpdir(), 'liamat-dependents-'))
const JS = readFileSync(new URL('../../js/update_dependents.js', import.meta.url), 'utf8')
const PORT = 4202, CDP = 9342
const config = JSON.stringify({
  items: [
    { id: 11, label: 'materiales/ut03-mvc/ut03-mvc.md · H5P' },
    { id: 12, label: 'materiales/ut03-mvc/ut03-mvc.md · PDF (LaTeX)' },
    { id: 13, label: 'materiales/ut04-pdo/ut04-pdo.md · Web' },
  ],
  returnurl: `http://127.0.0.1:${PORT}/materials.php?id=2`,
  strings: {
    confirm: 'Se volverán a generar 3 contenido(s). ¿Continuar?', title: 'Actualizar todos los contenidos dependientes de esta versión',
    pending: 'Pendiente', running: 'Actualizando…', updated: 'Actualizado', skipped: 'Ya estaba al día', error: 'Error',
    keepopen: 'No cierres ni recargues esta página.', done: 'Terminado.', close: 'Cerrar',
  },
}).replace(/'/g, '&#39;')
const form = `<form method="post" action="/update_dependents.php" class="liamat-update-dependents">
<input type="hidden" name="sesskey" value="abc"><input type="hidden" name="id" value="2">
<button type="submit">Actualizar todos los contenidos dependientes de esta versión</button></form>`
const page = `<!doctype html><html><head><meta charset="utf-8"></head><body>
<div id="aviso">${form}</div><table><tr><td>ut03 H5P</td><td id="fila">${form}</td></tr></table>
<div id="liamat-dependents" hidden data-config='${config}'></div><script>${JS}</script></body></html>`

const posts = []
let active = 0
let maxActive = 0
createServer((req, res) => {
  if (req.method === 'GET') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' })
    res.end(req.url.startsWith('/materials.php') ? '<html><body>LISTA</body></html>' : page)
    return
  }
  let body = ''
  req.on('data', (c) => (body += c))
  req.on('end', () => {
    const field = (n) => (new RegExp(`name="${n}"\\r\\n\\r\\n([^\\r]*)`).exec(body) || [])[1]
    const post = { id: field('id'), deploy: field('deploy'), ajax: field('ajax'), sesskey: field('sesskey') }
    posts.push(post)
    active++
    maxActive = Math.max(maxActive, active)
    setTimeout(() => {
      active--
      const answers = {
        11: [200, { status: 'updated', name: 'UT03 (H5P)', url: '/mod/h5pactivity/view.php?id=7' }],
        12: [502, { status: 'error', message: 'Pandoc falló' }],
        13: [200, { status: 'skipped', message: 'Ya estaba al día' }],
      }
      const [status, json] = answers[post.deploy] ?? [400, { status: 'error', message: '?' }]
      res.writeHead(status, { 'Content-Type': 'application/json' })
      res.end(JSON.stringify(json))
    }, 600)
  })
}).listen(PORT)

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
rmSync(SP + '/profile', { recursive: true, force: true })
const chrome = spawn('google-chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${CDP}`, `--user-data-dir=${SP}/profile`, 'about:blank'])
let cdp
const dialogs = []
let acceptDialog = true
for (let i = 0; i < 50 && !cdp; i++) {
  try {
    const target = await (await fetch(`http://127.0.0.1:${CDP}/json/new?about:blank`, { method: 'PUT' })).json()
    const ws = new WebSocket(target.webSocketDebuggerUrl)
    await new Promise((r, j) => ((ws.onopen = r), (ws.onerror = j)))
    let id = 0
    const pending = new Map()
    ws.onmessage = (m) => {
      const msg = JSON.parse(m.data)
      if (msg.method === 'Page.javascriptDialogOpening') {
        dialogs.push(msg.params.message)
        ws.send(JSON.stringify({ id: ++id, method: 'Page.handleJavaScriptDialog', params: { accept: acceptDialog } }))
      }
      if (msg.id) pending.get(msg.id)?.(msg)
    }
    cdp = (method, params = {}) => new Promise((r) => { pending.set(++id, r); ws.send(JSON.stringify({ id, method, params })) })
  } catch { await sleep(200) }
}
const ev = async (e) => (await cdp('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true })).result.result.value
const checks = []
const check = (name, ok, extra = '') => { checks.push(ok); console.log(`${ok ? '✓' : '✗'} ${name}${extra ? ' — ' + extra : ''}`) }
await cdp('Page.enable')

try {
  // 1) Cancelar la confirmación: no se envía nada.
  await cdp('Page.navigate', { url: `http://127.0.0.1:${PORT}/pagina` })
  await sleep(800)
  acceptDialog = false
  await ev(`document.querySelector('#fila button').click(), true`)
  await sleep(500)
  check('pide confirmación antes de regenerar', dialogs.length === 1 && dialogs[0].includes('3 contenido'), dialogs[0])
  check('cancelar no envía nada ni abre el progreso', posts.length === 0 && !(await ev(`!!document.querySelector('.liamat-progress')`)))

  // 2) Aceptar: uno a uno, con su estado, y botón Cerrar al terminar.
  acceptDialog = true
  await ev(`document.querySelector('#fila button').click(), true`)
  await sleep(300)
  check('progreso con la lista de contenidos pendientes', await ev(`document.querySelectorAll('.liamat-progress li').length === 3 && document.querySelector('.liamat-progress li [data-role=state]').textContent === 'Actualizando…'`))
  await sleep(2400)
  const states = await ev(`[...document.querySelectorAll('.liamat-progress [data-role=state]')].map(s => s.textContent)`)
  check('estado de cada contenido: actualizado, error y ya al día', JSON.stringify(states) === JSON.stringify(['Actualizado', 'Error', 'Ya estaba al día']), JSON.stringify(states))
  check('el error muestra su motivo', await ev(`document.querySelectorAll('.liamat-progress li')[1].textContent.includes('Pandoc falló')`))
  check('peticiones de una en una (deploy, ajax=1, sesskey e id del curso)',
    maxActive === 1 && JSON.stringify(posts.map((p) => p.deploy)) === '["11","12","13"]' && posts.every((p) => p.ajax === '1' && p.sesskey === 'abc' && p.id === '2'),
    JSON.stringify(posts))
  check('al terminar: resumen y botón Cerrar', await ev(`document.querySelector('[data-role=summary]').textContent === 'Terminado.' && !document.querySelector('.liamat-progress .btn-primary').hidden`))
  await ev(`document.querySelector('.liamat-progress .btn-primary').click(), true`)
  await sleep(600)
  check('Cerrar vuelve a la lista de materiales', (await ev('location.pathname')) === '/materials.php')
} catch (e) { console.error('✗', e.message) }
chrome.kill()
console.log(`\n${checks.filter(Boolean).length}/${checks.length} comprobaciones`)
process.exit(checks.every(Boolean) ? 0 : 1)
