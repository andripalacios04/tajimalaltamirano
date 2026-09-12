function TeamCard({ equipo }) {
  return (
    <article className="rounded-2xl bg-white p-5 shadow-md transition hover:-translate-y-1 hover:shadow-lg">
      <h2 className="text-xl font-bold text-slate-800">{equipo.nombre}</h2>
      <p className="mt-2 text-slate-600">Deporte: {equipo.deporte}</p>
      <p className="text-slate-600">Ciudad: {equipo.ciudad}</p>
      <p className="mt-3 text-sm font-semibold text-blue-600">
        Integrantes: {equipo.integrantes}
      </p>
    </article>
  )
}

export default TeamCard