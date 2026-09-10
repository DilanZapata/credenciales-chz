# 9. Migración arquitectónica: adoptar la arquitectura de Porcify Manager

**Sistema a modificar:** `htdocs/credencial` (Sistema de Gestión de Credenciales)
**Software de referencia:** `htdocs/www/porcify-manager` — **solo lectura, no se toca**

Documento de análisis previo. **No contiene cambios de código.**

---

## 1. Arquitectura actual — sistema de credenciales

### Cifras

| | |
|---|---|
| PHP | 116 archivos · 17.832 líneas |
| Núcleo (`app/Core`) | 16 clases |
| Controladores | 16 (Web 12 / Api 4) |
| Servicios | 16 |
| Repositorios | 9 |
| Middleware | 8 |
| Vistas | 36 |
| Rutas declaradas | 95 |
| Tablas | 29 |
| Pruebas | 209 |
| JS / CSS | 1 archivo cada uno (514 / 350 líneas) |

### Forma

```
public/index.php  (único punto de entrada; el resto del código está fuera del webroot)
        ▼
   Kernel — resuelve ruta y arma la cadena de middleware
        ▼
   security → cors → throttle → auth → csrf → perm:<código>
        ▼
   Controlador (Web o Api) — traduce petición↔respuesta, valida formato
        ▼
   Servicio — reglas de negocio Y **toda decisión de autorización**
        ▼
   Repositorio — SQL con sentencias preparadas
        ▼
   MySQL
```

### Rasgos definitorios

- **Inyección de dependencias por constructor** y contenedor de servicios. Métodos de instancia, no estáticos.
- **Autorización doble**: el middleware de ruta comprueba el permiso y el servicio lo vuelve a comprobar. Ninguna capa confía en la anterior.
- **100 % sentencias preparadas**, sin emulación. Cero concatenación de entrada de usuario.
- **Configuración fuera del código**: `.env` con la clave maestra, ignorado por git.
- **Migraciones versionadas** con suma de verificación.
- **209 pruebas** que ejecutan peticiones HTTP reales a través del núcleo.
- Sesiones propias en base de datos: revocación remota, caducidad doble, marca de reautenticación.

---

## 2. Arquitectura del software de referencia — Porcify Manager

### Cifras

| | |
|---|---|
| PHP | 178 archivos · 34.819 líneas |
| Controladores | 31 |
| Modelos | 37 |
| Endpoints API | 33 archivos independientes |
| Vistas | 44 |
| JS / CSS | 40 / 31 archivos (8.959 líneas JS) |
| Tablas | 43 |
| Pruebas | 0 |

### Forma

```
  index.php ──────────► vistas server-rendered
     │                  (resuelve la vista vía viewsModel, hace include)
     │
  fetch ──────────────► app/api/*-api.php   (33 entrypoints independientes)
                              │  auth + $_GET/$_POST + switch($_GET['accion'])
                              ▼
                        app/controllers/*Controller.php   (capa fina)
                              ▼
                        app/models/*Model.php   ← lógica de negocio + SQL juntos
                              ▼
                        mainModel::ejecutarConsulta()
                              ▼
                            MySQL
```

### Rasgos definitorios

- **Dos puntos de entrada**: `index.php` para vistas, un `*-api.php` por módulo para JSON.
- **Todo estático**: `loteModel::agregarLoteModel()`. Sin contenedor ni inyección.
- **Herencia como atajo**: `class loteController extends mainModel` — el controlador hereda el acceso a base de datos.
- **La lógica de negocio vive en los modelos**, mezclada con el SQL. `loteModel` 1.728 líneas, `costoModel` 1.420.
- **`mainModel`** como clase base universal: `conectar()`, `ejecutarConsulta()`, `limpiarDatos()`, `obtenerUsuarioDesdeToken()`, `validarPermisos()`.
- **Enrutado por arrays**: `viewsModel` mapea nombre de vista → archivo, con resolución **por rol** (`{vista}-{rol}-view.php`).
- **Registro manual de assets**: arrays `$cssPorVista`, `$jsPorVista`.
- **Respuesta JSON uniforme**: `{code, status, title, message, data}`.
- **Autenticación híbrida**: `$_SESSION` para vistas + JWT para API + columna `usuario.token` para revocación.
- **Permisos**: `validarPermisos($accion, $modulo)` sobre el payload del JWT.
- Frontend SSR + `fetch` vanilla + SweetAlert2. Sin bundler; librerías por CDN.

