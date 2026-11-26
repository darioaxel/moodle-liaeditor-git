// Prueba en Chrome del comportamiento de la web estática (assets/liamat.js).
//
// Uso: node tests/web/liamat_e2e.mjs <carpeta de la web de ut05-sesiones>
// (la web se genera con PandocExporter.web; ver tests/test_pandoc_export.py).
// Requiere google-chrome. Resuelve los quizzes de la página de
// autoevaluación y la encuesta, y falla si hay errores de JavaScript.

import { spawn } from 'node:child_process'
import { mkdtempSync, readFileSync, existsSync } from 'node:fs'
import { createServer } from 'node:http'
import { tmpdir } from 'node:os'
import { extname, join } from 'node:path'

const SITE = process.argv[2]
if (!SITE || !existsSync(join(SITE, 'index.html'))) {
  console.error('Uso: node tests/web/liamat_e2e.mjs <carpeta de la web>')
  process.exit(2)
}
const PORT = 4231
const CDP = 9351
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css' }

createServer((req, res) => {
  const file = join(SITE, decodeURIComponent(new URL(req.url, 'http://x').pathname))
  if (!existsSync(file)) {
    res.writeHead(404)
    res.end()
    return
  }
  res.writeHead(200, { 'Content-Type': TYPES[extname(file)] ?? 'application/octet-stream' })
  res.end(readFileSync(file))
}).listen(PORT)

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const chrome = spawn('google-chrome', [
  '--headless=new', '--disable-gpu', `--remote-debugging-port=${CDP}`,
  `--user-data-dir=${mkdtempSync(join(tmpdir(), 'liamat-'))}`, 'about:blank',
])
const errores = []
let cdp
for (let i = 0; i < 50 && !cdp; i++) {
  try {
    const target = await (await fetch(`http://127.0.0.1:${CDP}/json/new?about:blank`, { method: 'PUT' })).json()
    const ws = new WebSocket(target.webSocketDebuggerUrl)
    await new Promise((r, j) => ((ws.onopen = r), (ws.onerror = j)))
    let id = 0
    const pending = new Map()
    ws.onmessage = (m) => {
      const msg = JSON.parse(m.data)
      if (msg.method === 'Runtime.exceptionThrown') errores.push(msg.params.exceptionDetails.text)
      if (msg.id) pending.get(msg.id)?.(msg)
    }
    cdp = (method, params = {}) => new Promise((r) => { pending.set(++id, r); ws.send(JSON.stringify({ id, method, params })) })
  } catch {
    await sleep(200)
  }
}
const ev = async (expr) => (await cdp('Runtime.evaluate', { expression: expr, returnByValue: true })).result.result.value
const checks = []
const check = (nombre, ok, extra = '') => {
  checks.push(ok)
  console.log(`${ok ? '✓' : '✗'} ${nombre}${extra ? ' — ' + extra : ''}`)
}
const abrir = async (pagina) => {
  await cdp('Page.navigate', { url: `http://127.0.0.1:${PORT}/${pagina}` })
  await sleep(900)
}
// Helpers dentro de la página: quiz n (0-based), botón por texto, feedback.
const Q = (n) => `document.querySelectorAll('.quiz')[${n}]`
const pulsar = (n, texto) => `[...${Q(n)}.querySelectorAll('button')].find(b => b.textContent.startsWith('${texto}')).click()`
const feedback = (n) => ev(`${Q(n)}.querySelector('.lia-feedback').textContent`)

try {
  await cdp('Runtime.enable')
  await abrir('1.5-autoevaluacion.html')

  // 1) Opción única: fallo, pista, acierto y «Ver respuesta».
  await ev(`${Q(0)}.querySelectorAll('input')[0].click(); ${pulsar(0, 'Comprobar')}`)
  check('única: respuesta incorrecta', (await feedback(0)).startsWith('No es correcto'))
  await ev(pulsar(0, 'Pista'))
  check('única: la pista se muestra', await ev(`${Q(0)}.querySelector('.pista').classList.contains('visible')`))
  await ev(`${Q(0)}.querySelectorAll('input')[1].click(); ${pulsar(0, 'Comprobar')}`)
  check('única: respuesta correcta', (await feedback(0)) === '¡Correcto!')

  // 2) Opción múltiple: las dos correctas (1.ª y 3.ª).
  await ev(`(() => { const i = ${Q(1)}.querySelectorAll('input'); i[0].click(); i[2].click(); ${pulsar(1, 'Comprobar')} })()`)
  check('múltiple: correcta', (await feedback(1)) === '¡Correcto!')

  // 3) Selección en línea: «httponly» (índice 1).
  await ev(`(() => { const s = document.querySelector('select.lia-select'); s.value = '1'; s.dispatchEvent(new Event('change')) })()`)
  check('selección en línea: correcta', await ev(`document.querySelector('select.lia-select').classList.contains('es-correcta')`))

  // 4) Matriz: Cookie, Sesión, Sesión.
  await ev(`(() => { const f = ${Q(2)}.querySelectorAll('tbody tr'); [0, 1, 1].forEach((c, k) => f[k].querySelectorAll('input')[c].click()); ${pulsar(2, 'Comprobar')} })()`)
  check('matriz: correcta', (await feedback(2)) === '¡Correcto!')

  // 5) Texto con solución: «session_destroy» (mayúsculas y espacios no importan).
  await ev(`(() => { const i = ${Q(3)}.querySelector('input.lia-respuesta'); i.value = '  Session_Destroy '; ${pulsar(3, 'Comprobar')} })()`)
  check('texto: correcta y normalizada', (await feedback(3)) === '¡Correcto!')
  check('texto: la solución aparece al acertar', await ev(`${Q(3)}.querySelector('.solucion').classList.contains('visible')`))

  // 6) «Ver respuesta» marca la opción correcta.
  await abrir('1.5-autoevaluacion.html')
  await ev(pulsar(0, 'Ver respuesta'))
  check('ver respuesta: marca la correcta', await ev(`${Q(0)}.querySelectorAll('input')[1].checked && ${Q(0)}.querySelectorAll('.lia-opcion')[1].classList.contains('es-correcta')`))

  // 7) Encuesta: no guarda nada, solo confirma.
  await abrir('1.6-tu-opinion.html')
  await ev(`(() => { const e = document.querySelector('.encuesta'); e.querySelector('input').click(); [...e.querySelectorAll('button')].find(b => b.textContent === 'Enviar').click() })()`)
  check('encuesta: confirma sin guardar', (await ev(`document.querySelector('.encuesta .lia-feedback').textContent`)).includes('no se guarda'))

  // 8) Índice: página actual marcada.
  const actual = await ev(`[...document.querySelectorAll('.lia-indice a.actual')].map(a => a.textContent.replace(/\\s+/g, ' ').trim()).join(' | ')`)
  check('índice: página actual', actual === 'Tu opinión', actual)
} catch (e) {
  console.error('✗', e.message)
  checks.push(false)
}
check('sin errores de JavaScript', errores.length === 0, errores.join(' | '))
chrome.kill()
console.log(`\n${checks.filter(Boolean).length}/${checks.length} comprobaciones`)
process.exit(checks.every(Boolean) ? 0 : 1)
