import { goTo } from '../lib/router'
import { formatDate } from '../lib/db'
import { SectionTitle, StatCard, Badge, EmptyState } from '../components/Ui'

export default function DashboardPage({ db, user }) {
  const myTeams = db.teams.filter((t) => t.miembros.includes(user.id))
  const myLeagues = db.leagues.filter((l) => l.adminId === user.id || l.equipos.some((id) => myTeams.some((t) => t.id === id)))
  const nearby = db.challenges.filter((r) => r.estado === 'Publicada').slice(0, 3)
  const agenda = db.agenda.filter((a) => a.userId === user.id && a.estado !== 'Finalizado').slice(0, 3)
  const quick = [
    ['ligas','/Imagenes/ImgLigas.png','Ligas','Explora y administra ligas'],
    ['retar','/Imagenes/ImgReta.png','Retar','Publica o acepta una reta'],
    ['equipos','/Imagenes/ImgEquipo.png','Equipos','Crea, administra o únete'],
    ['canchas','/Imagenes/ImgCanchas.png','Canchas','Busca instalaciones deportivas'],
  ]
  return <>
    <SectionTitle eyebrow="🏆 Inicio" title={`Hola, ${user.nombre}`} description="Desde aquí puedes acceder a tus equipos, ligas, retas, canchas, agenda y configuración de perfil." />
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <StatCard label="Mis equipos" value={myTeams.length} icon="👥" helper="Equipos donde participas" />
      <StatCard label="Mis ligas" value={myLeagues.length} icon="🏆" helper="Administradas o inscritas" />
      <StatCard label="Retas disponibles" value={db.challenges.filter((r)=>r.estado==='Publicada').length} icon="⚔️" helper="Publicadas en la plataforma" />
      <StatCard label="Agenda" value={agenda.length} icon="📅" helper="Eventos próximos" />
    </div>

    <div className="mt-7 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      {quick.map(([route,img,title,desc]) => <button type="button" key={route} onClick={() => goTo(route)} className="group rounded-3xl border border-slate-200 bg-white p-5 text-left shadow-sm transition hover:-translate-y-1 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900">
        <img src={img} className="h-12 w-12 object-contain" alt="" /><h3 className="mt-4 text-lg font-black">{title}</h3><p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{desc}</p>
      </button>)}
    </div>

    <div className="mt-8 grid gap-6 xl:grid-cols-2">
      <section className="rounded-3xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-center justify-between"><h2 className="font-black">🔥 Retas cerca de ti</h2><button onClick={() => goTo('retar')} className="text-sm font-bold text-blue-600">Ver todas</button></div>
        <div className="mt-4 space-y-3">{nearby.length ? nearby.map((r) => <div key={r.id} className="rounded-2xl bg-slate-50 p-4 dark:bg-slate-950"><div className="flex items-start justify-between gap-3"><div><p className="font-black">{r.titulo}</p><p className="mt-1 text-xs text-slate-500">{r.ciudad} · {formatDate(r.fecha)} · {r.hora}</p></div><Badge>{r.deporte}</Badge></div></div>) : <EmptyState title="No hay retas publicadas" />}</div>
      </section>
      <section className="rounded-3xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <div className="flex items-center justify-between"><h2 className="font-black">📅 Próximamente</h2><button onClick={() => goTo('agenda')} className="text-sm font-bold text-blue-600">Abrir agenda</button></div>
        <div className="mt-4 space-y-3">{agenda.length ? agenda.map((a) => <div key={a.id} className="rounded-2xl bg-slate-50 p-4 dark:bg-slate-950"><p className="font-black">{a.titulo}</p><p className="mt-1 text-xs text-slate-500">{formatDate(a.fecha)} · {a.hora} · {a.lugar}</p></div>) : <EmptyState title="Agenda libre" description="No tienes eventos próximos." />}</div>
      </section>
    </div>
  </>
}
