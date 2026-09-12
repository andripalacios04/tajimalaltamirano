import { useEffect, useState } from 'react'
import Header from './components/Header'
import SearchBar from './components/SearchBar'
import TeamList from './components/TeamList'

function App() {
  const [equipos, setEquipos] = useState([])
  const [busqueda, setBusqueda] = useState('')

  useEffect(() => {
    fetch('/data.json')
      .then((response) => response.json())
      .then((data) => setEquipos(data))
      .catch((error) => console.error('Error al cargar los datos:', error))
  }, [])

  const equiposFiltrados = equipos.filter((equipo) =>
    equipo.nombre.toLowerCase().includes(busqueda.toLowerCase()) ||
    equipo.deporte.toLowerCase().includes(busqueda.toLowerCase()) ||
    equipo.ciudad.toLowerCase().includes(busqueda.toLowerCase())
  )

  return (
    <main className="min-h-screen bg-slate-100">
      <Header
        titulo="TAJIMAL ALTAMIRANO"
        subtitulo="Explora y busca equipos deportivos"
      />

      <SearchBar
        busqueda={busqueda}
        setBusqueda={setBusqueda}
      />

      {equiposFiltrados.length > 0 ? (
        <TeamList equipos={equiposFiltrados} />
      ) : (
        <p className="px-6 py-10 text-center text-slate-500">
          No se encontraron equipos.
        </p>
      )}
    </main>
  )
}

export default App