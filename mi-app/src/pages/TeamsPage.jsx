import { useMemo, useState } from 'react'
import { Badge, EmptyState, Field, inputClass, Modal, primaryButton, secondaryButton, SectionTitle } from '../components/Ui'
import { displayName } from '../lib/db'

export default function TeamsPage({ db, user, actions, toast }) {
  const [search, setSearch] = useState('')
  const [createOpen, setCreateOpen] = useState(false)
  const [detail, setDetail] = useState(null)
  const [form, setForm] = useState({ nombre:'', deporte:'Fútbol', ciudad:'Comitán', codigoPostal:user.codigoPostal || '', maxMiembros:'11', tipo:'Fijo' })
  const teams = useMemo(() => db.teams.filter((t) => `${t.nombre} ${t.deporte} ${t.ciudad}`.toLowerCase().includes(search.toLowerCase())), [db.teams, search])
  const myTeams = db.teams.filter((t) => t.miembros.includes(user.id))

  const create = (e) => { e.preventDefault(); if (!form.nombre.trim()) return toast('Escribe el nombre del equipo.','error'); actions.createTeam(form); setCreateOpen(false); setForm({...form,nombre:''}); toast('Equipo creado correctamente.') }
  const join = (team) => { const r=actions.requestTeam(team.id); toast(r.message, r.ok?'success':'error') }

  return <>
    <SectionTitle eyebrow="👥 Equipos" title="Equipos" description="Administra tus equipos, crea uno nuevo o busca otros equipos para enviar una solicitud de unión." actions={<><button className={secondaryButton} onClick={() => setSearch('')}>Limpiar</button><button className={primaryButton} onClick={() => setCreateOpen(true)}>＋ Crear equipo</button></>} />
    <div className="mb-6 grid gap-4 lg:grid-cols-[1fr_auto]"><input className={inputClass} placeholder="Buscar por nombre, deporte o ciudad..." value={search} onChange={(e)=>setSearch(e.target.value)} /><div className="rounded-2xl bg-blue-50 px-4 py-3 text-sm font-bold text-blue-700">Mis equipos: {myTeams.length}</div></div>
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      {teams.map((team) => { const mine=team.miembros.includes(user.id); const captain=db.users.find((u)=>u.id===team.capitanId); return <article key={team.id} className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900"><div className="flex items-start justify-between gap-3"><div><p className="text-xs font-black text-blue-600">{team.id}</p><h3 className="mt-1 text-xl font-black">{team.nombre}</h3></div><Badge tone={team.tipo==='Temporal'?'amber':'blue'}>{team.tipo}</Badge></div><div className="mt-4 space-y-1 text-sm text-slate-600 dark:text-slate-300"><p>⚽ {team.deporte}</p><p>📍 {team.ciudad} · CP {team.codigoPostal}</p><p>👤 Capitán: {displayName(captain)}</p><p>👥 Integrantes: {team.miembros.length}/{team.maxMiembros}</p></div><div className="mt-5 flex gap-2"><button className={`${secondaryButton} flex-1`} onClick={()=>setDetail(team)}>Ver detalles</button>{!mine && <button className={`${primaryButton} flex-1`} onClick={()=>join(team)}>Unirme</button>}</div></article> })}
    </div>
    {!teams.length && <EmptyState title="No se encontraron equipos" description="Prueba con otro nombre, deporte o ciudad." />}

    <Modal open={createOpen} title="Crear nuevo equipo" onClose={()=>setCreateOpen(false)}><form className="grid gap-4 sm:grid-cols-2" onSubmit={create}><Field label="Nombre"><input className={inputClass} value={form.nombre} onChange={(e)=>setForm({...form,nombre:e.target.value})}/></Field><Field label="Deporte"><select className={inputClass} value={form.deporte} onChange={(e)=>setForm({...form,deporte:e.target.value})}><option>Fútbol</option><option>Baloncesto</option><option>Voleibol</option><option>Béisbol</option></select></Field><Field label="Ciudad"><input className={inputClass} value={form.ciudad} onChange={(e)=>setForm({...form,ciudad:e.target.value})}/></Field><Field label="Código postal"><input className={inputClass} value={form.codigoPostal} onChange={(e)=>setForm({...form,codigoPostal:e.target.value})}/></Field><Field label="Máximo de integrantes"><input className={inputClass} type="number" min="2" value={form.maxMiembros} onChange={(e)=>setForm({...form,maxMiembros:e.target.value})}/></Field><Field label="Tipo"><select className={inputClass} value={form.tipo} onChange={(e)=>setForm({...form,tipo:e.target.value})}><option>Fijo</option><option>Temporal</option></select></Field><button className={`${primaryButton} sm:col-span-2`} type="submit">Crear equipo</button></form></Modal>
    <Modal open={!!detail} title="Detalles del equipo" onClose={()=>setDetail(null)}>{detail && <div><div className="rounded-3xl bg-slate-50 p-5 dark:bg-slate-950"><h3 className="text-2xl font-black">{detail.nombre}</h3><p className="mt-2 text-sm text-slate-500">{detail.deporte} · {detail.ciudad} · CP {detail.codigoPostal}</p></div><h4 className="mt-6 font-black">Integrantes</h4><div className="mt-3 space-y-2">{detail.miembros.map((id)=><div key={id} className="rounded-2xl border border-slate-200 px-4 py-3 text-sm dark:border-slate-700">{displayName(db.users.find((u)=>u.id===id))}{id===detail.capitanId && <span className="ml-2 text-xs font-bold text-blue-600">Capitán</span>}</div>)}</div></div>}</Modal>
  </>
}
