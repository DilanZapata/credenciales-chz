-- =====================================================================
--  Politica de contrasenas configurable
-- =====================================================================
--  Hasta ahora solo la longitud minima era ajustable desde la interfaz;
--  el resto de reglas —que exija mayusculas, minusculas, digitos o
--  simbolos— estaban escritas en el codigo, de modo que adaptarlas a la
--  politica de una empresa exigia tocar y volver a desplegar.
--
--  Cada organizacion tiene la suya, y quien debe decidirla es el
--  superadministrador, no el programador.
-- =====================================================================

INSERT INTO settings (setting_key, setting_value, value_type, group_name, label, description) VALUES
 ('security.password_max_length',     '200', 'int', 'politica',
  'Longitud maxima de contrasena de acceso',
  'Tope para evitar entradas desmesuradas. No lo baje de 64: las frases de paso largas son mas seguras que las cortas y complejas'),

 ('security.password_require_upper',  '1',   'bool','politica',
  'Exigir al menos una mayuscula',
  'Aplica a la contrasena de acceso de los usuarios del sistema'),

 ('security.password_require_lower',  '1',   'bool','politica',
  'Exigir al menos una minuscula',
  'Aplica a la contrasena de acceso de los usuarios del sistema'),

 ('security.password_require_digit',  '1',   'bool','politica',
  'Exigir al menos un digito',
  'Aplica a la contrasena de acceso de los usuarios del sistema'),

 ('security.password_require_symbol', '1',   'bool','politica',
  'Exigir al menos un caracter especial',
  'Cualquier caracter que no sea letra ni numero'),

 ('security.password_block_personal', '1',   'bool','politica',
  'Rechazar contrasenas con datos personales',
  'Impide usar el nombre, apellido, usuario, cedula o correo dentro de la contrasena'),

 ('security.password_block_common',   '1',   'bool','politica',
  'Rechazar patrones demasiado comunes',
  'Impide "password", "12345678", "qwerty", "admin123" y similares')

ON DUPLICATE KEY UPDATE
  value_type  = VALUES(value_type),
  group_name  = VALUES(group_name),
  label       = VALUES(label),
  description = VALUES(description);
