# 5. Arquitectura y modelo de datos

## 5.1 Visión general

El sistema sigue la arquitectura de **Porcify Manager**: autocarga por
convención de nombres, modelos estáticos con el SQL dentro, controladores
que traducen entrada y salida, y dos puntos de entrada por HTTP.

```
                        ┌────────────────────────────────────────────┐
   Navegador ───HTTPS───►│  index.php        páginas (GET)            │
                        │  app/api/*-api.php  operaciones (JSON)     │
                        └───────────────────┬────────────────────────┘
                                            ▼
                    ┌────────────────────────────────────────────┐
                    │  app/views/inc/session_start.php           │
                    │  entorno, configuración, contexto y sesión  │
                    │  cabeceras de seguridad (CSP con nonce)     │
                    └───────────────────┬────────────────────────┘
                                        ▼
             ┌──────────────────────────────────────────────────┐
             │  app/middlewares/accesoMiddleware                 │
             │  sesión · MFA pendiente · MFA obligatorio ·       │
             │  cambio de contraseña forzoso                     │
             └──────────────────────────┬───────────────────────┘
                                        ▼
        ┌────────────────────────────────────────────────────────┐
        │  app/controllers/*Controller  (estáticos)               │
        │  leen la entrada, devuelven                             │
        │  {code, status, title, message, data}                   │
        │  despachoController traduce ese sobre a 302 + mensaje   │
        │  para los envíos de formulario                          │
        └──────────────────────────┬─────────────────────────────┘
                                   ▼
     ┌──────────────────────────────────────────────────────────────┐
     │  app/models/*Model  (estáticos)                               │
     │  reglas de negocio, decisión de seguridad y SQL preparado     │
     │  permisoModel es el único punto de decisión de autorización   │
     │  mainModel es la base: conexión mysqli y consultas preparadas │
     └──────────────────────────┬───────────────────────────────────┘
                                ▼
                   ┌───────────────┐        ┌──────────────────────┐
                   │  MySQL        │        │  .env (clave maestra)│
                   │  (secretos    │        │  FUERA del webroot   │
                   │   cifrados)   │        └──────────────────────┘
                   └───────────────┘
```

## 5.2 Estructura de directorios

```
index.php                  controlador frontal: ensambla las páginas
autoload.php               autocarga por convención (sin Composer)
.htaccess                  reescritura de URL y protección del árbol

app/api/                   un endpoint JSON por módulo (+ _comun.php)
app/controllers/           un controlador por módulo, métodos estáticos
app/models/                un modelo por módulo, sobre mainModel
app/middlewares/           portero de acceso a las vistas
app/views/
    content/               una vista por pantalla: <nombre>-view.php
    inc/                   head, menú lateral, barra superior, scripts
    partials/              fragmentos reutilizables (avisos, paginación)
    css/  js/  img/        recursos, registrados en viewsModel
app/descargas.php          entrega de archivos generados (XLSX, CSV)

app/Core/                  infraestructura compartida: Config, Env, Logger,
                           View, Flash, Csrf, Validator y las excepciones
app/Support/               ayudantes de vista, migrador y escritor de XLSX
```

`app/Core` y `app/Support` no existen en la referencia. Se conservan porque
este sistema sí los necesita: la configuración por archivos, el registro
técnico, la validación declarativa, el token anti-CSRF y el escritor de
Excel no tienen equivalente en Porcify y escribirlos otra vez dentro de los
modelos los volvería imposibles de reutilizar entre módulos.

## 5.3 Separación de responsabilidades

| Capa | Responsabilidad | Lo que **no** hace |
|---|---|---|
| `index.php` | Resolver la dirección y montar la página | No consulta la base de datos |
| `app/api/*-api.php` | Sesión, CSRF, reparto por `accion` | No contiene lógica de negocio |
| `despachoController` | Traducir el resultado a redirección y mensaje | No duplica ninguna regla |
| `accesoMiddleware` | Sesión, segundo factor, contraseña caducada | No decide permisos concretos |
| `controllers` | Leer la entrada, formatear la salida | **No decide autorización** |
| `models` | Reglas de negocio, autorización y SQL preparado | No genera HTML |
| `views` | Presentación, siempre escapada | No consulta la base de datos |