### Rasgos que son defectos, no arquitectura

Distinción importante para lo que sigue. Estos puntos están documentados por el propio proyecto en `.claude/skills/porcify-backend/SKILL.md`:

| Rasgo | Naturaleza |
|---|---|
| SQL por concatenación (196 consultas, 0 preparadas) | **Defecto conocido** |
| Credenciales de producción escritas en `mainModel.php` | **Defecto conocido** |
| Secreto JWT versionado en `config/jwt.php` | **Defecto conocido** |
| Contraseñas con MD5 sin sal | **Defecto conocido** |
| Volcados `.sql` completos en el repositorio | **Defecto conocido** |
| Tres mecanismos de auth no unificados, vencimientos divergentes | **Deuda reconocida** |
| Permisos granulares en módulos viejos, ausentes en los nuevos | **Deuda reconocida** |

---

## 3. Diferencias

| Dimensión | credencial | Porcify | Distancia |
|---|---|---|---|
| Puntos de entrada | 1 (`public/index.php`) | 2 (`index.php` + 33 api) | Alta |
| Enrutado | `Router` con patrones y middleware | arrays en `viewsModel` + `switch($_GET['accion'])` | Alta |
| Invocación | métodos de instancia + DI | métodos estáticos | Alta |
| Capa de negocio | `Services/` (16) | dentro de `models/` | Media |
| Acceso a datos | `Repositories/` (9), preparadas | dentro de `models/`, concatenación | **Crítica** |
| Autorización | middleware por ruta + servicio | `if (!validarPermisos(...))` en cada api | Alta |
| Configuración | `.env` fuera de git | `const` en `config/*.php` versionado | **Crítica** |
| Sesiones | tabla propia, revocables | `$_SESSION` + JWT + columna token | Alta |
| CSRF | token sincronizador + Origin + SameSite | no existe | **Crítica** |
| Esquema | migraciones versionadas | volcados `.sql` sueltos | Media |
| Pruebas | 209 | 0 | **Crítica** |
| Vistas | layouts + parciales, escape obligatorio | includes desde `index.php`, variables globales | Media |
| Frontend | 1 JS, 1 CSS | 40 JS, 31 CSS por módulo | Baja (mecánica) |
| Respuesta API | `Response::json()` | `echo json_encode([...])` | Baja |

---

## 4. Arquitectura objetivo propuesta

Aquí hay una decisión que no puedo tomar por usted, porque cambia radicalmente el resultado.

### Variante A — Adopción literal

Replicar Porcify tal cual: métodos estáticos, `mainModel` con `ejecutarConsulta($sql)` por concatenación, `config/*.php` versionado, `$_SESSION` + JWT, sin CSRF, sin pruebas.

**Consecuencia:** el sistema deja de poder cumplir su función. Un gestor de contraseñas cuyo acceso a datos concatena entrada de usuario y cuya clave maestra vive en git no protege nada. Se perderían las 209 pruebas, el step-up, la revocación remota de sesiones y la protección CSRF.

No la recomiendo, pero es ejecutable si usted lo indica.

### Variante B — Adopción estructural *(recomendada)*

Adoptar **la forma, la organización y las convenciones** de Porcify, conservando el núcleo que hace seguro al sistema.

```
credencial/
├── index.php                     ← front controller de vistas (como Porcify)
├── autoload.php                  ← spl_autoload_register manual (como Porcify)
├── .htaccess                     ← reescritura /ruta → index.php?views=ruta
├── config/
│   ├── app.php                   ← const APP_SESSION_NAME, zona horaria…
│   ├── database.local.php        ← override local, ignorado por git
│   ├── cors.php
│   └── jwt.php
├── app/
│   ├── api/                      ← un archivo por módulo (como Porcify)
│   │   ├── credenciales-api.php
│   │   ├── secretos-api.php
│   │   ├── usuarios-api.php
│   │   ├── sistemas-api.php
│   │   ├── asignaciones-api.php
│   │   ├── auditoria-api.php
│   │   ├── reportes-api.php
│   │   ├── sesiones-api.php
│   │   ├── catalogos-api.php
│   │   └── login-api.php
│   ├── controllers/*Controller.php
│   ├── models/
│   │   ├── mainModel.php         ← base común (como Porcify)
│   │   ├── cifradoModel.php      ← el actual CryptoService
│   │   ├── auditoriaModel.php
│   │   └── {credencial,usuario,sistema,reporte…}Model.php
│   ├── Middleware/AuthMiddleware.php
│   └── views/{content,inc,css,js,img}
└── DB/                           ← esquema y migraciones
```

