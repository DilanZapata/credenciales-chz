# 6. Endpoints JSON

Base: `{APP_URL}/app/api/`

Un archivo por módulo, con reparto por `accion`, siguiendo el patrón de
Porcify Manager:

```
app/api/credenciales-api.php     app/api/usuarios-api.php
app/api/secretos-api.php         app/api/sistemas-api.php
app/api/login-api.php            app/api/catalogos-api.php
app/api/utilidades-api.php       app/api/auditoria-api.php
app/api/reportes-api.php         app/api/sesiones-api.php
app/api/importacion-api.php
```

## 6.1 Autenticación y convenios

Los endpoints usan **la misma sesión que la interfaz**: cookie
`scgca_session` (`HttpOnly`, `Secure`, `SameSite=Strict`). No hay tokens de
larga duración precisamente porque cualquier credencial permanente sería un
activo más que proteger.

Toda petición que modifica estado debe incluir:

```
X-CSRF-Token: <token de la sesión>
```

Todos verifican **autenticación y autorización en el servidor**. El
frontend no participa en la decisión.

### Forma de la respuesta

Siempre el mismo sobre, se acierte o se falle:

```json
{
  "code": 200,
  "status": "success",
  "title": "Listado de credenciales",
  "message": "Listado de credenciales.",
  "data": { }
}
```

`status` es `success` o `error`; `code` coincide con el código HTTP. Los
datos útiles van siempre en `data`. En los errores se añade, según el caso,
`errors` (validación por campo), `reauth_required` o `csrf`.

### Códigos de estado

| Código | Significado |
|---|---|
| 200 / 201 | Correcto |
| 400 | Solicitud inválida o acción no reconocida |
| 401 | Sesión inexistente o expirada |
| 403 | Autenticado pero sin autorización, **o token CSRF inválido** (`"csrf": true`) |
| 404 | No existe **o está fuera de su alcance** (no se distingue a propósito) |
| 405 | Método no permitido |
| 422 | Error de validación (incluye `errors` por campo) |
| **423** | **Requiere reautenticación** (step-up). Incluye `reauth_required: true` |
| 429 | Límite de frecuencia superado |
| 500 | Error interno (sin detalles internos en la respuesta) |

> **Por qué el fallo de CSRF es 403 y no 419.** El 419 no es un código
> estándar y Apache lo reescribe como 500, con lo que el usuario veía
> "Error del sistema" en lugar de "la sesión del formulario expiró". Se
> responde 403 con el marcador `"csrf": true` y la cabecera
> `X-Csrf-Failure: 1`, que el cliente reconoce para pedir recargar.

---

## 6.2 Sesión — `login-api.php`

| Acción | Método | Descripción |
|---|---|---|
| `estado` | GET | Estado de la sesión actual |
| `ingresar` | POST | Inicia sesión y fija la cookie |
| `mfa` | POST | Verifica el segundo factor |
| `reauth` | POST | Reautenticación previa a una operación sensible |
| `salir` | POST | Cierra la sesión |

### `GET login-api.php?accion=estado`

```json
{
  "code": 200, "status": "success", "title": "Estado de la sesion",
  "data": {
    "authenticated": true,
    "user": { "id": 3, "name": "Maria Gomez", "national_id": "987654321", "roles": ["ADMIN"] },
    "expires_in_seconds": 1740,
    "reauth_valid": true
  }
}
```

### `POST login-api.php?accion=reauth`

```json
{ "password": "…", "code": "123456" }
```

`code` sólo es necesario si el usuario tiene MFA activo. Responde `200`, o
`401` con un mensaje uniforme (no distingue entre contraseña y código
incorrectos).

El token de sesión **nunca** viaja en el cuerpo: sólo en la cookie.

---

## 6.3 Credenciales — `credenciales-api.php`

> Este archivo no devuelve contraseñas. Nunca.

| Acción | Método | Permiso |
|---|---|---|
| `listar` | GET | `credentials.view` |
| `ver` | GET | `credentials.view` |
| `historial` | GET | `history.view` |
| `asignaciones` | GET | `credentials.assign` |
| `agregar` | POST | `credentials.create` |
| `actualizar` | POST | `credentials.update` |
| `rotar` | POST | `credentials.rotate` + step-up |
| `eliminar` | POST | `credentials.delete` |
| `restaurar` | POST | `credentials.delete` |
| `asignar` | POST | `credentials.assign` |
| `revocar` | POST | `credentials.revoke` |

### `GET credenciales-api.php?accion=listar`

Un usuario sin `credentials.view_all` sólo obtiene lo que tiene asignado.

Parámetros: `q`, `category_id`, `system_id`, `company_id`, `department_id`,
`status`, `assigned_user_id`, `sort`, `direction`, `page`, `per_page`.

```json
{
  "code": 200, "status": "success",
  "data": {
    "items": [
      {
        "id": 1, "name": "Usuario de contabilidad", "system_name": "Sistema Contable",
        "category_name": "Sistemas", "username": "contabilidad",
        "status": "active", "rotation_state": "ok", "assignment_count": 2,
        "has_secret": true
      }
    ],
    "total": 8, "page": 1, "per_page": 25, "pages": 1,
    "filters": { "search": "contable" }
  }
}
```

