# HeroWO's HoMM 3 Map Processor

Produced by the HeroWO.js project - a JavaScript re-implementation of *Heroes of Might and Magic III*.

https://github.com/HeroWO-js/Workbench

https://herowo.game

## h3m2json.php

- Parses `.h3m` files into various formats (uncompressed `.h3m`, JSON, PHP `serialize()`).
- Compiles those formats back into `.h3m`. Can convert between different game versions.
- Performs excessive error-checking (lots of potential warnings).
- Provides ample debug information (byte-by-byte stream analysis, relation to parsed structures).
- Built around an extensible architecture with current support of all official HoMM 3 versions (RoE, AB, SoD).
- ...and HotA, but keeps raw values for non-official content (e.g. numeric identifiers for HotA-specific terrain).
- Written in an easy-to-follow manner. No C, please!
- Can be called from CLI or `include`'d into your own script.

Run `php h3m2json.php -h` for details.

## h3m-The-Corpus.txt

Documents the entire .h3m binary file format based on multiple sources, primarily on [homm3tools](https://github.com/potmdehex/homm3tools).