**Se adopta de Porcify:**

- La estructura de carpetas y los sufijos de nombre (`*Controller.php`, `*Model.php`, `*-api.php`, `*-view.php`).
- Los dos puntos de entrada: `index.php` para vistas, `app/api/*-api.php` para JSON.
- El despacho por `$_GET['accion']` dentro de cada endpoint.
- `mainModel` como clase base compartida.
- El formato de respuesta `{code, status, title, message, data}`.
- El registro de assets por vista en arrays (`$cssPorVista`, `$jsPorVista`).
- La organización del frontend: un JS y un CSS por módulo, SSR + `fetch` + SweetAlert2.
- El ensamblado de la página desde `index.php` con `inc/head.php`, `inc/nav-menu.php`, `inc/menu-lateral.php`, `inc/scripts.php`.

**Se conserva del sistema actual, adaptado a la forma nueva:**

| Qué | Cómo queda |
|---|---|
| Sentencias preparadas | `mainModel::ejecutarConsulta($sql, $params = [])` — misma firma que Porcify, pero parametrizada |
| Cifrado de sobre | `app/models/cifradoModel.php` (era `CryptoService`) |
| Clave maestra fuera de git | `.env` se mantiene; `config/app.php` la lee, no la contiene |
| Autorización | `mainModel::validarPermisos($accion, $modulo)` — convención de Porcify — **más** la comprobación en el modelo, que no se elimina |
| Step-up antes de revelar secretos | se conserva, invocado desde `secretos-api.php` |
| Sesiones revocables | se conserva la tabla `sessions`; `mainModel` la consulta |
| CSRF | se conserva para las vistas SSR |
| Auditoría de cada acceso a secreto | se conserva en `auditoriaModel` |
| Migraciones versionadas | se mueven a `DB/`, conservando el ejecutor |
| 209 pruebas | se adaptan (ver §10) |

### Variante C — Adopción mínima

Solo convenciones de nombres y disposición de carpetas, sin tocar el flujo de ejecución. Bajo riesgo, beneficio limitado: no resuelve el problema de fondo si lo que busca es un único modelo mental para mantener ambos proyectos.

---

## 5. Mapa de migración

| Origen (credencial) | Destino (forma Porcify) | Tipo |
|---|---|---|
| `public/index.php` | `index.php` en la raíz | Migrar |
| `app/Core/Kernel.php` + `Router.php` | `index.php` + `.htaccess` + `viewsModel.php` | Reemplazar |
| `app/Core/Request.php` / `Response.php` | `$_GET`/`$_POST` + `echo json_encode()` | Reemplazar |
| `app/Core/Database.php` | `app/models/mainModel.php` | **Adaptar — conservando preparadas** |
| `app/Core/View.php` + `Views/layouts/` | `index.php` + `app/views/inc/*` | Migrar |
| `app/Core/Validator.php` | `mainModel::limpiarDatos()` / `verificarDatos()` | Adaptar |
| `app/Core/Container.php` | — (desaparece: estáticos) | Eliminar |
| `app/Core/{Csrf,Flash,Logger,Env,Config}.php` | `mainModel` + `config/*.php` | Adaptar |
| `app/Http/Controllers/Api/*` (4) | `app/api/*-api.php` (≈10) | Migrar |
| `app/Http/Controllers/Web/*` (12) | `app/controllers/*Controller.php` | Migrar |
| `app/Http/Middleware/*` (8) | `AuthMiddleware` + comprobaciones en cada api | **Migrar — riesgo alto** |
| `app/Services/*` (16) | `app/models/*Model.php` | Fusionar |
| `app/Repositories/*` (9) | `app/models/*Model.php` | Fusionar |
| `app/Views/*` (36) | `app/views/content/*-view.php` | Migrar |
| `public/assets/js/app.js` | `app/views/js/{modulo}.js` | Dividir |
| `public/assets/css/app.css` | `app/views/css/{modulo}.css` | Dividir |
| `database/migrations/` | `DB/` | Mover |
| `tests/run.php` | `tests/` adaptado | **Adaptar — ver §10** |

Servicios que **no tienen equivalente** en Porcify y deben conservarse como modelos propios: `CryptoService`, `SessionService`, `RateLimiter`, `AuthorizationService`, `ExportService`, `AlertService`, `TotpService`, `PasswordGeneratorService`.

