-- =====================================================================
--  Duracion de la identificacion en la consulta rapida
-- =====================================================================
--  Correccion de un fallo: la identificacion se consumia al dibujar la
--  lista de accesos, de modo que el boton "Ver contrasena" respondia
--  siempre "su consulta expiro".
--
--  Necesita durar lo suficiente para mirar la lista y pedir una
--  contrasena, y lo bastante poco para no dejar la pantalla abierta en un
--  computador compartido de recepcion o planta.
-- =====================================================================

INSERT INTO settings (setting_key, setting_value, value_type, group_name, label, description) VALUES
 ('access.quick_lookup_minutes', '5', 'int', 'consulta',
  'Duracion de la consulta (minutos)',
  'Tiempo que la pantalla recuerda a quien se identifico antes de volver a pedirlo')
ON DUPLICATE KEY UPDATE
  value_type  = VALUES(value_type),
  group_name  = VALUES(group_name),
  label       = VALUES(label),
  description = VALUES(description);
