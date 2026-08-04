#!/usr/bin/env python3
"""Compila TODA la documentacion del repositorio en un unico PDF con imagenes.

Por que existe: el repositorio se evalua a veces sin acceso a GitHub —una
entrevista, un comite, un vuelo sin conexion—. Un dossier imprimible con los
mismos contenidos, el mismo orden editorial y los diagramas embebidos permite
esa lectura sin pedirle a nadie que clone nada.

Perfiles:
  --profile completo   (por defecto) todos los .md del repositorio
  --profile ejecutivo  raiz + docs/ + README de cada caso + comparativas

Los SVG de `docs/assets/` se embeben como vectores via `svglib`, asi que el PDF
no lleva imagenes rasterizadas: se puede hacer zoom sin perder nitidez.

Dependencias: reportlab, svglib (ambas puras Python, sin binarios del sistema).

    python scripts/build_dossier_pdf.py
    python scripts/build_dossier_pdf.py --profile ejecutivo -o /tmp/resumen.pdf
"""
from __future__ import annotations

import argparse
import html
import re
import sys
from datetime import date
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import (
    BaseDocTemplate,
    Frame,
    HRFlowable,
    KeepTogether,
    NextPageTemplate,
    PageBreak,
    PageTemplate,
    Paragraph,
    Preformatted,
    Spacer,
    Table,
    TableStyle,
)
from reportlab.platypus.tableofcontents import TableOfContents

ROOT = Path(__file__).resolve().parent.parent

INK = colors.HexColor("#0f172a")
MUTED = colors.HexColor("#64748b")
ACCENT = colors.HexColor("#2563eb")
PANEL = colors.HexColor("#f1f5f9")
LINE = colors.HexColor("#cbd5e1")
CODE_BG = colors.HexColor("#f8fafc")

# Orden editorial. Lo que no esta listado se agrega despues, ordenado por ruta,
# de forma que agregar un caso nuevo no requiera tocar este script.
ORDEN_RAIZ = [
    "README.md",
    "docs/QUE-ES-ESTO.md",
    "docs/BEGINNERS_GUIDE.md",
    "docs/executive-summary.md",
    "RECRUITER.md",
    "docs/recruiter-guide.md",
    "docs/positioning-and-objective.md",
    "ARCHITECTURE.md",
    "docs/architecture.md",
    "docs/stack-map.md",
    "docs/languages/README.md",
    "docs/languages/php.md",
    "docs/languages/python.md",
    "docs/languages/node.md",
    "docs/languages/java.md",
    "docs/languages/dotnet.md",
    "docs/languages/go.md",
    "docs/languages/rust.md",
    "docs/language-upgrade-protocol.md",
    "docs/case-catalog.md",
    "docs/case-methodology.md",
    "docs/problem-map.md",
    "docs/docker-strategy.md",
    "docs/usage-and-scope.md",
    "docs/growth-guidelines.md",
    "INSTALL.md",
    "RUNBOOK.md",
    "SECURITY.md",
    "AWS_MIGRATION.md",
    "ROADMAP.md",
    "CONTRIBUTING.md",
    "SUPPORT.md",
    "CHANGELOG.md",
]

EXCLUIR = {"docs/case-catalog.md"}  # se incluye por ORDEN; nada mas por ahora


# --------------------------------------------------------------------------
# seleccion de archivos
# --------------------------------------------------------------------------
def recolectar(profile: str) -> list[Path]:
    vistos: list[Path] = []

    def add(rel: str) -> None:
        p = ROOT / rel
        if p.exists() and p not in vistos:
            vistos.append(p)

    for rel in ORDEN_RAIZ:
        add(rel)

    for adr in sorted((ROOT / "docs/adr").glob("*.md")):
        if adr not in vistos:
            vistos.append(adr)

    for case_dir in sorted((ROOT / "cases").glob("*/")):
        add(f"cases/{case_dir.name}/README.md")
        add(f"cases/{case_dir.name}/comparison.md")
        if profile == "completo":
            for d in sorted((case_dir / "docs").glob("*.md")):
                if d not in vistos:
                    vistos.append(d)
            for stack in ("php", "python", "node", "java", "dotnet", "go", "rust"):
                add(f"cases/{case_dir.name}/{stack}/README.md")

    if profile == "completo":
        for md in sorted(ROOT.rglob("*.md")):
            if ".git" in md.parts or md in vistos:
                continue
            if any(part in {"node_modules", "vendor", "target"} for part in md.parts):
                continue
            vistos.append(md)

    return [p for p in vistos if str(p.relative_to(ROOT)).replace("\\", "/") not in EXCLUIR or True]


