--[[
Filtro del PDF de contenido (roadmap docs/generacion_web, §3.4).

Regla: el PDF solo muestra contenido. Ni quizzes ni encuestas ni estructuras
que se muevan.

- Quizzes y encuestas (con pistas y soluciones): se omiten, junto con su
  enunciado (el párrafo inmediatamente anterior), y se eliminan los títulos
  que se quedan sin contenido (p. ej. «Autoevaluación»).
- Párrafos con selección en línea: se omiten (son una pregunta).
- Fragmentos animados y acordeones: su contenido, sin el mecanismo.
- Narración: se omite.
- Vídeo, audio y embebidos: una línea con el título y el enlace.
- Cajas: se conservan para pandoc-latex-environment + content-boxes.tex de
  la plantilla FP Virtual (que define nota, aviso e importante; «error» y
  «consejo» se asocian a «importante» y «nota»).
- Código ejecutable: código normal.
]]

local OMITIR = { narracion = true }
local PREGUNTA = { quiz = true, encuesta = true }
local DESPLEGAR = { fragmento = true }
local CAJA_PDF = { nota = 'nota', aviso = 'aviso', importante = 'importante', error = 'importante', consejo = 'nota' }
local MEDIO = { video = 'Vídeo', audio = 'Audio', embed = 'Contenido externo' }

local function tiene(el, clase)
  return el.classes:includes(clase)
end

function Div(el)
  for clase in pairs(OMITIR) do
    if tiene(el, clase) then return {} end
  end
  for clase in pairs(DESPLEGAR) do
    if tiene(el, clase) then return el.content end
  end
  if tiene(el, 'acordeon') then
    local titulo = el.attributes.titulo or ''
    local bloques = { pandoc.Para({ pandoc.Strong(pandoc.Inlines(titulo)) }) }
    for _, b in ipairs(el.content) do table.insert(bloques, b) end
    return bloques
  end
  for clase, etiqueta in pairs(MEDIO) do
    if tiene(el, clase) then
      local src = el.attributes.src or ''
      local titulo = el.attributes.titulo or src
      return pandoc.Para({
        pandoc.Emph(pandoc.Inlines(etiqueta .. ':')), pandoc.Space(),
        pandoc.Link(pandoc.Inlines(titulo), src),
      })
    end
  end
  for clase, destino in pairs(CAJA_PDF) do
    if tiene(el, clase) and clase ~= destino then
      el.classes = pandoc.List({ destino })
      return el
    end
  end
end

function Span(el)
  if tiene(el, 'fragmento') then return el.content end
  -- Cajas en línea: el texto, en cursiva.
  if CAJA_PDF[el.classes[1] or ''] then return pandoc.Emph(el.content) end
end

function Link(el)
  -- Multimedia en línea: «título (url)».
  for clase, etiqueta in pairs(MEDIO) do
    if el.classes:includes(clase) then
      return { pandoc.Str(etiqueta .. ':'), pandoc.Space(), pandoc.Link(el.content, el.target) }
    end
  end
end

function CodeBlock(el)
  el.classes = el.classes:filter(function(c) return c ~= 'ejecutable' end)
  return el
end

-- HTML en bruto (restos de LiaScript) no tiene sentido en el PDF.
function RawBlock(el)
  if el.format:match('html') then return {} end
end

function RawInline(el)
  if el.format:match('html') then return {} end
end

-- ---------------------------------------------------------------------------
-- Preguntas: se quitan con su enunciado (en cada lista de bloques, que Pandoc
-- procesa después de los elementos sueltos).
-- ---------------------------------------------------------------------------

local function es_pregunta(b)
  if b.t ~= 'Div' then return false end
  for clase in pairs(PREGUNTA) do
    if b.classes:includes(clase) then return true end
  end
  return false
end

local function tiene_seleccion(b)
  local hay = false
  if b.t == 'Para' or b.t == 'Plain' then
    b:walk({ Span = function(s) if s.classes:includes('quiz-select') then hay = true end end })
  end
  return hay
end

function Blocks(bloques)
  local salida = pandoc.Blocks({})
  for _, b in ipairs(bloques) do
    if es_pregunta(b) then
      local previo = salida[#salida]
      if previo and (previo.t == 'Para' or previo.t == 'Plain') then salida:remove(#salida) end
    elseif not tiene_seleccion(b) then
      salida:insert(b)
    end
  end
  return salida
end

--- Quita los títulos sin contenido hasta el siguiente título de igual o mayor nivel.
function Pandoc(doc)
  local cambiado = true
  while cambiado do
    cambiado = false
    local salida = pandoc.Blocks({})
    for i, b in ipairs(doc.blocks) do
      local siguiente = doc.blocks[i + 1]
      local vacio = b.t == 'Header' and (siguiente == nil or (siguiente.t == 'Header' and siguiente.level <= b.level))
      if vacio then
        cambiado = true
      else
        salida:insert(b)
      end
    end
    doc.blocks = salida
  end
  return doc
end
