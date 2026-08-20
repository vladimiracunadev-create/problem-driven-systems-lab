#!/usr/bin/env python3
"""Renderizador Markdown -> HTML sin dependencias externas.

Por que existe: la landing del laboratorio no puede enlazar a `.md`. Un enlace a
Markdown en GitHub Pages es un 404 o una descarga, no una pagina; y enlazar al
blob de GitHub saca al visitante del sitio en el primer clic. La unica salida
honesta es publicar la documentacion como HTML propio.

Se resuelve con la stdlib, igual que `generate_diagrams.py`: el repositorio ya
decidio que sus generadores no arrastran dependencias, y un renderizador de
Markdown es exactamente el tipo de pieza que despues nadie actualiza. Cubre lo
que los 400+ documentos del repo usan de verdad -- encabezados, tablas, listas
anidadas, bloques de codigo, citas, imagenes, enlaces y enfasis -- y nada mas.

No es un parser CommonMark completo y no pretende serlo. `check_site_links.py`
falla si algun enlace queda roto, asi que un caso no cubierto se manifiesta como
error de build y no como pagina silenciosamente mal formada.
"""
from __future__ import annotations

import html as _html
import re
import unicodedata
from typing import Callable

__all__ = ["render_markdown", "render_inline", "slugify", "MarkdownDocument"]

# -- slugs -------------------------------------------------------------------
# GitHub genera los anchors bajando a minusculas, descartando todo lo que no sea
# letra, numero, `_`, espacio o guion, y cambiando espacios por guiones. Los
# emoji de los encabezados desaparecen y dejan el guion inicial: `## 🎯 Resumen`
# es `#-resumen`. Replicarlo importa porque los documentos del repo ya enlazan
# entre si con esos anchors.
_INLINE_STRIP = (
    (re.compile(r"`([^`]*)`"), r"\1"),
    (re.compile(r"!\[([^\]]*)\]\([^)]*\)"), r"\1"),
    (re.compile(r"\[([^\]]*)\]\([^)]*\)"), r"\1"),
    (re.compile(r"[*~]+"), ""),
)


def slugify(text: str) -> str:
    for pattern, repl in _INLINE_STRIP:
        text = pattern.sub(repl, text)
    text = text.strip().lower()
    out: list[str] = []
    for ch in text:
        if ch in " \t":
            out.append("-")
        elif ch in "-_":
            out.append(ch)
        elif unicodedata.category(ch)[0] in ("L", "N"):
            out.append(ch)
    return "".join(out)


class _Slugger:
    """Anchors unicos por documento, con el mismo desempate que GitHub (`-1`)."""

    def __init__(self) -> None:
        self._seen: dict[str, int] = {}

    def __call__(self, text: str) -> str:
        base = slugify(text) or "seccion"
        count = self._seen.get(base, 0)
        self._seen[base] = count + 1
        return base if count == 0 else f"{base}-{count}"


# -- inline ------------------------------------------------------------------
_CODE_RE = re.compile(r"(`+)(.+?)\1", re.S)
_IMG_RE = re.compile(r"!\[([^\]]*)\]\(\s*(<[^>]*>|\S*?)(?:\s+\"([^\"]*)\")?\s*\)")
_LINK_RE = re.compile(r"\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\(\s*(<[^>]*>|\S*?)(?:\s+\"([^\"]*)\")?\s*\)")
_AUTOLINK_RE = re.compile(r"&lt;(https?://[^\s&]+)&gt;")
_STRONG_RE = re.compile(r"\*\*(?=\S)(.+?)(?<=\S)\*\*", re.S)
_STRONG_US_RE = re.compile(r"(?<!\w)__(?=\S)(.+?)(?<=\S)__(?!\w)", re.S)
_EM_RE = re.compile(r"(?<!\*)\*(?=[^\s*])([^*\n]+?)(?<=[^\s*])\*(?!\*)")
_EM_US_RE = re.compile(r"(?<![\w\\])_(?=[^\s_])([^_\n]+?)(?<=[^\s_])_(?!\w)")
_DEL_RE = re.compile(r"~~(?=\S)(.+?)(?<=\S)~~", re.S)
_BR_RE = re.compile(r"  \n")
_TAG_RE = re.compile(r"<[^>]+>")


def _trim_angle(target: str) -> str:
    if target.startswith("<") and target.endswith(">"):
        return target[1:-1]
    return target


