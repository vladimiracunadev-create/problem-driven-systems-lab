# 📋 ADR 0003 — Docker por caso y por stack

| Campo | Valor |
|-------|-------|
| **Estado** | ✅ Aceptada |
| **Fecha** | 2025 |

---

## 🔍 Contexto

Un laboratorio con 12 casos y hasta 5 stacks por caso representa potencialmente hasta 60 entornos distintos. Levantar todo al mismo tiempo sería:

- costoso en recursos (CPU, memoria, red),
- difícil de mantener y depurar,
- innecesario para la mayoría de los flujos de trabajo,
- incompatible con la narrativa de portafolio que busca claridad.

---

## ✅ Decisión

Cada stack dentro de cada caso tiene su **propio `compose.yml` independiente**.

Adicionalmente, cada caso puede tener un `compose.compare.yml` para cuando se quiere levantar múltiples stacks del mismo caso de forma comparativa.

```
cases/01-api-latency-under-load/
├── compose.compare.yml        ← opcional, para comparar stacks
├── php/
│   └── compose.yml            ← solo el stack PHP de este caso
├── node/
│   └── compose.yml            ← solo el stack Node del caso
└── ...
```

---

## ⚖️ Consecuencias

| Consecuencia | Detalle |
|-------------|---------|
| ✅ Aislamiento total | Un caso no interfiere con otros |
| ✅ Reproducibilidad | Cada entorno se levanta de forma independiente y predecible |
| ✅ Mejor depuración | Menos servicios corriendo = menos ruido al investigar un problema |
| ✅ Crecimiento controlado | Agregar un nuevo caso no afecta los anteriores |
| ⚠️ Más archivos de compose | Hay múltiples `compose.yml` en el repositorio — los comandos de `Makefile` los estandarizan |