La regla que sostiene el diseño: **la autorización se decide en el modelo**,
a través de `permisoModel`. El menú sólo oculta lo que no se puede usar y el
portero es una primera barrera; ninguna capa confía en la anterior.

### Las tres entradas comparten todo

Una misma operación —crear una credencial, por ejemplo— se alcanza por
`POST /credenciales` (formulario) o por
`app/api/credenciales-api.php?accion=agregar` (fetch). Las dos terminan en
`credencialController::agregarController()`. No hay dos caminos que puedan
divergir: sólo cambia cómo se devuelve el resultado.

## 5.4 Principios aplicados

- **Un módulo, un archivo por capa.** `credenciales-api.php` →
  `credencialController` → `credencialModel`. Encontrar dónde vive algo no
  requiere seguir inyecciones de dependencias.
- **DRY.** Un solo `Validator`, un solo `auditoriaModel`, un solo
  `cifradoModel`, un solo lugar donde se comprueba el token anti-CSRF por
  frente (`_comun.php` para los endpoints, `despachoController` para los
  formularios).
- **Seguridad por diseño.** No hay ruta que devuelva un secreto por
  accidente: hay que pedirlo explícitamente a `secretos-api.php`.
- **Mínimo privilegio.** Denegación individual sobre concesión; alcance de
  datos en SQL; roles con nivel.
- **Sin dependencias de terceros.** Ni Composer en tiempo de ejecución.
  Menos superficie de ataque y ninguna cadena de suministro que vigilar. El
  escritor de XLSX y el TOTP son propios y auditables.

## 5.5 Modelo de datos (29 tablas)

### Organización y catálogos
`companies` · `locations` · `departments` · `categories`

### Identidad y control de acceso
`users` · `roles` · `permissions` · `role_permissions` · `user_roles` ·
`user_permissions` (excepciones individuales: `allow` / `deny`)

### Criptografía
`encryption_keys` — llavero versionado; cada versión guarda su sal HKDF.

### Inventario
`systems` — el recurso (aplicación, servidor, buzón, red).
`credentials` — la cuenta concreta. **Sin un solo campo de secreto.**

### Secretos
`credential_secrets` — un registro por versión de cada secreto:
criptograma, nonce, etiqueta GCM, DEK envuelta, versión de clave, longitud,
robustez, huella HMAC, motivo del cambio, autor y marca `is_current`.
Las versiones anteriores **son** el historial cifrado.

### Asignaciones
`credential_assignments` — qué usuario accede a qué credencial, con permisos
finos (ver / copiar / recuperación), vigencia, quién otorgó y quién revocó.

### Trazabilidad
`audit_logs` — todo evento relevante.
`secret_access_log` — registro dedicado de acceso a secretos.
`credential_history` — historial funcional (nunca contiene secretos).
`login_attempts` · `security_events` · `export_reports` · `export_report_items`

### Operación
`sessions` · `rate_limits` · `notifications` · `password_resets` ·
`mfa_secrets` · `mfa_backup_codes` · `settings` · `mail_config` · `mail_templates`

### Relación central

```
companies ──< locations ──< departments
     │                          │
     └──────────< systems >─────┘
                    │
                    ├──< credentials ──< credential_secrets   (versiones cifradas)
                    │         │
                    │         ├──< credential_history         (metadatos del cambio)
                    │         ├──< secret_access_log          (quién lo vio/copió/exportó)
                    │         └──< credential_assignments >── users
                    │
users ──< user_roles >── roles ──< role_permissions >── permissions
  │
  └──< user_permissions >── permissions        (excepciones individuales)
```

