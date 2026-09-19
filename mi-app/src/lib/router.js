import { useEffect, useState } from 'react'

export function getRoute() {
  const route = window.location.hash.replace(/^#\/?/, '')
  return route || 'inicio'
}

export function goTo(route) {
  const next = `#/${route}`
  if (window.location.hash !== next) window.location.hash = next
}

export function useHashRoute() {
  const [route, setRoute] = useState(getRoute())

  useEffect(() => {
    const handler = () => setRoute(getRoute())
    window.addEventListener('hashchange', handler)
    return () => window.removeEventListener('hashchange', handler)
  }, [])

  return route
}
