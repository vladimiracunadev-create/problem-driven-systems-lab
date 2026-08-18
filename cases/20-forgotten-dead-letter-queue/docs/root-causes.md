# 🧠 Causas raíz

## 1. El consumidor no clasifica el error

La causa central. Un `catch (Exception)` —o su equivalente en cada lenguaje— trata igual a un timeout que a un JSON malformado. El primero se resuelve reintentando; el segundo no se resuelve nunca.

Y cada stack pone una barrera distinta contra esto:

| Stack | Contra clasificar mal |
|---|---|
| 🦀 Rust | `enum` + `match` exhaustivo: **una variante nueva no compila** |
| 🔵 .NET | `catch (Ex e) when (...)`: filtra **sin desenrollar** la pila |
| ☕ Java | Jerarquía `sealed`: una clase nueva debe declararse en `permits` |
| 🐹 Go | `errors.Is` / `errors.As` sobre cadenas `%w` |
| 🐘 PHP | `catch (A \| B $e)` — sin exhaustividad |
| 🐍 Python | Jerarquía de excepciones — sin exhaustividad |
| 🟢 Node | `instanceof`, frágil entre paquetes y workers |

## 2. La DLQ no tiene profundidad publicada

Una cola cuya profundidad no es una métrica no se puede alertar. Y lo que no se alerta, no se mira.

## 3. No se guarda por qué falló

Sin clase de error y sin una muestra del payload, la DLQ es una lista de identificadores. Depurarla obliga a reprocesar a ciegas, que es exactamente lo que nadie quiere hacer con cuatrocientos mil mensajes.

## 4. No hay salida

La mitad que casi nunca se construye. Sin un comando de replay, el único camino de vuelta es un script improvisado en medio de un incidente — que es cuando peor se escriben los scripts.

## 5. El `catch` genérico se traga los bugs propios

La causa más traicionera. Un `except Exception`, un `catch (Throwable)` o un `catch (Exception)` alrededor del procesamiento manda a la DLQ **también los bugs del consumidor**: un `KeyError` por un typo, un `NullPointerException` de un refactor a medias, un `TypeError`.

**Esos mensajes no son venenosos. Son correctos, y el código está roto.** Terminan en la DLQ indistinguibles del resto, y cuando alguien la revisa meses después la conclusión es «datos malos» en vez de «tuvimos un bug tres semanas».

> Rust es el único stack donde eso no puede pasar: **un `panic!` no es un `Result`**. Un bug del consumidor no viaja por el mismo canal que un error de datos, así que no puede disfrazarse de mensaje venenoso.

<!-- nav-case-doc -->
---

**Caso 20 · La dead letter queue olvidada** — [⬅️ README del caso](../README.md) · [⚖️ Comparativa de los 7 stacks](../comparison.md)

[🗺️ Contexto](context.md) · [🩺 Síntomas](symptoms.md) · [🔍 Diagnóstico](diagnosis.md) · **🧠 Causas raíz** · [🛠️ Opciones de solución](solution-options.md) · [⚖️ Trade-offs](trade-offs.md) · [💼 Valor de negocio](business-value.md) · [🚨 Postmortem](postmortem.md)
<!-- /nav-case-doc -->