`has_secret` indica que **existe** un secreto, no lo entrega.

### `POST credenciales-api.php?accion=agregar`

El campo `password` se cifra antes de tocar la base de datos y no vuelve a
aparecer en ninguna respuesta.

### `POST credenciales-api.php?accion=actualizar&id={id}`

**No permite cambiar la contraseña**: para eso existe `rotar`, que conserva
el historial y exige motivo.

### `POST credenciales-api.php?accion=eliminar&id={id}`

Baja **lógica**: archiva, revoca las asignaciones y conserva historial y
auditoría.

### `POST credenciales-api.php?accion=rotar&id={id}`

```json
{ "password": "…", "reason": "Rotacion periodica", "rotation_period_days": 90 }
```

Conserva la contraseña anterior como versión histórica cifrada y rechaza
repetir la vigente (comparación por huella HMAC, sin descifrar).

---

## 6.4 Secretos — `secretos-api.php`

> Estos son los **únicos** endpoints que devuelven texto en claro. Viven en
> su propio archivo para que ninguna otra acción pueda entregar un secreto
> por descuido.

### `POST secretos-api.php?accion=revelar&id={id}`

Permiso: `credentials.secret.view` (o `.copy` con `"copy": true`).

Barreras: permiso → asignación vigente → permiso fino de la asignación →
límite de frecuencia (60 / 5 min) → **step-up**.

```json
{ "field": "password", "copy": false }
```

```json
{
  "code": 200, "status": "success",
  "data": { "secret": "…", "field": "password", "version": 2, "is_current": true, "ttl": 30 }
}
```

Si falta el step-up: `423` con `reauth_required: true`.
Cabecera de respuesta: `Cache-Control: no-store, private`.

### `POST secretos-api.php?accion=historico&id={id}&version={n}`

Permiso: `credentials.secret.history`. Revela una contraseña **anterior**.
Genera su propio registro de auditoría.

### `GET secretos-api.php?accion=recuperacion&id={id}`

Permiso: `credentials.recovery.view` + step-up. Devuelve correo, teléfono,
usuario y notas de recuperación. Se audita como `recovery.viewed`.

---

## 6.5 Resto de módulos

| Archivo | Acciones GET | Acciones POST |
|---|---|---|
| `usuarios-api.php` | `listar`, `ver`, `seleccion` | `agregar`, `actualizar`, `permisos`, `desactivar`, `reactivar`, `restablecer` |
| `sistemas-api.php` | `listar`, `ver`, `seleccion` | `agregar`, `actualizar`, `archivar` |
| `catalogos-api.php` | `categorias`, `organizacion`, `roles`, `configuracion` | `guardar-categoria`, `guardar-empresa`, `guardar-sede`, `guardar-departamento`, `guardar-rol`, `permisos-rol`, `guardar-configuracion` |
| `auditoria-api.php` | `listar`, `acciones`, `eventos` | `resolver` |
| `sesiones-api.php` | `listar` | `revocar`, `revocar-usuario` |
| `reportes-api.php` | `opciones`, `seleccion`, `historial`, `descargar` | `generar` |
| `importacion-api.php` | `columnas`, `plantilla` | `previsualizar`, `ejecutar` |
| `utilidades-api.php` | `buscar`, `notificaciones`, `alertas` | `generar`, `fortaleza` |

Dos acciones no devuelven JSON, porque entregan un archivo:
`reportes-api.php?accion=descargar&uuid=…` envía el XLSX y lo borra del
servidor en el mismo momento, e `importacion-api.php?accion=plantilla`
envía el CSV de ejemplo.

### `POST utilidades-api.php?accion=generar`

Permiso: `credentials.create`. Generador criptográficamente seguro.

```json
{ "length": 24, "upper": true, "lower": true, "digits": true,
  "symbols": true, "exclude_ambiguous": true }
```

La contraseña generada **no se registra en ningún log ni auditoría**.

### `POST utilidades-api.php?accion=fortaleza`

Evalúa la robustez de una contraseña sin almacenarla.

### `GET utilidades-api.php?accion=buscar&q=…`

Búsqueda global limitada al alcance del usuario. Mínimo 2 caracteres.

---

## 6.6 Ejemplo completo

```bash
BASE=https://credenciales.empresa.local
API=$BASE/app/api
JAR=/tmp/cookies.txt

# 1. Iniciar sesión por el formulario web (los endpoints comparten la sesión)
curl -s -c $JAR $BASE/entrar > /tmp/login.html
CSRF=$(grep -o 'name="_csrf" value="[a-f0-9]*"' /tmp/login.html | head -1 | sed 's/.*value="//;s/"//')
curl -s -b $JAR -c $JAR -X POST \
     -d "identifier=maria.gomez&password=***&_csrf=$CSRF" \
     $BASE/entrar

# 2. Listar credenciales (sin contraseñas, por diseño)
curl -s -b $JAR "$API/credenciales-api.php?accion=listar&q=contable"

# 3. Confirmar identidad (step-up)
curl -s -b $JAR -X POST -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
     -d '{"password":"***"}' "$API/login-api.php?accion=reauth"

# 4. Revelar el secreto (queda auditado)
curl -s -b $JAR -X POST -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
     -d '{"field":"password"}' "$API/secretos-api.php?accion=revelar&id=1"
```