# --------------------------------------------------------------------------
# parser de markdown -> flowables
# --------------------------------------------------------------------------
INLINE_CODE = re.compile(r"`([^`]+)`")
BOLD = re.compile(r"\*\*(.+?)\*\*")
ITALIC = re.compile(r"(?<![\*\w])\*([^\*\n]+)\*(?!\*)")
LINK = re.compile(r"\[([^\]]*)\]\(([^)\s]+)(?:\s+\"[^\"]*\")?\)")
IMAGE = re.compile(r"!\[([^\]]*)\]\(([^)\s]+)\)")
HEADING = re.compile(r"^(#{1,6})\s+(.*)$")
FENCE = re.compile(r"^\s*```+\s*(\w+)?\s*$")
HTML_COMMENT = re.compile(r"<!--.*?-->", re.S)
BADGE_LINE = re.compile(r"^\s*(\[!\[[^\]]*\]\([^)]*\)\]\([^)]*\)\s*)+$")


# Las fuentes base de PDF (Helvetica, Courier) no tienen glifos de emoji: cada
# uno se dibuja como un cuadrado negro. En pantalla el emoji decora; impreso,
# ensucia. Los que cargan significado se traducen a texto y el resto se quita.
EMOJI_SEMANTICO = {
    "🥇": "1o ", "🥈": "2o ", "🥉": "3o ",
    "✅": "[si] ", "❌": "[no] ", "🚫": "[no] ", "⚠️": "[!] ", "⚠": "[!] ",
    "→": "->", "←": "<-", "↔": "<->", "⇄": "<->",
}
# No se traducen: los iconos de stack (🐘 🐍 ☕ 🔵 🐹 🦀 🟢) porque en este
# repositorio siempre acompañan al nombre escrito y produciria "Go Go"; y los
# circulos de color (🔴 🟡 🟢) porque su fila ya dice EOL / NUEVA / Split.
EMOJI_RANGO = re.compile(
    "[\U0001F000-\U0001FAFF←-⇿⌀-➿⬀-⯿"
    "☀-⛿️‍⃣︎]"
)


def des_emoji(s: str) -> str:
    for glifo, texto in EMOJI_SEMANTICO.items():
        s = s.replace(glifo, texto)
    s = EMOJI_RANGO.sub("", s)
    return re.sub(r"[ \t]{2,}", " ", s).strip()


def inline(texto: str) -> str:
    """Markdown en linea -> markup de reportlab.

    El codigo entre backticks se aparta con un marcador antes de aplicar
    negrita/cursiva. Sin eso, un `/php/*` dentro de un span de codigo abre una
    cursiva que recien cierra en el siguiente asterisco del parrafo y rompe el
    parser de reportlab con un error de anidamiento de etiquetas.
    """
    texto = des_emoji(IMAGE.sub(r"\1", texto))
    s = html.escape(texto, quote=False)

    apartados: list[str] = []

    def _apartar(m: re.Match) -> str:
        apartados.append(m.group(1))
        return f"\x00{len(apartados) - 1}\x00"

    s = INLINE_CODE.sub(_apartar, s)
    s = BOLD.sub(r"<b>\1</b>", s)
    s = ITALIC.sub(r"<i>\1</i>", s)
    s = LINK.sub(
        lambda m: (
            f'<font color="#2563eb">{m.group(1)}</font>'
            if not m.group(2).startswith("http")
            else f'<link href="{html.escape(m.group(2), quote=True)}" color="#2563eb">{m.group(1)}</link>'
        ),
        s,
    )
    return re.sub(
        r"\x00(\d+)\x00",
        lambda m: (
            '<font face="Courier" size="8.5" backColor="#eef2f7">'
            f"{apartados[int(m.group(1))]}</font>"
        ),
        s,
    )


