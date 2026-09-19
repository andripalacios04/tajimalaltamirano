export const STORAGE_KEY = 'tajimal_altamirano_db_v2'

export function cloneDb(value) {
  return JSON.parse(JSON.stringify(value))
}

export function saveDb(db) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(db))
}

export function loadDb() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

export function resetDb() {
  localStorage.removeItem(STORAGE_KEY)
}

export function uid(prefix) {
  const chunk = Math.random().toString(36).slice(2, 7).toUpperCase()
  return `${prefix}-${Date.now().toString(36).toUpperCase()}-${chunk}`
}

export function displayName(user) {
  if (!user) return 'Usuario'
  return `${user.nombre || ''} ${user.apellido || ''}`.trim() || user.id
}

export function formatDate(value) {
  if (!value) return 'Sin fecha'
  const date = new Date(`${value}T12:00:00`)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat('es-MX', { day: '2-digit', month: 'short', year: 'numeric' }).format(date)
}

export function formatDateTime(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat('es-MX', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(date)
}
