#!/usr/bin/env python3
"""
Genera los catálogos JSON que consume wp.i18n en el navegador, uno por handle:

    languages/keytill-es_ES-<handle>.json

Es lo que busca wp_set_script_translations( <handle>, 'keytill', .../languages ).
Formato jed 1.x, el mismo que produce `wp i18n make-json`.

Cada archivo lleva SOLO los mensajes que usa ese script, para no mandar al
cliente el catálogo entero en cada pestaña.

Uso:  python3 tools/make_json.py
"""
import glob
import json
import os
import re
from datetime import datetime, timezone

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOCALE = 'es_ES'

# handle del script  ->  archivo fuente
HANDLES = {
    'keytill-app': 'assets/js/pos.js',
    'keytill-orders': 'assets/js/pos-orders.js',
    'keytill-products': 'assets/js/pos-products.js',
    'keytill-customers': 'assets/js/pos-customers.js',
    'keytill-cashbank': 'assets/js/pos-cashbank.js',
}

# Los templates traducen desde las expresiones de Alpine con keytillT(), que
# vive en pos.js: sus mensajes van al catálogo del handle de la app.
TEMPLATE_HANDLE = 'keytill-app'
TEMPLATES = ('templates/pos-app.php',)

# __( 'texto', 'keytill' ) — también dentro de sprintf()
PAT = re.compile(r"__\(\s*(['\"])((?:\\.|(?!\1)[^\\])*?)\1\s*,\s*['\"]keytill['\"]\s*\)")

# keytillT( 'texto' [, args...] ) en los atributos Alpine de los templates
PAT_T = re.compile(r"keytillT\(\s*'((?:\\.|[^'\\])*)'")

_ESC = {'n': '\n', 't': '\t', 'r': '\r', 'b': '\b', 'f': '\f', 'v': '\v',
        '0': '\0', '\\': '\\', "'": "'", '"': '"', '\n': ''}


def js_unescape(text):
    """
    Resuelve los escapes de un literal JS al texto que existe en runtime.

    Hace falta porque el msgid tiene que ser EXACTAMENTE el string que recibe
    __() al ejecutarse: en el fuente dice «\\n» (dos caracteres) pero en memoria
    es un salto de línea, y si el catálogo guarda los dos caracteres el mensaje
    nunca matchea y se queda sin traducir.
    """
    out, i, n = [], 0, len(text)
    while i < n:
        c = text[i]
        if c != '\\' or i + 1 >= n:
            out.append(c)
            i += 1
            continue
        nxt = text[i + 1]
        if nxt == 'u' and i + 5 < n:
            out.append(chr(int(text[i + 2:i + 6], 16)))
            i += 6
        elif nxt == 'x' and i + 3 < n:
            out.append(chr(int(text[i + 2:i + 4], 16)))
            i += 4
        else:
            out.append(_ESC.get(nxt, nxt))
            i += 2
    return ''.join(out)


def msgids(path):
    """Los msgids que usa un archivo, tal como los ve __() en runtime."""
    src = open(path, encoding='utf-8').read()
    return {js_unescape(m.group(2)) for m in PAT.finditer(src)}


def main():
    raw = json.load(open(os.path.join(ROOT, 'tools', 'catalog.json'),
                         encoding='utf-8'))
    # El catálogo viene del fuente, así que sus claves traen los escapes sin
    # resolver; lo normalizamos a texto de runtime para que matcheen los msgids.
    catalog = {js_unescape(en): js_unescape(es) for en, es in raw.items()}
    stamp = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S+00:00')
    total, missing = 0, []

    tpl_ids = set()
    for rel in TEMPLATES:
        src = open(os.path.join(ROOT, rel), encoding='utf-8').read()
        tpl_ids |= {js_unescape(m.group(1)) for m in PAT_T.finditer(src)}

    for handle, rel in HANDLES.items():
        ids = msgids(os.path.join(ROOT, rel))
        if handle == TEMPLATE_HANDLE:
            ids |= tpl_ids
        msgs = {'': {'domain': 'messages',
                     'lang': LOCALE,
                     'plural-forms': 'nplurals=2; plural=n != 1;'}}
        for mid in sorted(ids):
            if mid in catalog:
                msgs[mid] = [catalog[mid]]
            else:
                missing.append((handle, mid))

        out = os.path.join(ROOT, 'languages', f'keytill-{LOCALE}-{handle}.json')
        json.dump({
            'translation-revision-date': stamp,
            'generator': 'keytill tools/make_json.py',
            'domain': 'messages',
            'locale_data': {'messages': msgs},
        }, open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
        n = len(msgs) - 1
        total += n
        print(f"  {os.path.basename(out)}: {n} mensajes")

    print(f"total: {total} mensajes en {len(HANDLES)} archivos")
    if missing:
        print(f"SIN TRADUCCION ({len(missing)}):")
        for h, m in missing:
            print(f"   {h}: {m!r}")
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
