-- =====================================================================
--  Consulta rapida de accesos (/consulta)
-- =====================================================================
--  Pantalla publica y sencilla para que un empleado vea en que sistemas
--  tiene cuenta sin recorrer el panel completo.
--
--  Viene APAGADA. Cada parametro que se enciende amplia lo que un
--  desconocido puede averiguar, asi que la decision es del
--  superadministrador y no un valor por defecto comodo.
-- =====================================================================

INSERT INTO settings (setting_key, setting_value, value_type, group_name, label, description) VALUES

 ('access.quick_lookup_enabled', '0', 'bool', 'consulta',
  'Habilitar la consulta rapida en /consulta',
  'Pantalla publica donde un empleado consulta sus propios accesos sin entrar al sistema'),

 ('access.quick_lookup_require_password', '1', 'bool', 'consulta',
  'Exigir contrasena en la consulta rapida',
  'RECOMENDADO. Si lo apaga, bastara la cedula, el usuario o el correo: cualquiera que conozca ese dato vera en que sistemas trabaja esa persona'),

 ('access.quick_lookup_show_secrets', '0', 'bool', 'consulta',
  'Permitir revelar contrasenas en la consulta rapida',
  'PELIGROSO sin la contrasena activada: quien tenga una cedula ajena podria sacar las contrasenas de esa persona, y la auditoria registraria a la victima, no al intruso'),

 ('access.quick_lookup_max_attempts', '10', 'int', 'consulta',
  'Intentos por hora y direccion IP',
  'Freno a la prueba masiva de cedulas desde una misma procedencia')

ON DUPLICATE KEY UPDATE
  value_type  = VALUES(value_type),
  group_name  = VALUES(group_name),
  label       = VALUES(label),
  description = VALUES(description);
