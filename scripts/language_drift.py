#!/usr/bin/env python3
"""Compara las versiones de lenguaje fijadas en el repo contra endoflife.date.

Lee la version real desde los Dockerfile (no desde una constante duplicada, que
seria justo el drift que este script busca evitar) y consulta el ciclo de vida
publicado upstream.

Emite dos señales:
  · EOL      — la version que usamos ya no recibe soporte. Es accionable ya.
  · NUEVA    — hay una version mayor/menor mas reciente. No es urgente, pero
               puede haber vuelto obsoleta la primitiva que un caso enseña.

Salida: GITHUB_OUTPUT con `drift` (true/false) y `body` (markdown del issue),
mas un resumen legible en GITHUB_STEP_SUMMARY.

Sin dependencias externas: solo stdlib.
"""
from __future__ import annotations

import json
import os
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
API = "https://endoflife.date/api/{product}.json"

# El reporte usa emoji. En Windows la consola por defecto es cp1252 y `print`
# reventaria; en el runner de Actions ya es UTF-8. Se fuerza para que el script
# se pueda correr a mano desde cualquier maquina.
for _stream in (sys.stdout, sys.stderr):
    try:
        _stream.reconfigure(encoding="utf-8")  # type: ignore[union-attr]
    except (AttributeError, ValueError):
        pass

# stack -> (producto en endoflife.date, regex sobre el FROM del Dockerfile,
#           que primitiva del lab podria quedar obsoleta si el lenguaje avanza)
STACKS = {
    "php":    ("php",     r"^FROM php:(\d+\.\d+)",                        "El dashboard PHP y el pool FPM del caso 01."),
    "node":   ("nodejs",  r"^FROM node:(\d+)",                            "`node:sqlite` sigue tras `--experimental-sqlite` en 22; en 24+ el flag podria sobrar (casos 01 y 02)."),
    "python": ("python",  r"^FROM python:(\d+\.\d+)",                     "El GIL del caso 11: Python 3.13+ con free-threading cambia el argumento."),
    "java":   ("eclipse-temurin", r"^FROM eclipse-temurin:(\d+)",         "`ScopedValue` sale de preview en 25 y deja al `ThreadLocal` del caso 03 como la forma vieja."),
    "dotnet": ("dotnet",  r"^FROM mcr\.microsoft\.com/dotnet/sdk:(\d+\.\d+)", "Primitivas BCL de los casos 04, 09 y 11."),
    "go":     ("go",      r"^FROM golang:(\d+\.\d+)",                     "Cambios de scheduler afectan el argumento del caso 11."),
    "rust":   ("rust",    r"^FROM rust:(\d+\.\d+)",                       "Si `std` incorpora timeout cancelable o semaforo, la limitacion documentada en los casos 04 y 09 deja de ser cierta."),
}


def pinned_versions(pattern: str) -> list[str]:
    """Versiones fijadas hoy, leidas de los Dockerfile del repo.

    Devuelve TODAS las distintas, no solo una: si el portal fija `php:8.2` y los
    casos `php:8.3`, ocultarlo detras de un unico numero seria exactamente el
    drift que este script existe para mostrar.
    """
    found: set[str] = set()
    for df in ROOT.rglob("Dockerfile"):
        try:
            for line in df.read_text(encoding="utf-8", errors="ignore").splitlines():
                m = re.match(pattern, line.strip())
                if m:
                    found.add(m.group(1))
        except OSError:
            continue
    return sorted(found, key=as_tuple)


def fetch(product: str) -> list[dict] | None:
    try:
        req = urllib.request.Request(
            API.format(product=product),
            headers={"User-Agent": "problem-driven-systems-lab/language-drift"},
        )
        with urllib.request.urlopen(req, timeout=20) as resp:
            return json.load(resp)
    except (urllib.error.URLError, json.JSONDecodeError, TimeoutError) as exc:
        print(f"  aviso: no se pudo consultar {product}: {exc}", file=sys.stderr)
        return None


def as_tuple(v: str) -> tuple[int, ...]:
    return tuple(int(x) for x in re.findall(r"\d+", v))


def find_cycle(cycles: list[dict], pinned: str) -> dict | None:
    """Localiza el ciclo upstream para la version fijada.

    endoflife.date publica los ciclos de .NET como `8`, mientras el Dockerfile
    fija `8.0`. Se prueba la coincidencia exacta y luego por prefijo de major.
    """
    for c in cycles:
        if str(c.get("cycle")) == pinned:
            return c
    major = pinned.split(".")[0]
    for c in cycles:
        if str(c.get("cycle")) == major:
            return c
    return None


