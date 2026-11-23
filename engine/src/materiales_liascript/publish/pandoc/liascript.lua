--[[
Lector LiaScript para Pandoc (roadmap docs/generacion_web, §3.1).

Recibe el TEXTO ORIGINAL del Markdown LiaScript, convierte sus construcciones
al lenguaje intermedio (divs y spans con clase y atributos, §3.2) y delega en
el lector Markdown de Pandoc. Se hace en el texto y no en un filtro porque el
parseo de Pandoc estropea parte de la sintaxis (quizzes indentados → código,
`****` → separadores, comas de las macros).

Uso: pandoc -f liascript.lua material.md -t … (con los filtros web.lua o
contenido.lua según la salida).

Avisos: toda construcción LiaScript que no se convierte se escribe en stderr
como `AVISO-LIASCRIPT<TAB>línea<TAB>tipo<TAB>texto` y se guarda en los
metadatos `liascript-avisos`. Nunca se pierde nada en silencio.
]]

local CAJAS = { nota = true, aviso = true, error = true, importante = true, consejo = true }

local avisos = {}

local function aviso(linea, tipo, texto)
  table.insert(avisos, { linea = linea, tipo = tipo, texto = texto })
end

local function trim(s)
  return (s:gsub('^%s+', ''):gsub('%s+$', ''))
end

--- Valor de atributo seguro para el Markdown de Pandoc (entre comillas).
local function attr(v)
  return '"' .. tostring(v):gsub('\\', '\\\\'):gsub('"', '\\"') .. '"'
end

-- ---------------------------------------------------------------------------
-- Cabecera y macros
-- ---------------------------------------------------------------------------

--- Separa la cabecera `<!-- … -->` inicial: metadatos y macros.
local function leer_cabecera(texto)
  local ini, fin, cuerpo = texto:find('^%s*<!%-%-(.-)%-%->')
  if not ini then
    return {}, {}, texto, 0
  end
  local meta, macros = {}, {}
  local lineas = {}
  for l in (cuerpo .. '\n'):gmatch('(.-)\n') do
    table.insert(lineas, l)
  end
  local i, clave = 1, nil
  while i <= #lineas do
    local l = lineas[i]
    local nombre, valor = l:match('^@([%w_%.%-]+):%s*(.*)$')
    if nombre then
      -- Macro de una línea: `@nombre: valor`.
      macros[nombre] = valor
      clave = nil
    elseif l:match('^@([%w_%.%-]+)%s*$') then
      -- Macro de bloque: `@nombre` … `@end`.
      local n = l:match('^@([%w_%.%-]+)')
      local partes = {}
      i = i + 1
      while i <= #lineas and not lineas[i]:match('^@end%s*$') do
        table.insert(partes, lineas[i])
        i = i + 1
      end
      macros[n] = table.concat(partes, '\n')
      clave = nil
    else
      local k, v = l:match('^([%w_%-]+):%s*(.*)$')
      if k then
        clave = k
        meta[k] = trim(v)
      elseif clave and l:match('^%s+%S') then
        meta[clave] = meta[clave] .. ' ' .. trim(l)
      else
        clave = nil
      end
    end
    i = i + 1
  end
  local _, lineas_cabecera = texto:sub(1, fin):gsub('\n', '')
  return meta, macros, texto:sub(fin + 1), lineas_cabecera
end

--- Parámetros de una llamada `@macro(…)` desde la posición del `(`.
--- Un parámetro entre comillas invertidas puede llevar comas y paréntesis.
local function leer_parametros(s, pos)
  local params, actual, nivel, i = {}, '', 0, pos + 1
  while i <= #s do
    local c = s:sub(i, i)
    if c == '`' and actual:match('^%s*$') then
      local cierre = s:find('`', i + 1, true)
      if not cierre then return nil end
      actual = s:sub(i + 1, cierre - 1)
      i = cierre
    elseif c == '(' then
      nivel = nivel + 1
      actual = actual .. c
    elseif c == ')' then
      if nivel == 0 then
        table.insert(params, trim(actual))
        return params, i
      end
      nivel = nivel - 1
      actual = actual .. c
    elseif c == ',' and nivel == 0 then
      table.insert(params, trim(actual))
      actual = ''
    else
      actual = actual .. c
    end
    i = i + 1
  end
  return nil
