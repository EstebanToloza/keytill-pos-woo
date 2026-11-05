#!/usr/bin/env python3
"""
Traduce los strings traducibles del código fuente (español -> inglés) y genera
de paso el catálogo español, aprovechando que el fuente original ESTÁ en español.

Uso:
    python3 tools/i18n_apply.py tools/dict/<archivo>.json [--dry]

El diccionario es {"texto en español": "English text"}. El script:
  1) reemplaza el español por el inglés SOLO dentro de llamadas i18n
     ( __(), _e(), esc_html__(), esc_html_e(), esc_attr__(), esc_attr_e() ),
  2) acumula el par inverso en tools/catalog.json  -> con eso se arma el .po/.pot,
  3) nunca toca HTML suelto ni comentarios.

No se incluye en el zip del plugin (ver .distignore).
"""
import json
import re
import sys
import glob
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CATALOG = os.path.join(ROOT, 'tools', 'catalog.json')

FUNCS = r'__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e'
# Captura: func( 'texto'   -> grupos: 1=func 2=comilla 3=texto
PAT = re.compile(r"\b(" + FUNCS + r")\(\s*(['\"])((?:\\.|(?!\2).)*?)\2", re.S)


def php_escape(text: str, quote: str) -> str:
    """Escapa el texto para quedar dentro de comillas PHP del mismo tipo."""
    out = text.replace('\\', '\\\\').replace(quote, '\\' + quote)
    return out


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 1
    dry = '--dry' in sys.argv
    with open(sys.argv[1], encoding='utf-8') as fh:
        mapping = json.load(fh)

    files = [f for f in glob.glob(os.path.join(ROOT, '**', '*.php'), recursive=True)
             if '/.git/' not in f and '/tools/' not in f]

    changed, hits, missing = 0, 0, set()

    for path in files:
        src = open(path, encoding='utf-8').read()

        def repl(m):
            nonlocal hits
            func, quote, text = m.group(1), m.group(2), m.group(3)
            # el texto en el archivo viene escapado; lo comparamos "crudo"
            raw = text.replace("\\'", "'").replace('\\"', '"')
            if raw in mapping:
                hits += 1
                return f"{func}( {quote}{php_escape(mapping[raw], quote)}{quote}"
            missing.add(raw)
            return m.group(0)

        new = PAT.sub(repl, src)
        if new != src:
            changed += 1
            if not dry:
                open(path, 'w', encoding='utf-8').write(new)

    # Catálogo acumulado: msgid (inglés) -> msgstr (español original)
    if not dry:
        cat = {}
        if os.path.exists(CATALOG):
            cat = json.load(open(CATALOG, encoding='utf-8'))
        for es, en in mapping.items():
            cat[en] = es
        json.dump(cat, open(CATALOG, 'w', encoding='utf-8'),
                  ensure_ascii=False, indent=1, sort_keys=True)

    print(f"archivos modificados: {changed} · reemplazos: {hits} · "
          f"en el diccionario: {len(mapping)}")
    if missing:
        print(f"strings AÚN en español (sin traducir): {len(missing)}")
    return 0


if __name__ == '__main__':
    sys.exit(main())
