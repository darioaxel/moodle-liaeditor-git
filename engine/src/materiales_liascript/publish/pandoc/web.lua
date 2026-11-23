--[[
Filtro de la salida web (roadmap docs/generacion_web, §3.3).

El lenguaje intermedio se deja casi intacto: Pandoc escribe los divs con su
clase y sus atributos como `data-*`, y liamat.js les da comportamiento
(quizzes, selección en línea, matriz, encuestas). Aquí solo se resuelve lo
que necesita HTML concreto:

- Narración: se omite (no hay lectura en voz alta).
- Vídeo, audio y embebidos: <iframe> o <video>/<audio>.
- Acordeón: <details>/<summary>.
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

function Div(el)
  if el.classes:includes('narracion') then return {} end
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

function Link(el)
  for _, tipo in ipairs({ 'video', 'audio', 'embed' }) do
    if el.classes:includes(tipo) then
      return pandoc.RawInline('html', medio(tipo, el.target, pandoc.utils.stringify(el.content)))
    end
  end
end
