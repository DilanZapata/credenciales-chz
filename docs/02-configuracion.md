# 2. Configuración

Hay dos niveles de configuración, deliberadamente separados:

| Nivel | Dónde | Quién lo cambia | Requiere |
|---|---|---|---|
| **Infraestructura** | `.env` y `config/*.php` | Administrador de sistemas | Acceso al servidor |
| **Políticas** | Tabla `settings`, pantalla *Configuración* | Superadministrador | Permiso `settings.manage` + reautenticación |

---

## 2.1 Variables de entorno (`.env`)

### Aplicación

| Variable | Valor por defecto | Descripción |
|---|---|---|
| `APP_NAME` | Sistema Corporativo… | Título visible |
| `APP_SHORT_NAME` | Credenciales | Nombre corto (pestaña, emisor TOTP) |
| `APP_ORGANIZATION` | Mi Empresa | Nombre de la organización |
| `APP_ENV` | production | Entorno |
| `APP_DEBUG` | false | **Nunca `true` en producción**: expone mensajes internos |
| `DATABASE_URL` | *(vacío)* | Conexión completa en una línea: `mysql://usuario:clave@servidor:3306/base`. Alternativa a las cinco `DB_*`; si define ambas, mandan las sueltas |
| `APP_URL` | http://localhost/credencial | URL base; se usa para validar el `Origin` |
| `APP_BASE_PATH` | /credencial | Prefijo de las rutas; vacío si hay VirtualHost propio |
| `APP_TIMEZONE` | America/Bogota | Zona horaria de fechas y auditoría |
| `APP_TRUST_PROXY` | false | Sólo `true` detrás de un proxy inverso de confianza |

> `APP_TRUST_PROXY=true` hace que el sistema crea en la cabecera
> `X-Forwarded-For`. Si se activa sin un proxy que la sobrescriba, **cualquiera
> puede falsificar su IP** en la auditoría y evadir el limitador por IP.

### Criptografía

| Variable | Descripción |
|---|---|
| `APP_MASTER_KEY` | 32 bytes en base64. Raíz de todo el cifrado de secretos. |
| `APP_PEPPER` | Pimienta del lado del servidor para el hash de las contraseñas de acceso. |

Ambas se generan con `php bin/console.php key:generate`.

La pimienta implica que un volcado de la tabla `users` **no basta** para atacar
los hashes por fuerza bruta: hace falta además el `.env`.

### Base de datos

| Variable | Descripción |
|---|---|
| `DB_HOST`, `DB_PORT` | Servidor MySQL/MariaDB |
| `DB_DATABASE` | Nombre de la base |
| `DB_USERNAME`, `DB_PASSWORD` | Usuario dedicado (no `root` en producción) |
| `DB_SOCKET` | Socket Unix; en XAMPP macOS suele ser necesario |

Permisos mínimos recomendados para el usuario de base de datos:

```sql
CREATE USER 'credenciales'@'localhost' IDENTIFIED BY '<clave larga y aleatoria>';
GRANT SELECT, INSERT, UPDATE, DELETE ON credenciales_corp.* TO 'credenciales'@'localhost';
-- Sólo durante la instalación o las migraciones:
-- GRANT CREATE, ALTER, INDEX, REFERENCES ON credenciales_corp.* TO 'credenciales'@'localhost';
```

### Sesiones

| Variable | Por defecto | Descripción |
|---|---|---|
| `SESSION_SECURE` | false | **`true` obligatorio con HTTPS**: marca la cookie como `Secure` |
| `SESSION_SAMESITE` | Strict | `Strict` protege frente a CSRF por navegación cruzada |

### CORS

| Variable | Por defecto | Descripción |
|---|---|---|
| `CORS_ENABLED` | false | La API se consume desde el mismo origen; manténgalo desactivado |
| `CORS_ORIGINS` | (vacío) | Lista blanca separada por comas si necesita habilitarlo |

Nunca se responde con comodín `*` junto a credenciales: sólo se aceptan
orígenes de la lista blanca exacta.

---

## 2.2 Políticas administrables (pantalla *Configuración*)

### Grupo `sesiones`

| Clave | Por defecto | Efecto |
|---|---|---|
| `security.session_idle_minutes` | 30 | Cierre por inactividad |
| `security.session_absolute_hours` | 8 | Vida máxima de la sesión, aunque haya actividad |
| `security.reauth_minutes` | 10 | Cuánto dura una reautenticación (step-up) |

### Grupo `acceso`

| Clave | Por defecto | Efecto |
|---|---|---|
| `security.max_login_attempts` | 5 | Intentos fallidos antes de bloquear la cuenta |
| `security.lockout_minutes` | 15 | Duración del bloqueo temporal |
| `security.mfa_required_admins` | 1 | Exige MFA a los roles marcados con `requires_mfa` |

### Grupo `politica`

| Clave | Por defecto | Efecto |
|---|---|---|
| `security.password_min_length` | 12 | Longitud mínima de la contraseña **de acceso al sistema** |
| `security.password_expiry_days` | 90 | Vigencia sugerida |
| `security.reauth_for_secret` | 1 | Reautenticar antes de revelar/copiar un secreto |
| `security.reauth_for_export` | 1 | Reautenticar antes de exportar con contraseñas |
| `credentials.default_rotation_days` | 90 | Periodo de rotación por defecto de las credenciales |

### Grupo `alertas`

| Clave | Por defecto | Efecto |
|---|---|---|
| `alerts.expiry_warning_days` | 15 | Antelación del aviso de vencimiento |
| `alerts.failed_login_threshold` | 10 | Intentos fallidos/hora que disparan alerta |

### Grupo `reportes`

