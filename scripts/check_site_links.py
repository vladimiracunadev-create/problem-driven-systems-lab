#!/usr/bin/env python3
"""Verifica el sitio generado antes de publicarlo.

Por que existe: GitHub Pages despliega un 404 con el mismo exito que una pagina
buena. Un enlace roto no rompe el build, no aparece en ningun log y solo lo
descubre la persona a la que le mandaste el enlace. Por eso la comprobacion
tiene que ocurrir antes del deploy y fallar el workflow.

Que exige:

1. Todo destino interno (`href`/`src`) existe como archivo del sitio.
2. Todo anchor apunta a un `id` que existe en la pagina destino.
3. Ningun enlace del sitio apunta a un `.md`. Es la regla que motiva todo el
   generador: la web publicada se navega en HTML, no descargando Markdown.
4. La portada no carga recursos externos (`<script src>` / `<link href>` a otro
   host): el sitio se sirve entero desde su propio dominio.
5. Las paginas obligatorias existen y la portada muestra los casos que el
   catalogo declara — una portada a medias se publica igual de bien.

Con `--external` ademas comprueba por red los destinos http(s). No corre en CI
por defecto: depende de servicios de terceros y convertiria un fallo ajeno en un
despliegue bloqueado.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import urllib.error
import urllib.request
from collections import defaultdict
from pathlib import Path
from urllib.parse import unquote, urlsplit

ROOT = Path(__file__).resolve().parent.parent
CATALOG_PATH = ROOT / "shared" / "catalog" / "cases.json"

REQUIRED_PAGES = (
    "index.html",
    "casos.html",
    "stacks.html",
    "documentacion.html",
    "404.html",
    "README.html",
    "INSTALL.html",
    "SECURITY.html",
    "assets/site.css",
    "sitemap.xml",
    "robots.txt",
)

_ATTR_RE = re.compile(r"""\b(href|src)\s*=\s*["']([^"']*)["']""", re.I)
_ID_RE = re.compile(r"""\bid\s*=\s*["']([^"']+)["']""")
# `<link rel="canonical">` apunta al sitio publicado por diseno: lo que importa
# es que no se cargue codigo ni estilos de otro host.
_EXTERNAL_ASSET_RES = (
    re.compile(r"""<script\b[^>]*\bsrc\s*=\s*["']https?://""", re.I),
    re.compile(r"""<link\b[^>]*\brel\s*=\s*["'](?:stylesheet|preload|modulepreload)["'][^>]*"""
               r"""\bhref\s*=\s*["']https?://""", re.I),
)
_LOCAL_SCHEMES = ("mailto:", "tel:", "data:", "javascript:")