def main() -> int:
    today = __import__("datetime").date.today().isoformat()
    rows: list[tuple[str, str, str, str, str]] = []
    drift = False

    for stack, (product, pattern, impact) in STACKS.items():
        pinned_list = pinned_versions(pattern)
        if not pinned_list:
            rows.append((stack, "—", "—", "⚠️ sin Dockerfile detectado", ""))
            continue

        pinned = pinned_list[0]              # la mas vieja manda para el veredicto
        shown = " · ".join(f"`{v}`" for v in pinned_list)
        split_note = ""
        if len(pinned_list) > 1:
            split_note = f"**El repo fija {len(pinned_list)} versiones distintas de este stack.** "
            drift = True

        cycles = fetch(product)
        if cycles is None:
            rows.append((stack, shown, "?", "⚠️ upstream no consultable", split_note))
            continue

        latest_cycle = str(cycles[0].get("cycle", "?"))
        mine = find_cycle(cycles, pinned)

        nota = split_note
        if mine is None:
            estado = "⚠️ ciclo no listado upstream"
        else:
            eol = mine.get("eol")
            if eol is True or (isinstance(eol, str) and eol < today):
                fecha = f" ({eol})" if isinstance(eol, str) else ""
                estado = f"🔴 fuera de soporte{fecha}"
                nota += impact
                drift = True
            elif isinstance(eol, str):
                estado = f"🟢 soporte hasta {eol}"
            else:
                estado = "🟢 con soporte"

        if as_tuple(latest_cycle) > as_tuple(pinned):
            estado = f"🟡 hay {latest_cycle} · {estado}"
            if not nota:
                nota = impact
            drift = True

        rows.append((stack, shown, latest_cycle, estado, nota))

    header = "| Stack | Fijada en el repo | Ultima upstream | Estado | Que podria quedar obsoleto |\n|---|---|---|---|---|"
    lines = [f"| `{s}` | {p} | `{l}` | {e} | {n} |" for s, p, l, e, n in rows]
    table = header + "\n" + "\n".join(lines)

    body = (
        "Reporte automatico de **drift de versiones de lenguaje**.\n\n"
        "Comparacion entre la version que fijan los `Dockerfile` del repositorio y el ciclo "
        "de vida publicado en [endoflife.date](https://endoflife.date).\n\n"
        f"{table}\n\n"
        "### Que hacer con esto\n\n"
        "- 🔴 **EOL** — accionable ya: la version que usamos no recibe soporte.\n"
        "- 🟡 **Hay version mas nueva** — no es urgente. Lo que hay que revisar es si la "
        "primitiva que el caso enseña sigue siendo *la forma idiomatica actual*, o si el "
        "lenguaje incorporo algo que la reemplaza.\n\n"
        "👉 **El procedimiento completo esta en "
        "[`docs/language-upgrade-protocol.md`](../blob/main/docs/language-upgrade-protocol.md)**: "
        "checklist de 10 puntos, en que orden revisarlos y como cerrar este issue cuando la "
        "conclusion es \"no aplica\".\n\n"
        "El punto de partida **no** es el `Dockerfile` sino la seccion `Ciclo de versiones` del "
        "perfil del stack en [`docs/languages/`](../blob/main/docs/languages/), donde esta "
        "anotado que primitiva podria quedar obsoleta en el proximo salto. "
        "`scripts/check-language-versions.sh` verifica en cada PR lo determinista (imagen "
        "uniforme y documentacion coherente); el resto es criterio humano.\n\n"
        "_Issue generado por `.github/workflows/language-drift.yml`. Se actualiza solo; "
        "cerralo cuando la revision este hecha._\n"
    )

    print(table)

    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as fh:
            fh.write("## Drift de versiones de lenguaje\n\n" + table + "\n")

    out = os.environ.get("GITHUB_OUTPUT")
    if out:
        with open(out, "a", encoding="utf-8") as fh:
            fh.write(f"drift={'true' if drift else 'false'}\n")
            fh.write("body<<PDSL_EOF\n" + body + "\nPDSL_EOF\n")

    print(f"\ndrift={'true' if drift else 'false'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
