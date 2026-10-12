/**
 * Ishchi varaq hisoblagichi — brauzer uchun.
 *
 * Bu fayl serverdagi PHP klasslarining aynan nusxasi:
 *   app/Services/Worksheets/Rounding.php, Formula.php,
 *   WorksheetCalculator.php, TemplateValidator.php
 * Ekrandagi jonli hisob va serverdagi rasmiy hisob bir xil chiqishi uchun
 * ikkala tomondagi o'zgarish birga kiritilishi kerak (tests_engine/ dagi
 * solishtirish testi buni tekshiradi).
 *
 * Vue/axios'ga bog'liq emas — Node'da ham ishlaydi.
 */

/* =============================================================== Rounding */

export const ROUNDING_MODES = ['round', 'trunc', 'none']

function fixed(x, p) {
  return Number(x.toFixed(p))
}

export function roundValue(value, decimals, mode = 'round') {
  if (value === null || value === undefined || !Number.isFinite(value)) return null
  if (decimals === null || decimals === undefined || decimals === '' || mode === 'none') return value

  const d = Math.max(0, Math.min(10, parseInt(decimals, 10)))
  const factor = 10 ** d

  const clean = fixed(value, 10)
  const scaled = fixed(clean * factor, 6)
  const abs = Math.abs(scaled)

  let int = mode === 'trunc' ? Math.floor(abs) : Math.floor(abs + 0.5)
  if (scaled < 0) int = -int
  if (int === 0) int = 0

  return fixed(int / factor, d)
}

/* ================================================================ Formula */

export class FormulaError extends Error {}

export const FUNCTIONS = [
  'round', 'trunc', 'floor', 'ceil', 'abs', 'sqrt', 'pow',
  'min', 'max', 'avg', 'sum', 'count',
]
const AGGREGATES = ['min', 'max', 'avg', 'sum', 'count']
const MAX_LENGTH = 500
const MAX_DEPTH = 64

