# TAJIMAL ALTAMIRANO — React/Vite

Versión frontend del sistema deportivo preparada para Vercel.

## Qué incluye

- Inicio de sesión y registro local.
- Panel principal.
- Equipos: crear, buscar, enviar/aceptar solicitudes.
- Ligas: crear, solicitar inscripción y panel de administración.
- Retas: publicar, filtrar, aceptar y agregar a agenda.
- Canchas: listar, registrar, editar y eliminar las propias.
- Agenda y registro de resultados.
- Solicitudes, notificaciones, amigos y perfil.
- Diseño responsivo y modo oscuro.

## Persistencia sin servidor

Esta versión no usa PHP ni MySQL en producción. Los datos de demostración se cargan desde `public/data.json` y los cambios realizados por el usuario se conservan en `localStorage` del navegador.

Esto permite que el proyecto funcione en Vercel sin configurar una base de datos externa. La información queda guardada únicamente en el navegador/dispositivo donde se utiliza. Para una aplicación multiusuario real se recomienda conectar posteriormente Firebase/Supabase u otro backend.

## Cuenta de demostración

- Usuario: `demo`
- Contraseña: `demo123`

## Desarrollo

```bash
npm install
npm run dev
```

## Compilación

```bash
npm run build
```

En Vercel, configura **Root Directory** como `mi-app`.
