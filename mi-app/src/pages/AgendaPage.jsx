import { useState } from 'react'
import { Badge, EmptyState, Field, inputClass, Modal, primaryButton, SectionTitle } from '../components/Ui'
import { formatDate } from '../lib/db'

export default function AgendaPage({db,user,actions,toast}){
  const items=db.agenda.filter((a)=>a.userId===user.id).sort((a,b)=>a.fecha.localeCompare(b.fecha))
  const [item,setItem]=useState(null)
  const [resultado,setResultado]=useState('')
  const finish=(e)=>{e.preventDefault();if(!resultado.trim())return toast('Escribe el resultado.','error');actions.finishAgenda(item.id,resultado);setItem(null);setResultado('');toast('Resultado registrado.')}
  return <>
    <SectionTitle eyebrow="📅 Agenda" title="Mi agenda deportiva" description="Consulta tus retas, partidos y eventos programados. Desde aquí también puedes registrar resultados." />
    <div className="space-y-4">{items.map((a)=><article key={a.id} className="rounded-3xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div><div className="flex items-center gap-2"><Badge tone={a.estado==='Finalizado'?'green':'blue'}>{a.estado}</Badge><span className="text-xs font-bold text-slate-400">{a.tipo}</span></div><h3 className="mt-2 text-lg font-black">{a.titulo}</h3><p className="mt-1 text-sm text-slate-500">{formatDate(a.fecha)} · {a.hora} · {a.lugar}</p>{a.resultado&&<p className="mt-2 font-black text-emerald-600">Resultado: {a.resultado}</p>}</div>{a.estado!=='Finalizado'&&<button className={primaryButton} onClick={()=>setItem(a)}>Registrar resultado</button>}</div></article>)}</div>
    {!items.length&&<EmptyState icon="📅" title="Aún no tienes eventos agendados" description="Acepta una reta o participa en una liga para agregar actividades."/>}
    <Modal open={!!item} title="Registrar resultado" onClose={()=>setItem(null)}><form onSubmit={finish}><Field label="Resultado"><input className={inputClass} placeholder="Ej. Titanes FC 3 - 1 Halcones" value={resultado} onChange={(e)=>setResultado(e.target.value)}/></Field><button className={`${primaryButton} mt-5 w-full`} type="submit">Guardar resultado</button></form></Modal>
  </>
}
