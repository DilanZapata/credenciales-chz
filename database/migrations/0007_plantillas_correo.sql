-- =====================================================================
--  Plantillas de los correos que envia el sistema
-- =====================================================================
--  Hasta aqui el texto de cada aviso estaba escrito dentro de
--  correoModel. Cambiar una palabra exigia tocar el codigo y desplegar, y
--  no habia forma de que un mensaje saliera con la imagen de la empresa.
--
--  Cada tipo de correo pasa a tener asunto, cuerpo en HTML y cuerpo en
--  texto plano, editables desde Administracion > Correo > Plantillas. El
--  catalogo de tipos y las variables que admite cada uno viven en el
--  codigo (plantillaCorreoModel::CATALOGO): la base guarda el contenido,
--  no que variables existen.
--
--  No se usa `settings` por lo mismo que la configuracion SMTP: alli el
--  texto se recorta a 500 caracteres y un HTML de correo no cabe.
--
--  `enabled` apagado no deja al sistema mudo: se vuelve al texto por
--  defecto que trae el codigo. Un error editando una plantilla nunca
--  puede impedir que salga un restablecimiento de contrasena.
-- =====================================================================

CREATE TABLE IF NOT EXISTS mail_templates (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(40)  NOT NULL COMMENT 'clave del catalogo en codigo',
  subject     VARCHAR(255) NOT NULL,
  body_html   MEDIUMTEXT   NULL COMMENT 'vacio = solo texto plano',
  body_text   MEDIUMTEXT   NOT NULL COMMENT 'respaldo para clientes sin HTML',
  enabled     TINYINT(1)   NOT NULL DEFAULT 1,
  updated_by  INT UNSIGNED NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mail_template_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
