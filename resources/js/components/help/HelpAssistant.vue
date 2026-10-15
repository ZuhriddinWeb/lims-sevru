<script setup>
/**
 * Yordamchi (lokal AI) — o'ng pastdagi «?» tugmasi va suhbat oynasi.
 * Savol serverga ketadi (POST /api/help/ask), javob foydalanuvchi tilida va roliga mos keladi.
 * Suhbat sahifalar almashganda saqlanadi (komponent AppLayout ichida), yangilanganda tozalanadi.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ExternalLink, HelpCircle, Send, ThumbsDown, ThumbsUp, Trash2, X } from 'lucide-vue-next'
import api from '@/lib/api'
import '@/modules/help/i18n'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()

const open = ref(false)
const input = ref('')
const busy = ref(false)
const suggestions = ref([])
const messages = ref([]) // { id, role: 'user'|'bot', text, answer?, related?, kind?, rated? }
const listEl = ref(null)
const inputEl = ref(null)
let seq = 0
let suggestionsFor = ''

const routeName = computed(() => (typeof route.name === 'string' ? route.name : null))

function push(m) {
  messages.value.push({ id: ++seq, ...m })
  scrollDown()
}

async function scrollDown() {
  await nextTick()
  if (listEl.value) listEl.value.scrollTop = listEl.value.scrollHeight
}

/** Marshrut parametr talab qilmasa (masalan, /samples) — «Sahifani ochish» tugmasi chiqadi */
function linkFor(name) {
  if (!name || !router.hasRoute(name)) return null
  const rec = router.getRoutes().find((r) => r.name === name)
  if (!rec || rec.path.includes(':')) return null
  return { name }
}

function go(name) {
  const to = linkFor(name)
  if (to) router.push(to)
}

async function loadSuggestions() {
  const key = `${locale.value}|${routeName.value}`
  if (key === suggestionsFor) return
  suggestionsFor = key
  try {
    const { data } = await api.get('/help/suggestions', { params: { locale: locale.value, route: routeName.value || undefined } })
    suggestions.value = data.suggestions ?? []
  } catch {
    suggestionsFor = ''
  }
}

function show(res, question) {
  if (res.status === 'smalltalk') {
    push({ role: 'bot', text: t(res.kind === 'thanks' ? 'help.thanks' : 'help.hi') })
    return
  }
  if (res.status === 'answer' && res.answer) {
    push({ role: 'bot', text: res.answer.text, answer: res.answer, related: res.related ?? [], question })
    return
  }
  if (res.status === 'suggest' && res.related?.length) {
    push({ role: 'bot', text: t('help.suggestIntro'), related: res.related, question, plain: true })
    return
  }
  push({ role: 'bot', text: t('help.none'), question, plain: true, chips: true })
}

async function ask(text) {
  const q = (text ?? input.value).trim()
  if (!q || busy.value) return
  input.value = ''
  push({ role: 'user', text: q })
  busy.value = true
  try {
    const { data } = await api.post('/help/ask', { question: q, locale: locale.value, route: routeName.value || undefined })
    show(data, q)
  } catch {
    push({ role: 'bot', text: t('help.error'), plain: true })
  } finally {
    busy.value = false
    await nextTick()
    inputEl.value?.focus()
  }
}

/** Taklif (chip) bosildi — savolni yozmasdan to'g'ridan-to'g'ri javobni olamiz */
async function pick(item) {
  if (busy.value) return
  push({ role: 'user', text: item.question })
  busy.value = true
  try {
    const { data } = await api.get(`/help/entries/${item.id}`, { params: { locale: locale.value } })
    push({ role: 'bot', text: data.answer.text, answer: data.answer, related: [], question: item.question })
  } catch {
    push({ role: 'bot', text: t('help.error'), plain: true })
  } finally {
    busy.value = false
  }
}

async function rate(m, helpful) {
  if (m.rated !== undefined) return
  m.rated = helpful
  try {
    await api.post('/help/feedback', { entry_id: m.answer.id, helpful, question: m.question, locale: locale.value })
  } catch { /* baho yuborilmasa ham suhbat buzilmasin */ }
}

function clear() {
  messages.value = []
  push({ role: 'bot', text: t('help.welcome'), plain: true, chips: true })
}

function toggle() {
  open.value = !open.value
}

function onKey(e) {
  if (e.key === 'Escape' && open.value) open.value = false
}

