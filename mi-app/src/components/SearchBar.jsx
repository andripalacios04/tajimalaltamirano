function SearchBar({ busqueda, setBusqueda }) {
  return (
    <div className="mx-auto w-full max-w-6xl px-6 py-6">
      <input
        type="text"
        placeholder="Buscar equipo..."
        value={busqueda}
        onChange={(e) => setBusqueda(e.target.value)}
        className="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
      />
    </div>
  )
}

export default SearchBar