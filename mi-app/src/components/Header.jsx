function Header({ titulo, subtitulo }) {
  return (
    <header className="bg-slate-900 px-6 py-8 text-white">
      <div className="mx-auto max-w-6xl">
        <h1 className="text-3xl font-bold md:text-4xl">{titulo}</h1>
        <p className="mt-2 text-slate-300">{subtitulo}</p>
      </div>
    </header>
  )
}

export default Header