def _inline(text: str, resolve: Callable[[str, bool], str]) -> str:
    """Convierte el markup en linea. `resolve(destino, es_imagen)` da el href final."""
    stash: list[str] = []

    def _stash_code(match: re.Match[str]) -> str:
        stash.append(match.group(2).strip())
        return "\x00c" + str(len(stash) - 1) + "\x00"

    text = _CODE_RE.sub(_stash_code, text)
    text = _html.escape(text, quote=False)

    def _image(match: re.Match[str]) -> str:
        alt, src, title = match.group(1), _trim_angle(match.group(2)), match.group(3)
        attrs = ' title="' + _html.escape(title, quote=True) + '"' if title else ""
        href = _html.escape(resolve(src, True), quote=True)
        return f'<img src="{href}" alt="{_html.escape(alt, quote=True)}" loading="lazy"{attrs}>'

    text = _IMG_RE.sub(_image, text)

    def _link(match: re.Match[str]) -> str:
        label, href, title = match.group(1), _trim_angle(match.group(2)), match.group(3)
        resolved = resolve(href, False)
        attrs = ' title="' + _html.escape(title, quote=True) + '"' if title else ""
        if resolved.startswith(("http://", "https://")):
            attrs += ' target="_blank" rel="noopener noreferrer"'
        return f'<a href="{_html.escape(resolved, quote=True)}"{attrs}>{label}</a>'

    text = _LINK_RE.sub(_link, text)
    text = _AUTOLINK_RE.sub(
        lambda m: '<a href="' + _html.escape(m.group(1), quote=True)
        + '" target="_blank" rel="noopener noreferrer">' + m.group(1) + "</a>",
        text,
    )
    text = _STRONG_RE.sub(r"<strong>\1</strong>", text)
    text = _STRONG_US_RE.sub(r"<strong>\1</strong>", text)
    text = _DEL_RE.sub(r"<del>\1</del>", text)
    text = _EM_RE.sub(r"<em>\1</em>", text)
    text = _EM_US_RE.sub(r"<em>\1</em>", text)
    text = _BR_RE.sub("<br>\n", text)

    for index, code in enumerate(stash):
        token = "\x00c" + str(index) + "\x00"
        text = text.replace(token, "<code>" + _html.escape(code, quote=False) + "</code>")
    return text


# -- bloques -----------------------------------------------------------------
_FENCE_RE = re.compile(r"^(\s*)(`{3,}|~{3,})\s*([^\s`]*)\s*$")
_HEADING_RE = re.compile(r"^(#{1,6})\s+(.*?)\s*#*\s*$")
_HR_RE = re.compile(r"^\s{0,3}([-*_])(?:\s*\1){2,}\s*$")
_UL_RE = re.compile(r"^(\s*)([-*+])\s+(.*)$")
_OL_RE = re.compile(r"^(\s*)(\d{1,9})[.)]\s+(.*)$")
_TASK_RE = re.compile(r"^\[([ xX])\]\s+(.*)$")
_TABLE_SEP_RE = re.compile(r"^\s*\|?(\s*:?-{2,}:?\s*\|)+(\s*:?-{2,}:?\s*)?\|?\s*$")
_BLOCKQUOTE_RE = re.compile(r"^\s{0,3}>\s?(.*)$")
_HTML_BLOCK_RE = re.compile(r"^\s{0,3}<(/?)([a-zA-Z][a-zA-Z0-9-]*)")

_HTML_INLINE_TAGS = {
    "img", "br", "a", "code", "kbd", "sub", "sup", "span", "b", "i", "em", "strong",
}


class MarkdownDocument:
    """Resultado del render: HTML, titulo inferido y anchors disponibles."""

    def __init__(
        self,
        html: str,
        title: str | None,
        anchors: set[str],
        headings: list[tuple[int, str, str]],
    ) -> None:
        self.html = html
        self.title = title
        self.anchors = anchors
        self.headings = headings


def _split_row(line: str) -> list[str]:
    line = line.strip()
    if line.startswith("|"):
        line = line[1:]
    if line.endswith("|") and not line.endswith("\\|"):
        line = line[:-1]
    cells: list[str] = []
    current = ""
    escaped = False
    for ch in line:
        if escaped:
            current += ch
            escaped = False
        elif ch == "\\":
            escaped = True
            current += ch
        elif ch == "|":
            cells.append(current.strip())
            current = ""
        else:
            current += ch
    cells.append(current.strip())
    return cells


def _alignments(sep: str) -> list[str]:
    out: list[str] = []
    for cell in _split_row(sep):
        left, right = cell.startswith(":"), cell.endswith(":")
        if left and right:
            out.append("center")
        elif right:
            out.append("right")
        else:
            out.append("")
    return out


