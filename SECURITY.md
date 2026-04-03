# SECURITY

La seguridad importa tambien en un laboratorio. Este repositorio no pretende ser un producto expuesto a Internet, pero si mantiene reglas claras para evitar malas practicas y proteger el crecimiento del ecosistema.

## Principios

- No subir secretos, tokens ni credenciales reales al repositorio.
- Tratar `.env.example` como referencia y no como lugar para valores sensibles.
- Entender que Docker ayuda a reproducir entornos, pero no reemplaza el hardening.
- Recordar que las credenciales embebidas en casos de laboratorio son solo para uso local y no deben reutilizarse fuera del repo.

## Reporte responsable

Si encuentras una vulnerabilidad o una exposicion sensible:

1. No abras un issue publico con el detalle completo.
2. Usa GitHub Security Advisories o contacta directamente al responsable del repositorio.
3. Incluye pasos de reproduccion, impacto potencial y recomendacion de mitigacion si la tienes.

## Alcance de seguridad del laboratorio

| Area | Postura actual |
| --- | --- |
| Secretos reales | No deben existir en el arbol versionado |
| Credenciales de laboratorio | Aceptadas solo para entornos locales y controlados |
| Casos operativos | Deben poder levantarse con Docker sin pasos manuales inseguros |
| Stacks scaffold | No deben vender una postura de seguridad que aun no implementan |

## Versiones soportadas

| Rama | Estado |
| --- | --- |
| `main` | soportada |

## Nota importante sobre credenciales locales

Los casos `01` y `02` usan credenciales locales de PostgreSQL dentro de `compose.yml` para reproducibilidad. Eso es aceptable solo porque se trata de un laboratorio local. No reutilices esos valores en servidores reales ni los tomes como patron de produccion.
