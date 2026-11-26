--[[
Filtro de la salida web (roadmap docs/generacion_web, §3.3).

El lenguaje intermedio se deja casi intacto: Pandoc escribe los divs con su
clase y sus atributos como `data-*`, y liamat.js les da comportamiento
(quizzes, selección en línea, matriz, encuestas). Aquí solo se resuelve lo
que necesita HTML concreto:

- Narración: se omite (no hay lectura en voz alta).
- Vídeo, audio y embebidos: <iframe> o <video>/<audio>.
- Acordeón: <details>/<summary>.
- Cajas: contenedores con título, como los de vuepress-theme-hope.
]]

local function youtube(url)
  local id = url:match('youtube%.com/watch%?v=([%w_%-]+)') or url:match('youtu%.be/([%w_%-]+)')
    or url:match('youtube%.com/embed/([%w_%-]+)')
  return id and ('https://www.youtube-nocookie.com/embed/' .. id) or nil
end

local function escapar(s)
  return (s:gsub('&', '&amp;'):gsub('"', '&quot;'):gsub('<', '&lt;'):gsub('>', '&gt;'))
end

local function medio(tipo, src, titulo)
  local t = escapar(titulo or '')
  local s = escapar(src or '')
  if tipo == 'video' then
    local yt = youtube(src or '')
    if yt then
      return ('<figure class="lia-medio lia-video"><iframe src="%s" title="%s" loading="lazy" ' ..
        'allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>' ..
        '<figcaption>%s</figcaption></figure>'):format(escapar(yt), t, t)
    end
    return ('<figure class="lia-medio lia-video"><video src="%s" controls preload="metadata"></video>' ..
      '<figcaption>%s</figcaption></figure>'):format(s, t)
  elseif tipo == 'audio' then
    return ('<figure class="lia-medio lia-audio"><audio src="%s" controls preload="metadata"></audio>' ..
      '<figcaption>%s</figcaption></figure>'):format(s, t)
  end
  return ('<figure class="lia-medio lia-embed"><iframe src="%s" title="%s" loading="lazy"></iframe>' ..
    '<figcaption><a href="%s">%s</a></figcaption></figure>'):format(s, t, s, t)
end

-- Cajas → contenedores de aviso al estilo vuepress-theme-hope (`hint-container`
-- con título); se conserva la clase original.
local CAJAS = {
  nota = { 'info', 'Nota' },
  consejo = { 'tip', 'Consejo' },
  aviso = { 'warning', 'Aviso' },
  importante = { 'important', 'Importante' },
  error = { 'caution', 'Error' },
}

function Div(el)
  if el.classes:includes('narracion') then return {} end
  for clase, hope in pairs(CAJAS) do
    if el.classes:includes(clase) then
      el.classes:insert('hint-container')
      el.classes:insert(hope[1])
      el.content:insert(1, pandoc.RawBlock('html', '<p class="hint-container-title">' .. hope[2] .. '</p>'))
      return el
    end
  end
  for _, tipo in ipairs({ 'video', 'audio', 'embed' }) do
    if el.classes:includes(tipo) then
      return pandoc.RawBlock('html', medio(tipo, el.attributes.src, el.attributes.titulo))
    end
  end
  if el.classes:includes('acordeon') then
    local bloques = { pandoc.RawBlock('html', '<details class="lia-acordeon"><summary>' ..
      escapar(el.attributes.titulo or '') .. '</summary>') }
    for _, b in ipairs(el.content) do table.insert(bloques, b) end
    table.insert(bloques, pandoc.RawBlock('html', '</details>'))
    return bloques
  end
end

-- Imágenes sin URL (p. ej. un bloque de imagen del editor guardado sin
-- fichero: `![1.00]()`). chunkedhtml copia cada imagen local a la salida, con
-- la URL vacía intenta copiar "." y Pandoc falla («File '.' not found in
-- resource path»). Se omiten con un aviso del lector.
function Image(el)
  if (el.src or ''):match('^%s*$') then
    io.stderr:write(('AVISO-LIASCRIPT\t\timagen\timagen sin URL omitida (texto alternativo: «%s»)\n')
      :format(pandoc.utils.stringify(el.caption)))
    return {}
  end
end

-- La figura que contenía solo una imagen omitida queda vacía: se quita entera
-- (si no, quedaría su pie suelto). Los filtros de Inline corren antes que los
-- de Block, así que aquí la imagen ya no está.
function Figure(el)
  local imagenes = 0
  el.content:walk({ Image = function() imagenes = imagenes + 1 end })
  if imagenes == 0 and pandoc.utils.stringify(el.content):match('^%s*$') then
    return {}
  end
end

function Link(el)
  for _, tipo in ipairs({ 'video', 'audio', 'embed' }) do
    if el.classes:includes(tipo) then
      return pandoc.RawInline('html', medio(tipo, el.target, pandoc.utils.stringify(el.content)))
    end
  end
end