## 5.6 Decisiones de diseño relevantes

### Por qué los secretos viven en su propia tabla
Permite versionarlos sin duplicar metadatos, hace **imposible** que un `SELECT *`
sobre `credentials` devuelva una contraseña, y aísla la única tabla que necesita
controles reforzados.

### Por qué una DEK por secreto y no una clave global
Limita el radio de un compromiso y elimina el riesgo de reutilización de nonce.
El coste es un cifrado adicional de 32 bytes por operación: irrelevante.

### Por qué sesiones propias
Para poder listarlas y **cerrarlas remotamente**, aplicar caducidad doble y
marcar el step-up por sesión. Las sesiones nativas de PHP no lo permiten.

### Por qué baja lógica y nunca borrado
Un sistema de credenciales debe poder responder *"¿qué tenía asignado este
empleado el 3 de marzo?"*. Borrar destruye esa capacidad. Todo se desactiva; el
histórico permanece.

### Por qué se guarda una huella HMAC del secreto
Permite detectar contraseñas repetidas entre credenciales y rechazar la
repetición al rotar, **sin descifrar nada** y sin poder invertir el valor.

### Por qué un escritor XLSX propio
El archivo puede contener contraseñas reales. Se prefiere una superficie de
código pequeña y auditable (≈300 líneas) a arrastrar un árbol de dependencias
de terceros dentro del componente que manipula secretos en claro.

### Por qué se conserva el envío de formulario clásico

La referencia resuelve todo por `fetch`. Aquí los formularios siguen
enviándose como formularios y `despachoController` los atiende. El motivo es
concreto: un formulario que sólo funciona con JavaScript deja de enviarse si
el guion no carga —red intermitente, extensión que lo bloquea, versión
antigua en caché— y en un sistema de credenciales eso significa que un
administrador no puede revocar un acceso cuando lo necesita. El coste es un
archivo más; el beneficio es que la aplicación sigue siendo utilizable sin
JavaScript.

## 5.7 Ciclo de una petición

### Una página (GET)

1. `index.php` incluye `session_start.php`: entorno, configuración,
   contexto de la petición, resolución de la sesión y cabeceras de
   seguridad con el nonce de CSP.
2. `viewsController::obtenerVistasControlador()` traduce la dirección en
   una vista y sus hojas y guiones.
3. `accesoMiddleware::revisar()` decide si se puede continuar: sesión
   válida, segundo factor resuelto, contraseña vigente.
4. La vista se dibuja **en un búfer**, antes de emitir nada: así puede
   fijar su título, redirigir o fallar sin haber escrito media página.
5. La vista pide sus datos a su controlador; el controlador llama al
   modelo; el modelo comprueba el permiso con `permisoModel`, aplica el
   alcance de datos y audita lo que corresponda.
6. `index.php` emite `head` + menú lateral + barra superior + el contenido
   + los guiones. Si la vista lanzó una excepción, dibuja el error con su
   código real.

### Una operación por fetch (JSON)

1. `app/api/<modulo>-api.php` incluye `session_start.php` y `_comun.php`.
2. `exigirSesion()` y, en toda escritura, `exigirCsrf()`.
3. Reparto por `$_GET['accion']` al método del controlador.
4. El controlador devuelve `{code, status, title, message, data}` y el
   endpoint lo emite tal cual con ese código HTTP.

### Un envío de formulario (POST)

1. `index.php` deriva a `despachoController::despachar()`.
2. Limitador de frecuencia, token anti-CSRF y comprobación de origen.
3. Reparto por la ruta al mismo método del controlador que usaría el
   endpoint JSON.
4. El resultado se traduce a redirección con mensaje, o a una página de
   error con su código (403, 404, 405, 423, 429).

En los tres casos, cualquier excepción se traduce a una respuesta segura:
nunca se filtran rutas del servidor, consultas SQL ni trazas.
