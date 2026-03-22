<?php
header('Content-Type: application/json; charset=utf-8');
echo '{
  "lab": "Problem-Driven Systems Lab",
  "case": "08 - Extracción de módulo crítico sin romper operación",
  "stack": "PHP 8",
  "message": "Base mínima dockerizada del caso.",
  "focus": "Se necesita desacoplar una parte clave del sistema, pero esa parte participa en flujos sensibles y no admite quiebres."
}';
