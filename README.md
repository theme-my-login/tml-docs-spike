# tml-docs-spike

Throwaway. Proves out a generated developer reference for the TML plugin family, of the kind
developer.wordpress.org publishes: functions, hooks, classes and methods, all from the docblocks
already in the source.

Currently documents `theme-my-login` only. The 12 extension repos are private and paid, and
their API surface shouldn't land on a public URL as a side effect of a spike.

## How it works

1. `bin/install-parser.sh` vendors [WordPress/phpdoc-parser][parser] at a pinned commit into
   `.parser/`. It can't be a normal Composer dependency: it pins a branch of a personal fork of
   `phpdocumentor/reflection-docblock` whose own default branch aliases to a colliding version,
   so the graph only resolves against the lock file in its repository.
2. `bin/parse.php <repo> <out.json>` parses the source into phpdoc-parser's JSON model. Its own
   CLI takes a directory and recurses it blindly, which means parsing every vendored copy of
   WordPress, so this drives `parse_files()` off `git ls-files` instead. Naming files parses only
   those and merges them into an existing JSON by path.
3. `bin/render.php <in.json> <out-dir> <owner/repo> <ref>` writes the static site.

```
bash bin/install-parser.sh
php -dmemory_limit=2g bin/parse.php ../theme-my-login api.json
php bin/render.php api.json build theme-my-login/theme-my-login master
```

`.github/workflows/pages.yml` runs the same three steps and deploys to Pages, on a push to `bin/`
or on a `source-changed` repository dispatch from the plugin repo.

## Notes for the real thing

- Parsing all 13 repos takes **1.4 seconds** total. Incremental parsing is implemented and works,
  but there is no reason to use it; filter on changed paths at the workflow trigger and rebuild
  the whole site each time.
- The JSON carries signatures, docblocks, line numbers and a call graph. It carries no source
  bodies. Publishing a site built from the private extensions still discloses their API surface
  and what calls what, which is a decision to make deliberately rather than by default.
- Dynamic hook names render as `tml_activate_{$slug}` only because the source interpolates a local
  instead of concatenating a method call. Concatenated method calls come out as
  `tml_activate_{$this->get_slug()}`.

[parser]: https://github.com/WordPress/phpdoc-parser