---

## 6. Riesgos

| # | Riesgo | Gravedad | Mitigación |
|---|---|---|---|
| 1 | **Pérdida de la autorización por ruta.** Hoy 95 rutas declaran su permiso y el servicio lo revalida. Al pasar a comprobaciones manuales en ~10 archivos api, basta olvidar un `if` para dejar un endpoint abierto. Es exactamente lo que ocurrió en Porcify entre módulos viejos y nuevos. | **Crítica** | Conservar la comprobación dentro del modelo. Prueba automática que recorra todos los endpoints sin sesión y exija 401/403. |
| 2 | **Las 209 pruebas dejan de funcionar.** Están construidas sobre el Kernel y el contenedor. Con métodos estáticos no hay forma de inyectar dependencias. | **Crítica** | Reescribir el cliente de pruebas para invocar los `*-api.php` por HTTP real antes de migrar nada. §10. |
| 3 | **La clave maestra acaba en git** si se adopta literalmente `config/*.php` con `const`. | **Crítica** | No negociable: `.env` se mantiene. |
| 4 | **Pérdida de la protección CSRF** en las vistas. Porcify no la tiene. | **Alta** | Conservar el middleware como comprobación en `inc/session_start.php`. |
| 5 | **Pérdida de la revocación remota de sesiones y de la caducidad doble.** | **Alta** | Conservar la tabla `sessions` y su lógica dentro de `mainModel`. |
| 6 | **Regresión silenciosa en el cifrado.** Si `ejecutarConsulta` pierde el manejo de binarios (`PARAM_LOB`), los criptogramas se corrompen al guardarse y los secretos se vuelven ilegibles. | **Crítica** | Prueba de ida y vuelta de cifrado antes y después de cada paso. |
| 7 | Fusionar servicio + repositorio en un único modelo produce archivos de 1.000+ líneas, como en Porcify. | Media | Aceptado: es la arquitectura solicitada. |
| 8 | Dividir 1 JS en ~12 y 1 CSS en ~12 puede romper estilos y comportamientos. | Media | Migrar vista por vista, comprobando en navegador. |
| 9 | Perder el limitador de frecuencia al desaparecer el middleware. | Alta | Invocarlo explícitamente en login y en revelado de secretos. |
| 10 | El sistema está **desplegado en producción** (`manuel.chiquique.com`). Una migración a medias deja el servicio caído. | Alta | Trabajar en rama aparte; no desplegar hasta terminar y probar. |

---

## 7. Dependencias

**Orden forzoso** (no se puede alterar sin romper algo):

```
mainModel (base: conexión + consulta preparada + auth + permisos)
    └── es requisito de TODOS los modelos
            └── que son requisito de los controladores
                    └── que son requisito de los endpoints api
                            └── que son requisito del JS de cada vista

index.php + .htaccess + viewsModel
    └── son requisito de todas las vistas
```

**Dependencias transversales que atraviesan todos los módulos:**

- `cifradoModel` — lo usan credenciales, secretos, MFA y exportación.
- `auditoriaModel` — lo usan absolutamente todos los módulos.
- `AuthorizationService` → `mainModel::validarPermisos` — lo usan los 10 endpoints.
- `sessionModel` — lo usa cada petición.

Estos cuatro deben migrarse **primero y con pruebas propias**, porque un fallo en ellos se manifiesta en todo el sistema a la vez.

---

## 8. Orden recomendado de migración

| Fase | Qué | Por qué en este punto |
|---|---|---|
| **0** | Rama `arquitectura-porcify`. Reescribir el cliente de pruebas para que llame por HTTP a URLs reales, no al Kernel. Verificar 209/209 antes de tocar nada. | Sin red de seguridad no se empieza |
| **1** | `autoload.php`, `config/*.php`, `.htaccess`, `index.php` vacío que aún delegue en el Kernel | Andamiaje; el sistema sigue funcionando igual |
| **2** | `mainModel` con `ejecutarConsulta($sql, $params)` preparada + `conectar()` + `limpiarDatos()` | Base de todo lo demás |
| **3** | `cifradoModel`, `auditoriaModel`, `sessionModel`, permisos | Transversales; prueba de cifrado ida y vuelta obligatoria |
| **4** | Autenticación: `login-api.php`, `AuthMiddleware`, `inc/session_start.php` | Puerta de entrada |
| **5** | Módulo **catálogos** (categorías, empresas, sedes, departamentos) | El más simple: sirve de plantilla y valida el patrón |
| **6** | Módulo **sistemas** | Sin secretos: bajo riesgo |
| **7** | Módulo **usuarios**, roles y permisos | |
| **8** | Módulo **credenciales** — metadatos | |
| **9** | Módulo **secretos** — revelado, rotación, historial | El más delicado del sistema |
| **10** | Módulo **asignaciones** | |
| **11** | Módulo **auditoría** y sesiones | |
| **12** | Módulo **reportes** e importación | |
| **13** | Vistas: dividir CSS y JS por módulo, `inc/*`, `viewsModel` | |
| **14** | Retirar `app/Core`, `app/Http`, `app/Services`, `app/Repositories` | Solo cuando nada los use |
| **15** | Pruebas integrales, revisión de seguridad, actualizar `docs/` y las skills | |

