# 7. Pruebas

```bash
php tests/run.php
```

Crea y destruye una base de datos independiente (`credenciales_corp_test`), de
modo que **no toca los datos reales**.

## 7.1 Cómo están construidas

`tests/HttpClient.php` habla **por HTTP real** con la aplicación servida por
Apache: cURL, cookies, cabeceras y códigos de estado, exactamente igual que
una petición del navegador.

Esto es deliberado. Si las pruebas invocaran los modelos directamente,
verificarían la lógica pero **no** que el endpoint exija sesión, que el
formulario valide el token o que la vista compruebe el permiso. Al entrar
por la puerta, una acción a la que se le olvide una barrera falla la prueba.

Fue además lo que permitió migrar la arquitectura sin romper nada: la misma
batería validaba el sistema antes y después de cada fase, porque no conoce
la estructura interna, sólo las direcciones y las respuestas.

Apache debe estar encendido. Si la aplicación no está en
`http://localhost/credencial`, indíquelo con:

```bash
TEST_BASE_URL=http://mi-host/ruta php tests/run.php
```

Las peticiones se atienden contra `credenciales_corp_test` gracias a un
archivo marcador (`storage/testing.flag`) que `config/database.php` consulta
fuera de producción: el proceso de pruebas no puede pasarle variables de
entorno a Apache.

## 7.2 Qué cubre (336 comprobaciones)

| Grupo | Qué verifica |
|---|---|
| **1. Autenticación** | Redirección sin sesión, token CSRF en el formulario, contraseña incorrecta, ausencia de enumeración de cuentas, emisión y persistencia de la sesión (sólo el hash), auditoría del acceso, bloqueo por intentos fallidos, evento de seguridad, limitador por IP |
| **2. Autorización** | Consultor rechazado en 8 rutas administrativas, IDOR por URL y por API, imposibilidad de rotar/editar/crear, auditoría de cada denegación, auditor que ve todo pero **no** secretos ni exportaciones con contraseñas |
| **3. Información vs. secreto** | El listado y el detalle de la API nunca traen contraseñas; el HTML tampoco; step-up obligatorio (423); reauth incorrecta rechazada; revelado correcto tras step-up; registro con usuario e IP; permisos finos de la asignación (ver sí, copiar no); recuperación por endpoint propio y auditada |
| **4. Cifrado** | Criptograma ≠ texto, nonce de 96 bits, etiqueta GCM, DEK envuelta, descifrado correcto con AAD válido, **fallo con AAD de otra credencial**, **fallo con criptograma manipulado**, no determinismo, hash irreversible para contraseñas de acceso |
| **5. CSRF / XSS / SQLi** | Escritura sin token (419), token de otra sesión (419), auditoría del fallo, 4 cargas de inyección SQL, inyección en `ORDER BY`, XSS almacenado escapado, CSP con nonce, cabeceras de seguridad, `no-store` |
| **6. Ciclo de vida** | Rotación, conservación de la versión anterior, descifrado de la nueva, historial con motivo y autor, **el historial no guarda contraseñas**, rechazo de contraseña repetida, revelado de histórica con permiso y su denegación sin él, asignación y revocación con efecto inmediato |
| **7. Baja de empleados** | Estado inactivo, motivo conservado, revocación de todos los accesos, **historial preservado**, cierre de sesiones, sesión inutilizable al instante, imposibilidad de volver a entrar, auditoría con el detalle |
| **8. Sesiones** | Listado, cierre remoto con efecto inmediato, auditoría, endpoint de estado sin datos sensibles |
| **9. Reportes** | Inventario sin contraseñas, registro de la exportación, archivo fuera del webroot, nombre inocuo, XLSX válido, sin secretos en el inventario, **bloqueo sin confirmación**, generación con permiso + confirmación + step-up, registro de las credenciales incluidas, entrada por secreto en `secret_access_log`, evento de seguridad, descarga ajena rechazada y auditada, **borrado del archivo tras la descarga** |
| **10. Auditoría** | Filtros por acción, cédula, resultado y fecha; 11 acciones críticas presentes; columnas obligatorias; **ninguna de las 8 contraseñas reales aparece** en `audit_logs`, `login_attempts` ni en los archivos de log; trazabilidad de quién consultó y quién exportó |
| **11. Políticas** | Rechazo de contraseña corta, rechazo de contraseña con datos personales, generador (longitud, variedad, no repetición), **imposibilidad de auto-asignarse un rol superior**, **imposibilidad de conceder un permiso propio inexistente**, MFA obligatorio para administradores, TOTP válido/inválido/caducado, ausencia de open redirect, errores sin rutas ni SQL ni trazas, método no permitido, cierre de sesión efectivo |
| **12. Alertas** | Detección de vencidas, sin responsable, usuarios inactivos con accesos, exceso de fallos; despacho a notificaciones; **deduplicación diaria** |
| **13. Revisión de seguridad** | Permiso `export.reports` exigible por separado; auditor exportando inventario; **caducidad de la contraseña de acceso**; escritura con origen propio; el generador no deja rastro del valor producido |
| **14. Endpoints** | Los 7 módulos exigen sesión; cada acción devuelve el sobre completo; ficha por identificador; 404 en identificador inexistente; acción desconocida con 400; escritura sin token rechazada con `csrf`; escritura válida auditada; normalización de un color con carga XSS; el consultor rechazado en 5 endpoints administrativos y en la matriz de roles; imposibilidad de cerrar la sesión propia; generación, descarga única y 404 en reporte ajeno; plantilla CSV; el endpoint de credenciales no devuelve el secreto |
| **15. Ensamblado de vistas** | Las 27 páginas privadas se dibujan completas, con marco, hojas y guiones, y **sin un solo aviso de PHP ni rutas del servidor**; cada vista carga sólo sus recursos; las públicas se dibujan sin menú; dirección desconocida con 404; recurso inexistente con 404; el consultor rechazado por URL directa en 5 vistas; el panel lo lleva a "Mis accesos"; sin sesión, redirección al acceso |
| **16. Superficie de la arquitectura** | Doce archivos internos (modelos, controladores, vistas, arranque, autocargador, configuración, consola) **no descargables por HTTP**; los recursos sí; una dirección de sólo lectura rechaza el POST; el despacho rechaza `DELETE`; el fallo de CSRF con `X-Csrf-Failure` y sin filtrar rutas; `redirect` no permite salir del sitio; CSP con nonce distinto en cada petición, `no-store` y `X-Frame-Options` |
| **17. Los rechazos se explican** | Un envío rechazado vuelve **al formulario que lo produjo** (alta, edición, cambio de contraseña), con el motivo concreto a la vista; el aviso se consume y no reaparece; sobrevive a una redirección del portero; el campo declara `minlength` y la regla mostrada sigue a la política configurada |

