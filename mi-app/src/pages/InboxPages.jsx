import { Badge, EmptyState, primaryButton, secondaryButton, SectionTitle } from '../components/Ui'
import { displayName, formatDateTime } from '../lib/db'

export function RequestsPage({db,user,actions,toast}){
  const owned=db.teams.filter((t)=>t.capitanId===user.id).map((t)=>t.id)
  const incoming=db.teamRequests.filter((r)=>owned.includes(r.teamId)&&r.estado==='Pendiente')
  const outgoing=db.teamRequests.filter((r)=>r.userId===user.id)
  const leagueOwned=db.leagues.filter((l)=>l.adminId===user.id).map((l)=>l.id)
  const leagueIncoming=db.leagueRequests.filter((r)=>leagueOwned.includes(r.leagueId)&&r.estado==='Pendiente')
  return <>
    <SectionTitle eyebrow="📋 Solicitudes" title="Solicitudes" description="Revisa solicitudes de unión a tus equipos o ligas, y consulta las que tú has enviado." />
    <section className="rounded-3xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><h2 className="font-black">Recibidas</h2><div className="mt-4 space-y-3">{incoming.map((r)=>{const team=db.teams.find((t)=>t.id===r.teamId);const person=db.users.find((u)=>u.id===r.userId);return <div key={r.id} className="flex flex-col gap-3 rounded-2xl bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between dark:bg-slate-950"><div><p className="font-black">{displayName(person)} → {team?.nombre}</p><p className="text-xs text-slate-500">{formatDateTime(r.fecha)}</p></div><div className="flex gap-2"><button className={primaryButton} onClick={()=>{actions.resolveTeamRequest(r.id,true);toast('Solicitud aceptada.')}}>Aceptar</button><button className={secondaryButton} onClick={()=>actions.resolveTeamRequest(r.id,false)}>Rechazar</button></div></div>})}{leagueIncoming.map((r)=>{const team=db.teams.find((t)=>t.id===r.teamId);const league=db.leagues.find((l)=>l.id===r.leagueId);return <div key={r.id} className="flex items-center justify-between gap-3 rounded-2xl bg-slate-50 p-4 dark:bg-slate-950"><div><p className="font-black">{team?.nombre} → {league?.nombre}</p><p className="text-xs text-slate-500">Inscripción a liga</p></div><div className="flex gap-2"><button className={primaryButton} onClick={()=>actions.resolveLeagueRequest(r.id,true)}>Aceptar</button><button className={secondaryButton} onClick={()=>actions.resolveLeagueRequest(r.id,false)}>Rechazar</button></div></div>})}{!incoming.length&&!leagueIncoming.length&&<EmptyState title="No hay solicitudes pendientes"/>}</div></section>
    <section className="mt-6 rounded-3xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><h2 className="font-black">Enviadas</h2><div className="mt-4 space-y-2">{outgoing.length?outgoing.map((r)=>{const t=db.teams.find((x)=>x.id===r.teamId);return <div key={r.id} className="flex items-center justify-between rounded-2xl border border-slate-200 p-4 dark:border-slate-700"><p className="font-bold">{t?.nombre}</p><Badge tone={r.estado==='Aceptada'?'green':r.estado==='Rechazada'?'red':'amber'}>{r.estado}</Badge></div>}):<EmptyState title="No has enviado solicitudes"/>}</div></section>
  </>
}

export function NotificationsPage({db,user,actions}){
  const rows=db.notifications.filter((n)=>n.userId===user.id).sort((a,b)=>b.fecha.localeCompare(a.fecha))
  return <>
    <SectionTitle eyebrow="🔔 Notificaciones" title="Notificaciones" description="Aquí aparecen avisos de equipos, ligas, retas y movimientos importantes de tu cuenta." />
    <div className="space-y-3">{rows.map((n)=><button type="button" key={n.id} onClick={()=>actions.markNotification(n.id)} className={`w-full rounded-3xl border p-5 text-left transition ${n.leida?'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900':'border-blue-200 bg-blue-50 dark:border-blue-900 dark:bg-blue-950/40'}`}><div className="flex items-start justify-between gap-3"><div><p className="font-black">{n.titulo}</p><p className="mt-1 text-sm text-slate-600 dark:text-slate-300">{n.descripcion}</p><p className="mt-2 text-xs text-slate-400">{formatDateTime(n.fecha)}</p></div>{!n.leida&&<span className="mt-1 h-2.5 w-2.5 rounded-full bg-blue-600"/>}</div></button>)}</div>
    {!rows.length&&<EmptyState title="No tienes notificaciones"/>}
  </>
}

export function FriendsPage({db,user,actions,toast}){
  const friends=db.friends.filter((f)=>f.userA===user.id||f.userB===user.id).map((f)=>db.users.find((u)=>u.id===(f.userA===user.id?f.userB:f.userA))).filter(Boolean)
  const available=db.users.filter((u)=>u.id!==user.id&&!friends.some((f)=>f.id===u.id))
  return <>
    <SectionTitle eyebrow="🤝 Amigos" title="Comunidad" description="Mantén una lista sencilla de contactos deportivos para organizar equipos y retas." />
    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{friends.map((f)=><div key={f.id} className="rounded-3xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"><div className="flex h-12 w-12 items-center justify-center rounded-full bg-gradient-to-br from-blue-100 to-red-100 text-lg font-black">{f.nombre?.[0]}{f.apellido?.[0]}</div><p className="mt-3 font-black">{displayName(f)}</p><p className="text-xs text-slate-500">{f.estado} · CP {f.codigoPostal}</p></div>)}</div>
    {!friends.length&&<EmptyState title="Aún no tienes amigos agregados"/>}
    {!!available.length&&<div className="mt-8"><h2 className="mb-3 font-black">Personas sugeridas</h2><div className="flex flex-wrap gap-2">{available.map((u)=><button key={u.id} className={secondaryButton} onClick={()=>{actions.addFriend(u.id);toast('Contacto agregado.')}}>＋ {displayName(u)}</button>)}</div></div>}
  </>
}