Cada fase termina con las pruebas en verde. Si una fase no pasa, no se avanza.

---

## 9. Archivos y módulos que se modificarán

**Se eliminan** (16 archivos): todo `app/Core/`.

**Se reemplazan** (24): `app/Http/Controllers/*` (16) y `app/Http/Middleware/*` (8).

**Se fusionan** (25 → ~14): `app/Services/*` (16) + `app/Repositories/*` (9) → `app/models/*Model.php`.

**Se mueven y adaptan** (36): `app/Views/*` → `app/views/content/*`.

**Se dividen** (2 → ~24): `app/assets/js/app.js` y `app.css` → un archivo por módulo.

**Se crean** (~25): `index.php`, `autoload.php`, `config/{app,cors,jwt}.php`, `app/models/mainModel.php`, `app/api/*-api.php` (≈10), `app/views/inc/*` (5).

**No se tocan**: `database/migrations/*.sql` (solo cambian de ubicación), `Dockerfile`, `docker-compose.yml`, `docker/*`.

**Total estimado:** ~120 archivos afectados de 116 actuales. Es una reescritura de la fontanería conservando la lógica.

---

## 10. Estrategia para no perder ninguna funcionalidad

### Paso previo obligatorio: inventario funcional

Antes de tocar código, se levanta el inventario completo desde las 95 rutas declaradas y los 41 permisos. Cada funcionalidad queda clasificada como *conservar sin cambios* / *refactorizar* / *migrar de capa* / *adaptar*.

Esa lista existe ya de forma implícita: las 209 pruebas **son** el inventario funcional ejecutable. Cada una describe una funcionalidad concreta en español.

### La red de seguridad: convertir las pruebas antes de migrar

Hoy las pruebas construyen una `Request` y la pasan por el `Kernel` en proceso. Eso desaparece con la arquitectura nueva.

**Fase 0 las reescribe** para que hagan peticiones HTTP reales contra `http://localhost/credencial/...` con cURL, conservando **exactamente los mismos 209 asertos y los mismos textos**. Se verifica 209/209 con la arquitectura vieja.

A partir de ahí, el mismo conjunto de pruebas vale para las dos arquitecturas. Es lo que permite migrar módulo a módulo sabiendo, en cada paso, si algo se rompió.

### Verificación por fase

Al terminar cada fase:

1. `php tests/run.php` → 209/209 o no se avanza.
2. Comprobación manual de la vista afectada en el navegador.
3. Comprobación explícita de que ningún secreto aparece donde no debe (la prueba ya existe).

### Comprobaciones específicas por módulo migrado

| Módulo | Qué se verifica además |
|---|---|
| Secretos | Cifrar y descifrar ida y vuelta; el criptograma sigue siendo ilegible; el AAD sigue atando el registro a su credencial |
| Autorización | Un consultor no alcanza ningún endpoint administrativo; 404 ante credencial ajena |
| Auditoría | Cada acción crítica sigue generando su registro con usuario, cédula, IP y dispositivo |
| Exportación | El archivo sigue naciendo fuera del webroot y borrándose tras la descarga |
| Sesiones | El cierre remoto sigue surtiendo efecto en la petición siguiente |

### Regla de corte

Si al terminar una fase alguna prueba no pasa y la causa no se identifica en el momento, **se revierte esa fase** (`git revert`) y se analiza aparte. No se acumula deuda entre fases.

---

## Decisión pendiente

Antes de escribir la primera línea de código hace falta que confirme **la variante del punto 4**: A (literal), B (estructural, recomendada) o C (mínima).

De esa elección dependen el alcance, el riesgo y el tiempo.