def parse_tabla(lineas: list[str], i: int) -> tuple[list[list[str]], int]:
    filas = []
    while i < len(lineas) and lineas[i].strip().startswith("|"):
        crudo = lineas[i].strip().strip("|")
        if not re.fullmatch(r"[\s:|-]+", crudo):
            filas.append([c.strip() for c in crudo.split("|")])
        i += 1
    return filas, i


class Constructor:
    def __init__(self, estilos: dict) -> None:
        self.e = estilos
        self.svg_cache: dict[str, object] = {}

    def imagen(self, ruta: Path, ancho_max: float):
        from svglib.svglib import svg2rlg

        key = str(ruta)
        if key not in self.svg_cache:
            self.svg_cache[key] = svg2rlg(str(ruta))
        base = self.svg_cache[key]
        if base is None:
            return None
        # svg2rlg devuelve un Drawing mutable; se clona para poder escalar por uso
        dib = svg2rlg(str(ruta))
        escala = min(1.0, ancho_max / dib.width)
        dib.width *= escala
        dib.height *= escala
        dib.scale(escala, escala)
        dib.hAlign = "CENTER"
        return dib

    def convertir(self, md: str, rel: str, ancho: float) -> list:
        md = HTML_COMMENT.sub("", md)
        lineas = md.split("\n")
        out: list = []
        i = 0
        base_dir = (ROOT / rel).parent

        while i < len(lineas):
            ln = lineas[i]

            # bloque de codigo
            m = FENCE.match(ln)
            if m:
                i += 1
                buf = []
                while i < len(lineas) and not FENCE.match(lineas[i]):
                    buf.append(lineas[i])
                    i += 1
                i += 1
                cuerpo = "\n".join(buf).rstrip()
                if cuerpo:
                    cuerpo = "\n".join(l[:110] for l in cuerpo.split("\n"))
                    out.append(
                        Table(
                            [[Preformatted(cuerpo, self.e["code"])]],
                            colWidths=[ancho],
                            style=TableStyle([
                                ("BACKGROUND", (0, 0), (-1, -1), CODE_BG),
                                ("BOX", (0, 0), (-1, -1), 0.5, LINE),
                                ("LEFTPADDING", (0, 0), (-1, -1), 7),
                                ("RIGHTPADDING", (0, 0), (-1, -1), 7),
                                ("TOPPADDING", (0, 0), (-1, -1), 6),
                                ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
                            ]),
                        )
                    )
                    out.append(Spacer(1, 5))
                continue

            # imagen en linea propia
            mi = IMAGE.fullmatch(ln.strip())
            if mi:
                destino = (base_dir / mi.group(2)).resolve()
                if destino.exists() and destino.suffix.lower() == ".svg":
                    try:
                        dib = self.imagen(destino, ancho)
                        if dib is not None:
                            out.append(Spacer(1, 4))
                            out.append(dib)
                            if mi.group(1):
                                out.append(Paragraph(inline(mi.group(1)), self.e["caption"]))
                            out.append(Spacer(1, 8))
                    except Exception as exc:  # pragma: no cover
                        print(f"  aviso: no se pudo embeber {destino.name}: {exc}", file=sys.stderr)
                i += 1
                continue

            # linea de badges -> se omite (son imagenes remotas, sin valor impreso)
            if BADGE_LINE.match(ln):
                i += 1
                continue

            # encabezado
            mh = HEADING.match(ln)
            if mh:
                nivel = len(mh.group(1))
                txt = mh.group(2).strip()
                if nivel == 1:
                    out.append(Paragraph(inline(txt), self.e["h1"]))
                    out.append(HRFlowable(width="100%", color=ACCENT, thickness=1.1,
                                          spaceBefore=2, spaceAfter=8))
                else:
                    out.append(Paragraph(inline(txt), self.e[f"h{min(nivel, 4)}"]))
                i += 1
                continue

            # tabla
            if ln.strip().startswith("|"):
                filas, i = parse_tabla(lineas, i)
                if filas:
                    out.append(self.tabla(filas, ancho))
                continue

            # separador
            if re.fullmatch(r"\s*([-*_])\s*\1\s*\1[\s\1]*", ln):
                out.append(HRFlowable(width="100%", color=LINE, thickness=0.5,
                                      spaceBefore=6, spaceAfter=6))
                i += 1
                continue

            # cita
            if ln.strip().startswith(">"):
                buf = []
                while i < len(lineas) and (lineas[i].strip().startswith(">") or
                                           (buf and lineas[i].strip() and
                                            not lineas[i].strip().startswith(("#", "|", "-", "```")))):
                    if not lineas[i].strip().startswith(">"):
                        break
                    buf.append(re.sub(r"^\s*>\s?", "", lineas[i]))
                    i += 1
                cuerpo = " ".join(x for x in buf if x.strip())
                cuerpo = re.sub(r"^\[!\w+\]\s*", "", cuerpo).strip()
                if cuerpo:
                    out.append(
                        Table(
                            [[Paragraph(inline(cuerpo), self.e["quote"])]],
                            colWidths=[ancho],
                            style=TableStyle([
                                ("BACKGROUND", (0, 0), (-1, -1), PANEL),
                                ("LINEBEFORE", (0, 0), (0, -1), 2.5, ACCENT),
                                ("LEFTPADDING", (0, 0), (-1, -1), 9),
                                ("RIGHTPADDING", (0, 0), (-1, -1), 8),
                                ("TOPPADDING", (0, 0), (-1, -1), 6),
                                ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
                            ]),
                        )
                    )
                    out.append(Spacer(1, 5))
                continue

            # lista
            ml = re.match(r"^(\s*)([-*+]|\d+[.)])\s+(.*)$", ln)
            if ml:
                sangria = len(ml.group(1)) // 2
                vineta = "•" if not ml.group(2)[0].isdigit() else ml.group(2)
                est = ParagraphStyle(
                    f"li{sangria}", parent=self.e["body"],
                    leftIndent=10 + sangria * 12, bulletIndent=2 + sangria * 12,
                    spaceAfter=2,
                )
                out.append(Paragraph(inline(ml.group(3)), est, bulletText=vineta))
                i += 1
                continue

            # parrafo
            if ln.strip():
                buf = [ln]
                i += 1
                while i < len(lineas) and lineas[i].strip() and not (
                    HEADING.match(lineas[i]) or FENCE.match(lineas[i])
                    or lineas[i].strip().startswith(("|", ">", "-", "*", "+"))
                ):
                    buf.append(lineas[i])
                    i += 1
                out.append(Paragraph(inline(" ".join(x.strip() for x in buf)), self.e["body"]))
                continue

            i += 1

        return out

    def tabla(self, filas: list[list[str]], ancho: float) -> Table:
        cols = max(len(f) for f in filas)
        filas = [f + [""] * (cols - len(f)) for f in filas]
        datos = [
            [Paragraph(inline(c), self.e["th" if r == 0 else "td"]) for c in fila]
            for r, fila in enumerate(filas)
        ]
        # reparto proporcional al contenido, con piso para que ninguna columna colapse
        pesos = [max(4, max(len(f[c]) for f in filas) ** 0.62) for c in range(cols)]
        total = sum(pesos)
        anchos = [max(28, ancho * p / total) for p in pesos]
        exceso = sum(anchos) - ancho
        if exceso > 0:
            gordo = anchos.index(max(anchos))
            anchos[gordo] -= exceso
        t = Table(datos, colWidths=anchos, repeatRows=1)
        t.setStyle(TableStyle([
            ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#e2e8f0")),
            ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#f8fafc")]),
            ("GRID", (0, 0), (-1, -1), 0.4, LINE),
            ("VALIGN", (0, 0), (-1, -1), "TOP"),
            ("LEFTPADDING", (0, 0), (-1, -1), 5),
            ("RIGHTPADDING", (0, 0), (-1, -1), 5),
            ("TOPPADDING", (0, 0), (-1, -1), 4),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
        ]))
        return t


