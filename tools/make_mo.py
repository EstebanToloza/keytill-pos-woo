#!/usr/bin/env python3
"""Compila un .po a .mo sin depender de gettext/msgfmt (formato MO estandar)."""
import json, os, struct, sys
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

def write_mo(entries, path):
    """entries: lista de (msgid, msgstr) en bytes. Incluye la cabecera ''."""
    entries = sorted(entries, key=lambda e: e[0])
    n = len(entries)
    # offsets: 7 enteros de cabecera = 28 bytes, luego 2 tablas de n*8
    o_orig = 28
    o_tran = o_orig + n * 8
    o_data = o_tran + n * 8
    otab, ttab, data = b'', b'', b''
    for msgid, msgstr in entries:
        otab += struct.pack('<II', len(msgid), o_data + len(data)); data += msgid + b'\x00'
    for msgid, msgstr in entries:
        ttab += struct.pack('<II', len(msgstr), o_data + len(data)); data += msgstr + b'\x00'
    out = struct.pack('<Iihhiii', 0x950412de, 0, n, n, o_orig, o_tran, 0) [:28]
    # (struct de arriba puede variar por padding: lo armamos explicito)
    out = struct.pack('<II', 0x950412de, 0) + struct.pack('<IIII', n, o_orig, o_tran, 0) + struct.pack('<I', o_data)
    out = out[:28]
    open(path, 'wb').write(out + otab + ttab + data)

cat = json.load(open(os.path.join(ROOT, 'tools', 'catalog.json'), encoding='utf-8'))
# Pares singular->plural de las llamadas _n(). En el formato MO un plural NO son
# dos entradas sueltas: es UNA entrada cuya clave es "singular\0plural" y cuyo
# valor es "traduccion_singular\0traduccion_plural". Si se guardan por separado,
# ngettext() no encuentra la forma plural y devuelve siempre el singular.
PLURALS = os.path.join(ROOT, 'tools', 'plurals.json')
plurals = json.load(open(PLURALS, encoding='utf-8')) if os.path.exists(PLURALS) else {}

header = ('Content-Type: text/plain; charset=UTF-8\n'
          'Content-Transfer-Encoding: 8bit\n'
          'Language: es_ES\n'
          'Plural-Forms: nplurals=2; plural=(n != 1);\n')
entries = [(b'', header.encode('utf-8'))]
consumed = set()
for sing, plur in sorted(plurals.items()):
    if sing not in cat or plur not in cat:
        print(f"  AVISO: par de plural sin traduccion completa: {sing!r}")
        continue
    key = sing.encode('utf-8') + b'\x00' + plur.encode('utf-8')
    val = cat[sing].encode('utf-8') + b'\x00' + cat[plur].encode('utf-8')
    entries.append((key, val))
    consumed.update((sing, plur))
entries += [(en.encode('utf-8'), es.encode('utf-8'))
            for en, es in cat.items() if en not in consumed]
dest = os.path.join(ROOT, 'languages', 'keytill-es_ES.mo')
write_mo(entries, dest)
print(f".mo escrito: {dest} ({len(entries)} mensajes, {len(plurals)} con plural)")
