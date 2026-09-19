import { useEffect, useMemo, useState } from 'react'
import AppShell from './components/AppShell'
import { loadDb, resetDb, saveDb, uid } from './lib/db'
import { goTo, useHashRoute } from './lib/router'
import { LoginPage, RegisterPage } from './pages/AuthPages'
import DashboardPage from './pages/DashboardPage'
import TeamsPage from './pages/TeamsPage'
import LeaguesPage from './pages/LeaguesPage'
import ChallengesPage from './pages/ChallengesPage'
import CourtsPage from './pages/CourtsPage'
import AgendaPage from './pages/AgendaPage'
import { FriendsPage, NotificationsPage, RequestsPage } from './pages/InboxPages'
import ProfilePage from './pages/ProfilePage'

function App() {
  const route = useHashRoute()
  const [db, setDb] = useState(null)
  const [authMode, setAuthMode] = useState('login')
  const [toast, setToast] = useState(null)

  useEffect(() => {
    let cancelled = false
    async function boot() {
      const existing = loadDb()
      if (existing) { if (!cancelled) setDb(existing); return }
      try {
        const response = await fetch('/data.json')
        if (!response.ok) throw new Error('No se pudo cargar data.json')
        const seed = await response.json()
        const initial = { ...seed, sessionUserId: null }
        saveDb(initial)
        if (!cancelled) setDb(initial)
      } catch (error) {
        console.error(error)
        if (!cancelled) setDb({ users: [], teams: [], teamRequests: [], leagues: [], leagueRequests: [], challenges: [], courts: [], agenda: [], notifications: [], friends: [], sessionUserId: null })
      }
    }
    boot()
    return () => { cancelled = true }
  }, [])

  useEffect(() => {
    if (!toast) return
    const timer = setTimeout(() => setToast(null), 2800)
    return () => clearTimeout(timer)
  }, [toast])

  const commit = (recipe) => {
    setDb((current) => {
      const next = JSON.parse(JSON.stringify(current))
      recipe(next)
      saveDb(next)
      return next
    })
  }

  const notify = (message, tone='success') => setToast({ message, tone })
  const currentUser = useMemo(() => db?.users?.find((u) => u.id === db.sessionUserId) || null, [db])

  const login = (id, password) => {
    const user = db.users.find((u) => u.id.toLowerCase() === id.toLowerCase())
    if (!user) return { ok:false, message:'Usuario no encontrado.' }
    if (user.password !== password) return { ok:false, message:'Contraseña incorrecta.' }
    commit((next) => { next.sessionUserId = user.id })
    goTo('inicio')
    return { ok:true }
  }

  const createUser = (data) => {
    if (db.users.some((u)=>u.id.toLowerCase()===data.id.toLowerCase())) return {ok:false,message:'Ese ID ya existe.'}
    commit((next)=>next.users.push({ id:data.id, password:data.password, nombre:data.nombre.trim(), apellido:data.apellido.trim(), edad:data.edad, estado:data.estado.trim(), pais:data.pais.trim(), codigoPostal:data.codigoPostal, telefono:'', correo:data.correo||'', modo:'claro' }))
    return {ok:true}
  }

  const logout = () => { commit((next)=>{next.sessionUserId=null}); setAuthMode('login'); goTo('inicio') }
  const addNotification = (next,userId,title,descripcion) => next.notifications.push({id:uid('NT'),userId,titulo:title,descripcion,fecha:new Date().toISOString(),leida:false})

  const actions = {
    createTeam(form){ commit((next)=>next.teams.push({id:uid('EQ'),nombre:form.nombre.trim(),deporte:form.deporte,ciudad:form.ciudad,codigoPostal:form.codigoPostal,capitanId:currentUser.id,miembros:[currentUser.id],maxMiembros:Number(form.maxMiembros)||11,tipo:form.tipo})) },
    requestTeam(teamId){ const exists=db.teamRequests.some((r)=>r.teamId===teamId&&r.userId===currentUser.id&&r.estado==='Pendiente'); if(exists)return{ok:false,message:'Ya enviaste una solicitud a este equipo.'}; commit((next)=>{const team=next.teams.find((t)=>t.id===teamId);next.teamRequests.push({id:uid('SOL'),teamId,userId:currentUser.id,estado:'Pendiente',fecha:new Date().toISOString()});addNotification(next,team.capitanId,'Nueva solicitud de equipo',`${currentUser.nombre} quiere unirse a ${team.nombre}.`)});return{ok:true,message:'Solicitud enviada.'} },
    resolveTeamRequest(id,accept){commit((next)=>{const req=next.teamRequests.find((r)=>r.id===id);if(!req)return;req.estado=accept?'Aceptada':'Rechazada';if(accept){const team=next.teams.find((t)=>t.id===req.teamId);if(team&&!team.miembros.includes(req.userId))team.miembros.push(req.userId)}addNotification(next,req.userId,accept?'Solicitud aceptada':'Solicitud rechazada',`Tu solicitud de equipo fue ${accept?'aceptada':'rechazada'}.`)})},
    createLeague(form,teamId){commit((next)=>next.leagues.push({id:uid('LG'),nombre:form.nombre.trim(),deporte:form.deporte,codigoPostal:form.codigoPostal,fechaInicio:form.fechaInicio,adminId:currentUser.id,equipos:teamId?[teamId]:[],maxEquipos:Number(form.maxEquipos)||8}))},
    requestLeague(leagueId,teamId){if(db.leagueRequests.some((r)=>r.leagueId===leagueId&&r.teamId===teamId&&r.estado==='Pendiente'))return{ok:false,message:'Ya hay una solicitud pendiente.'};commit((next)=>{const league=next.leagues.find((l)=>l.id===leagueId);next.leagueRequests.push({id:uid('LSOL'),leagueId,teamId,estado:'Pendiente',fecha:new Date().toISOString()});addNotification(next,league.adminId,'Solicitud para liga',`Un equipo solicitó inscripción a ${league.nombre}.`)});return{ok:true,message:'Solicitud de liga enviada.'}},
    resolveLeagueRequest(id,accept){commit((next)=>{const req=next.leagueRequests.find((r)=>r.id===id);if(!req)return;req.estado=accept?'Aceptada':'Rechazada';if(accept){const league=next.leagues.find((l)=>l.id===req.leagueId);if(league&&!league.equipos.includes(req.teamId))league.equipos.push(req.teamId)}})},
    createChallenge(form){commit((next)=>next.challenges.push({id:uid('RT'),...form,creadorId:currentUser.id,equipoRivalId:null,estado:'Publicada'}))},
    joinChallenge(id,teamId){const challenge=db.challenges.find((r)=>r.id===id);if(!challenge||challenge.estado!=='Publicada')return{ok:false,message:'La reta ya no está disponible.'};if(challenge.equipoCreadorId===teamId)return{ok:false,message:'No puedes retar con el mismo equipo.'};commit((next)=>{const r=next.challenges.find((x)=>x.id===id);r.equipoRivalId=teamId;r.estado='Programada';[r.creadorId,currentUser.id].forEach((uidUser)=>next.agenda.push({id:uid('AG'),userId:uidUser,tipo:'Reta',titulo:r.titulo,fecha:r.fecha,hora:r.hora,lugar:r.lugar,estado:'Programado',resultado:''}));addNotification(next,r.creadorId,'Reta aceptada',`${currentUser.nombre} aceptó tu reta ${r.titulo}.`)});return{ok:true,message:'Reta aceptada y agregada a tu agenda.'}},
    createCourt(form){commit((next)=>next.courts.push({id:uid('CN'),...form,duenoId:currentUser.id}))},
    updateCourt(id,form){commit((next)=>{const idx=next.courts.findIndex((c)=>c.id===id);if(idx>=0)next.courts[idx]={...next.courts[idx],...form,id,duenoId:next.courts[idx].duenoId}})},
    deleteCourt(id){commit((next)=>{next.courts=next.courts.filter((c)=>c.id!==id)})},
    finishAgenda(id,resultado){commit((next)=>{const a=next.agenda.find((x)=>x.id===id);if(a){a.estado='Finalizado';a.resultado=resultado}})},
    markNotification(id){commit((next)=>{const n=next.notifications.find((x)=>x.id===id);if(n)n.leida=true})},
    addFriend(otherId){commit((next)=>{if(!next.friends.some((f)=>(f.userA===currentUser.id&&f.userB===otherId)||(f.userB===currentUser.id&&f.userA===otherId)))next.friends.push({id:uid('AM'),userA:currentUser.id,userB:otherId})})},
    updateProfile(form){commit((next)=>{const u=next.users.find((x)=>x.id===currentUser.id);Object.assign(u,{nombre:form.nombre,apellido:form.apellido,edad:form.edad,estado:form.estado,pais:form.pais,codigoPostal:form.codigoPostal,telefono:form.telefono,correo:form.correo});if(form.password)u.password=form.password})},
  }

  const reset = () => { resetDb(); window.location.reload() }

  if (!db) return <div className="flex min-h-screen items-center justify-center bg-slate-950 text-white"><div className="text-center"><div className="mx-auto h-10 w-10 animate-spin rounded-full border-4 border-white/20 border-t-white"/><p className="mt-4 text-sm font-bold">Cargando TAJIMAL ALTAMIRANO...</p></div></div>
  if (!currentUser) return authMode==='register' ? <RegisterPage db={db} onCreate={createUser} onBack={()=>setAuthMode('login')}/> : <LoginPage onLogin={login} onRegister={()=>setAuthMode('register')}/>

  const unread=db.notifications.filter((n)=>n.userId===currentUser.id&&!n.leida).length
  const dark=currentUser.modo==='oscuro'
  const toggleDark=()=>commit((next)=>{const u=next.users.find((x)=>x.id===currentUser.id);u.modo=u.modo==='oscuro'?'claro':'oscuro'})
  const common={db,user:currentUser,actions,toast:notify}
  let content
  switch(route){
    case 'equipos': content=<TeamsPage {...common}/>; break
    case 'ligas': content=<LeaguesPage {...common}/>; break
    case 'retar': content=<ChallengesPage {...common}/>; break
    case 'canchas': content=<CourtsPage {...common}/>; break
    case 'agenda': content=<AgendaPage {...common}/>; break
    case 'solicitudes': content=<RequestsPage {...common}/>; break
    case 'notificaciones': content=<NotificationsPage {...common}/>; break
    case 'amigos': content=<FriendsPage {...common}/>; break
    case 'perfil': content=<ProfilePage {...common} onLogout={logout} onReset={reset}/>; break
    default: content=<DashboardPage {...common}/>; break
  }
  return <>
    <AppShell route={route} user={currentUser} notificationCount={unread} dark={dark} onToggleDark={toggleDark} onLogout={logout}>{content}</AppShell>
    {toast&&<div className={`fixed bottom-24 right-4 z-[100] max-w-sm rounded-2xl px-5 py-3 text-sm font-black text-white shadow-2xl lg:bottom-6 ${toast.tone==='error'?'bg-red-600':'bg-slate-950'}`}>{toast.message}</div>}
  </>
}

export default App