def collect_ids(text: str) -> set[str]:
    return set(_ID_RE.findall(text))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", default=str(ROOT / "site"), help="directorio del sitio generado")
    parser.add_argument(
        "--external",
        action="store_true",
        help="ademas comprueba por red los enlaces http(s) (lento, depende de terceros)",
    )
    args = parser.parse_args()

    site = Path(args.site).resolve()
    if not site.is_dir():
        print(f"ERROR: no existe el sitio en {site}", file=sys.stderr)
        return 1

    pages = sorted(p for p in site.rglob("*.html"))
    if not pages:
        print("ERROR: el sitio no tiene ni una pagina HTML", file=sys.stderr)
        return 1

    contents = {page: page.read_text(encoding="utf-8") for page in pages}
    ids = {page.relative_to(site).as_posix(): collect_ids(text) for page, text in contents.items()}

    errors: list[str] = []
    warnings: list[str] = []
    external: dict[str, list[str]] = defaultdict(list)
    internal_checked = 0

    for page, text in contents.items():
        rel_page = page.relative_to(site).as_posix()
        for _attribute, raw in _ATTR_RE.findall(text):
            value = raw.strip()
            if not value or value.startswith("#"):
                if value.startswith("#") and len(value) > 1:
                    anchor = unquote(value[1:])
                    if anchor not in ids[rel_page]:
                        errors.append(f"{rel_page}: anchor propio inexistente '{value}'")
                continue
            if value.startswith(_LOCAL_SCHEMES):
                continue
            if value.startswith(("http://", "https://", "//")):
                if urlsplit(value).path.endswith(".md"):
                    errors.append(f"{rel_page}: enlace a Markdown '{value}'")
                external[value].append(rel_page)
                continue

            split = urlsplit(value)
            target_path = unquote(split.path)
            if target_path.endswith(".md"):
                errors.append(f"{rel_page}: enlace a Markdown '{value}'")
                continue

            if target_path.startswith("/"):
                # Rutas absolutas del sitio publicado (`/problem-driven-systems-lab/...`).
                prefix = "/problem-driven-systems-lab/"
                if not target_path.startswith(prefix):
                    errors.append(f"{rel_page}: ruta absoluta fuera del sitio '{value}'")
                    continue
                resolved = site / target_path[len(prefix) :]
            else:
                resolved = (page.parent / target_path).resolve()

            internal_checked += 1
            if resolved.is_dir():
                resolved = resolved / "index.html"
            if not resolved.exists():
                errors.append(f"{rel_page}: destino inexistente '{value}'")
                continue
            try:
                resolved_rel = resolved.relative_to(site).as_posix()
            except ValueError:
                errors.append(f"{rel_page}: destino fuera del sitio '{value}'")
                continue
            if split.fragment and resolved_rel.endswith(".html"):
                anchor = unquote(split.fragment)
                if anchor not in ids.get(resolved_rel, set()):
                    warnings.append(f"{rel_page}: anchor inexistente en destino '{value}'")

    for required in REQUIRED_PAGES:
        target = site / required
        if not target.is_file() or target.stat().st_size == 0:
            errors.append(f"falta o esta vacio: {required}")

    index_text = contents.get(site / "index.html", "")
    if any(pattern.search(index_text) for pattern in _EXTERNAL_ASSET_RES):
        errors.append("index.html carga scripts o estilos de un host externo")

    catalog = json.loads(CATALOG_PATH.read_text(encoding="utf-8"))
    expected_cases = len(catalog["cases"])
    shown = index_text.count('class="case-card"')
    if shown != expected_cases:
        errors.append(f"index.html muestra {shown} casos y el catalogo declara {expected_cases}")
    for case in catalog["cases"]:
        page = site / "casos" / f"{case['id']}-{case['slug']}.html"
        if not page.is_file():
            errors.append(f"falta la ficha del caso {case['id']}: {page.name}")

    if args.external:
        errors.extend(check_external(external))

    print(f"paginas revisadas   : {len(pages)}")
    print(f"enlaces internos    : {internal_checked}")
    print(f"enlaces externos    : {len(external)} destinos unicos")
    print(f"casos en la portada : {shown}/{expected_cases}")

    if warnings:
        print(f"\nAvisos ({len(warnings)}):")
        for warning in warnings[:40]:
            print(f"  ! {warning}")
        if len(warnings) > 40:
            print(f"  ... y {len(warnings) - 40} mas")

    if errors:
        print(f"\nERRORES ({len(errors)}):", file=sys.stderr)
        for error in errors[:80]:
            print(f"  x {error}", file=sys.stderr)
        if len(errors) > 80:
            print(f"  ... y {len(errors) - 80} mas", file=sys.stderr)
        return 1

    print("\nOK: sin enlaces rotos y sin destinos Markdown.")
    return 0


def check_external(external: dict[str, list[str]]) -> list[str]:
    """Comprobacion opcional por red. Solo se llama con `--external`."""
    problems: list[str] = []
    for url in sorted(external):
        request = urllib.request.Request(url, method="HEAD", headers={"User-Agent": "pdsl-linkcheck"})
        try:
            with urllib.request.urlopen(request, timeout=20) as response:
                if response.status >= 400:
                    problems.append(f"{url} -> HTTP {response.status}")
        except urllib.error.HTTPError as exc:
            if exc.code in (403, 405, 429):
                continue  # el host rechaza HEAD o limita: no es un enlace roto
            problems.append(f"{url} -> HTTP {exc.code}")
        except Exception as exc:  # noqa: BLE001 - cualquier fallo de red se reporta igual
            problems.append(f"{url} -> {type(exc).__name__}: {exc}")
    return problems


if __name__ == "__main__":
    raise SystemExit(main())
