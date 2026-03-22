# Extracción de módulo crítico sin romper operación — PHP 8

## Objetivo de esta variante
Representar este caso desde el stack **PHP 8**, manteniendo foco en el problema y no solo en la sintaxis.

## Qué debería mostrar esta carpeta
- una base dockerizada,
- un punto de entrada mínimo,
- espacio para instrumentación, pruebas o scripts,
- notas de diseño específicas del stack.

## Qué NO debería hacer
- mezclar dependencias de otros stacks,
- levantar todo el laboratorio,
- esconder decisiones importantes fuera del repositorio.

## Puertos de referencia
- Puerto local sugerido: `818`

## Comando esperado
```bash
docker compose -f compose.yml up -d --build
```

## Notas del stack
En PHP 8 conviene estudiar este caso considerando:
- ergonomía del runtime,
- patrones habituales del ecosistema,
- observabilidad disponible,
- costos de complejidad,
- límites y trade-offs específicos.

## Estado inicial
Esta carpeta deja una base mínima documentada y ampliable para que el caso evolucione hacia un escenario más realista.