end

local function sustituir_parametros(cuerpo, params)
  return (cuerpo:gsub('@(%d)', function(n)
    return params[tonumber(n) + 1] or ''
  end))
end

-- ---------------------------------------------------------------------------
-- Conversión de línea (elementos en línea)
-- ---------------------------------------------------------------------------

local function convertir_en_linea(l, macros, n)
  -- Selección en línea: [[ a | (b) | c ]]
  l = l:gsub('%[%[([^%]]-|[^%]]-)%]%]', function(dentro)
    local opciones, correcta = {}, 0
    for op in (dentro .. '|'):gmatch('(.-)|') do
      op = trim(op)
      local marcada = op:match('^%((.*)%)$')
      if marcada then
        op = trim(marcada)
        correcta = #opciones
      end
      table.insert(opciones, op)
    end
    return '[' .. table.concat(opciones, ' / ') .. ']{.quiz-select opciones=' ..
      attr(table.concat(opciones, '|')) .. ' correcta="' .. correcta .. '"}'
  end)
  -- Efecto en línea: {1}{texto}
  l = l:gsub('{(%d+)}{([^{}]*)}', function(paso, texto)
    return '[' .. texto .. ']{.fragmento paso="' .. paso .. '"}'
  end)
  -- Multimedia en línea.
  l = l:gsub('!%?%[([^%]]*)%]%(([^%)]+)%)', '[%1](%2){.video}')
  l = l:gsub('%?%?%[([^%]]*)%]%(([^%)]+)%)', '[%1](%2){.embed}')
  l = l:gsub('([^!%?%]])%?%[([^%]]*)%]%(([^%)]+)%)', '%1[%2](%3){.audio}')
  l = l:gsub('^%?%[([^%]]*)%]%(([^%)]+)%)', '[%1](%2){.audio}')
  -- Macros en línea (cajas como span; el resto, por su definición). No son
  -- macros las de un correo (texto@dominio) ni las de un `código en línea`.
  local salida, pos = '', 1
  while true do
    local ini, fin, nombre = l:find('@([%a_][%w_%.%-]*)', pos)
    if not ini then break end
    local previo = ini > 1 and l:sub(ini - 1, ini - 1) or ''
    local _, comillas = l:sub(1, ini - 1):gsub('`', '')
    if previo:match('[%w%._%-]') or comillas % 2 == 1 then
      salida = salida .. l:sub(pos, fin)
      pos = fin + 1
    else
      local params, cierre = {}, fin
      if l:sub(fin + 1, fin + 1) == '(' then
        local p, c = leer_parametros(l, fin + 1)
        if p then params, cierre = p, c end
      end
      salida = salida .. l:sub(pos, ini - 1)
      if CAJAS[nombre] then
        salida = salida .. '[' .. (params[1] or '') .. ']{.' .. nombre .. '}'
      elseif macros[nombre] and not macros[nombre]:find('\n') then
        salida = salida .. sustituir_parametros(macros[nombre], params)
      else
        aviso(n, 'macro', '@' .. nombre .. ' no se puede expandir')
        salida = salida .. l:sub(ini, cierre)
      end
      pos = cierre + 1
    end
  end
  return salida .. l:sub(pos)
end

-- ---------------------------------------------------------------------------
-- Patrones de bloque
-- ---------------------------------------------------------------------------

local function sin_sangria(l)
  return (l:gsub('^%s+', ''))
end

local function es_estrellas(l)
  return l:match('^%s*%*%*%*+%s*$') ~= nil
end