function tokenize(s) {
  const tokens = []
  let i = 0
  const isDigit = (c) => c >= '0' && c <= '9'
  const isAlpha = (c) => (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z')

  while (i < s.length) {
    const ch = s[i]
    if (/\s/.test(ch)) { i++; continue }

    if (isDigit(ch) || (ch === '.' && i + 1 < s.length && isDigit(s[i + 1]))) {
      const start = i
      while (i < s.length && isDigit(s[i])) i++
      if (i < s.length && s[i] === '.') {
        i++
        while (i < s.length && isDigit(s[i])) i++
      }
      tokens.push(['num', parseFloat(s.slice(start, i)), start])
      continue
    }

    if (isAlpha(ch) || ch === '_') {
      const start = i
      while (i < s.length && (isAlpha(s[i]) || isDigit(s[i]) || s[i] === '_')) i++
      tokens.push(['id', s.slice(start, i).toLowerCase(), start])
      continue
    }

    if ('+-*/^(),'.includes(ch)) {
      tokens.push(['op', ch, i])
      i++
      continue
    }

    throw new FormulaError(`Ruxsat etilmagan belgi: «${ch}» (${i + 1}-pozitsiya)`)
  }
  tokens.push(['end', null, s.length])
  return tokens
}

function unexpected(t) {
  if (t[0] === 'end') return 'formula kutilmaganda tugadi'
  const text = String(t[1])
  const hint = text === ',' ? " (o'nlik son uchun nuqta ishlating: 0.5)" : ''
  return `kutilmagan «${text}» (${t[2] + 1}-pozitsiya)${hint}`
}

export function parseFormula(source) {
  const src = String(source ?? '').trim()
  if (src === '') throw new FormulaError("Formula bo'sh")
  if ([...src].length > MAX_LENGTH) throw new FormulaError(`Formula juda uzun (${MAX_LENGTH} belgidan oshmasin)`)

  const tokens = tokenize(src)
  let pos = 0
  let depth = 0

  const peek = () => tokens[pos]
  const next = () => tokens[pos++]
  const isOp = (op) => peek()[0] === 'op' && peek()[1] === op
  const enter = () => { if (++depth > MAX_DEPTH) throw new FormulaError('Formula juda chuqur ichma-ich') }
  const leave = () => { depth-- }
  const expectOp = (op) => {
    const t = next()
    if (t[0] !== 'op' || t[1] !== op) throw new FormulaError(`«${op}» kutilgan edi — ${unexpected(t)}`)
  }

  function expression() {
    enter()
    let left = term()
    while (isOp('+') || isOp('-')) {
      const op = next()[1]
      left = ['b', op, left, term()]
    }
    leave()
    return left
  }

  function term() {
    let left = unary()
    while (isOp('*') || isOp('/')) {
      const op = next()[1]
      left = ['b', op, left, unary()]
    }
    return left
  }

  function unary() {
    if (isOp('-') || isOp('+')) {
      const op = next()[1]
      enter()
      const operand = unary()
      leave()
      return op === '-' ? ['u', '-', operand] : operand
    }
    return power()
  }

  function power() {
    const base = primary()
    if (isOp('^')) {
      next()
      enter()
      const exp = unary()
      leave()
      return ['b', '^', base, exp]
    }
    return base
  }

  function primary() {
    const t = next()
    if (t[0] === 'num') return ['n', t[1]]

    if (t[0] === 'id') {
      if (isOp('(')) {
        if (!FUNCTIONS.includes(t[1])) throw new FormulaError(`Noma'lum funksiya: «${t[1]}»`)
        next()
        const args = []
        if (!isOp(')')) {
          args.push(expression())
          while (isOp(',')) {
            next()
            args.push(expression())
          }
        }
        expectOp(')')
        return ['f', t[1], args]
      }
      if (FUNCTIONS.includes(t[1])) {
        throw new FormulaError(`«${t[1]}» — funksiya, undan keyin qavs kerak: ${t[1]}(…)`)
      }
      return ['v', t[1]]
    }

    if (t[0] === 'op' && t[1] === '(') {
      const inner = expression()
      expectOp(')')
      return inner
    }

    throw new FormulaError(unexpected(t))
  }

  const ast = expression()
  if (peek()[0] !== 'end') throw new FormulaError(unexpected(peek()))
  return ast
}

export function formulaIdentifiers(ast) {
  const out = new Set()
  const walk = (node) => {
    switch (node[0]) {
      case 'v': out.add(node[1]); break
      case 'u': walk(node[2]); break
      case 'b': walk(node[2]); walk(node[3]); break
      case 'f': node[2].forEach(walk); break
    }
  }
  walk(ast)
  return [...out]
}

function assertScalar(value, node) {
  if (Array.isArray(value)) {
    const name = node[0] === 'v' ? `«${node[1]}»` : 'ifoda'
    throw new FormulaError(
      `${name} — bir nechta qator qiymati; uni avg(), sum(), min(), max() yoki count() ichida ishlating`,
    )
  }
}

function evalNode(node, vars) {
  switch (node[0]) {
    case 'n':
      return node[1]

    case 'v': {
      if (!Object.prototype.hasOwnProperty.call(vars, node[1])) {
        throw new FormulaError(`Noma'lum o'zgaruvchi: «${node[1]}»`)
      }
      const val = vars[node[1]]
      if (Array.isArray(val)) return [...val]
      return val === null || val === undefined ? null : Number(val)
    }

    case 'u': {
      const x = evalNode(node[2], vars)
      assertScalar(x, node[2])
      return x === null ? null : -x
    }

    case 'b': {
      const a = evalNode(node[2], vars)
      const b = evalNode(node[3], vars)
      assertScalar(a, node[2])
      assertScalar(b, node[3])
      if (a === null || b === null) return null
      let r
      switch (node[1]) {
        case '+': r = a + b; break
        case '-': r = a - b; break
        case '*': r = a * b; break
        case '/':
          if (b === 0) return null
          r = a / b
          break
        default: r = a ** b
      }
      return Number.isFinite(r) ? r : null
    }

    case 'f':
      return callFn(node[1], node[2], vars)
  }
  throw new FormulaError("Noto'g'ri formula tuzilmasi")
}

const ARITY = {
  round: [1, 2], trunc: [1, 2], floor: [1, 1], ceil: [1, 1],
  abs: [1, 1], sqrt: [1, 1], pow: [2, 2],
}

function callFn(fn, argNodes, vars) {
  const args = argNodes.map((n) => evalNode(n, vars))

  if (AGGREGATES.includes(fn)) {
    if (args.length === 0) throw new FormulaError(`${fn}() kamida bitta argument talab qiladi`)
    const flat = []
    for (const a of args) {
      for (const x of Array.isArray(a) ? a : [a]) {
        if (x !== null && x !== undefined) flat.push(Number(x))
      }
    }
    switch (fn) {
      case 'count': return flat.length
      case 'sum': return flat.length ? flat.reduce((s, x) => s + x, 0) : null
      case 'avg': return flat.length ? flat.reduce((s, x) => s + x, 0) / flat.length : null
      case 'min': return flat.length ? Math.min(...flat) : null
      default: return flat.length ? Math.max(...flat) : null
    }
  }

  args.forEach((a, i) => assertScalar(a, argNodes[i]))

  const [lo, hi] = ARITY[fn]
  if (args.length < lo || args.length > hi) {
    const need = lo === hi ? lo : `${lo}–${hi}`
    throw new FormulaError(`${fn}() ${need} ta argument qabul qiladi`)
  }

  const x = args[0]
  if (x === null) return null

  switch (fn) {
    case 'round':
    case 'trunc': {
      const n = args.length > 1 ? args[1] : 0
      if (n === null) return null
      if (n < 0 || n > 10 || Math.floor(n) !== n) {
        throw new FormulaError(`${fn}() ning ikkinchi argumenti 0 dan 10 gacha butun son bo'lishi kerak`)
      }
      return roundValue(x, n, fn)
    }
    case 'floor': return Math.floor(x)
    case 'ceil': return Math.ceil(x)
    case 'abs': return Math.abs(x)
    case 'sqrt': return x < 0 ? null : Math.sqrt(x)
    default: {
      if (args[1] === null) return null
      const r = x ** args[1]
      return Number.isFinite(r) ? r : null
    }
  }
}

export function evaluateFormula(ast, vars) {
  const v = evalNode(ast, vars)
  if (Array.isArray(v)) {
    throw new FormulaError(
      "Natija bitta son bo'lishi kerak — ustun ro'yxatini avg(), sum(), min(), max() yoki count() ichida ishlating",
    )
  }
  return v
}

/* ============================================================= Calculator */

/** "0,093" → 0.093; bo'sh → null; son emas → false */
export function parseNumber(raw) {
  if (raw === null || raw === undefined) return null
  if (typeof raw === 'number') return Number.isFinite(raw) ? raw : false
  if (typeof raw !== 'string') return false
  const s = raw.trim().replace(/[  ]/g, '').replace(/,/g, '.')
  if (s === '') return null
  if (!/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/.test(s)) return false
  return Number(s)
}

export function isComputed(c) {
  const scope = c.scope ?? 'row'
  return scope === 'summary' || scope === 'total' || (c.type ?? 'input') === 'computed'
}

function decimalsOf(c) {
  const d = c.decimals
  return d === null || d === undefined || d === '' ? null : parseInt(d, 10)
}

export function orderColumns(cols, asts) {
  const keys = cols.map((c) => c.key)
  const inScope = new Set(keys)
  const deps = {}
  for (const k of keys) {
    const ids = asts[k] ? formulaIdentifiers(asts[k]) : []
    deps[k] = ids.filter((id) => inScope.has(id))
  }
  const order = []
  const done = new Set()
  let progress = true
  while (progress) {
    progress = false
    for (const k of keys) {
      if (done.has(k)) continue
      if (deps[k].every((d) => done.has(d))) {
        done.add(k)
        order.push(k)
        progress = true
      }
    }
  }
  return [order, keys.filter((k) => !done.has(k))]
}

/**
 * rows: [{ values, excluded, group }] — group: qator qaysi probaga tegishli (partiya varaq).
 * Ko'lamlar: row — qator, summary — har proba bo'yicha, total — butun varaq bo'yicha jami.
 * PHP'dagi WorksheetCalculator::calculate() bilan aynan bir xil natija beradi.
 */
export function calculate(definition, header = {}, rows = []) {
  const params = definition?.params ?? []
  const columns = definition?.columns ?? []
  const rowCols = columns.filter((c) => (c.scope ?? 'row') === 'row')
  const sumCols = columns.filter((c) => (c.scope ?? 'row') === 'summary')
  const totCols = columns.filter((c) => (c.scope ?? 'row') === 'total')

  const errors = []
  const errorSeen = new Set()
  const addError = (scope, key, message, row = null) => {
    const sig = `${scope}|${key}|${message}`
    if (errorSeen.has(sig)) return
    errorSeen.add(sig)
    errors.push({ scope, key, row, message })
  }

  const paramVals = {}
  for (const p of params) {
    let raw = header?.[p.key] ?? null
    if (raw === null || raw === '') raw = p.default ?? null
    let num = parseNumber(raw)
    if (num === false) {
      addError('param', p.key, `Son emas: «${raw}»`)
      num = null
    }
    paramVals[p.key] = num
  }

  const asts = {}
  for (const c of columns) {
    if (!isComputed(c)) continue
    try {
      asts[c.key] = parseFormula(c.formula ?? '')
    } catch (e) {
      asts[c.key] = null
      addError(c.scope ?? 'row', c.key, e.message)
    }
  }

  const [rowOrder, rowCycle] = orderColumns(rowCols.filter(isComputed), asts)
  const [sumOrder, sumCycle] = orderColumns(sumCols, asts)
  const [totOrder, totCycle] = orderColumns(totCols, asts)
  rowCycle.forEach((k) => addError('row', k, "Formulalar bir-biriga aylanma bog'langan"))
  sumCycle.forEach((k) => addError('summary', k, "Formulalar bir-biriga aylanma bog'langan"))
  totCycle.forEach((k) => addError('total', k, "Formulalar bir-biriga aylanma bog'langan"))

  const rowColByKey = Object.fromEntries(rowCols.map((c) => [c.key, c]))
  const sumColByKey = Object.fromEntries(sumCols.map((c) => [c.key, c]))
  const totColByKey = Object.fromEntries(totCols.map((c) => [c.key, c]))

  const outRows = []
  const rowValues = []
  rows.forEach((row, i) => {
    const values = row?.values ?? {}
    const vars = { ...paramVals }
    const invalid = {}

    for (const c of rowCols) {
      if ((c.type ?? 'input') !== 'input') continue
      let num = parseNumber(values[c.key] ?? null)
      if (num === false) {
        invalid[c.key] = 'Son emas'
        num = null
      }
      vars[c.key] = num
    }

    const computed = {}
    for (const key of rowOrder) {
      const c = rowColByKey[key]
      let value = null
      if (asts[key]) {
        try {
          value = roundValue(evaluateFormula(asts[key], vars), decimalsOf(c), c.rounding ?? 'round')
        } catch (e) {
          if (!(e instanceof FormulaError)) throw e
          addError('row', key, e.message)
        }
      }
      vars[key] = value
      computed[key] = value
    }
    for (const key of rowCycle) {
      computed[key] = null
      vars[key] = null
    }

    rowValues[i] = vars
    outRows[i] = { computed, invalid, out_of_tolerance: [] }
  })

  // Yakuniy formulalar uchun: parametrlar (son) + qator ustunlari (ro'yxat)
  const listVars = (indices) => {
    const vars = { ...paramVals }
    for (const c of rowCols) {
      if ((c.type ?? 'input') === 'text') continue
      vars[c.key] = indices.map((i) => rowValues[i][c.key] ?? null)
    }
    return vars
  }

  const evalScope = (order, colByKey, cycle, vars, scope) => {
    const out = {}
    for (const key of order) {
      const c = colByKey[key]
      let value = null
      if (asts[key]) {
        try {
          value = roundValue(evaluateFormula(asts[key], vars), decimalsOf(c), c.rounding ?? 'round')
        } catch (e) {
          if (!(e instanceof FormulaError)) throw e
          addError(scope, key, e.message)
        }
      }
      vars[key] = value
      out[key] = value
    }
    for (const key of cycle) out[key] = null
    return out
  }

  // ---- Guruhlar (probalar) — qatorlar paydo bo'lish tartibida ----
  const groupOrder = []
  const groupRows = new Map()
  rows.forEach((row, i) => {
    const g = row?.group ?? null
    const sig = g === null || g === '' ? '_' : String(g)
    if (!groupRows.has(sig)) {
      groupRows.set(sig, [])
      groupOrder.push({ sig, key: g === null || g === '' ? '_' : g })
    }
    groupRows.get(sig).push(i)
  })
  if (!groupOrder.length) {
    groupOrder.push({ sig: '_', key: '_' })
    groupRows.set('_', [])
  }

  let hasWarnings = errors.length > 0
  const groups = groupOrder.map((g) => {
    const members = groupRows.get(g.sig)
    const included = members.filter((i) => !rows[i]?.excluded)

    const summary = evalScope(sumOrder, sumColByKey, sumCycle, listVars(included), 'summary')

    const tolerance = {}
    let groupWarn = false
    for (const c of sumCols) {
      const limit = c.tolerance_pct === null || c.tolerance_pct === undefined || c.tolerance_pct === ''
        ? null : parseFloat(c.tolerance_pct)
      const source = c.tolerance_source ?? null
      if (!limit || limit <= 0 || !source || !rowColByKey[source]) continue
      const mode = (c.tolerance_mode ?? 'mean') === 'range' ? 'range' : 'mean'

      const vals = []
      for (const i of included) {
        const v = rowValues[i][source] ?? null
        if (v !== null) vals.push([i, v])
      }

      const entry = { source, limit, mode, deviation: null, ok: null, rows: [], n: vals.length }

      if (vals.length >= 2) {
        const mean = vals.reduce((s, [, v]) => s + v, 0) / vals.length
        if (mean !== 0) {
          const bad = []
          let dev
          if (mode === 'range') {
            const nums = vals.map(([, v]) => v)
            const max = Math.max(...nums)
            const min = Math.min(...nums)
            dev = ((max - min) / Math.abs(mean)) * 100
            if (dev > limit + 1e-9) {
              for (const [i, v] of vals) if (v === max || v === min) bad.push(i)
            }
          } else {
            dev = 0
            for (const [i, v] of vals) {
              const d = (Math.abs(v - mean) / Math.abs(mean)) * 100
              dev = Math.max(dev, d)
              if (d > limit + 1e-9) bad.push(i)
            }
          }
          entry.deviation = roundValue(dev, 2, 'round')
          entry.ok = dev <= limit + 1e-9
          entry.rows = bad
          for (const i of bad) outRows[i].out_of_tolerance.push(c.key)
          if (entry.ok === false) groupWarn = true
        }
      }
      tolerance[c.key] = entry
    }

    for (const i of members) if (Object.keys(outRows[i].invalid).length) groupWarn = true
    if (groupWarn) hasWarnings = true

    return { key: g.key, rows: members, summary, tolerance, has_warnings: groupWarn }
  })

  // ---- Varaq bo'yicha jami ----
  const allIncluded = []
  rows.forEach((row, i) => { if (!row?.excluded) allIncluded.push(i) })
  const totals = evalScope(totOrder, totColByKey, totCycle, listVars(allIncluded), 'total')

  for (const r of outRows) if (Object.keys(r.invalid).length) hasWarnings = true
  if (errors.length) hasWarnings = true

  const single = groups.length === 1
  return {
    rows: outRows,
    groups,
    summary: single ? groups[0].summary : {},
    tolerance: single ? groups[0].tolerance : {},
    totals,
    errors,
    has_warnings: hasWarnings,
  }
}

/* ============================================================== Validator */

export const KEY_PATTERN = /^[a-z][a-z0-9_]{0,39}$/
export const ROW_TYPES = ['input', 'computed', 'text']
export const TOLERANCE_MODES = ['mean', 'range']

const isNumeric = (v) => v !== null && v !== undefined && String(v).trim() !== '' && Number.isFinite(Number(v))

export function validateDefinition(def) {
  const errors = []
  const err = (field, message) => errors.push({ field, message })

  const stages = def?.stages ?? []
  const params = def?.params ?? []
  const columns = def?.columns ?? []

  const stageKeys = new Set()
  stages.forEach((s, i) => {
    const key = String(s.key ?? '')
    if (!KEY_PATTERN.test(key)) {
      err(`stages.${i}.key`, "Kalit lotin kichik harf bilan boshlanib, harf/raqam/_ dan iborat bo'lsin")
    } else if (stageKeys.has(key)) {
      err(`stages.${i}.key`, `Bosqich kaliti takrorlangan: ${key}`)
    }
    stageKeys.add(key)
    if (String(s.label_uz ?? '').trim() === '') err(`stages.${i}.label_uz`, 'Bosqich nomi kiritilmagan')
    const signers = parseInt(s.signers ?? 1, 10)
    if (signers < 0 || signers > 6) err(`stages.${i}.signers`, "Imzolovchilar soni 0–6 oralig'ida bo'lsin")
  })

  const allKeys = new Set()
  const checkKey = (field, key) => {
    key = String(key ?? '')
    if (!KEY_PATTERN.test(key)) {
      err(field, "Kalit lotin kichik harf bilan boshlanib, harf/raqam/_ dan iborat bo'lsin (masalan: kortochka)")
      return
    }
    if (FUNCTIONS.includes(key)) {
      err(field, `«${key}» — funksiya nomi, kalit sifatida ishlatib bo'lmaydi`)
      return
    }
    if (allKeys.has(key)) {
      err(field, `Kalit takrorlangan: ${key}`)
      return
    }
    allKeys.add(key)
  }

  params.forEach((p, i) => {
    checkKey(`params.${i}.key`, p.key)
    if (String(p.label_uz ?? '').trim() === '') err(`params.${i}.label_uz`, 'Parametr nomi kiritilmagan')
    const d = p.default ?? null
    if (d !== null && d !== '' && parseNumber(d) === false) {
      err(`params.${i}.default`, "Boshlang'ich qiymat son bo'lishi kerak")
    }
  })

  let rowCount = 0
  const rowKeysNumeric = new Set()
  columns.forEach((c, i) => {
    const scope = c.scope ?? 'row'
    checkKey(`columns.${i}.key`, c.key)

    if (!['row', 'summary', 'total'].includes(scope)) {
      err(`columns.${i}.scope`, "Noto'g'ri ko'lam")
      return
    }
    if (String(c.label_uz ?? '').trim() === '') err(`columns.${i}.label_uz`, 'Ustun nomi kiritilmagan')

    if (scope === 'row') {
      rowCount++
      const type = c.type ?? 'input'
      if (!ROW_TYPES.includes(type)) err(`columns.${i}.type`, "Noto'g'ri tur")
      if (type !== 'text') rowKeysNumeric.add(String(c.key ?? ''))
      if (c.element_id) err(`columns.${i}.element_id`, 'Element faqat yakuniy natija ustuniga biriktiriladi')
    }
    if (scope === 'total' && c.element_id) err(`columns.${i}.element_id`, 'Element faqat yakuniy natija ustuniga biriktiriladi')

    const stage = c.stage ?? null
    if (stage !== null && stage !== '' && !stageKeys.has(stage)) err(`columns.${i}.stage`, `Bunday bosqich yo'q: ${stage}`)

    const dec = c.decimals ?? null
    if (dec !== null && dec !== '' && (!isNumeric(dec) || parseInt(dec, 10) < 0 || parseInt(dec, 10) > 10)) {
      err(`columns.${i}.decimals`, "Xona soni 0–10 oralig'ida bo'lsin")
    }
    if (!ROUNDING_MODES.includes(c.rounding ?? 'round')) err(`columns.${i}.rounding`, "Noto'g'ri yaxlitlash usuli")

    if (isComputed(c)) {
      const formula = String(c.formula ?? '').trim()
      if (formula === '') {
        err(`columns.${i}.formula`, 'Hisoblanadigan ustun uchun formula kiritilmagan')
      } else {
        try { parseFormula(formula) } catch (e) { err(`columns.${i}.formula`, e.message) }
      }
    }
  })

  if (rowCount === 0) err('columns', "Kamida bitta qator ustuni bo'lishi kerak")

  const paramKeys = new Set(params.map((p) => String(p.key ?? '')))
  const summaryKeys = new Set(columns.filter((c) => (c.scope ?? 'row') === 'summary').map((c) => String(c.key ?? '')))
  const totalKeys = new Set(columns.filter((c) => (c.scope ?? 'row') === 'total').map((c) => String(c.key ?? '')))

  columns.forEach((c, i) => {
    if (!isComputed(c)) return
    let ast
    try { ast = parseFormula(c.formula ?? '') } catch { return }
    const scope = c.scope ?? 'row'
    for (const id of formulaIdentifiers(ast)) {
      const known = paramKeys.has(id) || rowKeysNumeric.has(id)
        || (scope === 'summary' && summaryKeys.has(id))
        || (scope === 'total' && totalKeys.has(id))
      if (!known) {
        if (scope === 'row' && summaryKeys.has(id)) {
          err(`columns.${i}.formula`, `Qator formulasida yakuniy natija («${id}») ishlatib bo'lmaydi`)
        } else if (totalKeys.has(id)) {
          err(`columns.${i}.formula`, `Jami («${id}») faqat boshqa jami formulasida ishlatiladi`)
        } else if (scope === 'total' && summaryKeys.has(id)) {
          err(`columns.${i}.formula`, `Jami formulasida yakuniy natija («${id}») ishlatib bo'lmaydi`)
        } else if (allKeys.has(id)) {
          err(`columns.${i}.formula`, `«${id}» — matnli ustun, formulada ishlatib bo'lmaydi`)
        } else {
          err(`columns.${i}.formula`, `Noma'lum o'zgaruvchi: «${id}»`)
        }
      }
    }

    if (scope === 'summary') {
      const pct = c.tolerance_pct ?? null
      if (pct !== null && pct !== '') {
        if (!isNumeric(pct) || Number(pct) <= 0 || Number(pct) > 100) {
          err(`columns.${i}.tolerance_pct`, "Ruxsat etilgan farq 0 dan katta, 100 dan kichik bo'lsin")
        }
        const src = c.tolerance_source ?? null
        if (!src || !rowKeysNumeric.has(src)) {
          err(`columns.${i}.tolerance_source`, "Farq qaysi qator ustuni bo'yicha tekshirilishini tanlang")
        }
        if (!TOLERANCE_MODES.includes(c.tolerance_mode ?? 'mean')) err(`columns.${i}.tolerance_mode`, "Noto'g'ri usul")
      }
    }
  })

  if (errors.length) return errors

  const dryRows = [0, 1].map(() => {
    const values = {}
    for (const c of columns) {
      if ((c.scope ?? 'row') === 'row' && (c.type ?? 'input') === 'input') values[c.key] = '1'
    }
    return { values, excluded: false }
  })
  const dryHeader = Object.fromEntries(params.map((p) => [p.key, '1']))

  const result = calculate(def, dryHeader, dryRows)
  for (const e of result.errors) {
    const idx = columns.findIndex((c) => c.key === e.key)
    err(idx >= 0 ? `columns.${idx}.formula` : 'columns', e.message)
  }
  return errors
}
