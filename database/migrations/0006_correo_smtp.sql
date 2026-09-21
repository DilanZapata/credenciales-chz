-- =====================================================================
--  Servidor de correo saliente (SMTP)
-- =====================================================================
--  Hasta esta migracion el sistema "enviaba" correo con mail() de PHP y
--  solo tenia dos parametros: mail.enabled y mail.from. Eso nunca llego a
--  funcionar en un despliegue real: la imagen del contenedor no instala
--  ningun agente de transporte, de modo que mail() devolvia false y el
--  correo de recuperacion de contrasena no salia jamas. Tampoco quedaba
--  rastro del fallo.
--
--  Aqui la configuracion del servidor pasa a tabla propia por dos
--  razones concretas:
--
--    1. La contrasena del buzon es un secreto y no puede vivir en
--       `settings`, que guarda texto en claro y se dibuja entera en la
--       pantalla de configuracion. Se almacena con el mismo sobre
--       AES-256-GCM que los secretos de las credenciales.
--    2. `settings` recorta cualquier texto a 500 caracteres al guardar.
--
--  Las dos claves antiguas se trasladan y se eliminan de `settings` para
--  que no queden dos fuentes de verdad diciendo cosas distintas.
-- =====================================================================

CREATE TABLE IF NOT EXISTS mail_config (
  id             TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'fila unica',
  enabled        TINYINT(1)   NOT NULL DEFAULT 0,
  host           VARCHAR(255) NULL,
  port           SMALLINT UNSIGNED NOT NULL DEFAULT 587,
  encryption     ENUM('none','tls','ssl') NOT NULL DEFAULT 'tls'
                 COMMENT 'tls = STARTTLS (587), ssl = TLS implicito (465)',
  username       VARCHAR(255) NULL,

  -- Sobre cifrado de la contrasena del buzon. Mismas columnas que
  -- credential_secrets: el llavero y la rotacion ya saben tratarlas.
  algo           VARCHAR(30)     NULL DEFAULT 'aes-256-gcm',
  key_version    SMALLINT UNSIGNED NULL,
  ciphertext     VARBINARY(4096) NULL,
  nonce          VARBINARY(12)   NULL,
  tag            VARBINARY(16)   NULL,
  wrapped_dek    VARBINARY(64)   NULL,
  dek_nonce      VARBINARY(12)   NULL,
  dek_tag        VARBINARY(16)   NULL,

  from_email     VARCHAR(255) NOT NULL DEFAULT 'no-reply@empresa.local',
  from_name      VARCHAR(120) NULL,
  reply_to       VARCHAR(255) NULL,
  timeout        SMALLINT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'segundos',

  -- Diagnostico de la ultima prueba: evita tener que mirar el log del
  -- servidor para saber por que no sale el correo.
  last_test_at     DATETIME NULL,
  last_test_ok     TINYINT(1) NULL,
  last_test_error  VARCHAR(500) NULL,

  updated_by     INT UNSIGNED NULL,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT ck_mail_config_fila_unica CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fila unica. Se crea con los valores por defecto y despues se le
-- trasladan los dos parametros que ya existieran en `settings`.
INSERT IGNORE INTO mail_config (id) VALUES (1);

UPDATE mail_config SET enabled = 1
 WHERE id = 1
   AND EXISTS (SELECT 1 FROM (SELECT setting_value FROM settings
                WHERE setting_key = 'mail.enabled') AS s
               WHERE s.setting_value IN ('1','true','on'));

UPDATE mail_config SET from_email = (
         SELECT s.setting_value FROM (SELECT setting_value FROM settings
           WHERE setting_key = 'mail.from') AS s)
 WHERE id = 1
   AND EXISTS (SELECT 1 FROM (SELECT setting_value FROM settings
                WHERE setting_key = 'mail.from') AS s
               WHERE s.setting_value <> '');

-- Una sola fuente de verdad: la pantalla de configuracion general ya no
-- debe ofrecer estos dos campos.
DELETE FROM settings WHERE setting_key IN ('mail.enabled', 'mail.from');
