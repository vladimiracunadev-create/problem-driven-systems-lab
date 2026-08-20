#!/usr/bin/env python3
"""Genera el sitio publico de `site/` a partir del repositorio.

Por que existe: el laboratorio tenia portal (localhost:8080) y documentacion
(400+ archivos Markdown), pero ninguna puerta de entrada publica. Quien recibia
el enlace del repo caia en un README de 48 KB y tenia que decidir por su cuenta
por donde empezar.

Reglas que se derivan de eso y que este generador impone:

1. **Nada apunta a `.md`.** Cada documento del repositorio se publica como HTML
   propio en la misma ruta, con la extension cambiada. Un enlace a Markdown en
   GitHub Pages no es una pagina: es un 404 o una descarga.
2. **La landing se deriva del catalogo**, no se escribe a mano. Si entra un caso
   21 o un octavo stack, `shared/catalog/cases.json` manda y la portada cambia
   sola. Una portada mantenida aparte es la primera cosa que miente cuando el
   repositorio crece.
3. **Cero dependencias y cero recursos externos** (salvo los badges que ya viven
   dentro de los documentos). El sitio es HTML y CSS estaticos.

`check_site_links.py` valida el resultado: si algo queda roto, el build falla
antes de publicar. Un 404 se despliega igual de bien que una pagina buena, asi
que la verificacion tiene que ocurrir antes del deploy y no despues.
"""
from __future__ import annotations

import argparse
import html
import json
import posixpath
import re
import shutil
import sys
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from site_markdown import render_inline, render_markdown  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
SITE_SRC = ROOT / "site-src"
CATALOG_PATH = ROOT / "shared" / "catalog" / "cases.json"

REPO_SLUG = "vladimiracunadev-create/problem-driven-systems-lab"
REPO_URL = f"https://github.com/{REPO_SLUG}"
BLOB_URL = f"{REPO_URL}/blob/main/"
TREE_URL = f"{REPO_URL}/tree/main/"
BASE_URL = "https://vladimiracunadev-create.github.io/problem-driven-systems-lab/"
AUTHOR = "Vladimir Acuna"
AUTHOR_URL = "https://vladimiracunadev-create.github.io/"

# Directorios que nunca entran al sitio: artefactos de build o dependencias.
SKIP_DIRS = {
    ".git", "site", "site-src", "node_modules", "vendor",
    "__pycache__", "target", "bin", "obj", ".claude",
}
# El Markdown de `.github/` (plantillas de issue, politicas) no es documentacion
# del laboratorio, pero sus workflows si son destino legitimo de enlaces.
SKIP_MD_DIRS = SKIP_DIRS | {".github"}
ASSET_SUFFIXES = {".svg", ".png", ".jpg", ".jpeg", ".gif", ".webp", ".pdf", ".ico"}

NAV = [
    ("index.html", "Inicio"),
    ("casos.html", "Los 20 casos"),
    ("stacks.html", "Los 7 stacks"),
    ("documentacion.html", "Documentacion"),
]

CATEGORY_BLURB = {
    "Rendimiento": "Latencia, N+1, coste de consulta y presion de memoria.",
    "Observabilidad": "Logs que sirven, correlacion y metricas que se pueden mirar.",
    "Resiliencia": "Timeouts, reintentos, cuotas, idempotencia y colas muertas.",
    "Arquitectura": "Modernizacion incremental y sobre-ingenieria evitable.",
    "Entrega": "Pipelines fragiles y despliegues que no se pueden repetir.",
    "Operaciones": "Riesgo de conocimiento unico y trabajo pesado en horario productivo.",
}

WHAT_IT_PROVES = [
    (
        "🔬",
        "Diagnostico tecnico",
        "Cada caso parte de sintomas observados, nombra causas y compara opciones antes de proponer solucion.",
    ),
    (
        "🐳",
        "Ejecucion reproducible",
        "Cada caso y cada stack traen su propio Dockerfile y su compose. La via oficial es Docker, no un README optimista.",
    ),
    (
        "📈",
        "Operacion realista",
        "Los casos usan base de datos, workers, metricas y trazas segun corresponda. No hay demos vacias que solo devuelven 200.",
    ),
    (
        "🎯",
        "Honestidad tecnica",
        "El repositorio declara donde el substrato es real y donde es simulado, y que garantiza y que no garantiza su seguridad.",
    ),
]


# -- utilidades ---------------------------------------------------------------
def esc(text: str) -> str:
    return html.escape(str(text), quote=True)


def md(text: str) -> str:
    """Prosa del catalogo: viene escrita en Markdown, se publica interpretada."""
    return render_inline(str(text))


def doc_label(path: str) -> str:
    """Nombre legible de un documento a partir de su ruta.

    El catalogo titula los documentos por su nombre de archivo (`RECRUITER.md`).
    En el repositorio eso es lo correcto; en una pagina publicada, mostrar la
    extension `.md` invita a hacer clic esperando otra cosa.
    """
    stem = posixpath.basename(path)
    if stem.lower().endswith(".md"):
        stem = stem[:-3]
    return stem.replace("_", " ").replace("-", " ").capitalize()


def rel_to_root(site_path: str) -> str:
    depth = site_path.count("/")
    return "../" * depth


def rel_href(from_page: str, to_page: str) -> str:
    from_dir = posixpath.dirname(from_page)
    if not from_dir:
        return to_page
    return posixpath.relpath(to_page, from_dir)


def read_text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


# -- descubrimiento -----------------------------------------------------------
def discover(
    root: Path,
    suffixes: set[str] | None = None,
    skip: set[str] | None = None,
) -> list[str]:
    """Rutas relativas (posix) de los archivos del repo que el sitio puede usar."""
    skip = SKIP_DIRS if skip is None else skip
    found: list[str] = []
    for path in root.rglob("*"):
        if not path.is_file():
            continue
        rel_parts = path.relative_to(root).parts
        if any(part in skip for part in rel_parts[:-1]):
            continue
        if suffixes is not None and path.suffix.lower() not in suffixes:
            continue
        found.append("/".join(rel_parts))
    return sorted(found)


