import { useMemo, useState } from 'react'
import { Field, inputClass, primaryButton, secondaryButton } from '../components/Ui'

function AuthBackdrop({ children }) {
  return (
    <div className="relative min-h-screen overflow-hidden bg-[linear-gradient(135deg,#ffffff_0%,#f0f9ff_48%,#fff1f2_100%)] px-4 py-10 text-slate-950">
      <div className="pointer-events-none absolute -left-40 -top-40 h-[34rem] w-[34rem] rounded-full border-[70px] border-blue-100/70" />
      <div className="pointer-events-none absolute -bottom-44 -right-32 h-[36rem] w-[36rem] rounded-full border-[70px] border-red-100/70" />
      <div className="pointer-events-none absolute left-1/2 top-1/2 h-96 w-96 -translate-x-1/2 -translate-y-1/2 rounded-full bg-blue-100/40 blur-3xl" />
      <div className="relative mx-auto flex min-h-[calc(100vh-5rem)] max-w-6xl items-center justify-center">{children}</div>
    </div>
  )
}

export function LoginPage({ onLogin, onRegister }) {
  const [usuario, setUsuario] = useState('demo')
  const [contrasena, setContrasena] = useState('demo123')
  const [error, setError] = useState('')

  const submit = (e) => {
    e.preventDefault()
    const result = onLogin(usuario.trim(), contrasena)
    if (!result.ok) setError(result.message)
  }

  return (
    <AuthBackdrop>
      <div className="grid w-full max-w-5xl overflow-hidden rounded-[2rem] border border-white/80 bg-white/85 shadow-2xl shadow-blue-200/40 backdrop-blur-xl md:grid-cols-[1.1fr_.9fr]">
        <section className="hidden bg-slate-950 p-10 text-white md:flex md:flex-col md:justify-between">
          <div>
            <div className="mb-10 flex items-center gap-3">
              <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white font-black text-slate-950">TA</div>
              <div><p className="font-black">TAJIMAL ALTAMIRANO</p><p className="text-xs text-slate-400">RETAME · Plataforma deportiva</p></div>
            </div>
            <h1 className="text-4xl font-black leading-tight">Organiza, reta y compite de una forma más fácil.</h1>
            <p className="mt-5 max-w-md text-sm leading-7 text-slate-300">Administra equipos, ligas, canchas, retas, agenda y solicitudes desde un solo panel. Esta versión funciona directamente en Vercel sin servidor PHP ni base de datos MySQL.</p>
          </div>
          <div className="grid grid-cols-3 gap-3 text-center text-xs font-bold text-slate-300">
            <div className="rounded-2xl bg-white/5 p-3">🏆<br/>Ligas</div>
            <div className="rounded-2xl bg-white/5 p-3">⚔️<br/>Retas</div>
            <div className="rounded-2xl bg-white/5 p-3">🏟️<br/>Canchas</div>
          </div>
        </section>
        <section className="p-7 sm:p-10">
          <p className="text-xs font-black uppercase tracking-[0.25em] text-blue-600">Bienvenido</p>
          <h2 className="mt-2 text-3xl font-black">Iniciar sesión</h2>
          <p className="mt-2 text-sm text-slate-500">Ingresa a tu panel deportivo.</p>
          <form onSubmit={submit} className="mt-8 space-y-4">
            <Field label="Usuario / ID de retador"><input className={inputClass} value={usuario} onChange={(e) => setUsuario(e.target.value)} autoComplete="username" /></Field>
            <Field label="Contraseña"><input className={inputClass} type="password" value={contrasena} onChange={(e) => setContrasena(e.target.value)} autoComplete="current-password" /></Field>
            {error && <div className="rounded-2xl bg-red-50 px-4 py-3 text-sm font-bold text-red-700">{error}</div>}
            <button className={`${primaryButton} w-full`} type="submit">Iniciar sesión</button>
          </form>
          <div className="mt-5 rounded-2xl bg-blue-50 p-4 text-xs text-blue-800">
          </div>
          <button type="button" onClick={onRegister} className={`${secondaryButton} mt-4 w-full`}>Crear una cuenta nueva</button>
        </section>
      </div>
    </AuthBackdrop>
  )
}