# --------------------------------------------------------------------------
# estilos
# --------------------------------------------------------------------------
def construir_estilos() -> dict:
    ss = getSampleStyleSheet()
    base = ParagraphStyle("body", parent=ss["BodyText"], fontName="Helvetica",
                          fontSize=9.2, leading=13, textColor=INK, spaceAfter=6,
                          alignment=TA_LEFT)
    return {
        "body": base,
        "h1": ParagraphStyle("h1", parent=base, fontName="Helvetica-Bold", fontSize=17,
                             leading=21, spaceBefore=4, spaceAfter=2, textColor=INK),
        "h2": ParagraphStyle("h2", parent=base, fontName="Helvetica-Bold", fontSize=12.5,
                             leading=16, spaceBefore=12, spaceAfter=5, textColor=ACCENT),
        "h3": ParagraphStyle("h3", parent=base, fontName="Helvetica-Bold", fontSize=10.5,
                             leading=14, spaceBefore=9, spaceAfter=4),
        "h4": ParagraphStyle("h4", parent=base, fontName="Helvetica-Bold", fontSize=9.6,
                             leading=13, spaceBefore=7, spaceAfter=3, textColor=MUTED),
        "code": ParagraphStyle("code", parent=base, fontName="Courier", fontSize=7.6,
                               leading=9.6, spaceAfter=0, textColor=colors.HexColor("#1e293b")),
        "quote": ParagraphStyle("quote", parent=base, fontSize=9, leading=12.5, spaceAfter=0),
        "th": ParagraphStyle("th", parent=base, fontName="Helvetica-Bold", fontSize=8,
                             leading=10.5, spaceAfter=0),
        "td": ParagraphStyle("td", parent=base, fontSize=8, leading=10.5, spaceAfter=0),
        "caption": ParagraphStyle("caption", parent=base, fontSize=7.8, leading=10,
                                  textColor=MUTED, alignment=TA_CENTER, spaceBefore=3),
        "portada_t": ParagraphStyle("pt", parent=base, fontName="Helvetica-Bold", fontSize=27,
                                    leading=32, alignment=TA_CENTER, textColor=INK),
        "portada_s": ParagraphStyle("ps", parent=base, fontSize=12, leading=17,
                                    alignment=TA_CENTER, textColor=MUTED),
        "seccion": ParagraphStyle("sec", parent=base, fontName="Helvetica-Bold", fontSize=21,
                                  leading=26, alignment=TA_CENTER, textColor=ACCENT),
    }


