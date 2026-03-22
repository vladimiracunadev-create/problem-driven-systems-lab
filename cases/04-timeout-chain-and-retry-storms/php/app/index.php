<?php
header('Content-Type: application/json; charset=utf-8');
echo '{
  "lab": "Problem-Driven Systems Lab",
  "case": "04 - Cadena de timeouts y tormentas de reintentos",
  "stack": "PHP 8",
  "message": "Base mínima dockerizada del caso.",
  "focus": "Una integración lenta o inestable dispara reintentos, bloqueos y cascadas de fallas entre servicios."
}';
