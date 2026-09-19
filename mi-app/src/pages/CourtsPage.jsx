import { useMemo, useState } from 'react'
import { Badge, EmptyState, Field, inputClass, Modal, primaryButton, secondaryButton, SectionTitle } from '../components/Ui'

export default function CourtsPage({db,user,actions,toast}){
  const [search,setSearch]=useState('')
  const [mine,setMine]=useState(false)
  const [open,setOpen]=useState(false)
  const [editing,setEditing]=useState(null)
  const empty={nombre:'',tipo:'Pública',deportes:'Fútbol',ciudad:'Comitán',estado:'Chiapas',codigoPostal:user.codigoPostal||'',direccion:'',horario:'08:00 - 20:00',contacto:''}
  const [form,setForm]=useState(empty)
  const rows=useMemo(()=>db.courts.filter((c)=>!mine||c.duenoId===user.id).filter((c)=>`${c.nombre} ${c.deportes} ${c.ciudad} ${c.codigoPostal}`.toLowerCase().includes(search.toLowerCase())),[db.courts,mine,search,user.id])
  const submit=(e)=>{e.preventDefault();if(!form.nombre||!form.ciudad)return toast('Completa nombre y ciudad.','error');if(editing)actions.updateCourt(editing.id,form);else actions.createCourt(form);setOpen(false);setEditing(null);setForm(empty);toast(editing?'Cancha actualizada.':'Cancha registrada.')}
  const edit=(c)=>{setEditing(c);setForm({...c});setOpen(true)}
  return <>
    <SectionTitle eyebrow="🏟️ Canchas" title="Canchas registradas" description="Consulta instalaciones deportivas. Primero puedes filtrar por nombre, deporte, ciudad o código postal." actions={<><button className={secondaryButton} onClick={()=>setMine(!mine)}>{mine?'Todas las canchas':'Mis canchas'}</button><button className={primaryButton} onClick={()=>{setEditing(null);setForm(empty);setOpen(true)}}>＋ Registrar cancha</button></>} />
    <input className={`${inputClass} mb-6`} placeholder="Buscar cancha..." value={search} onChange={(e)=>setSearch(e.target.value)}/>
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{rows.map((c)=><article key={c.id} className="rounded-3xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><div className="flex items-start justify-between"><div><p className="text-xs font-black text-blue-600">{c.id}</p><h3 className="mt-1 text-lg font-black">{c.nombre}</h3></div><Badge tone={c.tipo==='Pública'?'green':'amber'}>{c.tipo}</Badge></div><div className="mt-4 space-y-1 text-sm text-slate-600 dark:text-slate-300"><p>⚽ {c.deportes}</p><p>📍 {c.direccion}, {c.ciudad} · CP {c.codigoPostal}</p><p>🕒 {c.horario}</p>{c.contacto&&<p>📞 {c.contacto}</p>}</div>{c.duenoId===user.id&&<div className="mt-5 flex gap-2"><button className={`${secondaryButton} flex-1`} onClick={()=>edit(c)}>Editar</button><button className="rounded-2xl border border-red-200 px-4 py-3 text-sm font-bold text-red-600" onClick={()=>{if(confirm('¿Eliminar esta cancha?')){actions.deleteCourt(c.id);toast('Cancha eliminada.')}}}>Eliminar</button></div>}</article>)}</div>
    {!rows.length&&<EmptyState icon="🏟️" title="No hay canchas para mostrar"/>}
    <Modal open={open} title={editing?'Editar cancha':'Registrar cancha'} onClose={()=>setOpen(false)}><form className="grid gap-4 sm:grid-cols-2" onSubmit={submit}>{['nombre','deportes','ciudad','estado','codigoPostal','direccion','horario','contacto'].map((k)=><Field key={k} label={{nombre:'Nombre',deportes:'Deportes',ciudad:'Ciudad',estado:'Estado',codigoPostal:'Código postal',direccion:'Dirección',horario:'Horario',contacto:'Contacto'}[k]}><input className={inputClass} value={form[k]} onChange={(e)=>setForm({...form,[k]:e.target.value})}/></Field>)}<Field label="Tipo"><select className={inputClass} value={form.tipo} onChange={(e)=>setForm({...form,tipo:e.target.value})}><option>Pública</option><option>Privada</option></select></Field><button className={`${primaryButton} sm:col-span-2`} type="submit">Guardar cancha</button></form></Modal>
  </>
}
