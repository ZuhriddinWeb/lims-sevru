/**
 * Proba kartochkasiga (sample-detail sahifasi) «Qabul va proba miqdori» panelini modulning O'ZI qo'shadi —
 * views/samples/SampleDetail.vue ga qo'lda tegish shart emas.
 * Agar panel SampleDetail.vue ga qo'lda qo'shilgan bo'lsa — bu yerdagisi ko'rsatilmaydi (ikki marta chiqmaydi).
 * Chaqiriladi: installPortalGuard(router) ichidan.
 */
import { defineComponent, h, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

function wrap(Original, Panel) {
  return defineComponent({
    name: 'SampleDetailWithIntake',
    setup() {
      const route = useRoute()
      const show = ref(false)
      const box = ref(null)
      let ready = false
      let timer = null
      let observer = null

      // Sahifada boshqa (qo'lda qo'yilgan) panel bormi
      const check = () => {
        if (!ready) return
        const manual = [...document.querySelectorAll('[data-intake-panel]')].some((p) => !box.value || !box.value.contains(p))
        show.value = !manual
      }

      onMounted(() => {
        // Asosiy sahifa probani yuklab chizib olguncha kutamiz
        timer = setTimeout(() => { ready = true; check() }, 700)
        if (typeof MutationObserver !== 'undefined') {
          observer = new MutationObserver(check)
          observer.observe(document.body, { childList: true, subtree: true })
        }
      })
      onBeforeUnmount(() => {
        clearTimeout(timer)
        observer?.disconnect()
      })

      return () => h('div', [
        h(Original),
        show.value && route.params.id
          ? h('div', { ref: box, 'data-intake-auto': '' }, [h(Panel, { sample: { id: Number(route.params.id) }, class: 'mt-5' })])
          : null,
      ])
    },
  })
}

export function installSampleDetailPanel(router) {
  const record = router?.getRoutes?.().find((r) => r.name === 'sample-detail')
  if (!record || record.meta?.intakePanel || !record.components?.default) return false

  const original = record.components.default
  record.meta = { ...(record.meta ?? {}), intakePanel: true }
  record.components.default = () => Promise.all([
    typeof original === 'function' ? original() : Promise.resolve(original),
    import('./components/SampleIntakePanel.vue'),
  ]).then(([o, p]) => wrap(o.default ?? o, p.default ?? p))

  return true
}