class SiteBuilder:
    def __init__(self, out_dir: Path) -> None:
        self.out = out_dir
        self.catalog = json.loads(read_text(CATALOG_PATH))
        self.cases = self.catalog["cases"]
        self.languages = self.catalog["languages"]
        self.lang_by_key = {lang["key"]: lang for lang in self.languages}
        self.md_files = discover(ROOT, {".md"}, SKIP_MD_DIRS)
        self.md_set = set(self.md_files)
        self.all_files = set(discover(ROOT))
        self.copied_assets: set[str] = set()
        self.pages: list[str] = []
        self.broken_sources: list[tuple[str, str]] = []
        self.today = date.today().isoformat()

    # -- escritura ------------------------------------------------------------
    def write(self, site_path: str, content: str) -> None:
        target = self.out / site_path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content, encoding="utf-8", newline="\n")
        if site_path.endswith(".html"):
            self.pages.append(site_path)

    def copy_asset(self, repo_path: str) -> None:
        if repo_path in self.copied_assets:
            return
        source = ROOT / repo_path
        if not source.is_file():
            return
        target = self.out / repo_path
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, target)
        self.copied_assets.add(repo_path)

    # -- resolucion de enlaces -------------------------------------------------
    def resolve(self, href: str, is_image: bool, source: str, page: str) -> str:
        """Traduce un destino escrito en Markdown al destino real del sitio.

        `source` es la ruta del `.md` en el repo (para resolver rutas relativas)
        y `page` la ruta de la pagina generada (para emitir un href relativo).
        """
        raw = href.strip()
        if not raw:
            return "#"
        if raw.startswith(("http://", "https://", "mailto:", "tel:", "data:", "//")):
            return raw
        if raw.startswith("#"):
            return raw

        path_part, _, fragment = raw.partition("#")
        suffix = f"#{fragment}" if fragment else ""
        path_part = path_part.split("?", 1)[0]
        if not path_part:
            return suffix or "#"

        # `SECURITY.md` cita evidencia como `archivo.php:105`. En GitHub eso es
        # una linea concreta del blob; aqui se traduce al anchor `#L105` en vez
        # de perderse como ruta inexistente.
        line_ref = re.fullmatch(r"(.+?):(\d+)", path_part)
        if line_ref and not suffix:
            path_part = line_ref.group(1)
            suffix = f"#L{line_ref.group(2)}"

        base_dir = posixpath.dirname(source)
        target = posixpath.normpath(posixpath.join(base_dir, path_part))
        if target.startswith(".."):
            # Sale del repositorio: no hay nada que publicar, se manda a GitHub.
            return REPO_URL

        if target in self.md_set:
            return rel_href(page, target[:-3] + ".html") + suffix

        readme = posixpath.join(target, "README.md")
        if readme in self.md_set:
            return rel_href(page, readme[:-3] + ".html") + suffix

        if posixpath.splitext(target)[1].lower() in ASSET_SUFFIXES and target in self.all_files:
            self.copy_asset(target)
            return rel_href(page, target) + suffix

        if target in self.all_files:
            # Codigo, compose o Dockerfile: el destino natural es GitHub, donde
            # se lee con resaltado e historial. No es un enlace roto.
            return BLOB_URL + target + suffix
        if (ROOT / target).is_dir():
            return TREE_URL + target

        self.broken_sources.append((source, raw))
        return BLOB_URL + target + suffix

    # -- plantilla ------------------------------------------------------------
    def shell(
        self,
        *,
        page: str,
        title: str,
        description: str,
        body: str,
        active: str = "",
        wide: bool = True,
        extra_head: str = "",
    ) -> str:
        root = rel_to_root(page)
        nav_items = []
        for target, label in NAV:
            current = ' aria-current="page"' if target == active else ""
            nav_items.append(f'<a href="{esc(root + target)}"{current}>{esc(label)}</a>')
        nav_items.append(
            f'<a href="{esc(REPO_URL)}" target="_blank" rel="noopener noreferrer">GitHub &#8599;</a>'
        )
        canonical = BASE_URL + ("" if page == "index.html" else page)
        full_title = title if page == "index.html" else f"{title} · Problem-Driven Systems Lab"
        footer_docs = "".join(
            f'<li><a href="{esc(root + doc["path"][:-3] + ".html")}">{esc(doc_label(doc["path"]))}</a></li>'
            for doc in self.catalog["documents"]
            if doc["path"] in self.md_set
        )
        return f"""<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{esc(full_title)}</title>
<meta name="description" content="{esc(description)}">
<meta name="author" content="{esc(AUTHOR)}">
<link rel="canonical" href="{esc(canonical)}">
<meta property="og:type" content="website">
<meta property="og:title" content="{esc(full_title)}">
<meta property="og:description" content="{esc(description)}">
<meta property="og:url" content="{esc(canonical)}">
<meta property="og:site_name" content="Problem-Driven Systems Lab">
<meta name="twitter:card" content="summary">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#129514;</text></svg>">
<link rel="stylesheet" href="{esc(root)}assets/site.css">
{extra_head}</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="{esc(root)}index.html">
      <span class="brand-mark">&#129514;</span>
      <span>Problem-Driven Systems Lab</span>
    </a>
    <nav class="nav" aria-label="Navegacion principal">
      {"".join(nav_items)}
    </nav>
  </div>
</header>
<main class="wrap{'' if wide else ' narrow'}">
{body}
</main>
<footer class="footer">
  <div class="footer-inner">
    <div>
      <h4>El laboratorio</h4>
      <p>20 problemas reales de ingenieria, resueltos en 7 lenguajes con la primitiva
      nativa de cada runtime y ejecutables con Docker.</p>
    </div>
    <div>
      <h4>Recorrido</h4>
      <ul>
        <li><a href="{esc(root)}casos.html">Los 20 casos</a></li>
        <li><a href="{esc(root)}stacks.html">Los 7 stacks</a></li>
        <li><a href="{esc(root)}documentacion.html">Toda la documentacion</a></li>
      </ul>
    </div>
    <div>
      <h4>Documentos clave</h4>
      <ul>{footer_docs}</ul>
    </div>
    <div>
      <h4>Codigo</h4>
      <ul>
        <li><a href="{esc(REPO_URL)}" target="_blank" rel="noopener noreferrer">Repositorio en GitHub</a></li>
        <li><a href="{esc(AUTHOR_URL)}" target="_blank" rel="noopener noreferrer">Portafolio de {esc(AUTHOR)}</a></li>
        <li><a href="{esc(root)}LICENSE.html">Licencia MIT</a></li>
      </ul>
      <p style="margin-top:12px">Generado desde el repositorio el {esc(self.today)}.</p>
    </div>
  </div>
</footer>
</body>
</html>
"""

    # -- documentos markdown ---------------------------------------------------
    def build_documents(self) -> None:
        for md_path in self.md_files:
            page = md_path[:-3] + ".html"
            source_text = read_text(ROOT / md_path)
            document = render_markdown(
                source_text,
                lambda href, is_image, _s=md_path, _p=page: self.resolve(href, is_image, _s, _p),
            )
            title = document.title or posixpath.basename(md_path)[:-3]
            description = self._description(source_text, title)
            root = rel_to_root(page)

            toc_links = [
                f'<a class="lvl-{level}" href="#{esc(anchor)}">{esc(text)}</a>'
                for level, anchor, text in document.headings
                if 2 <= level <= 3
            ]
            has_toc = len(toc_links) >= 3
            toc = (
                '<aside class="toc"><strong>En esta pagina</strong>' + "".join(toc_links) + "</aside>"
                if has_toc
                else ""
            )
            crumbs = self._crumbs(md_path, page)
            body = f"""{crumbs}
<div class="doc-layout{' with-toc' if has_toc else ''}">
  <article class="doc">
{document.html}
  </article>
  {toc}
</div>
<p style="margin-top:22px"><a class="btn btn-ghost" href="{esc(TREE_URL + posixpath.dirname(md_path))}" target="_blank" rel="noopener noreferrer">Ver esta carpeta en GitHub &#8599;</a></p>
"""
            self.write(
                page,
                self.shell(page=page, title=title, description=description, body=body, active=""),
            )
            del root

    def _description(self, text: str, title: str) -> str:
        for line in text.splitlines():
            stripped = line.strip()
            if not stripped or stripped.startswith(("#", "[!", "|", ">", "```", "---")):
                continue
            plain = re.sub(r"!\[[^\]]*\]\([^)]*\)", "", stripped)
            plain = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", plain)
            plain = re.sub(r"[*`_]+", "", plain).strip()
            if len(plain) > 40:
                return plain[:250]
        return f"{title} — Problem-Driven Systems Lab."

    def _crumbs(self, md_path: str, page: str) -> str:
        root = rel_to_root(page)
        items = [f'<a href="{esc(root)}index.html">Inicio</a>']
        parts = md_path.split("/")
        if parts[0] == "cases" and len(parts) >= 2:
            case = self._case_by_dir(parts[1])
            items.append(f'<a href="{esc(root)}casos.html">Los 20 casos</a>')
            if case:
                target = f"casos/{case['id']}-{case['slug']}.html"
                items.append(
                    f'<a href="{esc(rel_href(page, target))}">Caso {esc(case["id"])} · {esc(case["title"])}</a>'
                )
            if len(parts) >= 3 and parts[2] in self.lang_by_key:
                items.append(f'<span>{esc(self.lang_by_key[parts[2]]["label"])}</span>')
        else:
            items.append(f'<a href="{esc(root)}documentacion.html">Documentacion</a>')
        items.append(f"<span>{esc(parts[-1][:-3])}</span>")
        return '<nav class="crumbs" aria-label="Migas de pan">' + " › ".join(items) + "</nav>"

    def _case_by_dir(self, dir_name: str) -> dict | None:
        for case in self.cases:
            if f"{case['id']}-{case['slug']}" == dir_name:
                return case
        return None

    # -- piezas reutilizables --------------------------------------------------
    def case_dir(self, case: dict) -> str:
        return f"cases/{case['id']}-{case['slug']}"

    def case_page(self, case: dict) -> str:
        return f"casos/{case['id']}-{case['slug']}.html"

    def case_card(self, case: dict, page: str) -> str:
        stacks = case.get("operational_stacks", [])
        chips = "".join(
            f'<span class="chip">{esc(self.lang_by_key[key]["icon"])} {esc(self.lang_by_key[key]["label"])}</span>'
            for key in stacks
            if key in self.lang_by_key
        )
        return f"""<a class="case-card" href="{esc(rel_href(page, self.case_page(case)))}"
   data-category="{esc(case['category'])}" data-stacks="{esc(' '.join(stacks))}">
  <div class="case-card-head">
    <span class="case-icon">{esc(case['icon'])}</span>
    <div>
      <span class="case-id">Caso {esc(case['id'])} · {esc(case['category'])}</span>
      <h3>{esc(case['title'])}</h3>
    </div>
  </div>
  <p>{md(case['summary'])}</p>
  <div class="chips"><span class="chip ok">{esc(case['status'])}</span>{chips}</div>
</a>"""

    def stats(self) -> list[tuple[str, str, str]]:
        implementations = sum(len(case.get("operational_stacks", [])) for case in self.cases)
        compose_files = sum(
            1 for path in self.all_files if posixpath.basename(path).startswith("compose")
            and path.endswith((".yml", ".yaml"))
        )
        dockerfiles = sum(1 for path in self.all_files if posixpath.basename(path) == "Dockerfile")
        return [
            ("Casos de ingenieria", str(len(self.cases)), "de sintoma a remediacion verificable"),
            ("Lenguajes", str(len(self.languages)), "con la primitiva nativa de cada runtime"),
            ("Implementaciones", str(implementations), "casos x stacks, todas operativas"),
            ("Documentos publicados", str(len(self.md_files)), "cada uno como pagina HTML"),
            ("Imagenes Docker", str(dockerfiles), f"y {compose_files} archivos compose validados en CI"),
        ]

    # -- portada ---------------------------------------------------------------
    def build_index(self) -> None:
        page = "index.html"
        lab = self.catalog["lab"]
        stats = "".join(
            f'<div class="stat"><small>{esc(label)}</small><strong>{esc(value)}</strong><span>{esc(note)}</span></div>'
            for label, value, note in self.stats()
        )
        proves = "".join(
            f'<div class="card"><div class="case-icon">{esc(icon)}</div>'
            f'<h3 style="margin:14px 0 6px;font-size:1.05rem">{esc(title)}</h3>'
            f'<p style="margin:0;color:var(--muted);font-size:0.93rem">{esc(text)}</p></div>'
            for icon, title, text in WHAT_IT_PROVES
        )
        audiences = "".join(
            f'<a class="link-card" href="{esc(audience["document_path"][:-3] + ".html")}">'
            f'<strong><span class="case-icon">{esc(audience["icon"])}</span>{esc(audience["label"])}</strong>'
            f'<span>{md(audience["headline"])}</span>'
            f'<span style="margin-top:10px;color:var(--accent);font-weight:600">'
            f'Empezar por aqui &#8594;</span></a>'
            for audience in self.catalog["audiences"]
            if audience["document_path"] in self.md_set
        )
        languages = "".join(
            f'<a class="link-card" href="{esc(lang["profile_path"][:-3] + ".html")}">'
            f'<strong><span class="case-icon">{esc(lang["icon"])}</span>{esc(lang["version_label"])}</strong>'
            f'<span>{md(lang["execution_model"])}</span>'
            f'<span style="margin-top:8px"><code>{esc(lang["docker_image"])}</code></span></a>'
            for lang in self.languages
            if lang["profile_path"] in self.md_set
        )
        cases = "".join(self.case_card(case, page) for case in self.cases)
        documents = "".join(
            f'<a class="link-card" href="{esc(doc["path"][:-3] + ".html")}">'
            f'<strong><span class="case-icon">{esc(doc["icon"])}</span>{esc(doc_label(doc["path"]))}</strong>'
            f'<span>{md(doc["description"])}</span></a>'
            for doc in self.catalog["documents"]
            if doc["path"] in self.md_set
        )
        pdfs = ""
        for pdf, label in (
            ("dist/problem-driven-systems-lab-dossier-ejecutivo.pdf", "Dossier ejecutivo (PDF)"),
            ("dist/problem-driven-systems-lab-dossier-completo.pdf", "Dossier completo (PDF)"),
        ):
            if pdf in self.all_files:
                self.copy_asset(pdf)
                size_mb = (ROOT / pdf).stat().st_size / 1_048_576
                pdfs += (
                    f'<a class="link-card" href="{esc(pdf)}">'
                    f'<strong><span class="case-icon">&#128196;</span>{esc(label)}</strong>'
                    f'<span>Generado desde el mismo catalogo · {size_mb:.1f} MB</span></a>'
                )

        matrix = ""
        if "docs/assets/stack-matrix.svg" in self.all_files:
            self.copy_asset("docs/assets/stack-matrix.svg")
            matrix = (
                '<div class="card" style="margin-top:18px">'
                '<img src="docs/assets/stack-matrix.svg" '
                'alt="Cobertura real de los 20 casos en los 7 stacks del laboratorio">'
                "</div>"
            )

        body = f"""<section class="hero">
  <span class="eyebrow">Portafolio tecnico &middot; Docker-first &middot; {len(self.languages)} stacks</span>
  <h1>20 problemas reales de ingenieria, resueltos y verificables</h1>
  <p class="hero-sub">{md(lab['tagline'])} Cada caso parte de un sintoma medible, nombra la causa,
  compara opciones y deja evidencia observable — en {esc(str(len(self.languages)))} lenguajes, con la
  primitiva nativa de cada runtime y no con la misma solucion traducida siete veces.</p>
  <div class="cta-row">
    <a class="btn btn-primary" href="casos.html">Ver los {len(self.cases)} casos</a>
    <a class="btn btn-ghost" href="INSTALL.html">Levantarlo en local</a>
    <a class="btn btn-ghost" href="{esc(REPO_URL)}" target="_blank" rel="noopener noreferrer">Codigo en GitHub &#8599;</a>
  </div>
  <div class="stats">{stats}</div>
</section>

<section class="section">
  <div class="section-head">
    <h2>Que demuestra este laboratorio</h2>
    <p>No es una coleccion de demos que devuelven <code>200 OK</code>. Cada caso existe porque
    el problema aparece en produccion y cuesta dinero, tiempo o confianza.</p>
  </div>
  <div class="section-body grid grid-4">{proves}</div>
</section>

<section class="section">
  <div class="section-head">
    <h2>Empieza por tu rol</h2>
    <p>{md(self.catalog['lab']['audience_message'])}</p>
  </div>
  <div class="section-body grid grid-4">{audiences}</div>
</section>

<section class="section">
  <div class="section-head">
    <h2>Los {len(self.cases)} casos</h2>
    <p>Cada ficha abre el problema completo: sintomas, diagnostico, causas raiz, opciones,
    trade-offs, la solucion en los {len(self.languages)} stacks y que mirar para comprobarlo.</p>
  </div>
  <div class="section-body grid grid-3">{cases}</div>
  <p style="margin-top:18px"><a class="btn btn-ghost" href="casos.html">Filtrar por categoria o stack &#8594;</a></p>
</section>

<section class="section">
  <div class="section-head">
    <h2>Los {len(self.languages)} stacks</h2>
    <p>El mismo problema resuelto con la herramienta que cada runtime trae de fabrica.
    Donde un lenguaje no tiene la primitiva, el perfil lo dice en vez de disimularlo.</p>
  </div>
  <div class="section-body grid grid-4">{languages}</div>
  <p style="margin-top:18px"><a class="btn btn-ghost" href="stacks.html">Comparar los {len(self.languages)} stacks &#8594;</a></p>
  {matrix}
</section>

<section class="section">
  <div class="section-head">
    <h2>Como se ejecuta</h2>
    <p>Docker es la via oficial. Un comando levanta el hub de un stack completo con sus
    {len(self.cases)} casos detras de un unico puerto.</p>
  </div>
  <div class="section-body grid grid-2">
    <div class="card">
      <h3 style="margin:0 0 10px;font-size:1.02rem">Laboratorio PHP + portal</h3>
      <div class="codeblock"><pre><code>git clone {esc(REPO_URL)}.git
cd problem-driven-systems-lab
docker compose -f compose.root.yml up -d --build
# portal en http://localhost:8080</code></pre></div>
      <p style="margin:0;color:var(--muted);font-size:0.92rem">El portal es el hub de evaluacion:
      rutas por audiencia, seleccion por lenguaje y probes server-side.</p>
    </div>
    <div class="card">
      <h3 style="margin:0 0 10px;font-size:1.02rem">Cualquier otro stack</h3>
      <div class="codeblock"><pre><code>docker compose -f compose.rust.yml up -d --build
curl -s http://localhost:8700/13/health

docker compose -f compose.go.yml up -d --build
curl -s http://localhost:8600/20/health</code></pre></div>
      <p style="margin:0;color:var(--muted);font-size:0.92rem">Cada stack expone sus
      {len(self.cases)} casos en su propio puerto. Detalle completo en
      <a href="INSTALL.html">INSTALL</a> y <a href="RUNBOOK.html">RUNBOOK</a>.</p>
    </div>
  </div>
</section>

<section class="section">
  <div class="section-head">
    <h2>Que es real y que no</h2>
    <p>La honestidad tecnica es parte del producto. El repositorio declara la frontera
    en vez de dejar que el lector la asuma.</p>
  </div>
  <div class="section-body grid grid-2">
    <div class="card">
      <h3 style="margin:0 0 10px;font-size:1.02rem">&#128269; Fidelidad del substrato</h3>
      <p style="color:var(--muted);font-size:0.94rem">Los casos 01 y 02 ejecutan SQL real contra un
      motor en los {len(self.languages)} stacks: <code>db_hits</code> cuenta ejecuciones, no
      iteraciones de un bucle. La asimetria que queda — solo PHP cruza un socket TCP contra
      PostgreSQL — esta documentada caso por caso.</p>
      <p style="margin:0"><a href="{esc(self.case_page(self.cases[0]))}">Ver el caso 01 &#8594;</a></p>
    </div>
    <div class="card">
      <h3 style="margin:0 0 10px;font-size:1.02rem">&#128274; Postura de seguridad</h3>
      <p style="color:var(--muted);font-size:0.94rem">El laboratorio esta pensado para
      <code>localhost</code>. Lo que el codigo garantiza (prepared statements, allowlists,
      clamping) y lo que no (auth, rate limiting, TLS) esta escrito, no insinuado.</p>
      <p style="margin:0"><a href="SECURITY.html">Leer SECURITY &#8594;</a></p>
    </div>
  </div>
  <div class="note" style="margin-top:16px"><strong>Nota:</strong> este no es un benchmark de
  lenguajes. Comparar tiempos entre stacks aqui seria comparar decisiones de diseno distintas
  bajo cargas distintas. Lo que se compara es <em>como piensa cada runtime el mismo problema</em>.</div>
</section>

<section class="section">
  <div class="section-head">
    <h2>Documentacion</h2>
    <p>Los {len(self.md_files)} documentos del repositorio estan publicados aqui como HTML.
    Ninguno obliga a bajar un <code>.md</code> ni a salir del sitio.</p>
  </div>
  <div class="section-body grid grid-3">{documents}{pdfs}</div>
  <p style="margin-top:18px"><a class="btn btn-ghost" href="documentacion.html">Indice completo &#8594;</a></p>
</section>
"""
        self.write(
            page,
            self.shell(
                page=page,
                title="Problem-Driven Systems Lab",
                description=lab["tagline"],
                body=body,
                active="index.html",
            ),
        )

    # -- catalogo de casos -----------------------------------------------------
    def build_cases_index(self) -> None:
        page = "casos.html"
        categories = sorted({case["category"] for case in self.cases})
        category_buttons = "".join(
            f'<button class="filter" type="button" data-filter="category" data-value="{esc(cat)}"'
            f' aria-pressed="false">{esc(cat)}</button>'
            for cat in categories
        )
        stack_buttons = "".join(
            f'<button class="filter" type="button" data-filter="stack" data-value="{esc(lang["key"])}"'
            f' aria-pressed="false">{esc(lang["icon"])} {esc(lang["label"])}</button>'
            for lang in self.languages
        )
        cards = "".join(self.case_card(case, page) for case in self.cases)
        blurbs = "".join(
            f'<div class="card"><h3 style="margin:0 0 6px;font-size:1rem">{esc(cat)} '
            f'<span class="chip cat">{sum(1 for c in self.cases if c["category"] == cat)}</span></h3>'
            f'<p style="margin:0;color:var(--muted);font-size:0.9rem">{esc(CATEGORY_BLURB.get(cat, ""))}</p></div>'
            for cat in categories
        )
        body = f"""<section style="margin-top:26px">
  <span class="eyebrow">Catalogo</span>
  <h1 class="page-title">Los {len(self.cases)} casos del laboratorio</h1>
  <p class="lead" style="margin-top:14px">Todos operativos en los {len(self.languages)} stacks.
  Filtra por categoria de problema o por lenguaje para ver donde vive cada solucion.</p>
</section>

<section class="section" style="margin-top:34px">
  <div class="grid grid-3">{blurbs}</div>
</section>

<section class="section">
  <div class="filters" role="group" aria-label="Filtrar por categoria">
    <span class="filter-label">Categoria</span>
    <button class="filter" type="button" data-filter="category" data-value="" aria-pressed="true">Todas</button>
    {category_buttons}
  </div>
  <div class="filters" role="group" aria-label="Filtrar por stack">
    <span class="filter-label">Stack</span>
    <button class="filter" type="button" data-filter="stack" data-value="" aria-pressed="true">Todos</button>
    {stack_buttons}
    <span class="filter-count" id="filter-count">{len(self.cases)} casos</span>
  </div>
  <div class="section-body grid grid-3" id="case-grid">{cards}</div>
  <p class="note hidden" id="empty-state" style="margin-top:18px">Ninguna combinacion de filtros
  coincide. Quita uno de los dos para volver a ver casos.</p>
</section>

<script>
// Filtrado en el cliente sin dependencias: las 20 tarjetas ya estan en el HTML,
// asi que el sitio sigue siendo util con JavaScript desactivado.
(function () {{
  var state = {{ category: "", stack: "" }};
  var cards = Array.prototype.slice.call(document.querySelectorAll("#case-grid .case-card"));
  var counter = document.getElementById("filter-count");
  var empty = document.getElementById("empty-state");

  function apply() {{
    var visible = 0;
    cards.forEach(function (card) {{
      var okCategory = !state.category || card.dataset.category === state.category;
      var okStack = !state.stack || (" " + card.dataset.stacks + " ").indexOf(" " + state.stack + " ") >= 0;
      var show = okCategory && okStack;
      card.classList.toggle("hidden", !show);
      if (show) visible++;
    }});
    counter.textContent = visible + (visible === 1 ? " caso" : " casos");
    empty.classList.toggle("hidden", visible !== 0);
  }}

  document.querySelectorAll(".filter").forEach(function (button) {{
    button.addEventListener("click", function () {{
      var kind = button.dataset.filter;
      state[kind] = button.dataset.value;
      document.querySelectorAll('.filter[data-filter="' + kind + '"]').forEach(function (other) {{
        other.setAttribute("aria-pressed", String(other === button));
      }});
      apply();
    }});
  }});
  apply();
}})();
</script>
"""
        self.write(
            page,
            self.shell(
                page=page,
                title=f"Los {len(self.cases)} casos",
                description=(
                    f"Catalogo completo de los {len(self.cases)} casos del laboratorio, "
                    f"operativos en {len(self.languages)} stacks, con filtros por categoria y lenguaje."
                ),
                body=body,
                active="casos.html",
            ),
        )

    # -- ficha de caso ---------------------------------------------------------
    def build_case_pages(self) -> None:
        case_docs = [
            ("context.md", "Contexto", "El sistema y su realidad operativa antes del problema."),
            ("symptoms.md", "Sintomas", "Que se observa desde fuera, con numeros."),
            ("diagnosis.md", "Diagnostico", "Como se aisla la causa sin adivinar."),
            ("root-causes.md", "Causas raiz", "Por que ocurre, no solo donde duele."),
            ("solution-options.md", "Opciones", "Las alternativas reales y su coste."),
            ("trade-offs.md", "Trade-offs", "Que se gana y que se paga con la opcion elegida."),
            ("business-value.md", "Valor de negocio", "Que cambia para quien paga el sistema."),
            ("observability.md", "Observabilidad", "Que metricas hacen visible el problema."),
            ("benchmarking.md", "Medicion", "Como se mide el antes y el despues."),
            ("postmortem.md", "Postmortem", "La historia completa del incidente modelado."),
        ]

        for index, case in enumerate(self.cases):
            page = self.case_page(case)
            directory = self.case_dir(case)
            root = rel_to_root(page)

            proof = "".join(f"<li>{md(item)}</li>" for item in case.get("proof_points", []))
            look = "".join(f"<li>{md(item)}</li>" for item in case.get("look_for", []))

            rows = []
            for key in case.get("operational_stacks", []):
                lang = self.lang_by_key.get(key)
                entry = case.get("runtime_entries", {}).get(key, {})
                if not lang or not entry:
                    continue
                readme = entry.get("readme_path", "")
                readme_link = (
                    f'<a href="{esc(rel_href(page, readme[:-3] + ".html"))}">README del stack</a>'
                    if readme in self.md_set
                    else "&mdash;"
                )
                compose = entry.get("compose_path", "")
                compose_link = (
                    f'<a href="{esc(BLOB_URL + compose)}" target="_blank" rel="noopener noreferrer">'
                    f"<code>{esc(compose)}</code></a>"
                    if compose
                    else "&mdash;"
                )
                port = entry.get("port", "")
                probe = (
                    f"<code>http://localhost:{esc(port)}{esc(entry.get('health_path', '/health'))}</code>"
                    if port
                    else "&mdash;"
                )
                rows.append(
                    f"<tr><td><strong>{esc(lang['icon'])} {esc(lang['version_label'])}</strong></td>"
                    f"<td>{probe}</td><td>{compose_link}</td><td>{readme_link}</td></tr>"
                )

            doc_links = []
            for filename, label, blurb in case_docs:
                doc_path = f"{directory}/docs/{filename}"
                if doc_path in self.md_set:
                    doc_links.append(
                        f'<a class="link-card" href="{esc(rel_href(page, doc_path[:-3] + ".html"))}">'
                        f"<strong>{esc(label)}</strong><span>{esc(blurb)}</span></a>"
                    )
            comparison = f"{directory}/comparison.md"
            if comparison in self.md_set:
                doc_links.append(
                    f'<a class="link-card" href="{esc(rel_href(page, comparison[:-3] + ".html"))}">'
                    "<strong>Comparativa entre stacks</strong>"
                    "<span>El veredicto por runtime: que primitiva resuelve el caso y a que precio.</span></a>"
                )

            readme_path = case.get("case_readme_path", f"{directory}/README.md")
            readme_href = (
                rel_href(page, readme_path[:-3] + ".html") if readme_path in self.md_set else ""
            )

            previous_case = self.cases[index - 1] if index > 0 else None
            next_case = self.cases[index + 1] if index + 1 < len(self.cases) else None
            pager = []
            if previous_case:
                pager.append(
                    f'<a class="btn btn-ghost" href="{esc(rel_href(page, self.case_page(previous_case)))}">'
                    f'&#8592; Caso {esc(previous_case["id"])}</a>'
                )
            if next_case:
                pager.append(
                    f'<a class="btn btn-ghost" href="{esc(rel_href(page, self.case_page(next_case)))}">'
                    f'Caso {esc(next_case["id"])} &#8594;</a>'
                )

            body = f"""<nav class="crumbs" aria-label="Migas de pan">
  <a href="{esc(root)}index.html">Inicio</a> ›
  <a href="{esc(root)}casos.html">Los {len(self.cases)} casos</a> ›
  <span>Caso {esc(case['id'])}</span>
</nav>

<section class="hero" style="margin-top:16px">
  <span class="eyebrow">{esc(case['icon'])} Caso {esc(case['id'])} &middot; {esc(case['category'])}</span>
  <h1 style="max-width:22ch">{esc(case['title'])}</h1>
  <p class="hero-sub">{md(case['summary'])}</p>
  <div class="chips" style="margin-top:20px">
    <span class="chip ok">{esc(case['status'])}</span>
    {"".join(f'<span class="chip">{esc(self.lang_by_key[k]["icon"])} {esc(self.lang_by_key[k]["label"])}</span>' for k in case.get('operational_stacks', []) if k in self.lang_by_key)}
  </div>
  <div class="cta-row">
    {f'<a class="btn btn-primary" href="{esc(readme_href)}">Leer el caso completo</a>' if readme_href else ''}
    <a class="btn btn-ghost" href="{esc(TREE_URL + directory)}" target="_blank" rel="noopener noreferrer">Codigo del caso &#8599;</a>
  </div>
</section>

<section class="section">
  <div class="grid grid-2">
    <div class="card">
      <h3 style="margin:0 0 10px;font-size:1.05rem">&#128200; Que cambia para el negocio</h3>
      <p style="margin:0;color:var(--muted)">{md(case.get('business_outcome', ''))}</p>
    </div>
    <div class="card">
      <h3 style="margin:0 0 10px;font-size:1.05rem">&#128188; Que demuestra tecnicamente</h3>
      <p style="margin:0;color:var(--muted)">{md(case.get('recruiter_pitch', ''))}</p>
    </div>
  </div>
</section>

<section class="section">
  <div class="grid grid-2">
    <div class="card">
      <h3 style="margin:0 0 10px;font-size:1.05rem">&#9989; Evidencia que deja</h3>
      <ul style="margin:0;padding-left:20px;color:var(--muted)">{proof}</ul>
    </div>
    <div class="card">
      <h3 style="margin:0 0 10px;font-size:1.05rem">&#128064; Que mirar al ejecutarlo</h3>
      <ul style="margin:0;padding-left:20px;color:var(--muted)">{look}</ul>
    </div>
  </div>
  <div class="note" style="margin-top:16px"><strong>Honestidad:</strong> {md(case.get('honesty_note', ''))}</div>
</section>

<section class="section">
  <div class="section-head">
    <h2>Como esta resuelto en cada stack</h2>
    <p>{md(case.get('level_detail', ''))}</p>
  </div>
  <div class="section-body tablewrap"><table>
    <thead><tr><th>Stack</th><th>Health check local</th><th>Compose</th><th>Detalle</th></tr></thead>
    <tbody>{"".join(rows)}</tbody>
  </table></div>
</section>

<section class="section">
  <div class="section-head">
    <h2>El expediente completo</h2>
    <p>El caso no empieza en el codigo: empieza en el sintoma y termina en el postmortem.</p>
  </div>
  <div class="section-body grid grid-3">{"".join(doc_links)}</div>
</section>

<div class="cta-row" style="margin-top:36px">{"".join(pager)}</div>
"""
            self.write(
                page,
                self.shell(
                    page=page,
                    title=f"Caso {case['id']} · {case['title']}",
                    description=case["summary"],
                    body=body,
                    active="casos.html",
                ),
            )

    # -- stacks ----------------------------------------------------------------
    def build_stacks(self) -> None:
        page = "stacks.html"
        cards = []
        for lang in self.languages:
            profile = lang["profile_path"]
            profile_href = (
                f'<a class="btn btn-ghost" href="{esc(rel_href(page, profile[:-3] + ".html"))}">'
                "Perfil del lenguaje &#8594;</a>"
                if profile in self.md_set
                else ""
            )
            covered = sum(1 for case in self.cases if lang["key"] in case.get("operational_stacks", []))
            cards.append(
                f"""<div class="card">
  <div class="case-card-head">
    <span class="case-icon">{esc(lang['icon'])}</span>
    <div>
      <span class="case-id">Hub en el puerto {esc(lang['hub_port'])}</span>
      <h3 style="margin:2px 0 0;font-size:1.1rem">{esc(lang['version_label'])}</h3>
    </div>
  </div>
  <p style="margin:14px 0 0;color:var(--muted)"><strong>Modelo de ejecucion:</strong> {md(lang['execution_model'])}</p>
  <p style="margin:10px 0 0;color:var(--muted)">{md(lang['headline'])}</p>
  <p style="margin:10px 0 0;color:var(--muted);font-size:0.92rem">{md(lang['note'])}</p>
  <div class="chips" style="margin-top:14px">
    <span class="chip ok">{covered}/{len(self.cases)} casos</span>
    <span class="chip"><code>{esc(lang['docker_image'])}</code></span>
  </div>
  <div class="codeblock" style="margin-top:14px"><pre><code>docker compose -f {esc(self._compose_for(lang['key']))} up -d --build
curl -s http://localhost:{esc(lang['hub_port'])}/01/health</code></pre></div>
  {profile_href}
</div>"""
            )

        matrix = ""
        for asset, alt in (
            ("docs/assets/stack-matrix.svg", "Cobertura de los 20 casos en los 7 stacks"),
            ("docs/assets/execution-models.svg", "Modelos de ejecucion comparados por stack"),
        ):
            if asset in self.all_files:
                self.copy_asset(asset)
                matrix += (
                    f'<div class="card" style="margin-top:18px">'
                    f'<img src="{esc(asset)}" alt="{esc(alt)}"></div>'
                )

        body = f"""<section style="margin-top:26px">
  <span class="eyebrow">Runtimes</span>
  <h1 class="page-title">Los {len(self.languages)} stacks del laboratorio</h1>
  <p class="lead" style="margin-top:14px">El mismo problema, la primitiva nativa de cada runtime.
  Donde un lenguaje no trae la herramienta — el <code>RwLock</code> sin deadline de Rust, el pool
  que Go no tiene — el perfil lo dice en vez de disimularlo con una capa propia.</p>
</section>

<section class="section" style="margin-top:34px">
  <div class="grid grid-2">{"".join(cards)}</div>
</section>

<section class="section">
  <div class="section-head">
    <h2>Cobertura real</h2>
    <p>Los diagramas se generan desde el mismo catalogo que alimenta esta pagina, con
    <code>scripts/generate_diagrams.py</code>. Un octavo stack los redibuja solo.</p>
  </div>
  {matrix}
</section>
"""
        self.write(
            page,
            self.shell(
                page=page,
                title=f"Los {len(self.languages)} stacks",
                description=(
                    f"Los {len(self.languages)} runtimes del laboratorio: modelo de ejecucion, "
                    "imagen Docker, puerto del hub y cobertura real de casos."
                ),
                body=body,
                active="stacks.html",
            ),
        )

    def _compose_for(self, key: str) -> str:
        mapping = {
            "php": "compose.root.yml",
            "python": "compose.python.yml",
            "node": "compose.nodejs.yml",
            "java": "compose.java.yml",
            "dotnet": "compose.dotnet.yml",
            "go": "compose.go.yml",
            "rust": "compose.rust.yml",
        }
        return mapping.get(key, "compose.root.yml")

    # -- indice de documentacion ----------------------------------------------
    def build_docs_index(self) -> None:
        page = "documentacion.html"
        groups: dict[str, list[str]] = {}
        # Cada grupo recorta su propio prefijo: dentro del caso 01 el documento
        # util se llama `docs/diagnosis`, no
        # `cases/01-api-latency-under-load/docs/diagnosis` repetido veinte veces.
        prefixes: dict[str, str] = {}
        for md_path in self.md_files:
            parts = md_path.split("/")
            if len(parts) == 1:
                group, prefix = "Raiz del repositorio", ""
            elif parts[0] == "cases":
                case = self._case_by_dir(parts[1])
                group = f"Caso {case['id']} · {case['title']}" if case else "Casos"
                prefix = f"cases/{parts[1]}/" if case else "cases/"
            elif parts[0] == "docs" and len(parts) > 2:
                group, prefix = f"docs/{parts[1]}", f"docs/{parts[1]}/"
            else:
                group, prefix = parts[0] + "/", parts[0] + "/"
            groups.setdefault(group, []).append(md_path)
            prefixes[group] = prefix

        def sort_key(name: str) -> tuple[int, str]:
            if name == "Raiz del repositorio":
                return (0, "")
            if name.startswith("docs"):
                return (1, name)
            if name.startswith("Caso"):
                return (3, name)
            return (2, name)

        sections = []
        for group in sorted(groups, key=sort_key):
            prefix = prefixes[group]
            items = "".join(
                f'<li><a href="{esc(path[:-3] + ".html")}">'
                f'{esc(path[len(prefix):-3] if path.startswith(prefix) else path[:-3])}</a></li>'
                for path in sorted(groups[group])
            )
            sections.append(
                f'<div class="card"><h3 style="margin:0 0 10px;font-size:1rem">{esc(group)} '
                f'<span class="chip">{len(groups[group])}</span></h3>'
                f'<ul style="margin:0;padding-left:18px;font-size:0.9rem">{items}</ul></div>'
            )

        body = f"""<section style="margin-top:26px">
  <span class="eyebrow">Indice</span>
  <h1 class="page-title">Los {len(self.md_files)} documentos, publicados como HTML</h1>
  <p class="lead" style="margin-top:14px">Todo el Markdown del repositorio esta renderizado aqui,
  en su misma ruta y con sus enlaces internos reescritos. Ningun enlace de este sitio te obliga a
  descargar un <code>.md</code> ni a salir a GitHub para leer.</p>
</section>

<section class="section" style="margin-top:34px">
  <div class="grid grid-3">{"".join(sections)}</div>
</section>
"""
        self.write(
            page,
            self.shell(
                page=page,
                title="Documentacion",
                description=(
                    f"Indice de los {len(self.md_files)} documentos del laboratorio, "
                    "publicados como paginas HTML navegables."
                ),
                body=body,
                active="documentacion.html",
            ),
        )

    # -- paginas auxiliares ----------------------------------------------------
    def build_extras(self) -> None:
        body = """<section class="hero" style="margin-top:24px">
  <span class="eyebrow">Error 404</span>
  <h1>Esta pagina no existe</h1>
  <p class="hero-sub">Puede que el enlace venga de una version anterior del laboratorio o que
  apunte a un archivo que ya no se publica.</p>
  <div class="cta-row">
    <a class="btn btn-primary" href="/problem-driven-systems-lab/">Volver a la portada</a>
    <a class="btn btn-ghost" href="/problem-driven-systems-lab/casos.html">Ver los casos</a>
    <a class="btn btn-ghost" href="/problem-driven-systems-lab/documentacion.html">Indice de documentacion</a>
  </div>
</section>"""
        # GitHub Pages sirve esta pagina sin cambiar la URL, asi que un 404 en
        # `/cases/01-x/loquesea.html` resolveria los enlaces relativos contra esa
        # carpeta inexistente. `<base>` los ancla a la raiz del sitio.
        self.write(
            "404.html",
            self.shell(
                page="404.html",
                title="Pagina no encontrada",
                description="La pagina solicitada no existe en el sitio del laboratorio.",
                body=body,
                extra_head='<base href="/problem-driven-systems-lab/">\n',
            ),
        )
        self.pages.remove("404.html")  # no entra al sitemap ni al chequeo de enlaces

        # La licencia no es Markdown, pero el pie la enlaza en todas las paginas:
        # publicarla como HTML evita el unico enlace del sitio que saldria a GitHub
        # para leer un texto plano.
        license_path = ROOT / "LICENSE"
        if license_path.is_file():
            license_body = (
                '<section style="margin-top:26px"><span class="eyebrow">Licencia</span>'
                '<h1 class="page-title">MIT License</h1></section>'
                '<article class="doc"><div class="codeblock"><pre><code>'
                + html.escape(read_text(license_path), quote=False)
                + "</code></pre></div></article>"
            )
            self.write(
                "LICENSE.html",
                self.shell(
                    page="LICENSE.html",
                    title="Licencia MIT",
                    description="Licencia MIT del laboratorio Problem-Driven Systems Lab.",
                    body=license_body,
                    wide=False,
                ),
            )

        (self.out / ".nojekyll").write_text("", encoding="utf-8")
        (self.out / "robots.txt").write_text(
            f"User-agent: *\nAllow: /\nSitemap: {BASE_URL}sitemap.xml\n",
            encoding="utf-8",
            newline="\n",
        )

        urls = "".join(
            "  <url><loc>"
            + BASE_URL
            + ("" if page == "index.html" else page)
            + f"</loc><lastmod>{self.today}</lastmod></url>\n"
            for page in sorted(self.pages)
        )
        self.write_raw(
            "sitemap.xml",
            '<?xml version="1.0" encoding="UTF-8"?>\n'
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
            + urls
            + "</urlset>\n",
        )

    def write_raw(self, site_path: str, content: str) -> None:
        target = self.out / site_path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content, encoding="utf-8", newline="\n")

    def copy_static(self) -> None:
        if not SITE_SRC.is_dir():
            raise SystemExit(f"falta {SITE_SRC}")
        for path in SITE_SRC.rglob("*"):
            if path.is_file():
                relative = path.relative_to(SITE_SRC).as_posix()
                target = self.out / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(path, target)

    # -- orquestacion ----------------------------------------------------------
    def build(self) -> int:
        # Se vacia el contenido en vez de borrar el directorio: en Windows un
        # servidor local con el cwd dentro de `site/` bloquea el rmdir y haria
        # fallar un build que no tiene nada de malo.
        self.out.mkdir(parents=True, exist_ok=True)
        for child in self.out.iterdir():
            shutil.rmtree(child) if child.is_dir() else child.unlink()
        self.copy_static()
        self.build_documents()
        self.build_index()
        self.build_cases_index()
        self.build_case_pages()
        self.build_stacks()
        self.build_docs_index()
        self.build_extras()

        print(f"paginas HTML        : {len(self.pages) + 1}")
        print(f"documentos markdown : {len(self.md_files)}")
        print(f"assets copiados     : {len(self.copied_assets)}")
        if self.broken_sources:
            print(f"\nenlaces sin destino en el repositorio: {len(self.broken_sources)}")
            for source, href in self.broken_sources[:40]:
                print(f"  {source} -> {href}")
            return 1
        return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--out",
        default=str(ROOT / "site"),
        help="directorio de salida (por defecto: site/)",
    )
    args = parser.parse_args()
    return SiteBuilder(Path(args.out).resolve()).build()


if __name__ == "__main__":
    raise SystemExit(main())
