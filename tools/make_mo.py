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
header = ('Content-Type: text/plain; charset=UTF-8\n'
          'Content-Transfer-Encoding: 8bit\n'
          'Language: es_ES\n'
          'Plural-Forms: nplurals=2; plural=(n != 1);\n')
entries = [(b'', header.encode('utf-8'))]
entries += [(en.encode('utf-8'), es.encode('utf-8')) for en, es in cat.items()]
dest = os.path.join(ROOT, 'languages', 'keytill-es_ES.mo')
write_mo(entries, dest)
print(f".mo escrito: {dest} ({len(entries)} mensajes)")
