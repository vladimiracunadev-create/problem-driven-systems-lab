#!/usr/bin/env python3
"""Genera los diagramas SVG de `docs/assets/` desde `shared/catalog/cases.json`.

Por que existe: un diagrama dibujado a mano es la primera cosa que miente cuando
el repositorio crece. El lab paso de 5 a 7 stacks y cualquier PNG exportado de
una herramienta externa habria quedado mostrando cinco columnas para siempre,
sin que ningun linter lo notara.

Aqui los diagramas se derivan del mismo catalogo que alimenta al portal y a
`docs/case-catalog.md`. Si manana entra un octavo stack, se agrega al JSON y los
cuatro diagramas se redibujan con `python scripts/generate_diagrams.py`.

`--check` no escribe: falla si algun SVG del repo difiere de lo que el catalogo
dice hoy. Es lo que corre en CI.

Sin dependencias externas: se emite SVG como texto. El SVG se ve en GitHub, en
el portal y se embebe en el PDF del dossier via `svglib`.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path
from xml.sax.saxutils import escape

ROOT = Path(__file__).resolve().parent.parent
CATALOG = ROOT / "shared" / "catalog" / "cases.json"
OUT_DIR = ROOT / "docs" / "assets"

# Paleta fija con fondo claro explicito. GitHub no aplica `prefers-color-scheme`
# a un <img> de SVG referenciado desde markdown, asi que un diagrama "adaptable"
# terminaria siendo texto oscuro sobre fondo transparente e ilegible en el tema
# oscuro. Fondo propio = legible en ambos temas.
BG = "#ffffff"
PANEL = "#f1f5f9"
INK = "#0f172a"
MUTED = "#64748b"
LINE = "#cbd5e1"
ACCENT = "#2563eb"
OK = "#16a34a"
WARN = "#d97706"

# Color de identidad por stack. No es decoracion: permite seguir una misma
# columna entre los cuatro diagramas.
STACK_COLOR = {
    "php": "#6f7fb5",
    "python": "#3b7cb8",
    "node": "#4b9c46",
    "java": "#c4622d",
    "dotnet": "#6c4bb6",
    "go": "#2a9bb5",
    "rust": "#a8453a",
}

CATEGORY_COLOR = {
    "Rendimiento": "#2563eb",
    "Observabilidad": "#7c3aed",
    "Resiliencia": "#d97706",
    "Arquitectura": "#0891b2",
    "Entrega": "#16a34a",
    "Operaciones": "#be185d",
}

FONT = "Segoe UI, Helvetica Neue, Arial, sans-serif"
MONO = "SFMono-Regular, Consolas, Liberation Mono, monospace"


# --------------------------------------------------------------------------
# primitivas de dibujo
# --------------------------------------------------------------------------
def rect(x, y, w, h, fill, *, r=8, stroke="none", sw=1, opacity=None) -> str:
    op = f' opacity="{opacity}"' if opacity is not None else ""
    return (
        f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{r}" ry="{r}" '
        f'fill="{fill}" stroke="{stroke}" stroke-width="{sw}"{op}/>'
    )


def text(x, y, s, *, size=13, fill=INK, anchor="start", weight="400", font=FONT, spacing=None) -> str:
    ls = f' letter-spacing="{spacing}"' if spacing else ""
    return (
        f'<text x="{x}" y="{y}" font-family="{font}" font-size="{size}" '
        f'fill="{fill}" text-anchor="{anchor}" font-weight="{weight}"{ls}>{escape(s)}</text>'
    )


def line(x1, y1, x2, y2, *, stroke=LINE, sw=1.5, dash=None) -> str:
    d = f' stroke-dasharray="{dash}"' if dash else ""
    return f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="{stroke}" stroke-width="{sw}"{d}/>'


def arrow(x1, y1, x2, y2, *, stroke=ACCENT, sw=2) -> str:
    """Flecha horizontal con punta dibujada como poligono, no como `marker`.

    `svglib` —el conversor que usa el PDF del dossier— ignora `marker-end`, asi
    que un diagrama que dependa de marcadores se ve bien en GitHub y pierde
    todas las puntas al imprimirse. El poligono explicito sobrevive a los dos.
    """
    head = 8
    return (
        f'<line x1="{x1}" y1="{y1}" x2="{x2 - head}" y2="{y2}" stroke="{stroke}" stroke-width="{sw}"/>'
        f'<polygon points="{x2},{y2} {x2 - head},{y2 - 4.5} {x2 - head},{y2 + 4.5}" fill="{stroke}"/>'
    )


def clip(s: str, limit: int) -> str:
    """Recorta respetando la palabra. Cortar a mitad de palabra convierte
    'GC generacional' en 'GC generacion', que dice algo distinto."""
    if len(s) <= limit:
        return s
    corte = s[:limit].rsplit(" ", 1)[0]
    return corte + "…"


def wrap(s: str, limit: int, lines: int = 2) -> list[str]:
    """Parte en `lines` renglones de a lo sumo `limit` caracteres."""
    palabras, out, actual = s.split(), [], ""
    for w in palabras:
        cand = f"{actual} {w}".strip()
        if len(cand) <= limit:
            actual = cand
        else:
            out.append(actual)
            actual = w
            if len(out) == lines - 1:
                break
    resto = " ".join([actual] + palabras[len(" ".join(out + [actual]).split()) :]).strip()
    out.append(clip(resto, limit))
    return out[:lines]


def svg(width: int, height: int, body: str, title: str) -> str:
    """Envoltorio con defs, fondo y titulo accesible."""
    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} {height}" '
        f'width="{width}" height="{height}" role="img" aria-label="{escape(title)}">\n'
        f"  <title>{escape(title)}</title>\n"
        "  <defs>\n"
        '    <marker id="arrowhead" markerWidth="9" markerHeight="7" refX="8" refY="3.5" orient="auto">\n'
        f'      <polygon points="0 0, 9 3.5, 0 7" fill="{ACCENT}"/>\n'
        "    </marker>\n"
        "  </defs>\n"
        f"  {rect(0, 0, width, height, BG, r=0)}\n"
        f"{body}\n"
        "</svg>\n"
    )


def header(x: int, y: int, title: str, subtitle: str) -> str:
    return (
        text(x, y, title, size=19, weight="600")
        + "\n"
        + text(x, y + 21, subtitle, size=12.5, fill=MUTED)
    )


def load() -> dict:
    return json.loads(CATALOG.read_text(encoding="utf-8"))


# --------------------------------------------------------------------------
# 1. matriz casos x stacks
# --------------------------------------------------------------------------
def diagram_stack_matrix(cat: dict) -> str:
    langs = cat["languages"]
    cases = sorted(cat["cases"], key=lambda c: c["id"])

    left, top = 34, 128
    col_w, row_h = 96, 40
    label_w = 336
    width = left + label_w + col_w * len(langs) + 34
    height = top + row_h * len(cases) + 96

    p = [
        header(
            left,
            42,
            f"Cobertura real: {len(cases)} casos x {len(langs)} stacks",
            "Cada celda marcada es codigo que levanta con Docker y resuelve el caso con la primitiva idiomatica del runtime.",
        )
    ]

    # cabecera de columnas
    for i, lg in enumerate(langs):
        cx = left + label_w + i * col_w + col_w / 2
        color = STACK_COLOR.get(lg["key"], ACCENT)
        p.append(rect(left + label_w + i * col_w + 5, top - 46, col_w - 10, 38, color, r=7))
        p.append(text(cx, top - 28, lg["label"], size=12.5, fill="#ffffff", anchor="middle", weight="600"))
        p.append(text(cx, top - 14, lg["version"], size=10.5, fill="#ffffff", anchor="middle", font=MONO))

    for r, c in enumerate(cases):
        y = top + r * row_h
        if r % 2 == 0:
            p.append(rect(left, y, label_w + col_w * len(langs), row_h, PANEL, r=5))

        cat_color = CATEGORY_COLOR.get(c["category"], MUTED)
        p.append(rect(left + 6, y + 9, 4, row_h - 18, cat_color, r=2))
        p.append(text(left + 20, y + 20, c["id"], size=12, fill=MUTED, weight="600", font=MONO))
        p.append(text(left + 46, y + 20, clip(c["title"], 44), size=12.5))
        p.append(text(left + 46, y + 33, c["category"], size=10.5, fill=cat_color, weight="600"))

        for i, lg in enumerate(langs):
            cx = left + label_w + i * col_w + col_w / 2
            cy = y + row_h / 2
            if lg["key"] in c.get("operational_stacks", []):
                color = STACK_COLOR.get(lg["key"], ACCENT)
                p.append(f'<circle cx="{cx}" cy="{cy}" r="9" fill="{color}"/>')
                p.append(
                    f'<path d="M {cx-4.2} {cy} l 3 3.2 l 5.4 -6" stroke="#ffffff" '
                    'stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
                )
            else:
                p.append(f'<circle cx="{cx}" cy="{cy}" r="8" fill="none" stroke="{LINE}" stroke-width="1.5"/>')

    fy = top + row_h * len(cases) + 30
    total = len(cases) * len(langs)
    hechos = sum(len(c.get("operational_stacks", [])) for c in cases)
    p.append(rect(left, fy, label_w + col_w * len(langs), 44, PANEL, r=8))
    p.append(
        text(
            left + 16,
            fy + 20,
            f"{hechos} de {total} implementaciones operativas ({hechos * 100 // total}%).",
            size=12.5,
            weight="600",
        )
    )
    p.append(
        text(
            left + 16,
            fy + 35,
            "Paridad funcional, no sintactica: el mismo problema resuelto con la herramienta que cada runtime considera correcta.",
            size=11,
            fill=MUTED,
        )
    )
    return svg(width, height, "\n".join("  " + s for s in p), "Matriz de cobertura de casos por stack")


# --------------------------------------------------------------------------
# 2. mapa de casos por categoria
# --------------------------------------------------------------------------
def diagram_case_map(cat: dict) -> str:
    cases = sorted(cat["cases"], key=lambda c: c["id"])
    grupos: dict[str, list] = {}
    for c in cases:
        grupos.setdefault(c["category"], []).append(c)
    orden = ["Rendimiento", "Resiliencia", "Observabilidad", "Arquitectura", "Entrega", "Operaciones"]
    grupos = {k: grupos[k] for k in orden if k in grupos}

    left = 34
    card_w, card_h, gap = 236, 92, 12
    cols = 3
    width = left * 2 + card_w * cols + gap * (cols - 1)

    y = 104
    p = [
        header(
            left,
            42,
            f"Los {len(cases)} problemas, agrupados por naturaleza",
            "El laboratorio se organiza por problema, no por tecnologia. La tecnologia es la variable, el problema es la constante.",
        )
    ]

    for categoria, items in grupos.items():
        color = CATEGORY_COLOR.get(categoria, MUTED)
        p.append(rect(left, y, 6, 22, color, r=3))
        p.append(text(left + 16, y + 17, categoria.upper(), size=12.5, fill=color, weight="700", spacing="0.6"))
        plural = "caso" if len(items) == 1 else "casos"
        p.append(text(left + 16 + len(categoria) * 8.6 + 14, y + 17, f"{len(items)} {plural}", size=11.5, fill=MUTED))
        y += 32

        for idx, c in enumerate(items):
            cx = left + (idx % cols) * (card_w + gap)
            cy = y + (idx // cols) * (card_h + gap)
            p.append(rect(cx, cy, card_w, card_h, PANEL, r=9))
            p.append(rect(cx, cy, 4, card_h, color, r=2))
            p.append(text(cx + 16, cy + 22, f"Caso {c['id']}", size=11, fill=color, weight="700", font=MONO))
            for j, ln in enumerate(wrap(c["title"], 30, 2)):
                p.append(text(cx + 16, cy + 41 + j * 16, ln, size=12.5, weight="600"))
            n = len(c.get("operational_stacks", []))
            p.append(text(cx + 16, cy + 79, f"{n} stacks operativos", size=10.5, fill=MUTED))
        filas = (len(items) + cols - 1) // cols
        y += filas * (card_h + gap) + 16

    return svg(width, y + 16, "\n".join("  " + s for s in p), "Mapa de los 12 casos por categoria")


# --------------------------------------------------------------------------
# 3. flujo del protocolo de actualizacion de lenguaje
# --------------------------------------------------------------------------
def diagram_upgrade_flow(cat: dict) -> str:
    width, height = 1000, 600
    left = 34
    p = [
        header(
            left,
            42,
            "Protocolo: que pasa cuando un lenguaje publica version nueva",
            "La deteccion es automatica. La decision es humana. El repositorio nunca se sube de version solo.",
        )
    ]

    etapas = [
        ("1", "Deteccion", "language-drift.yml\nlunes 06:00 UTC", ACCENT),
        ("2", "Triage", "Issue unico con tabla\nfijada vs upstream", WARN),
        ("3", "Revision", "Codigo + docs de\nese stack", "#7c3aed"),
        ("4", "Cierre", "PR con bump o nota\nde 'no aplica'", OK),
    ]
    bw, bh = 210, 92
    gap = 30
    y = 106
    for i, (num, titulo, detalle, color) in enumerate(etapas):
        x = left + i * (bw + gap)
        p.append(rect(x, y, bw, bh, "#ffffff", r=10, stroke=color, sw=2))
        p.append(f'<circle cx="{x + 24}" cy="{y + 24}" r="13" fill="{color}"/>')
        p.append(text(x + 24, y + 29, num, size=13, fill="#ffffff", anchor="middle", weight="700"))
        p.append(text(x + 46, y + 29, titulo, size=14, weight="600"))
        for j, ln in enumerate(detalle.split("\n")):
            p.append(text(x + 16, y + 56 + j * 15, ln, size=11, fill=MUTED, font=MONO))
        if i < len(etapas) - 1:
            p.append(arrow(x + bw + 6, y + bh / 2, x + bw + gap - 6, y + bh / 2))

    # que se revisa en la etapa 3
    ry = y + bh + 44
    p.append(text(left, ry, "Que se revisa en la etapa 3 — y por que no basta con cambiar el Dockerfile", size=14, weight="600"))
    ry += 14

    revisiones = [
        ("Dockerfile", "Todos los casos del stack y el hub. Uno solo desalineado y el mismo caso corre en dos runtimes.", ACCENT),
        ("Codigo del caso", "¿La primitiva que el caso enseña sigue siendo idiomatica, o el lenguaje ya la reemplazo?", "#7c3aed"),
        ("comparison.md", "La comparativa entre stacks del caso afectado. Es lo que un lector usa para decidir.", WARN),
        ("docs/languages/", "Perfil del lenguaje: version, primitivas y limitaciones que quiza dejaron de ser ciertas.", "#0891b2"),
        ("README + stack-map", "Las tablas de version visibles. check-language-versions.sh falla si divergen.", OK),
        ("CHANGELOG", "Por que se subio, o por que se decidio no subir. La decision vale mas que el numero.", MUTED),
    ]
    bw2, bh2 = 470, 58
    for i, (titulo, detalle, color) in enumerate(revisiones):
        x = left + (i % 2) * (bw2 + 22)
        yy = ry + (i // 2) * (bh2 + 12) + 14
        p.append(rect(x, yy, bw2, bh2, PANEL, r=8))
        p.append(rect(x, yy, 4, bh2, color, r=2))
        p.append(text(x + 16, yy + 22, titulo, size=12.5, weight="600", font=MONO))
        p.append(text(x + 16, yy + 41, clip(detalle, 92), size=10.8, fill=MUTED))

    fy = ry + 3 * (bh2 + 12) + 28
    p.append(rect(left, fy, bw2 * 2 + 22, 40, "#fef3c7", r=8))
    p.append(
        text(
            left + 16,
            fy + 25,
            "Regla: un bump de version mayor automatico rompe contenido didactico antes que arreglarlo. El issue informa; la persona decide.",
            size=11.5,
            fill="#78350f",
        )
    )
    return svg(width, fy + 74, "\n".join("  " + s for s in p), "Protocolo de actualizacion por version de lenguaje")


# --------------------------------------------------------------------------
# 4. modelos de ejecucion comparados
# --------------------------------------------------------------------------
def diagram_execution_models(cat: dict) -> str:
    langs = cat["languages"]
    left = 34
    row_h = 86
    width = 1000
    height = 118 + row_h * len(langs) + 40

    p = [
        header(
            left,
            42,
            "Un problema, siete modelos de ejecucion",
            "Por que el mismo caso se resuelve distinto en cada stack: el modelo de ejecucion cambia que primitiva es la correcta.",
        )
    ]

    for i, lg in enumerate(langs):
        y = 112 + i * row_h
        color = STACK_COLOR.get(lg["key"], ACCENT)
        p.append(rect(left, y, width - left * 2, row_h - 12, PANEL, r=9))
        p.append(rect(left, y, 5, row_h - 12, color, r=2))
        p.append(text(left + 20, y + 26, lg["label"], size=15, weight="600", fill=color))
        p.append(text(left + 20, y + 45, lg["version_label"], size=11, fill=MUTED, font=MONO))
        p.append(text(left + 20, y + 62, f"hub :{lg['hub_port']}", size=10.5, fill=MUTED, font=MONO))

        p.append(line(left + 168, y + 14, left + 168, y + row_h - 26, stroke=LINE))
        p.append(text(left + 190, y + 26, "MODELO DE EJECUCION", size=9.5, fill=MUTED, weight="700", spacing="0.5"))
        for j, ln in enumerate(wrap(lg.get("execution_model", ""), 52, 2)):
            p.append(text(left + 190, y + 46 + j * 16, ln, size=12.5))

        p.append(line(left + 560, y + 14, left + 560, y + row_h - 26, stroke=LINE))
        p.append(text(left + 582, y + 26, "CONSECUENCIA EN EL LAB", size=9.5, fill=MUTED, weight="700", spacing="0.5"))
        for j, ln in enumerate(wrap(lg.get("headline", ""), 48, 2)):
            p.append(text(left + 582, y + 46 + j * 16, ln, size=11.5))

    return svg(width, height, "\n".join("  " + s for s in p), "Modelos de ejecucion comparados por stack")


# --------------------------------------------------------------------------
# 5. mapa de calor de fit por caso
# --------------------------------------------------------------------------
RANK_VALUE = {"🥇": 1, "🥈": 2, "🥉": 3, "4º": 4, "5º": 5, "6º": 6, "7º": 7}
RANK_FILL = {
    1: "#15803d",
    2: "#4d9a54",
    3: "#86bb72",
    4: "#cbd5e1",
    5: "#e2e8f0",
    6: "#f1f5f9",
    7: "#f8fafc",
}


def read_verdicts() -> tuple[list[str], dict[str, dict[str, int]]]:
    """Lee el ranking de fit desde la seccion Veredicto de cada `comparison.md`.

    Se parsea la documentacion en vez de mantener una tabla paralela: si un
    veredicto cambia manana, el diagrama cambia con el. `--check` en CI avisa
    cuando alguien edito un veredicto y no redibujo.
    """
    import re

    etiquetas = {"PHP": "php", "Python": "python", "Node.js": "node", "Java": "java",
                 ".NET": "dotnet", "Go": "go", "Rust": "rust"}
    datos: dict[str, dict[str, int]] = {}
    casos: list[str] = []
    for comp in sorted((ROOT / "cases").glob("*/comparison.md")):
        cid = comp.parent.name[:2]
        bloque = re.search(
            r"## \U0001f3c1 Veredicto.*?\n(.*?)(?=\n## |\Z)",
            comp.read_text(encoding="utf-8", errors="ignore"),
            re.S,
        )
        if not bloque:
            continue
        fila: dict[str, int] = {}
        for linea in bloque.group(1).splitlines():
            if not linea.startswith("|") or "---" in linea:
                continue
            celdas = [c.strip() for c in linea.strip("|").split("|")]
            if len(celdas) < 3 or celdas[0] not in RANK_VALUE:
                continue
            negritas = re.findall(r"\*\*(.+?)\*\*", celdas[1])
            if not negritas:
                continue
            for parte in negritas[0].split("/"):
                parte = parte.strip()
                for nombre, key in etiquetas.items():
                    if parte.startswith(nombre):
                        fila[key] = RANK_VALUE[celdas[0]]
        if fila:
            casos.append(cid)
            datos[cid] = fila
    return casos, datos


def diagram_fit_ranking(cat: dict) -> str:
    langs = cat["languages"]
    casos, datos = read_verdicts()

    left, top = 34, 152
    col_w, row_h = 60, 44
    label_w = 132
    width = left * 2 + label_w + col_w * len(casos) + 190
    height = top + row_h * len(langs) + 118

    p = [
        header(
            left,
            42,
            "Que stack expresa mejor cada problema",
            "Agregado de los veredictos de cada comparison.md. Mide fit con el problema, no calidad del lenguaje.",
        )
    ]
    p.append(
        text(
            left,
            84,
            "Verde oscuro = primer puesto en ese caso. Gris = el ultimo. Ningun stack gana en todo, y ese es el punto del laboratorio.",
            size=11.5,
            fill=MUTED,
        )
    )

    for j, cid in enumerate(casos):
        cx = left + label_w + j * col_w + col_w / 2
        p.append(text(cx, top - 14, cid, size=12, fill=MUTED, anchor="middle", weight="600", font=MONO))
    p.append(text(left, top - 14, "CASO", size=9.5, fill=MUTED, weight="700", spacing="0.5"))

    for i, lg in enumerate(langs):
        y = top + i * row_h
        color = STACK_COLOR.get(lg["key"], ACCENT)
        p.append(rect(left, y + 6, 4, row_h - 16, color, r=2))
        p.append(text(left + 14, y + 24, lg["label"], size=13, weight="600"))

        puestos = [datos[c][lg["key"]] for c in casos if lg["key"] in datos.get(c, {})]
        for j, cid in enumerate(casos):
            v = datos.get(cid, {}).get(lg["key"])
            cx = left + label_w + j * col_w
            cy = y + 4
            if v is None:
                continue
            p.append(rect(cx + 4, cy, col_w - 8, row_h - 14, RANK_FILL.get(v, PANEL), r=6))
            tinta = "#ffffff" if v <= 2 else INK
            p.append(text(cx + col_w / 2, cy + 20, str(v) + "º", size=12.5, fill=tinta, anchor="middle", weight="600"))

        if puestos:
            oros = puestos.count(1)
            prom = sum(puestos) / len(puestos)
            bx = left + label_w + col_w * len(casos) + 16
            p.append(text(bx, y + 24, f"{oros} oro" + ("" if oros == 1 else "s"), size=11.5, fill=MUTED, font=MONO))
            p.append(text(bx + 66, y + 24, f"media {prom:.1f}", size=11.5, fill=MUTED, font=MONO))

    fy = top + row_h * len(langs) + 24
    p.append(rect(left, fy, width - left * 2, 60, PANEL, r=8))
    p.append(
        text(
            left + 16,
            fy + 24,
            "Go gana por economia conceptual —una primitiva, canal + select, cubre semaforo, timeout, cola y cancelacion—;",
            size=11.5,
        )
    )
    p.append(
        text(
            left + 16,
            fy + 42,
            "Rust por lo que el compilador impide. Java y .NET dominan donde el problema ES el pool de threads (caso 11).",
            size=11.5,
        )
    )
    return svg(width, height, "\n".join("  " + s for s in p), "Ranking de fit por caso y stack")


DIAGRAMS = {
    "stack-matrix.svg": diagram_stack_matrix,
    "case-map.svg": diagram_case_map,
    "language-upgrade-flow.svg": diagram_upgrade_flow,
    "execution-models.svg": diagram_execution_models,
    "fit-ranking.svg": diagram_fit_ranking,
}


def main(argv: list[str]) -> int:
    check = "--check" in argv
    cat = load()
    OUT_DIR.mkdir(parents=True, exist_ok=True)

    stale: list[str] = []
    for name, fn in DIAGRAMS.items():
        content = fn(cat)
        dest = OUT_DIR / name
        if check:
            actual = dest.read_text(encoding="utf-8") if dest.exists() else ""
            if actual != content:
                stale.append(name)
            continue
        dest.write_text(content, encoding="utf-8", newline="\n")
        print(f"  ok  docs/assets/{name}  ({len(content):,} bytes)")

    if check:
        if stale:
            print("Diagramas desincronizados respecto de shared/catalog/cases.json:")
            for n in stale:
                print(f"  x  docs/assets/{n}")
            print("\nRegenera con: python scripts/generate_diagrams.py")
            return 1
        print("Los diagramas SVG estan sincronizados con el catalogo.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
