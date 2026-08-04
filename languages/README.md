# Translations

`smartlinker.pot` is the template every translation starts from. It holds every
translatable string in the plugin — **1,108** of them, PHP and JavaScript.

## Regenerating the POT

Run this after adding or changing any user-facing string:

```
wp i18n make-pot . languages/smartlinker.pot --slug=smartlinker --domain=smartlinker --package-name="SmartLinker" --exclude=vendor,tests,node_modules
```

## Adding a language

1. Copy `smartlinker.pot` to `smartlinker-<locale>.po` (e.g. `smartlinker-af.po`)
2. Fill in the `msgstr` lines
3. Compile it: `msgfmt smartlinker-af.po -o smartlinker-af.mo`

WordPress looks in `wp-content/languages/plugins/` first and falls back to this
directory, so either location works.

## JavaScript translations need a second step

PHP reads `.mo` files. JavaScript cannot, so the browser is served JSON:

```
wp i18n make-json languages --no-purge
```

Run it after compiling each `.po`. It writes one JSON file per script, named
`smartlinker-<locale>-<md5 of the script path>.json`, which is how WordPress
finds them.

### Never name a JavaScript file `*admin.js`

That md5 is taken from the script's path after a `.min.js` suffix is stripped,
so translations key off the unminified name. **WordPress core checks that
suffix strictly** (`str_ends_with($relative, '.min.js')` — the dot included).
**`wp i18n make-json` checks it loosely**, on the letters `min.js` alone.

For a file called `admin.js`, make-json sees `...dmin.js`, strips seven
characters, and writes the JSON under the hash for `js/a.js`. Core then looks
up the hash for `js/admin.js`, finds nothing, and every string in that file
silently stays English — no error, no warning.

This is why the admin script is `js/admin-ui.js`. Any name ending in the
letters `min.js` hits it.

Verify after generating, rather than trusting it:

```
python3 -c "import json,hashlib,glob; [print(json.load(open(f))['source'], hashlib.md5(json.load(open(f))['source'].encode()).hexdigest() in f) for f in glob.glob('languages/*.json')]"
```

Every line must end in `True`.
