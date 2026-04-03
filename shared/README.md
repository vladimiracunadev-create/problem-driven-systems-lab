# 🧩 Shared

Recursos compartidos entre casos:

- convenciones,
- notas reutilizables,
- scripts,
- base documental,
- observabilidad base futura,
- metadatos compartidos del catalogo.

La existencia de `shared/` no implica que los casos dependan todos entre si.
Su funcion es evitar duplicacion documental o de plantillas cuando resulte razonable.

## 📚 Catalogo compartido

La fuente de verdad del catalogo vive en `shared/catalog/cases.json`.

- el portal local consume este archivo;
- `scripts/generate_case_catalog.php` genera `docs/case-catalog.md`;
- la CI puede validar que la documentacion siga sincronizada con estos metadatos.
