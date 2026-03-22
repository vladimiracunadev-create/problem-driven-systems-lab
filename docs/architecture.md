# 🏛️ Arquitectura del repositorio

> Cómo está organizado el laboratorio y por qué.

---

## 📐 Estructura por niveles

```
problem-driven-systems-lab/
│
├── [Nivel 1] Raíz del repositorio
│   ├── README.md          → punto de entrada del laboratorio
│   ├── ROADMAP.md         → estado y evolución del proyecto
│   ├── Makefile           → comandos operacionales
│   ├── compose.root.yml   → portal principal
│   └── docs/              → documentación global
│
├── [Nivel 2] Portal
│   └── portal/            → landing local PHP 8
│
├── [Nivel 3] Casos
│   └── cases/             → 12 problemas reales documentados
│       ├── 01-api-latency-under-load/
│       ├── 02-n-plus-one-and-db-bottlenecks/
│       └── ... (hasta el 12)
│
└── [Nivel 4] Implementaciones por stack
    └── cases/<caso>/
        ├── php/           → implementación PHP 8
        ├── node/          → implementación Node.js
        ├── python/        → implementación Python
        ├── java/          → implementación Java
        └── dotnet/        → implementación .NET 8
```

---

## 📂 Descripción de cada nivel

### Nivel 1 — Raíz del repositorio

Contiene el punto de entrada, el mapa general y los comandos operacionales del laboratorio. Sirve para orientar al lector antes de entrar a cualquier caso.

### Nivel 2 — Portal

`portal/` es el punto de entrada **local** del laboratorio. No reemplaza a los casos ni ejecuta todos los entornos. Sirve para aterrizar el proyecto, navegar su propósito y mostrar el mapa inicial cuando alguien lo levanta localmente.

### Nivel 3 — Casos

Cada carpeta bajo `cases/` representa un **problema real documentado**. El caso es la unidad principal del laboratorio: tiene su propio README, documentación técnica, stacks y archivos Docker.

### Nivel 4 — Implementaciones por stack

Dentro de cada caso, cada stack es una **implementación o variante del mismo problema**, no un fin en sí mismo. Los stacks permiten comparar cómo la misma dificultad técnica se resuelve de forma diferente según el runtime, el tooling y las convenciones del ecosistema.

---

## 📏 Regla principal

> La estructura responde a la pregunta:
> **¿Cómo resolver y estudiar este problema?**
>
> No a:
> **¿Cómo ordenar mis lenguajes favoritos?**