--- Tipo de línea de quiz/encuesta y sus partes, o nil.
local function linea_quiz(l)
  local s = sin_sangria(l)
  local t = s:match('^%[%[%?%]%]%s*(.*)$')
  if t then return 'pista', t end
  if s:match('^%[%[!%]%]%s*$') then return 'confirmar' end
  -- Encuestas: número y texto detrás ([[4]] solo es una respuesta corta).
  local num, texto = s:match('^%[%((%d+)%)%]%s+(%S.*)$')
  if num then return 'encuesta-single', texto end
  num, texto = s:match('^%[%[(%d+)%]%]%s+(%S.*)$')
  if num then return 'encuesta-multiple', texto end
  if s:match('^%[%[_+%]%]%s*$') then return 'encuesta-text' end
  local marca, resto = s:match('^%[%(([ Xx]?)%)%]%s*(.*)$')
  if marca then return 'single', resto, marca:upper() == 'X' end
  marca, resto = s:match('^%[%[([ Xx]?)%]%]%s+(%S.*)$')
  if marca then return 'multiple', resto, marca:upper() == 'X' end
  if s:match('^%[%[.-%]%s*%(.-%)%]%s*$') or s:match('^%[%[.-%]%s*%[.-%]%]%s*$') then
    return 'matriz-cabecera', s
  end
  if s:match('^%[%s*[%(%[][ Xx]?[%)%]]') then return 'matriz-fila', s end
  local respuesta = s:match('^%[%[([^%]|]+)%]%]%s*$')
  if respuesta then return 'text', trim(respuesta) end
  return nil
end

-- ---------------------------------------------------------------------------
-- Conversión de bloques
-- ---------------------------------------------------------------------------

local convertir

