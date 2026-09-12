import TeamCard from './TeamCard'

function TeamList({ equipos }) {
  return (
    <section className="mx-auto grid w-full max-w-6xl grid-cols-1 gap-5 px-6 pb-10 sm:grid-cols-2 lg:grid-cols-3">
      {equipos.map((equipo) => (
        <TeamCard key={equipo.id} equipo={equipo} />
      ))}
    </section>
  )
}

export default TeamList