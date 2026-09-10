# Sistema Corporativo de Gestión de Credenciales y Accesos

Inventario **centralizado, cifrado y auditable** de todas las credenciales de la
empresa: sitios web, sistemas internos, aplicaciones, computadores, servidores,
correos, redes Wi-Fi, servicios en la nube, redes sociales, plataformas
financieras, licencias y cualquier otro recurso que exija autenticación.

El objetivo es que la organización deje de manejar contraseñas en hojas de
cálculo, documentos, correos o chats, y pase a un sistema donde **cada consulta
de una contraseña deja rastro de quién, cuándo y desde dónde**.

---

## Regla de oro del sistema

> **La información de una credencial y el secreto de una credencial son cosas
> distintas y tienen controles distintos.**

- `app/api/credenciales-api.php` **nunca** devuelve contraseñas.
- El texto en claro sólo se obtiene por un endpoint dedicado
  (`app/api/secretos-api.php?accion=revelar`) que exige, en este orden:
  permiso → asignación vigente → permiso fino de la asignación → límite de
  frecuencia → reautenticación reciente → registro de auditoría.

---

## Requisitos

PHP 8.1+ (`openssl`, `mysqli`, `mbstring`, `zip`) · **MySQL 5.7+ o MariaDB 10.3+** ·
Apache con `mod_rewrite` y `mod_headers`, o Nginx.

> La base de datos **debe ser MySQL o MariaDB**. El esquema y los modelos usan
> sintaxis propia de MySQL (`ON DUPLICATE KEY UPDATE`, `FULLTEXT`, `VARBINARY`,
> `GROUP_CONCAT`…). No es intercambiable por PostgreSQL sin reescribir la capa de datos.

## Arranque rápido

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/credencial
cp .env.example .env
php bin/console.php key:generate     # genera APP_MASTER_KEY y APP_PEPPER
php bin/console.php install          # base de datos, esquema y superadministrador
php bin/console.php doctor           # diagnóstico de la instalación
```

Abrir `http://localhost/credencial/` e iniciar sesión con el usuario y la
contraseña temporal que imprimió el instalador.

Para cargar datos de ejemplo en un entorno de pruebas:

```bash
php bin/console.php seed:demo
```

Para dejar la base limpia (borra datos operativos y conserva un superadministrador):

```bash
php bin/console.php db:clean
```

## Despliegue

```bash
docker compose up -d --build
```

Las migraciones se aplican solas al arrancar el contenedor. Guía completa para
Dokploy en [docs/08-despliegue.md](docs/08-despliegue.md).

Para ejecutar la batería de pruebas funcionales y de seguridad (usa una base de
datos independiente, `credenciales_corp_test`):

```bash
php tests/run.php
```

---

## Documentación

| Documento | Contenido |
|---|---|
| [docs/01-instalacion.md](docs/01-instalacion.md) | Requisitos, instalación paso a paso, despliegue en producción |
| [docs/02-configuracion.md](docs/02-configuracion.md) | Variables de entorno y parámetros administrables |
| [docs/03-administracion.md](docs/03-administracion.md) | Manual del administrador: usuarios, credenciales, reportes, bajas |
| [docs/04-seguridad.md](docs/04-seguridad.md) | Políticas de seguridad, modelo de amenazas y auditoría realizada |
| [docs/05-arquitectura.md](docs/05-arquitectura.md) | Arquitectura, modelo de datos y decisiones de diseño |
| [docs/06-api.md](docs/06-api.md) | Referencia de los endpoints JSON |
| [docs/07-pruebas.md](docs/07-pruebas.md) | Qué verifica cada prueba y cómo ampliarlas |
| [docs/08-despliegue.md](docs/08-despliegue.md) | **Despliegue en Dokploy con Docker, migraciones y respaldos** |
| [docs/09-migracion-arquitectura.md](docs/09-migracion-arquitectura.md) | Análisis y registro de la migración a la arquitectura de Porcify Manager |

---

## Roles incluidos

| Rol | Puede | No puede |
|---|---|---|
| **Superadministrador** | Todo, incluidas políticas de seguridad y matriz de roles | — |
| **Administrador** | Usuarios, sistemas, credenciales, asignaciones, rotaciones, reportes | Cambiar políticas de seguridad ni la matriz de roles |
| **Auditor** | Consultar y exportar auditoría, historial e inventario | **Ver o exportar contraseñas** |
| **Consultor** | Ver y copiar únicamente las credenciales que le fueron asignadas | Modificar nada, exportar, ver credenciales ajenas |

Los roles son sólo un envoltorio: el control real es una **matriz de permisos
granulares** (41 permisos) editable desde la interfaz, con excepciones
individuales por usuario donde una denegación siempre prevalece.

---

## Estructura del proyecto

```
credencial/
├── index.php          ← controlador frontal: dibuja las páginas
├── autoload.php       ← autocarga por convención de nombres (sin Composer)
├── .htaccess          ← reescritura de URL y protección del árbol interno
├── app/
│   ├── api/           ← un endpoint JSON por módulo (*-api.php)
│   ├── controllers/   ← un controlador por módulo, métodos estáticos
│   ├── models/        ← reglas de negocio, autorización y SQL, sobre mainModel
│   ├── middlewares/   ← portero de acceso a las vistas
│   ├── views/
│   │   ├── content/   ← una vista por pantalla (*-view.php)
│   │   ├── inc/       ← head, menú lateral, barra superior, guiones
│   │   ├── partials/  ← fragmentos reutilizables
│   │   └── css/ js/   ← recursos por módulo, registrados en viewsModel
│   ├── descargas.php  ← entrega de archivos generados (XLSX, plantilla CSV)
│   ├── Core/          ← infraestructura: configuración, entorno, registro,
│   │                     plantillas, avisos, CSRF, validación, excepciones
│   └── Support/       ← migrador versionado, escritor XLSX, ayudantes de vista
├── config/            ← configuración estática
├── database/          ← migraciones versionadas y datos de demostración
├── storage/           ← logs y archivos exportados (FUERA del webroot)
├── Dockerfile         ← imagen de producción PHP 8.2 + Apache
├── docker-compose.yml ← despliegue en Dokploy: app + MariaDB + cron
├── bin/console.php    ← consola de administración
├── tests/run.php      ← 336 pruebas funcionales y de seguridad por HTTP real
├── docs/              ← documentación
└── .env               ← clave maestra y credenciales de BD (nunca versionar)
```

La arquitectura sigue el patrón de **Porcify Manager**: dos puntos de entrada
(`index.php` para las páginas, `app/api/*-api.php` para las operaciones),
modelos estáticos con el SQL dentro y autocarga por convención. Los detalles
y el porqué de cada desviación están en
[docs/05-arquitectura.md](docs/05-arquitectura.md).

---

## Estado de las pruebas

```
336/336 pruebas superadas
```

Se ejecutan **por HTTP real** contra la aplicación servida por Apache, sobre
una base de datos independiente. Cubren autenticación, autorización, IDOR,
CSRF, XSS, inyección SQL, cifrado, step-up, rotación, historial, bajas de
personal, sesiones, exportación, auditoría, políticas de contraseña, alertas,
los once endpoints JSON y el dibujado de las 31 vistas.