| Clave | Por defecto | Efecto |
|---|---|---|
| `exports.retention_minutes` | 15 | Vida del archivo generado antes de purgarse |
| `exports.max_records` | 5000 | Tope de registros por exportación |

Todo cambio en esta pantalla exige reautenticación y queda registrado en la
auditoría como evento de severidad **crítica**, con el detalle exacto de qué
claves cambiaron.

---

## 2.3 Correo saliente

El servidor de correo no se configura en la pantalla anterior, sino en
*Administración → Correo* (`/admin/correo`). Vive en su propia tabla
(`mail_config`) por dos motivos: la contraseña del buzón es un secreto y se
guarda cifrada con el mismo sobre AES-256-GCM que los secretos de las
credenciales, y `settings` recorta cualquier texto a 500 caracteres.

| Campo | Por defecto | Efecto |
|---|---|---|
| Habilitar el envío | apagado | Sin esto no sale ningún mensaje |
| Servidor / Puerto | — / 587 | Host SMTP |
| Cifrado | `tls` | `tls` = STARTTLS (587), `ssl` = TLS implícito (465), `none` = sin cifrar |
| Usuario / Contraseña | — | Vacíos si el relay no exige autenticación |
| Remitente / Nombre visible | no-reply@empresa.local | Cabecera `From` |
| Responder a | — | Cabecera `Reply-To` |
| Espera máxima | 10 s | Tiempo antes de dar la conexión por perdida |

> El correo **nunca transporta contraseñas**. `correoModel` incluye un
> cortafuegos que descarta cualquier mensaje cuyo cuerpo tenga aspecto de
> contener un secreto, y la recuperación de cuenta envía siempre un enlace de
> un solo uso, jamás la contraseña actual.

#### Gmail

Google no acepta la contraseña normal de la cuenta en SMTP. Hay que activar la
verificación en dos pasos y generar una **contraseña de aplicación** de 16
caracteres; esa es la que se guarda aquí.

| | |
|---|---|
| Servidor | `smtp.gmail.com` |
| Puerto | `587` |
| Cifrado | STARTTLS |
| Usuario | la dirección completa de la cuenta |
| Remitente | la propia cuenta o un alias verificado |

Una cuenta gratuita admite del orden de 500 mensajes al día.

#### Comprobar que funciona

La pantalla tiene un botón **Enviar prueba** que muestra literalmente lo que
respondió el servidor si el envío falla. Desde la consola, cuando todavía no se
puede entrar al panel:

```bash
php bin/console.php mail:test alguien@dominio.com
```

Los fallos de envío quedan en el registro técnico (`storage/logs/`) con el
motivo. Si el correo de recuperación no llega y aquí no hay nada, es que la
solicitud no llegó a generar el mensaje: el identificador no correspondía a una
cuenta **activa**.

> Mientras el envío esté apagado, quien pida recuperar su contraseña verá el
> mensaje de confirmación de todos modos —la respuesta es idéntica exista o no
> la cuenta, para no filtrar cuáles existen— pero no recibirá nada. La salida de
> emergencia es `php bin/console.php user:reset <usuario>`.

---

## 2.4 Cabeceras de seguridad (`config/security.php`)

Aplicadas a toda respuesta HTML:

```
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-…';
    style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none';
    base-uri 'self'; form-action 'self'; frame-ancestors 'none'
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: no-referrer
Cross-Origin-Opener-Policy: same-origin
Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=()
Cache-Control: no-store, no-cache, must-revalidate, private
Strict-Transport-Security: max-age=31536000; includeSubDomains   (sólo bajo HTTPS)
```

`script-src` usa **nonce por petición y no admite `unsafe-inline`**: aunque se
lograra inyectar HTML, el navegador no ejecutaría el script. Para las hojas de
estilo sí se admite el atributo `style` en línea, necesario para valores
calculados en servidor (barras de progreso, colores de categoría); la inyección
de CSS tiene un impacto mucho menor y no permite ejecutar código.

`Cache-Control: no-store` evita que una ficha con datos sensibles quede en la
caché del navegador tras cerrar sesión.

---

## Política de contraseñas de acceso

Todas las reglas que se aplican a la contraseña **de los usuarios del
sistema** se configuran desde *Administración → Configuración*, grupo
**politica**. No hay que tocar código ni redesplegar.

| Parámetro | Por defecto | Qué hace |
|---|---|---|
| `security.password_min_length` | 12 | Longitud mínima |
| `security.password_max_length` | 200 | Longitud máxima |
| `security.password_require_upper` | sí | Exigir una mayúscula |
| `security.password_require_lower` | sí | Exigir una minúscula |
| `security.password_require_digit` | sí | Exigir un dígito |
| `security.password_require_symbol` | sí | Exigir un carácter especial |
| `security.password_block_personal` | sí | Rechazar contraseñas que contengan nombre, apellido, usuario, cédula o correo |
| `security.password_block_common` | sí | Rechazar `password`, `12345678`, `qwerty` y similares |
| `security.password_expiry_days` | 90 | Caducidad; 0 la desactiva |

Los cambios surten efecto de inmediato: la siguiente pantalla de cambio de
contraseña anuncia las reglas nuevas, el navegador exige la longitud nueva
y el servidor valida contra ellas. El aviso que ve el usuario se construye
de la configuración vigente, de modo que no puede quedar desfasado — un
aviso que no coincide con lo que el servidor exige es peor que ninguno,
porque la persona cumple lo que lee y aun así la rechazan.

> Esto **no** afecta a las contraseñas guardadas en el inventario, que son
> las de los sistemas de la empresa. Aquellas no las valida este sistema:
> las impone cada proveedor. El generador criptográfico de la ficha sí
> permite elegir longitud y conjuntos de caracteres en cada uso.