class _Renderer:
    def __init__(self, resolve: Callable[[str, bool], str]) -> None:
        self.resolve = resolve
        self.slug = _Slugger()
        self.anchors: set[str] = set()
        self.headings: list[tuple[int, str, str]] = []
        self.title: str | None = None

    def inline(self, text: str) -> str:
        return _inline(text, self.resolve)

    def render(self, lines: list[str]) -> str:
        out: list[str] = []
        index = 0
        total = len(lines)
        while index < total:
            line = lines[index]

            if not line.strip():
                index += 1
                continue

            fence = _FENCE_RE.match(line)
            if fence:
                index = self._code(lines, index, out, fence)
                continue

            heading = _HEADING_RE.match(line)
            if heading:
                self._heading(heading, out)
                index += 1
                continue

            if _HR_RE.match(line):
                out.append("<hr>")
                index += 1
                continue

            if _BLOCKQUOTE_RE.match(line):
                index = self._blockquote(lines, index, out)
                continue

            if "|" in line and index + 1 < total and _TABLE_SEP_RE.match(lines[index + 1]):
                index = self._table(lines, index, out)
                continue

            if _UL_RE.match(line) or _OL_RE.match(line):
                index = self._list(lines, index, out)
                continue

            html_block = _HTML_BLOCK_RE.match(line)
            if html_block and html_block.group(2).lower() not in _HTML_INLINE_TAGS:
                index = self._html(lines, index, out)
                continue

            index = self._paragraph(lines, index, out)
        return "\n".join(out)

    def _heading(self, match: re.Match[str], out: list[str]) -> None:
        level = len(match.group(1))
        raw = match.group(2)
        anchor = self.slug(raw)
        self.anchors.add(anchor)
        text = self.inline(raw)
        plain = _html.unescape(_TAG_RE.sub("", text)).strip()
        self.headings.append((level, anchor, plain))
        if self.title is None and level <= 2:
            self.title = plain
        out.append(
            f'<h{level} id="{anchor}">{text}'
            f'<a class="anchor" href="#{anchor}" aria-label="Enlace a esta seccion">#</a>'
            f"</h{level}>"
        )

    def _code(self, lines: list[str], index: int, out: list[str], fence: re.Match[str]) -> int:
        marker = fence.group(2)[0]
        min_len = len(fence.group(2))
        lang = fence.group(3).strip()
        body: list[str] = []
        index += 1
        while index < len(lines):
            closing = _FENCE_RE.match(lines[index])
            if (
                closing
                and closing.group(2)[0] == marker
                and len(closing.group(2)) >= min_len
                and not closing.group(3)
            ):
                index += 1
                break
            body.append(lines[index])
            index += 1
        code = _html.escape("\n".join(body), quote=False)
        # GitHub dibuja los bloques `mermaid`; el sitio no carga librerias
        # externas, asi que se publica la fuente y se dice que lo es en vez de
        # mostrar un bloque de codigo sin explicacion.
        shown = "diagrama mermaid" if lang.lower() == "mermaid" else lang
        label = f'<span class="code-lang">{_html.escape(shown)}</span>' if lang else ""
        cls = f' class="language-{_html.escape(lang, quote=True)}"' if lang else ""
        out.append(f'<div class="codeblock">{label}<pre><code{cls}>{code}</code></pre></div>')
        return index

    def _blockquote(self, lines: list[str], index: int, out: list[str]) -> int:
        inner: list[str] = []
        while index < len(lines):
            match = _BLOCKQUOTE_RE.match(lines[index])
            if match is None:
                break
            inner.append(match.group(1))
            index += 1
        nested = _Renderer(self.resolve)
        nested.slug = self.slug
        body = nested.render(inner)
        self.anchors |= nested.anchors
        out.append("<blockquote>\n" + body + "\n</blockquote>")
        return index

    def _table(self, lines: list[str], index: int, out: list[str]) -> int:
        header = _split_row(lines[index])
        aligns = _alignments(lines[index + 1])
        index += 2
        rows: list[list[str]] = []
        while index < len(lines) and lines[index].strip() and "|" in lines[index]:
            rows.append(_split_row(lines[index]))
            index += 1

        def cell(tag: str, text: str, position: int) -> str:
            align = aligns[position] if position < len(aligns) else ""
            style = f' style="text-align:{align}"' if align else ""
            return f"<{tag}{style}>{self.inline(text.replace(chr(92) + chr(124), chr(124)))}</{tag}>"

        head = "".join(cell("th", value, position) for position, value in enumerate(header))
        body = "\n".join(
            "<tr>"
            + "".join(cell("td", value, position) for position, value in enumerate(row))
            + "</tr>"
            for row in rows
        )
        out.append(
            '<div class="tablewrap"><table>\n<thead><tr>'
            + head
            + "</tr></thead>\n<tbody>\n"
            + body
            + "\n</tbody>\n</table></div>"
        )
        return index

    def _list(self, lines: list[str], index: int, out: list[str]) -> int:
        block, index = self._collect_list(lines, index)
        out.append(self._render_list(block))
        return index

    def _collect_list(self, lines: list[str], index: int) -> tuple[list[str], int]:
        block: list[str] = []
        while index < len(lines):
            line = lines[index]
            if not line.strip():
                # Una linea en blanco solo continua la lista si lo que sigue es
                # otro item o texto indentado; si no, la lista termina ahi.
                lookahead = index + 1
                if lookahead < len(lines) and (
                    _UL_RE.match(lines[lookahead])
                    or _OL_RE.match(lines[lookahead])
                    or lines[lookahead].startswith("  ")
                ):
                    block.append("")
                    index += 1
                    continue
                break
            if _UL_RE.match(line) or _OL_RE.match(line) or line.startswith("  "):
                block.append(line)
                index += 1
                continue
            break
        while block and not block[-1].strip():
            block.pop()
        return block, index

    def _render_list(self, block: list[str]) -> str:
        if not block:
            return ""
        first = _OL_RE.match(block[0])
        ordered = first is not None
        opening = first or _UL_RE.match(block[0])
        assert opening is not None
        indent = len(opening.group(1))
        items: list[list[str]] = []
        for line in block:
            match = _OL_RE.match(line) or _UL_RE.match(line)
            if match and len(match.group(1)) <= indent:
                items.append([match.group(3)])
            elif items:
                items[-1].append(line[indent:] if len(line) > indent else line.strip())
            else:
                items.append([line.strip()])

        rendered: list[str] = []
        has_tasks = False
        for item in items:
            head = item[0]
            task = _TASK_RE.match(head)
            prefix = ""
            if task:
                has_tasks = True
                checked = " checked" if task.group(1).lower() == "x" else ""
                prefix = f'<input type="checkbox" disabled{checked}> '
                head = task.group(2)
            nested_html = ""
            if item[1:]:
                nested = _Renderer(self.resolve)
                nested.slug = self.slug
                nested_html = nested.render(item[1:])
                self.anchors |= nested.anchors
            rendered.append(f"<li>{prefix}{self.inline(head)}{nested_html}</li>")
        tag = "ol" if ordered else "ul"
        cls = ' class="tasklist"' if has_tasks else ""
        return f"<{tag}{cls}>\n" + "\n".join(rendered) + f"\n</{tag}>"

    def _html(self, lines: list[str], index: int, out: list[str]) -> int:
        buffer: list[str] = []
        while index < len(lines) and lines[index].strip():
            buffer.append(lines[index])
            index += 1
        out.append("\n".join(buffer))
        return index

    def _paragraph(self, lines: list[str], index: int, out: list[str]) -> int:
        buffer: list[str] = []
        while index < len(lines) and lines[index].strip():
            line = lines[index]
            if buffer and (
                _HEADING_RE.match(line)
                or _FENCE_RE.match(line)
                or _HR_RE.match(line)
                or _BLOCKQUOTE_RE.match(line)
                or _UL_RE.match(line)
                or _OL_RE.match(line)
            ):
                break
            buffer.append(line.strip())
            index += 1
        if buffer:
            out.append("<p>" + self.inline("\n".join(buffer)) + "</p>")
        return index


