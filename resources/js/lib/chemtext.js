/**
 * Kimyoviy belgi va o'lchov birligini klaviaturadan qulay yozish. [Paket 25]
 *
 *   formula rejimi (element belgisi):
 *     harf yoki ) dan keyingi raqam  → pastki indeks      SO4 → SO₄,  (NH4)2SO4 → (NH₄)₂SO₄
 *     ^ dan keyingi raqam va +/-     → yuqori indeks      Fe^3+ → Fe³⁺,  As^+5 → As⁺⁵
 *     boshqa raqamlar o'zgarmaydi                         CuSO4·5H2O → CuSO₄·5H₂O,  T-92 → T-92
 *   unit rejimi (o'lchov birligi):
 *     harfdan keyingi raqam          → yuqori indeks      mg/dm3 → mg/dm³,  m3/h → m³/h
 *     ^ ham ishlaydi                                      10^6 → 10⁶
 *   _ dan keyingi raqam — har doim pastki indeks          OD_600 emas, OD600 qoldirish uchun: avtomatni o'chiring
 */
export const SUB = { 0: '₀', 1: '₁', 2: '₂', 3: '₃', 4: '₄', 5: '₅', 6: '₆', 7: '₇', 8: '₈', 9: '₉', '+': '₊', '-': '₋' }
export const SUP = { 0: '⁰', 1: '¹', 2: '²', 3: '³', 4: '⁴', 5: '⁵', 6: '⁶', 7: '⁷', 8: '⁸', 9: '⁹', '+': '⁺', '-': '⁻' }

const SUB_SET = new Set(Object.values(SUB))
const SUP_SET = new Set(Object.values(SUP))
const LETTER = /[A-Za-zА-Яа-яЁёЎўҚқҒғҲҳ)\]]/

/**
 * @param {string} text
 * @param {'formula'|'unit'} mode
 * @return {{ text: string, map: number[] }}  map[i] — asl matndagi i-pozitsiya natijada qayerga tushadi (kursor uchun)
 */
export function convertWithMap(text, mode = 'formula') {
  const s = String(text ?? '')
  let out = ''
  let run = '' // '' | 'sup' | 'sub'
  const map = []
  for (let i = 0; i < s.length; i++) {
    map[i] = out.length
    const c = s[i]
    if (c === '^' || c === '_') {
      const next = s[i + 1]
      if (next !== undefined && /[0-9+\-]/.test(next)) { run = c === '^' ? 'sup' : 'sub'; continue }
      if (next === undefined) { out += c; continue } // hali yozilmoqda — belgi qoladi
    }
    if (run) {
      if (/[0-9+\-]/.test(c)) { out += (run === 'sup' ? SUP : SUB)[c]; continue }
      run = ''
    }
    if (/[0-9]/.test(c)) {
      const prev = out[out.length - 1] ?? ''
      if (mode === 'unit') {
        if (LETTER.test(prev) || SUP_SET.has(prev)) { out += SUP[c]; continue }
      } else if (LETTER.test(prev) || SUB_SET.has(prev)) {
        out += SUB[c]; continue
      }
    }
    out += c
  }
  map[s.length] = out.length
  return { text: out, map }
}

export function convert(text, mode = 'formula') {
  return convertWithMap(text, mode).text
}

/** Qidiruv uchun: indekslarni oddiy raqam/belgiga qaytarish (SO₄ → so4), kichik harf */
export function fold(text) {
  let out = ''
  for (const c of String(text ?? '')) {
    const sub = Object.keys(SUB).find((k) => SUB[k] === c)
    const sup = sub === undefined ? Object.keys(SUP).find((k) => SUP[k] === c) : undefined
    out += sub ?? sup ?? c
  }
  return out.toLowerCase()
}

/** Panel: tugma bilan qo'yiladigan belgilar */
export const PALETTE = {
  sub: ['₀', '₁', '₂', '₃', '₄', '₅', '₆', '₇', '₈', '₉', '₊', '₋'],
  sup: ['⁰', '¹', '²', '³', '⁴', '⁵', '⁶', '⁷', '⁸', '⁹', '⁺', '⁻'],
  sym: ['·', '°', 'µ', '‰', '±', '×', '≤', '≥', '→', '⇌', 'α', 'β', 'γ', 'λ', 'Ω', 'Δ'],
}
