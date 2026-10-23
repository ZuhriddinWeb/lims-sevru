import{K as u,G as _,c as m,o as f,d as g,t as h,f as l,C as x}from"./app-DZpVJhpa.js";const c={draft:"bg-surface-sunken text-ink-muted dark:bg-white/5",submitted:"bg-aqua-50 text-aqua-700 dark:bg-aqua-800/30 dark:text-aqua-200",partial:"bg-warn-light text-warn-dark dark:bg-warn-dark/25 dark:text-warn-light",received:"bg-ok-light text-ok-dark dark:bg-ok-dark/25 dark:text-ok-light",rejected:"bg-crit-light text-crit-dark dark:bg-crit-dark/25 dark:text-crit-light",cancelled:"bg-surface-sunken text-ink-faint line-through dark:bg-white/5",expired:"bg-crit-light text-crit-dark dark:bg-crit-dark/25 dark:text-crit-light"},$={pending:"text-ink-muted",received:"text-ok-dark dark:text-ok-light",rejected:"text-crit line-through"};function y(t,e){return t&&(t[`name_${e}`]||t.name_uz||t.name_ru||t.code)||""}function b(t={}){return{customer_code:"",state_of_matter:"solid",test_profile_id:null,element_ids:[],quantity:"",quantity_unit:"g",notes:"",...t}}function w(t){var e;return{department_id:((e=t==null?void 0:t.department)==null?void 0:e.id)??"",delivery_date:(t==null?void 0:t.delivery_date)??"",delivery_shift_id:(t==null?void 0:t.delivery_shift_id)??"",contact_name:(t==null?void 0:t.contact_name)??"",contact_phone:(t==null?void 0:t.contact_phone)??"",notes:(t==null?void 0:t.notes)??"",items:((t==null?void 0:t.items)??[]).map(a=>b({customer_code:a.customer_code??"",state_of_matter:a.state_of_matter||"solid",test_profile_id:a.test_profile_id??null,element_ids:[...a.element_ids??[]],quantity:a.quantity??"",quantity_unit:a.quantity_unit||"g",notes:a.notes??""}))}}function j(t){return{department_id:t.department_id||null,delivery_date:t.delivery_date||null,delivery_shift_id:t.delivery_shift_id||null,contact_name:t.contact_name||null,contact_phone:t.contact_phone||null,notes:t.notes||null,items:t.items.map(e=>({customer_code:(e.customer_code||"").trim()||null,state_of_matter:e.state_of_matter||"solid",test_profile_id:e.test_profile_id||null,element_ids:e.element_ids??[],quantity:e.quantity===""||e.quantity===null?null:String(e.quantity).replace(",","."),quantity_unit:e.quantity===""||e.quantity===null?null:e.quantity_unit,notes:(e.notes||"").trim()||null}))}}function q(t){return[t!=null&&t.delivery_date?_(t.delivery_date):"",(t==null?void 0:t.delivery_shift)||""].filter(Boolean).join(" · ")}function S(t,e){const a=t==null?void 0:t[e];return Array.isArray(a)?a[0]:a||""}function z(t,e){var i;const a=(i=t==null?void 0:t.response)==null?void 0:i.data;if(a!=null&&a.errors){const o=Object.values(a.errors).flat()[0];if(a.message&&String(a.message).startsWith("Qoralama"))return a.message;if(o)return o}return(a==null?void 0:a.message)||e}const n=t=>String(t??"").replace(/[&<>"']/g,e=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"})[e]);function T(t,e,a){var r;const i=window.open("","_blank","width=900,height=700");if(!i)return!1;const o=(t.items??[]).map(s=>`
    <tr>
      <td class="c">${s.position}</td>
      <td><b>${n(s.customer_code||"—")}</b></td>
      <td>${n(e(`sreq.form.states.${s.state_of_matter}`))}</td>
      <td>${n([s.profile?y(s.profile,a):"",(s.elements??[]).map(d=>d.symbol).join(", ")].filter(Boolean).join(" · "))}</td>
      <td class="r">${s.quantity!==null&&s.quantity!==void 0?n(s.quantity)+" "+n(s.quantity_unit||""):""}</td>
      <td>${n(s.notes||"")}</td>
      <td class="c">${s.sample?n(s.sample.code):"☐"}</td>
    </tr>`).join(""),p=[[e("sreq.col.department"),t.department?`${t.department.name}${t.department.code?` (${t.department.code})`:""}`:""],[e("sreq.info.submitted"),u(t.submitted_at)],[e("sreq.col.delivery"),q(t)||"—"],[e("sreq.col.contact"),[t.contact_name,t.contact_phone].filter(Boolean).join(", ")||"—"],[e("sreq.form.notes"),t.notes||""]].filter(([,s])=>s);return i.document.write(`<!doctype html><html lang="${a}"><head><meta charset="utf-8">
<title>${n(t.number||"")}</title>
<style>
  @page { size: A4; margin: 14mm; }
  body { font: 12.5px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; margin: 0; padding: 20px; }
  .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #111; padding-bottom: 10px; }
  h1 { font-size: 17px; margin: 0 0 4px; }
  .num { font: 700 26px/1 ui-monospace, Menlo, Consolas, monospace; letter-spacing: .02em; border: 2px solid #111; padding: 8px 12px; }
  dl { display: grid; grid-template-columns: max-content 1fr; gap: 3px 14px; margin: 12px 0; }
  dt { color: #555; } dd { margin: 0; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th, td { border: 1px solid #999; padding: 5px 7px; vertical-align: top; text-align: left; }
  th { background: #f2f2f2; font-weight: 600; font-size: 11.5px; }
  .c { text-align: center; } .r { text-align: right; white-space: nowrap; }
  .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 34px; }
  .sign div { border-top: 1px solid #111; padding-top: 4px; font-size: 11.5px; color: #444; }
  .foot { margin-top: 18px; font-size: 11.5px; color: #555; }
  @media print { body { padding: 0; } }
</style></head><body>
  <div class="head">
    <div><h1>${n(e("sreq.slip.title"))}</h1><div>${n(((r=t.department)==null?void 0:r.name)||"")}</div></div>
    <div class="num">${n(t.number||"")}</div>
  </div>
  <dl>${p.map(([s,d])=>`<dt>${n(s)}</dt><dd>${n(d)}</dd>`).join("")}</dl>
  <table>
    <thead><tr>
      <th class="c">№</th><th>${n(e("sreq.form.code"))}</th><th>${n(e("sreq.form.state"))}</th>
      <th>${n(e("sreq.form.what"))}</th><th class="r">${n(e("sreq.form.quantity"))}</th>
      <th>${n(e("sreq.form.itemNotes"))}</th><th class="c">✓</th>
    </tr></thead>
    <tbody>${o}</tbody>
  </table>
  <div class="sign">
    <div>${n(e("sreq.slip.handed"))}: ${n(t.contact_name||"")} &nbsp; ____________ (${n(e("sreq.slip.sign"))})</div>
    <div>${n(e("sreq.slip.accepted"))}: ______________________ (${n(e("sreq.slip.sign"))})</div>
  </div>
  <p class="foot">${n(e("sreq.slip.footer"))}</p>
  <script>window.onload = function () { window.focus(); window.print(); }<\/script>
</body></html>`),i.document.close(),!0}const k=["data-status"],E={__name:"StatusPill",props:{status:{type:String,required:!0}},setup(t){const{t:e}=m();return(a,i)=>(f(),g("span",{class:x(["inline-flex items-center rounded-full px-2 py-0.5 text-[12px] font-medium whitespace-nowrap",l(c)[t.status]??l(c).draft]),"data-status":t.status},h(l(e)(`sreq.status.${t.status}`)),11,k))}};export{$ as I,E as _,z as a,b,w as c,q as d,S as f,y as n,T as p,j as t};
