import { goTo } from '../lib/router'
import { displayName } from '../lib/db'

const items = [
  ['inicio', 'ImgInicio.png', 'Inicio'],
  ['equipos', 'ImgEquipo.png', 'Equipos'],
  ['ligas', 'ImgLigas.png', 'Ligas'],
  ['retar', 'ImgReta.png', 'Retar'],
  ['canchas', 'ImgCanchas.png', 'Canchas'],
  ['agenda', 'ImgAgenda.png', 'Agenda'],
  ['solicitudes', 'ImgSolicitud.png', 'Solicitudes'],
  ['notificaciones', 'ImgNoti.png', 'Notificaciones'],
  ['amigos', 'ImgAmigos.png', 'Amigos'],
  ['perfil', 'ImgPerfil.png', 'Mi perfil'],
]

export default function AppShell({ route, user, notificationCount, dark, onToggleDark, onLogout, children }) {
  const nav = (key) => goTo(key)
  return (
    <div className={dark ? 'dark' : ''}>
      <div className="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-white">
        <header className="sticky top-0 z-50 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl dark:border-slate-800 dark:bg-slate-950/90">
          <div className="mx-auto flex max-w-[1500px] items-center gap-4 px-4 py-3 sm:px-6">
            <button type="button" onClick={() => nav('inicio')} className="group flex min-w-0 items-center gap-3 text-left">
              <div className="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                <span className="absolute left-2 top-2 h-3 w-3 rounded-full bg-blue-600" />
                <span className="absolute bottom-2 right-2 h-3 w-3 rounded-full bg-red-500" />
                <span className="text-sm font-black tracking-tighter">TA</span>
              </div>
              <div className="min-w-0">
                <p className="truncate text-sm font-black tracking-wide">TAJIMAL ALTAMIRANO</p>
                <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">RETAME · Panel deportivo</p>
              </div>
            </button>
            <div className="ml-auto flex items-center gap-2">
              <button type="button" onClick={() => nav('notificaciones')} className="relative rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-900">
                🔔
                {notificationCount > 0 && <span className="absolute -right-1 -top-1 min-w-5 rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-black text-white">{notificationCount}</span>}
              </button>
              <button type="button" onClick={onToggleDark} className="rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-900">{dark ? '☀️' : '🌙'}</button>
              <button type="button" onClick={() => nav('perfil')} className="hidden rounded-2xl border border-slate-200 bg-white px-4 py-2 text-left shadow-sm sm:block dark:border-slate-700 dark:bg-slate-900">
                <p className="text-xs font-black">{displayName(user)}</p>
                <p className="text-[10px] text-slate-500">{user?.id}</p>
              </button>
            </div>
          </div>
        </header>

        <div className="mx-auto flex max-w-[1500px]">
          <aside className="sticky top-[69px] hidden h-[calc(100vh-69px)] w-64 shrink-0 border-r border-slate-200 bg-white/80 p-4 lg:block dark:border-slate-800 dark:bg-slate-950/80">
            <nav className="space-y-1">
              {items.map(([key, icon, label]) => (
                <button key={key} type="button" onClick={() => nav(key)} className={`flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-left text-sm font-bold transition ${route === key ? 'bg-slate-950 text-white shadow-lg shadow-slate-300 dark:bg-white dark:text-slate-950 dark:shadow-none' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-900'}`}>
                  <img src={`/Imagenes/${icon}`} className="h-6 w-6 object-contain" alt="" />
                  <span>{label}</span>
                </button>
              ))}
            </nav>
            <div className="mt-6 border-t border-slate-200 pt-4 dark:border-slate-800">
              <button type="button" onClick={onLogout} className="flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-left text-sm font-bold text-red-600 hover:bg-red-50 dark:hover:bg-red-950/40">
                <img src="/Imagenes/ImgCerrar.png" className="h-6 w-6 object-contain" alt="" />
                Cerrar sesión
              </button>
            </div>
          </aside>

          <main className="min-w-0 flex-1 px-4 py-6 pb-28 sm:px-6 lg:px-8 lg:pb-10">{children}</main>
        </div>

        <nav className="fixed bottom-0 left-0 right-0 z-50 border-t border-slate-200 bg-white/95 px-2 py-2 backdrop-blur lg:hidden dark:border-slate-800 dark:bg-slate-950/95">
          <div className="mx-auto flex max-w-xl justify-between gap-1">
            {items.slice(0, 5).map(([key, icon, label]) => (
              <button key={key} type="button" onClick={() => nav(key)} className={`flex min-w-0 flex-1 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-[10px] font-bold ${route === key ? 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300' : 'text-slate-500'}`}>
                <img src={`/Imagenes/${icon}`} className="h-5 w-5 object-contain" alt="" />
                <span className="truncate">{label}</span>
              </button>
            ))}
            <button type="button" onClick={() => nav('perfil')} className={`flex min-w-0 flex-1 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-[10px] font-bold ${route === 'perfil' ? 'bg-blue-50 text-blue-700' : 'text-slate-500'}`}>
              <img src="/Imagenes/ImgPerfil.png" className="h-5 w-5 object-contain" alt="" />
              Más
            </button>
          </div>
        </nav>
      </div>
    </div>
  )
}