export function RegisterPage({ db, onCreate, onBack }) {
  const [step, setStep] = useState(1)
  const [form, setForm] = useState({ nombre: '', apellido: '', codigoPostal: '', pais: 'México', estado: 'Chiapas', edad: '', correo: '', password: '', confirm: '' })
  const [error, setError] = useState('')
  const previewId = useMemo(() => {
    if (!form.nombre || !form.apellido) return 'Se generará al registrar'
    const base = `${form.nombre.trim().slice(0, 6)}${form.apellido.trim().charAt(0)}`.replace(/\s/g, '')
    let n = 10
    let candidate = `${base}${n}`
    while (db.users.some((u) => u.id.toLowerCase() === candidate.toLowerCase())) { n += 1; candidate = `${base}${n}` }
    return candidate
  }, [db.users, form.apellido, form.nombre])

  const next = () => {
    setError('')
    if (step === 1) {
      if (!form.nombre || !form.apellido || !form.codigoPostal || !form.estado || !form.pais || !form.edad) return setError('Completa todos los datos personales.')
      if (!/^\d+$/.test(form.codigoPostal) || !/^\d+$/.test(form.edad)) return setError('Código postal y edad deben contener solo números.')
      setStep(2)
      return
    }
    if (!form.password || form.password.length < 4) return setError('La contraseña debe tener al menos 4 caracteres.')
    if (form.password !== form.confirm) return setError('Las contraseñas no coinciden.')
    const result = onCreate({ ...form, id: previewId })
    if (!result.ok) return setError(result.message)
    setStep(3)
  }

  return (
    <AuthBackdrop>
      <div className="w-full max-w-2xl rounded-[2rem] border border-white/80 bg-white/90 p-7 shadow-2xl shadow-blue-200/40 backdrop-blur-xl sm:p-10">
        <button type="button" onClick={onBack} className="text-sm font-bold text-slate-500 hover:text-blue-700">← Volver al inicio de sesión</button>
        <div className="mt-6 flex gap-2">{[1,2,3].map((n) => <span key={n} className={`h-2 flex-1 rounded-full ${step >= n ? 'bg-blue-600' : 'bg-slate-200'}`} />)}</div>
        {step === 1 && <>
          <h1 className="mt-7 text-3xl font-black">👤 Registro de nuevo retador</h1>
          <p className="mt-2 text-sm text-slate-500">Completa tus datos personales para crear el perfil.</p>
          <div className="mt-7 grid gap-4 sm:grid-cols-2">
            {['nombre','apellido','codigoPostal','pais','estado','edad'].map((key) => <Field key={key} label={{nombre:'Nombre',apellido:'Apellido',codigoPostal:'Código postal',pais:'País',estado:'Estado',edad:'Edad'}[key]}><input className={inputClass} value={form[key]} onChange={(e) => setForm({...form,[key]:e.target.value})} /></Field>)}
            <Field label="Correo (opcional)"><input className={inputClass} type="email" value={form.correo} onChange={(e) => setForm({...form,correo:e.target.value})} /></Field>
          </div>
        </>}
        {step === 2 && <>
          <h1 className="mt-7 text-3xl font-black">🔒 Crear contraseña</h1>
          <p className="mt-2 text-sm text-slate-500">Tu ID de retador será <b>{previewId}</b>. Guárdalo para iniciar sesión.</p>
          <div className="mt-7 space-y-4">
            <Field label="Contraseña"><input className={inputClass} type="password" value={form.password} onChange={(e) => setForm({...form,password:e.target.value})} /></Field>
            <Field label="Confirmar contraseña"><input className={inputClass} type="password" value={form.confirm} onChange={(e) => setForm({...form,confirm:e.target.value})} /></Field>
          </div>
        </>}
        {step === 3 && <div className="py-10 text-center">
          <div className="text-5xl">🎉</div><h1 className="mt-4 text-3xl font-black">Registro completo</h1><p className="mt-3 text-slate-500">Tu cuenta fue creada. ID: <b>{previewId}</b></p><button className={`${primaryButton} mt-7`} type="button" onClick={onBack}>Ir a iniciar sesión</button>
        </div>}
        {error && <div className="mt-5 rounded-2xl bg-red-50 px-4 py-3 text-sm font-bold text-red-700">{error}</div>}
        {step < 3 && <div className="mt-7 flex justify-end"><button type="button" className={primaryButton} onClick={next}>{step === 1 ? 'Continuar' : 'Crear cuenta'}</button></div>}
      </div>
    </AuthBackdrop>
  )
}