--- Recoge un bloque `****` … `****` desde i (que es la apertura).
local function bloque_estrellas(L, i)
  local j = i + 1
  while j <= #L and not es_estrellas(L[j].t) do j = j + 1 end
  local dentro = {}
  for k = i + 1, math.min(j - 1, #L) do table.insert(dentro, L[k]) end
  return dentro, j
end

local function quiz(L, i, macros, out)
  local tipo_bloque, opciones, pistas, filas, cabecera = nil, {}, {}, {}, nil
  local respuesta
  local j = i
  while j <= #L do
    local tipo, texto, correcta = linea_quiz(L[j].t)
    if not tipo then break end
    if tipo == 'pista' then
      table.insert(pistas, texto)
    elseif tipo == 'matriz-cabecera' then
      tipo_bloque, cabecera = 'matrix', texto
    elseif tipo == 'matriz-fila' then
      table.insert(filas, texto)
    elseif tipo == 'text' then
      tipo_bloque, respuesta = 'text', texto
    else
      tipo_bloque = tipo_bloque or tipo
      table.insert(opciones, { texto = texto or '', correcta = correcta })
    end
    j = j + 1
  end
  -- Script de comprobación propio del quiz: no se traslada.
  if j <= #L and L[j].t:match('^%s*<script') then
    aviso(L[j].n, 'script', 'comprobación con <script> de un quiz: se usa la respuesta escrita')
    while j <= #L and not L[j].t:match('</script>') do j = j + 1 end
    j = j + 1
  end
  local solucion
  if j <= #L and es_estrellas(L[j].t) then
    solucion, j = bloque_estrellas(L, j)
    j = j + 1
  end

  local encuesta = tipo_bloque and tipo_bloque:match('^encuesta%-(.*)$')
  local clase = encuesta and '.encuesta' or '.quiz'
  local tipo = encuesta or tipo_bloque or 'single'
  local attrs = clase .. ' tipo="' .. tipo .. '"'
  if respuesta then attrs = attrs .. ' respuesta=' .. attr(respuesta) end
  if cabecera then
    local columnas = {}
    for c in cabecera:sub(2):gmatch('[%[%(]%s*([^%]%)]-)%s*[%]%)]') do
      table.insert(columnas, trim(c))
    end
    attrs = attrs .. ' columnas=' .. attr(table.concat(columnas, '|'))
  end
  table.insert(out, '::: {' .. attrs .. '}')
  for _, op in ipairs(opciones) do
    table.insert(out, '::: {.opcion correcta="' .. tostring(op.correcta == true) .. '"}')
    table.insert(out, convertir_en_linea(op.texto, macros, L[i].n))
    table.insert(out, ':::')
  end
  for _, fila in ipairs(filas) do
    local marcas, texto = fila:match('^%[(.-)%]%s*(.*)$')
    local idx, k = -1, 0
    for m in (marcas or ''):gmatch('[%(%[]([ Xx]?)[%)%]]') do
      if m:upper() == 'X' then idx = k end
      k = k + 1
    end
    table.insert(out, '::: {.fila correcta="' .. idx .. '"}')
    table.insert(out, convertir_en_linea(texto or '', macros, L[i].n))
    table.insert(out, ':::')
  end
  for _, p in ipairs(pistas) do
    table.insert(out, '::: pista')
    table.insert(out, convertir_en_linea(p, macros, L[i].n))
    table.insert(out, ':::')
  end
  if solucion then
    table.insert(out, '::: solucion')
    convertir(solucion, macros, out)
    table.insert(out, ':::')
  end
  table.insert(out, ':::')
  table.insert(out, '')
  return j
end

--- Bloque `{{n}}`: el siguiente bloque `****` o el siguiente párrafo.
local function fragmento(L, i, paso, macros, out)
  local j = i + 1
  local dentro
  if j <= #L and es_estrellas(L[j].t) then
    dentro, j = bloque_estrellas(L, j)
    j = j + 1
  else
    dentro = {}
    while j <= #L and not L[j].t:match('^%s*$') do
      table.insert(dentro, L[j])
      j = j + 1
    end
  end
  table.insert(out, '::: {.fragmento paso="' .. paso .. '"}')
  convertir(dentro, macros, out)
  table.insert(out, ':::')
  table.insert(out, '')
  return j
end

--- Narración `--{{n}}--`: el párrafo siguiente.
local function narracion(L, i, paso, macros, out)
  local j = i + 1
  table.insert(out, '::: {.narracion paso="' .. paso .. '"}')
  while j <= #L and not L[j].t:match('^%s*$') do
    table.insert(out, convertir_en_linea(L[j].t, macros, L[j].n))
    j = j + 1
  end
  table.insert(out, ':::')
  table.insert(out, '')
  return j
end

local function multimedia(l)
  local s = trim(l)
  local titulo, url = s:match('^!%?%[([^%]]*)%]%(([^%)]+)%)$')
  if titulo then return 'video', titulo, url end
  titulo, url = s:match('^%?%?%[([^%]]*)%]%(([^%)]+)%)$')
  if titulo then return 'embed', titulo, url end
  titulo, url = s:match('^%?%[([^%]]*)%]%(([^%)]+)%)$')
  if titulo then return 'audio', titulo, url end
  return nil
end

--- Marca de ejecución que LiaScript pone justo debajo de un bloque de código.
local function marca_ejecutable(l)
  if not l then return false end
  local s = trim(l)
  return s:match('^<script>.*@input.*</script>$') ~= nil
    or s:match('^@[%w_]+%.eval') ~= nil
    or s:match('^@LIA%.') ~= nil
end

convertir = function(L, macros, out)
  local i = 1
  while i <= #L do
    local l, n = L[i].t, L[i].n
    local valla = l:match('^%s*(```+)') or l:match('^%s*(~~~+)')
    if valla then
      -- Bloque de código: se copia tal cual; LiaScript usa la info y la línea
      -- siguiente para nombres de fichero y ejecución.
      local info = trim(l:gsub('^%s*[`~]+', ''))
      local j = i + 1
      while j <= #L and not L[j].t:match('^%s*' .. valla:sub(1, 1):rep(#valla)) do j = j + 1 end
      local lenguaje, resto = info:match('^(%S*)%s*(.*)$')
      local clases = {}
      if lenguaje and lenguaje ~= '' then table.insert(clases, '.' .. lenguaje) end
      local extra = ''
      if resto and resto ~= '' then
        local fichero = resto:match('^[%+%-]?(%S+)')
        if fichero and not fichero:match('^@') then extra = ' titulo=' .. attr(fichero) end
        if resto:match('@') then table.insert(clases, '.ejecutable') end
      end
      local ejecutable = marca_ejecutable(L[j + 1] and L[j + 1].t)
      if ejecutable then table.insert(clases, '.ejecutable') end
      if #clases > 0 or extra ~= '' then
        table.insert(out, valla .. ' {' .. table.concat(clases, ' ') .. extra .. '}')
      else
        table.insert(out, valla)
      end
      for k = i + 1, math.min(j, #L) do table.insert(out, L[k].t) end
      i = j + 1
      if ejecutable then i = i + 1 end
    elseif l:match('^%s*$') then
      table.insert(out, '')
      i = i + 1
    elseif l:match('^%s*%-%-{{%d+}}%-%-%s*$') then
      i = narracion(L, i, l:match('{{(%d+)}}'), macros, out)
    elseif l:match('^%s*{{%d+%-?%d*}}%s*$') then
      i = fragmento(L, i, l:match('{{(%d+)'), macros, out)
    elseif linea_quiz(l) then
      i = quiz(L, i, macros, out)
    elseif l:match('^%s*<!%-%-.*%-%->%s*$') then
      -- Comentario de estilo/animación de LiaScript tras un elemento.
      aviso(n, 'estilo', trim(l))
      i = i + 1
    elseif l:match('^%s*<script') then
      aviso(n, 'script', 'bloque <script> omitido')
      while i <= #L and not L[i].t:match('</script>') do i = i + 1 end
      i = i + 1
    elseif multimedia(l) then
      local tipo, titulo, url = multimedia(l)
      table.insert(out, '::: {.' .. tipo .. ' src=' .. attr(url) .. ' titulo=' .. attr(titulo) .. '}')
      table.insert(out, ':::')
      table.insert(out, '')
      i = i + 1
    else
      -- Caja como bloque: una línea que es solo `@nota(…)`.
      local nombre = trim(l):match('^@([%a_][%w_%.%-]*)%(')
      local params, cierre
      if nombre and CAJAS[nombre] then
        local s = trim(l)
        params, cierre = leer_parametros(s, #nombre + 2)
        if params and cierre == #s then
          table.insert(out, '::: ' .. nombre)
          table.insert(out, convertir_en_linea(params[1] or '', macros, n))
          table.insert(out, ':::')
          table.insert(out, '')
          i = i + 1
          goto continuar
        end
      end
      if l:match('^    ') and not l:match('^%s*[%-%*%+%d]') then
        -- Código indentado: literal.
        table.insert(out, l)
      else
        table.insert(out, convertir_en_linea(l, macros, n))
      end
      i = i + 1
    end
    ::continuar::
  end
  return out
end

-- ---------------------------------------------------------------------------
-- Lector
-- ---------------------------------------------------------------------------

--- Restos de sintaxis LiaScript que la conversión no ha absorbido.
local function revisar_restos(salida, lineas_origen)
  local en_codigo = false
  for _, l in ipairs(salida) do
    if l:match('^%s*```') or l:match('^%s*~~~') then en_codigo = not en_codigo end
    if not en_codigo and not l:match('^:::') then
      if l:match('{{%d') or l:match('%-%-{{') then
        aviso(nil, 'resto', 'marca de animación sin convertir: ' .. trim(l))
      end
    end
  end
end

function Reader(input, opts)
  avisos = {}
  local texto = tostring(input):gsub('\r\n', '\n')
  local meta, macros, cuerpo, desfase = leer_cabecera(texto)
  local L, n = {}, desfase
  for l in (cuerpo .. '\n'):gmatch('(.-)\n') do
    n = n + 1
    table.insert(L, { t = l, n = n })
  end
  if meta.import then
    aviso(nil, 'import', 'plantilla importada (sus macros no se expanden): ' .. meta.import)
  end
  local salida = convertir(L, macros, {})
  revisar_restos(salida, L)

  -- ascii_identifiers: nombres de página sin tildes (más robustos en URLs de Moodle).
  local doc = pandoc.read(table.concat(salida, '\n'), 'markdown+ascii_identifiers', opts)
  if meta.title then doc.meta.title = pandoc.MetaInlines(pandoc.read(meta.title, 'markdown').blocks[1].content) end
  if meta.author then doc.meta.author = pandoc.MetaInlines({ pandoc.Str(meta.author) }) end
  if meta.language then doc.meta.lang = pandoc.MetaString(meta.language) end
  if meta.version then doc.meta.version = pandoc.MetaString(meta.version) end
  if meta.comment then doc.meta.description = pandoc.MetaString(meta.comment) end

  local lista = {}
  for _, a in ipairs(avisos) do
    io.stderr:write(('AVISO-LIASCRIPT\t%s\t%s\t%s\n'):format(a.linea or '', a.tipo, a.texto))
    table.insert(lista, pandoc.MetaString(('%s: %s (línea %s)'):format(a.tipo, a.texto, a.linea or '?')))
  end
  doc.meta['liascript-avisos'] = pandoc.MetaList(lista)
  return doc
end