def render_inline(text: str) -> str:
    """Markdown en linea para prosa corta que no vive en un `.md`.

    Los textos del catalogo (`headline`, `note`, `summary`, los proof points)
    estan escritos en Markdown: traen backticks alrededor de `ConcurrentHashMap`
    o `chan struct{}`. Escaparlos sin interpretarlos publicaria los backticks
    como caracteres, que es exactamente el detalle que delata una pagina armada
    a mano.
    """
    return _inline(text, lambda href, _is_image: href)


_COMMENT_RE = re.compile(r"<!--.*?-->", re.S)


def render_markdown(text: str, resolve: Callable[[str, bool], str]) -> MarkdownDocument:
    """Renderiza `text`. `resolve(destino, es_imagen)` devuelve el href final."""
    normalized = text.replace("\r\n", "\n").replace("\r", "\n").replace("\t", "    ")
    # Los comentarios HTML son notas para quien edita el Markdown (marcadores de
    # seccion, TODO). GitHub no los muestra; sin quitarlos aqui se publicarian
    # como texto suelto en medio de la pagina.
    normalized = _COMMENT_RE.sub("", normalized)
    renderer = _Renderer(resolve)
    html = renderer.render(normalized.split("\n"))
    return MarkdownDocument(html, renderer.title, renderer.anchors, renderer.headings)
