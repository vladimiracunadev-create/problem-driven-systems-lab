# 🧱 CONTRIBUTING

Guia para contribuir sin degradar el estandar tecnico ni documental del laboratorio.

## 🔁 Flujo recomendado

1. Trabaja en una rama dedicada.
2. Implementa o ajusta el caso.
3. Valida que el stack siga levantando con Docker.
4. Actualiza la documentacion afectada en el mismo cambio.
5. Usa un commit descriptivo y pequeno si es posible.

## 🎯 Regla principal del repositorio

Si un caso se marca como `OPERATIVO`, debe resolver un problema real con suficiente evidencia. No basta con un endpoint trivial o una simulacion superficial.

## 🧪 Al agregar o profundizar un caso

Cada cambio serio deberia considerar:

- `README.md` del caso actualizado;
- documentacion de contexto, sintomas, diagnostico y trade-offs;
- `Dockerfile` y `compose.yml` funcionales;
- explicacion honesta de limites;
- observabilidad o diagnostico suficiente para sostener la narrativa tecnica.

## 📝 Reglas editoriales

- Mantener consistencia con la familia documental raiz.
- Explicitar si algo es `OPERATIVO`, `DOCUMENTADO / SCAFFOLD` o `PLANIFICADO`.
- Evitar claims de paridad multi-stack que el codigo no soporte.
- No convertir un caso real en un demo pobre solo para "completar lenguajes".

## 🧾 Convenciones utiles de commit

| Prefijo | Uso |
| --- | --- |
| `feat:` | nueva capacidad o nuevo caso operativo |
| `fix:` | correccion funcional |
| `docs:` | cambios solo documentales |
| `refactor:` | reorganizacion sin cambio funcional principal |
| `chore:` | mantenimiento, limpieza o tooling |

## 🧹 Artefactos que no deben versionarse

- binarios compilados;
- caches temporales;
- archivos de salida de benchmarks;
- datos locales de ejecucion que no pertenezcan a la fuente del caso.

## ✅ Validacion minima esperada

Antes de proponer cambios:

- revisar `docker compose ... config` en el caso afectado;
- validar la sintaxis del lenguaje principal tocado;
- confirmar que la documentacion no quedo desalineada del estado real.
