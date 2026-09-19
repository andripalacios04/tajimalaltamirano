import { useState } from 'react'
import { Field, inputClass, primaryButton, SectionTitle } from '../components/Ui'

export default function ProfilePage({user,actions,toast,onLogout,onReset}){
  const [form,setForm]=useState({...user,password:''})
  const save=(e)=>{e.preventDefault();actions.updateProfile(form);toast('Perfil actualizado correctamente.')}
  return <>
    <SectionTitle eyebrow="👤 Mi perfil" title={`${user.nombre} ${user.apellido}`} description={`ID de retador: ${user.id}`} actions={<button className="rounded-2xl border border-red-200 bg-white px-4 py-3 text-sm font-bold text-red-600 dark:bg-slate-900" onClick={onLogout}>Cerrar sesión</button>} />
    <div className="grid gap-6 xl:grid-cols-[280px_1fr]">
      <aside className="rounded-3xl border border-slate-200 bg-white p-6 text-center dark:border-slate-800 dark:bg-slate-900"><div className="mx-auto flex h-28 w-28 items-center justify-center rounded-full bg-gradient-to-br from-blue-100 to-red-100 text-4xl font-black text-slate-800">{user.nombre?.[0]}{user.apellido?.[0]}</div><p className="mt-4 text-xl font-black">{user.nombre} {user.apellido}</p><p className="text-sm text-slate-500">{user.estado}, {user.pais}</p><button type="button" className="mt-6 text-xs font-bold text-slate-400 underline" onClick={()=>{if(confirm('Esto restaura los datos de demostración y elimina cambios locales. ¿Continuar?')) onReset()}}>Restablecer datos de demostración</button></aside>
      <form onSubmit={save} className="rounded-3xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"><h2 className="font-black">Datos personales</h2><div className="mt-5 grid gap-4 sm:grid-cols-2">{['nombre','apellido','edad','estado','pais','codigoPostal','telefono','correo'].map((k)=><Field key={k} label={{nombre:'Nombre',apellido:'Apellido',edad:'Edad',estado:'Estado',pais:'País',codigoPostal:'Código postal',telefono:'Teléfono',correo:'Correo'}[k]}><input className={inputClass} value={form[k]||''} onChange={(e)=>setForm({...form,[k]:e.target.value})}/></Field>)}<Field label="Nueva contraseña" hint="Déjala vacía para conservar la actual."><input type="password" className={inputClass} value={form.password||''} onChange={(e)=>setForm({...form,password:e.target.value})}/></Field></div><button className={`${primaryButton} mt-6`} type="submit">💾 Guardar cambios</button></form>
    </div>
  </>
}
