# Translations

`smartlinker.pot` is the template every translation starts from. It holds every
translatable string in the plugin's **PHP** — 995 of them.

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

## Known gap: JavaScript is not covered

`wp i18n make-pot` only extracts strings wrapped in a translation function.
Around 78 user-facing strings in `js/admin.js` and `js/editor-sidebar.js` are
still bare English literals, so they are absent from this POT and will stay
English in every locale.

The plumbing for fixing that is already in place — `wp_set_script_translations()`
is called for both handles — so each string only needs wrapping in
`wp.i18n.__( '…', 'smartlinker' )`, after which:

```
wp i18n make-json languages --no-purge
```

generates the JSON files the browser loads. Until then, translators should know
the editor sidebar and the admin JavaScript remain untranslated.
