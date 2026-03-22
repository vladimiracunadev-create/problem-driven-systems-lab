<?php
header('Content-Type: application/json; charset=utf-8');
echo '{
  "lab": "Problem-Driven Systems Lab",
  "case": "05 - Presión de memoria y fugas de recursos",
  "stack": "PHP 8",
  "message": "Base mínima dockerizada del caso.",
  "focus": "El sistema consume memoria, descriptores o conexiones de forma progresiva hasta degradar o caerse."
}';
