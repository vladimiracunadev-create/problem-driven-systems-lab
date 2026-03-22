<?php
header('Content-Type: application/json; charset=utf-8');
echo '{
  "lab": "Problem-Driven Systems Lab",
  "case": "02 - N+1 queries y cuellos de botella en base de datos",
  "stack": "PHP 8",
  "message": "Base mínima dockerizada del caso.",
  "focus": "La aplicación ejecuta demasiadas consultas por solicitud o usa el ORM de forma ineficiente, generando saturación de base de datos."
}';
