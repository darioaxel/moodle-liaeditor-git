// Prueba del progreso de la página Publicar (js/publish_progress.js) en
// Chrome headless contra un servidor simulado que tarda como liaex.
//
// Uso (desde plugin/): node tests/js/publish_progress_e2e.mjs
// Requiere google-chrome. No necesita Moodle: reproduce el formulario de
// publish.php y sus respuestas (JSON con ajax=1, fichero al descargar).
import { spawn } from 'node:child_process'
import { mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { createServer } from 'node:http'

const SP = mkdtempSync(join(tmpdir(), 'liamat-progress-'))
mkdirSync(SP + '/dl')
const JS = readFileSync(new URL('../../js/publish_progress.js', import.meta.url), 'utf8')
const PORT = 4201, CDP = 9341
const strings = {
  progress_generate: 'Generando y publicando {fmt}…', progress_download: 'Generando {fmt} para descargar…',
  progress_upload: 'Subiendo y publicando {fmt}…', progress_elapsed: 'Tiempo transcurrido: {time}',
  progress_estimate: 'Suele tardar unos {s} segundos.', progress_slow: 'Está tardando más de lo habitual.',
  progress_keepopen: 'No cierres ni recargues esta página.', progress_published: 'Publicado. Abriendo la actividad…',
  progress_downloaded: 'Fichero generado. Se está descargando.', progress_error: 'No se pudo completar: {message}',
  progress_nofile: 'Selecciona primero el fichero que quieres subir.', type_h5p: 'H5P', type_pdf: 'PDF', type_epub: 'ePub', type_scorm: 'SCORM',
}
const config = JSON.stringify({ estimates: { h5p: 1, scorm: 2, epub: 4, pdf: 4, upload: 2 }, strings }).replace(/'/g, '&#39;')
const page = `<!doctype html><html><head><meta charset="utf-8"></head><body>
<form method="post" enctype="multipart/form-data" action="/publish.php" id="liamat-publish-form" data-progress='${config}'>
<input type="hidden" name="sesskey" value="abc">
<input type="radio" name="version" value="branch" checked>
<select name="artifact_type" id="artifact_type"><option value="h5p">H5P</option><option value="pdf">PDF</option><option value="scorm">SCORM</option></select>
<button type="submit" name="mode" value="generate" id="gen">Generar y publicar</button>
<button type="submit" name="mode" value="download" id="dl">Descargar sin publicar</button>
<input type="file" name="artifact"><button type="submit" name="mode" value="upload" id="up">Subir y publicar</button>
</form><script>${JS}</script></body></html>`

const posts = []
createServer((req, res) => {
  if (req.method === 'GET') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' })
    res.end(req.url.startsWith('/mod/') ? '<html><body>ACTIVIDAD</body></html>' : page)
    return
  }
  let body = ''
  req.on('data', (c) => (body += c))
  req.on('end', () => {
    const field = (n) => (new RegExp(`name="${n}"\\r\\n\\r\\n([^\\r]*)`).exec(body) || [])[1]
    const post = { mode: field('mode'), ajax: field('ajax'), type: field('artifact_type'), sesskey: field('sesskey') }
    posts.push(post)
    setTimeout(() => {
      if (post.type === 'pdf') {
        res.writeHead(502, { 'Content-Type': 'application/json' })
        res.end(JSON.stringify({ status: 'error', message: 'liaex falló (pdf)' }))
      } else if (post.mode === 'download') {
        res.writeHead(200, { 'Content-Type': 'application/zip', 'Content-Disposition': "attachment; filename=\"ut03@abc12345.zip\"" })
        res.end(Buffer.from('PK\x03\x04SCORM'))
      } else {
        res.writeHead(200, { 'Content-Type': 'application/json' })
        res.end(JSON.stringify({ status: 'published', url: `http://127.0.0.1:${PORT}/mod/h5pactivity/view.php?id=7` }))
      }
    }, 3500)
  })
}).listen(PORT)

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
rmSync(SP + '/profile', { recursive: true, force: true })
const chrome = spawn('google-chrome', ['--headless=new', '--disable-gpu', `--remote-debugging-port=${CDP}`, `--user-data-dir=${SP}/profile`, 'about:blank'])
let cdp
for (let i = 0; i < 50 && !cdp; i++) {
  try {
    const target = await (await fetch(`http://127.0.0.1:${CDP}/json/new?about:blank`, { method: 'PUT' })).json()
    const ws = new WebSocket(target.webSocketDebuggerUrl)
    await new Promise((r, j) => ((ws.onopen = r), (ws.onerror = j)))
    let id = 0
    const pending = new Map()
    ws.onmessage = (m) => { const msg = JSON.parse(m.data); if (msg.id) pending.get(msg.id)?.(msg) }
    cdp = (method, params = {}) => new Promise((r) => { pending.set(++id, r); ws.send(JSON.stringify({ id, method, params })) })
  } catch { await sleep(200) }
}
const ev = async (e) => (await cdp('Runtime.evaluate', { expression: e, awaitPromise: true, returnByValue: true })).result.result.value
const checks = []
const check = (name, ok, extra = '') => { checks.push(ok); console.log(`${ok ? '✓' : '✗'} ${name}${extra ? ' — ' + extra : ''}`) }
await cdp('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: SP + '/dl' })
const open = async () => { await cdp('Page.navigate', { url: `http://127.0.0.1:${PORT}/publish.php` }); await sleep(800) }

try {
  // 1) Generar y publicar (H5P): overlay con tiempo, controles bloqueados y navegación al final.
  await open()
  await ev(`document.getElementById('gen').click(), true`)
  await sleep(1200)
  check('sin aviso de tardanza antes de 1,5 × la estimación', !(await ev(`document.querySelector('[data-role=status]').textContent.includes('más de lo habitual')`)))
  await sleep(1000)
  check('overlay con spinner y título', await ev(`!!document.querySelector('.liamat-progress .spinner-border') && document.getElementById('liamat-progress-title').textContent === 'Generando y publicando H5P…'`))
  const elapsed = await ev(`document.querySelector('[data-role=elapsed]').textContent`)
  check('cuenta el tiempo transcurrido', /0:0[12]/.test(elapsed), elapsed)
  check('estimación del formato', await ev(`document.querySelector('[data-role=estimate]').textContent === 'Suele tardar unos 1 segundos.'`))
  check('botones deshabilitados durante el proceso', await ev(`document.getElementById('gen').disabled && document.getElementById('dl').disabled`))
  check('envía mode, ajax=1, formato y sesskey', JSON.stringify(posts.at(-1)) === JSON.stringify({ mode: 'generate', ajax: '1', type: 'h5p', sesskey: 'abc' }), JSON.stringify(posts.at(-1)))
  check('aviso de tardanza al superar 1,5 × la estimación', await ev(`document.querySelector('[data-role=status]').textContent.includes('más de lo habitual')`))
  await sleep(2500)
  check('al publicar navega a la actividad', (await ev('location.pathname')) === '/mod/h5pactivity/view.php')

  // 2) Error (PDF): aviso en la página, sin overlay y con los controles activos.
  await open()
  await ev(`(() => { const s = document.getElementById('artifact_type'); s.value = 'pdf'; return true })()`)
  await ev(`document.getElementById('gen').click(), true`)
  await sleep(4200)
  check('error: mensaje en la página', await ev(`document.getElementById('liamat-publish-error')?.textContent === 'No se pudo completar: liaex falló (pdf)'`))
  check('error: overlay cerrado y controles activos', await ev(`!document.querySelector('.liamat-progress') && !document.getElementById('gen').disabled`))

  // 3) Descargar (SCORM): fichero descargado sin salir de la página.
  await ev(`(() => { const s = document.getElementById('artifact_type'); s.value = 'scorm'; return true })()`)
  await ev(`document.getElementById('dl').click(), true`)
  await sleep(4300)
  check('descarga: mensaje de fichero generado', await ev(`document.querySelector('[data-role=status]')?.textContent === 'Fichero generado. Se está descargando.'`))
  await sleep(2500)
  check('descarga: fichero con su nombre y la página sigue ahí', readdirSync(SP + '/dl').includes('ut03@abc12345.zip') && (await ev('location.pathname')) === '/publish.php', readdirSync(SP + '/dl').join(','))
  check('descarga: overlay cerrado y controles activos', await ev(`!document.querySelector('.liamat-progress') && !document.getElementById('dl').disabled`))

  // 4) Subir sin fichero: no envía nada.
  const before = posts.length
  await ev(`document.getElementById('up').click(), true`)
  await sleep(500)
  check('subir sin fichero: aviso y sin petición', posts.length === before && (await ev(`document.getElementById('liamat-publish-error').textContent.includes('Selecciona primero')`)))
} catch (e) { console.error('✗', e.message) }
chrome.kill()
console.log(`\n${checks.filter(Boolean).length}/${checks.length} comprobaciones`)
process.exit(checks.every(Boolean) ? 0 : 1)