class Dossier(BaseDocTemplate):
    """Documento con marcadores de indice y pie con numero de pagina."""

    def __init__(self, ruta: str, **kw) -> None:
        super().__init__(ruta, **kw)
        marco = Frame(self.leftMargin, self.bottomMargin, self.width, self.height, id="cuerpo")
        self.addPageTemplates([
            PageTemplate(id="portada", frames=[marco]),
            PageTemplate(id="cuerpo", frames=[marco], onPage=self.pie),
        ])

    def pie(self, canvas, doc) -> None:
        canvas.saveState()
        canvas.setStrokeColor(LINE)
        canvas.setLineWidth(0.4)
        canvas.line(doc.leftMargin, 13 * mm, doc.leftMargin + doc.width, 13 * mm)
        canvas.setFont("Helvetica", 7.3)
        canvas.setFillColor(MUTED)
        canvas.drawString(doc.leftMargin, 9 * mm, "Problem-Driven Systems Lab — dossier tecnico")
        canvas.drawRightString(doc.leftMargin + doc.width, 9 * mm, str(canvas.getPageNumber()))
        canvas.restoreState()

    def afterFlowable(self, flowable) -> None:
        if not isinstance(flowable, Paragraph):
            return
        estilo = flowable.style.name
        if estilo == "h1":
            self.notify("TOCEntry", (0, re.sub(r"<[^>]+>", "", flowable.getPlainText()), self.page))
        elif estilo == "h2":
            self.notify("TOCEntry", (1, re.sub(r"<[^>]+>", "", flowable.getPlainText()), self.page))


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description="Compila la documentacion del repositorio en un PDF.")
    ap.add_argument("--profile", choices=["completo", "ejecutivo"], default="completo")
    ap.add_argument("-o", "--output", default=None)
    args = ap.parse_args(argv)

    archivos = recolectar(args.profile)
    salida = args.output or str(
        ROOT / "dist" / f"problem-driven-systems-lab-dossier-{args.profile}.pdf"
    )
    Path(salida).parent.mkdir(parents=True, exist_ok=True)

    est = construir_estilos()
    doc = Dossier(
        salida, pagesize=A4,
        leftMargin=17 * mm, rightMargin=17 * mm, topMargin=16 * mm, bottomMargin=20 * mm,
        title="Problem-Driven Systems Lab — dossier tecnico",
        author="Vladimir Acuna", subject="Laboratorio de sistemas problem-driven",
    )
    ancho = doc.width
    ctor = Constructor(est)
    hist: list = []

    # ---- portada
    hist.append(Spacer(1, 32 * mm))
    hist.append(Paragraph("Problem-Driven<br/>Systems Lab", est["portada_t"]))
    hist.append(Spacer(1, 7 * mm))
    hist.append(Paragraph(
        "12 problemas reales de ingenieria, resueltos y documentados<br/>"
        "en 7 lenguajes con la primitiva idiomatica de cada runtime.",
        est["portada_s"]))
    hist.append(Spacer(1, 10 * mm))
    portada_svg = ROOT / "docs/assets/stack-matrix.svg"
    if portada_svg.exists():
        dib = ctor.imagen(portada_svg, ancho * 0.94)
        if dib is not None:
            hist.append(dib)
    hist.append(Spacer(1, 10 * mm))
    perfil_txt = "Edicion completa" if args.profile == "completo" else "Edicion ejecutiva"
    hist.append(Paragraph(
        f"{perfil_txt} · {len(archivos)} documentos · generado el {date.today().isoformat()}",
        est["portada_s"]))
    hist.append(Paragraph(
        "github.com/vladimiracunadev-create/problem-driven-systems-lab", est["portada_s"]))
    hist.append(NextPageTemplate("cuerpo"))
    hist.append(PageBreak())

    # ---- indice
    toc = TableOfContents()
    toc.levelStyles = [
        ParagraphStyle("t0", fontName="Helvetica-Bold", fontSize=9.6, leading=15,
                       leftIndent=0, firstLineIndent=-12, spaceBefore=5, textColor=INK),
        ParagraphStyle("t1", fontName="Helvetica", fontSize=8.4, leading=12,
                       leftIndent=14, firstLineIndent=-10, textColor=MUTED),
    ]
    hist.append(Paragraph("Indice", est["seccion"]))
    hist.append(Spacer(1, 7 * mm))
    hist.append(toc)
    hist.append(PageBreak())

    # ---- contenido
    for n, f in enumerate(archivos):
        rel = str(f.relative_to(ROOT)).replace("\\", "/")
        try:
            md = f.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        hist.append(Paragraph(rel, ParagraphStyle(
            "ruta", parent=est["body"], fontName="Courier", fontSize=7.2,
            textColor=MUTED, spaceAfter=3)))
        hist.extend(ctor.convertir(md, rel, ancho))
        if n < len(archivos) - 1:
            hist.append(PageBreak())
        print(f"  [{n + 1:3}/{len(archivos)}] {rel}")

    print("\nResolviendo indice (dos pasadas)...")
    doc.multiBuild(hist)
    tam = Path(salida).stat().st_size
    print(f"\nPDF generado: {salida}")
    print(f"  {len(archivos)} documentos · {tam / 1_048_576:.1f} MB")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
