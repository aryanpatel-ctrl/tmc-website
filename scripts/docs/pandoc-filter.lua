--[[
Pandoc Lua filter used by scripts/docs/build-docs.sh.

1. Links between Markdown documents point to the generated file of the same format, so the DOCX and
   PDF sets stay navigable after handover: "../manuals/x.md#4-users" becomes "../manuals/x.pdf"
   (or .docx). Fragments are dropped because heading anchors do not survive across separate files.
2. For PDF output, status symbols that the DejaVu fonts cannot render are replaced by words.
]]

local ext = FORMAT:match("docx") and ".docx" or ".pdf"

local symbols = {
  ["✅"] = "[Done]",
  ["🟡"] = "[Partial]",
  ["🔲"] = "[Planned]",
  ["⛔"] = "[Blocked]",
}

function Link(link)
  local target = link.target
  if target:match("^%a[%w+.-]*:") then
    return nil -- external link (http, https, mailto)
  end
  local path = target:gsub("#.*$", "")
  if path:match("%.md$") then
    link.target = path:gsub("%.md$", ext)
    return link
  end
  return nil
end

function Str(el)
  if ext ~= ".pdf" then
    return nil
  end
  local text = el.text
  local changed = false
  for symbol, word in pairs(symbols) do
    if text:find(symbol, 1, true) then
      text = text:gsub(symbol, word)
      changed = true
    end
  end
  if changed then
    return pandoc.Str(text)
  end
  return nil
end

--[[
3. Table column widths. The GFM reader gives tables no column widths, so LaTeX sets every column on a
   single line and wide tables run off the page. Each column first gets room for its longest word (so
   identifiers such as "R-4.8-1" or "BUILD/CERT" never split or overlap), and the rest of the line is
   shared in proportion to how much longer its longest cell is. Applies to PDF and DOCX.
]]
local LINE = 110 -- characters per table line: tables are set in footnotesize on A4 with 18 mm margins (approximate)

local CODE_WIDTH = 1.3 -- a monospace character is wider than an average proportional one

local WIDE = 1.3 -- capitals and digits are wider than an average lower-case letter

local function token_width(token)
  local width = 0
  for _, code in utf8.codes(token) do
    if (code >= 65 and code <= 90) or (code >= 48 and code <= 57) then
      width = width + WIDE
    else
      width = width + 1
    end
  end
  return width
end

local function measure_cell(cell)
  local len, word = 0, 0
  local function add(text, factor)
    for token in text:gmatch("%S+") do
      local ok, width = pcall(token_width, token)
      local l = (ok and width or #token) * factor
      if l > word then word = l end
      len = len + l + 1
    end
  end
  pandoc.walk_block(pandoc.Div(cell.contents), {
    Str = function(el) add(el.text, 1) end,
    Code = function(el) add(el.text, CODE_WIDTH) end,
  })
  return len, word
end

function Table(tbl)
  local n = #tbl.colspecs
  if n == 0 then
    return nil
  end
  local longest, word = {}, {}
  for i = 1, n do longest[i] = 1; word[i] = 1 end
  local function measure(rows)
    for _, row in ipairs(rows) do
      local col = 1
      for _, cell in ipairs(row.cells) do
        local span = cell.col_span or 1
        if col <= n and span == 1 then
          local len, w = measure_cell(cell)
          if len > longest[col] then longest[col] = len end
          if w > word[col] then word[col] = w end
        end
        col = col + span
      end
    end
  end
  measure(tbl.head.rows)
  for _, body in ipairs(tbl.bodies) do measure(body.body) end

  local natural = 0
  for i = 1, n do natural = natural + longest[i] + 2 end
  if natural <= LINE then
    return nil -- the table fits on one line per row: keep natural widths
  end

  local minimum, extra, min_total, extra_total = {}, {}, 0, 0
  for i = 1, n do
    minimum[i] = math.min(word[i], 36) + 4 -- + cell padding (\tabcolsep on both sides)
    extra[i] = math.max(0, math.min(longest[i], 80) - minimum[i])
    min_total = min_total + minimum[i]
    extra_total = extra_total + extra[i]
  end
  local budget = math.max(0, LINE - min_total)
  local chars, total = {}, 0
  for i = 1, n do
    chars[i] = minimum[i] + (extra_total > 0 and budget * extra[i] / extra_total or 0)
    total = total + chars[i]
  end
  for i = 1, n do
    tbl.colspecs[i] = { tbl.colspecs[i][1], chars[i] / total * 0.98 }
  end
  return tbl
end