## 7.3 Resultado

```
══════════════════════════════════════════════════════════════════════
  TODAS LAS PRUEBAS SUPERADAS  (336/336)
══════════════════════════════════════════════════════════════════════
```

## 7.4 Añadir pruebas

```php
$t->group('16. Mi nueva área');

$cliente = new HttpClient('198.51.100.20');
$cliente->login('admin.test', PASS_ADMIN);

$r = $cliente->get('/mi-ruta');
$t->status(200, $r, 'Descripción de lo que debe ocurrir');
$t->assert(!str_contains($r['body'], $secreto), 'La respuesta no filtra el secreto');
```

Métodos disponibles: `assert()`, `equals()`, `status()`.
Cliente: `get()`, `post()`, `getJson()`, `postJson()`, `request()`, `login()`.

## 7.5 Pruebas manuales recomendadas antes de producción

1. Iniciar sesión con HTTPS y confirmar que la cookie lleva `Secure`.
2. Configurar MFA con una aplicación autenticadora real y verificar un código
   de respaldo.
3. Generar un Excel con contraseñas y comprobar que el archivo desaparece de
   `storage/exports/` tras descargarlo.
4. Cerrar remotamente la sesión de otro usuario y comprobar el efecto inmediato.
5. Dar de baja a un usuario de prueba y comprobar que pierde el acceso al
   instante y que su historial permanece.
6. Ejecutar `php bin/console.php maintenance` y confirmar que purga lo vencido.
