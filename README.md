# Tudásfa dokumentumok (WordPress bővítmény)

A Tudásfa Tanoda Alapítványi Óvoda weboldalának Dokumentumok oldala.

- `docs/` – a PDF-ek
- `dokumentumok.json` – a Dokumentumok oldal (Elementor) és a stílus
- `tudasfa-dokumentumok.php` – a bővítmény; a `Version:` sor emelésekor az élő oldal óránként ellenőriz, és magától frissül

Kézi azonnali frissítés (admin): `/wp-admin/?tudasfa_frissit=1`

## Célzott módosítások (valtozasok.json)
Minden tétel egyszer fut le, azonosító alapján. Ha a keresett szöveget/képet nem találja (mert kézzel átírták), nem ír felül semmit, csak naplózza.
- szöveg: `{"id":"…","tipus":"szoveg","hol":"oldal-slug|lablec|fejlec|*","regi":"…","uj":"…"}`
- kép: `{"id":"…","tipus":"kep","hol":"…","regi":"regi-fajlnev.webp","uj":"media/uj.jpg","alt":"…"}`

Állapot: `/wp-json/tudasfa/v1/allapot`