watch(open, async (v) => {
  if (!v) return
  if (!messages.value.length) clear()
  loadSuggestions()
  await nextTick()
  inputEl.value?.focus()
  scrollDown()
})
watch([routeName, locale], () => { if (open.value) loadSuggestions() })

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <div class="print:hidden">
    <!-- Tugma -->
    <button
      v-if="!open"
      type="button"
      class="fixed bottom-4 right-4 z-40 h-12 w-12 rounded-full bg-assay-500 text-white shadow-lg grid place-items-center hover:bg-assay-600 active:scale-95 transition"
      :title="t('help.open')"
      :aria-label="t('help.open')"
      @click="toggle"
    >
      <HelpCircle :size="24" />
    </button>

    <!-- Oyna -->
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0 translate-y-2"
      leave-active-class="transition duration-100 ease-in"
      leave-to-class="opacity-0 translate-y-2"
    >
      <section
        v-if="open"
        role="dialog"
        :aria-label="t('help.title')"
        class="fixed z-50 flex flex-col bg-surface border border-line shadow-2xl
               inset-x-0 bottom-0 h-[78vh] rounded-t-2xl
               sm:inset-x-auto sm:right-4 sm:bottom-4 sm:w-[400px] sm:h-[600px] sm:max-h-[calc(100vh-2rem)] sm:rounded-2xl"
      >
        <header class="flex items-center gap-3 px-4 py-3 border-b border-line shrink-0">
          <span class="h-8 w-8 rounded-full bg-assay-500 text-white grid place-items-center shrink-0"><HelpCircle :size="18" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="text-[14px] font-semibold text-ink leading-tight">{{ t('help.title') }}</h2>
            <p class="text-[12px] text-ink-faint truncate">{{ t('help.subtitle') }}</p>
          </div>
          <button class="btn-ghost btn-sm btn-icon" :title="t('help.clear')" :aria-label="t('help.clear')" @click="clear"><Trash2 :size="16" /></button>
          <button class="btn-ghost btn-sm btn-icon" :title="t('help.close')" :aria-label="t('help.close')" @click="open = false"><X :size="18" /></button>
        </header>

        <div ref="listEl" class="flex-1 overflow-y-auto px-4 py-3 space-y-3" aria-live="polite">
          <div v-for="m in messages" :key="m.id" :class="m.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
            <div
              class="max-w-[92%] rounded-2xl px-3.5 py-2.5 text-[13.5px] leading-relaxed"
              :class="m.role === 'user' ? 'bg-assay-500 text-white rounded-br-md' : 'bg-surface-sunken text-ink rounded-bl-md'"
            >
              <p v-if="m.answer?.section" class="text-[11px] font-semibold uppercase tracking-wide text-ink-faint mb-1">{{ m.answer.section }}</p>
              <p class="whitespace-pre-line break-words">{{ m.text }}</p>

              <button
                v-if="m.answer && linkFor(m.answer.route)"
                class="mt-2 inline-flex items-center gap-1.5 text-[12.5px] font-medium text-assay-600 dark:text-assay-300 hover:underline"
                @click="go(m.answer.route)"
              >
                <ExternalLink :size="14" /> {{ t('help.openPage') }}
              </button>

              <div v-if="m.answer" class="mt-2 flex items-center gap-1 text-ink-faint">
                <template v-if="m.rated === undefined">
                  <button class="btn-ghost btn-sm !px-2" :title="t('help.helpful')" :aria-label="t('help.helpful')" @click="rate(m, true)"><ThumbsUp :size="14" /></button>
                  <button class="btn-ghost btn-sm !px-2" :title="t('help.notHelpful')" :aria-label="t('help.notHelpful')" @click="rate(m, false)"><ThumbsDown :size="14" /></button>
                </template>
                <span v-else class="text-[12px]">{{ t('help.thanksFeedback') }}</span>
              </div>

              <div v-if="m.related?.length" class="mt-2.5">
                <p v-if="m.answer" class="text-[12px] text-ink-faint mb-1">{{ t('help.related') }}</p>
                <div class="flex flex-col gap-1.5">
                  <button
                    v-for="r in m.related" :key="r.id"
                    class="text-left text-[12.5px] px-2.5 py-1.5 rounded-lg border border-line bg-surface hover:border-assay-400 transition-colors"
                    @click="pick(r)"
                  >{{ r.question }}</button>
                </div>
              </div>

              <div v-if="m.chips && suggestions.length" class="mt-2.5 flex flex-col gap-1.5">
                <button
                  v-for="s in suggestions" :key="s.id"
                  class="text-left text-[12.5px] px-2.5 py-1.5 rounded-lg border border-line bg-surface hover:border-assay-400 transition-colors"
                  @click="pick(s)"
                >{{ s.question }}</button>
              </div>
            </div>
          </div>

          <p v-if="busy" class="text-[12.5px] text-ink-faint px-1">{{ t('help.typing') }}</p>
        </div>

        <form class="flex items-center gap-2 px-3 py-3 border-t border-line shrink-0" @submit.prevent="ask()">
          <input
            ref="inputEl"
            v-model="input"
            type="text"
            maxlength="300"
            autocomplete="off"
            class="field-input flex-1"
            :placeholder="t('help.placeholder')"
            :aria-label="t('help.placeholder')"
          />
          <button type="submit" class="btn-primary btn-icon" :disabled="busy || !input.trim()" :title="t('help.send')" :aria-label="t('help.send')">
            <Send :size="16" />
          </button>
        </form>
      </section>
    </Transition>
  </div>
</template>
