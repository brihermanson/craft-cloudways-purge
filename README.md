# Cloudways cache purge for Craft CMS

A small Craft 5 module that clears the hosting cache when content changes.

## Problem

A Craft site behind a Varnish proxy was serving stale pages to anonymous
visitors. Editors saw their changes immediately (a logged-in session
bypasses Varnish) while the public saw an older render, so the reports were
intermittent and hard to pin down.

Diagnosis came from the response headers:

```
curl -sI https://example.com/page | grep -iE 'x-cache|age'
x-cache: HIT
age: 117295
```

A 32-hour-old cached object, and no `Cache-Control` from the origin, so
Varnish was applying its own TTL. The host's cache lifetime was set to 30
days. Nothing told Varnish when an entry was saved, so edits stayed invisible
until the TTL expired.

## Approach

Two changes:

1. Lower the TTL so a missed purge degrades to hours, not weeks.
2. Purge on save, so the cache clears when content actually changes.

The module handles the second. It listens for element saves and deletes,
queues a single job per request, and calls the host's API from that job.

## Installation

1. Copy `modules/cachepurge/` into your Craft project's `modules/` directory.
2. Confirm `composer.json` maps the namespace:

   ```json
   "autoload": {
       "psr-4": {
           "modules\\": "modules/"
       }
   }
   ```

   Run `composer dump-autoload` after adding it.

3. Register the module in `config/app.php`, merging with whatever is
   already there:

   ```php
   return [
       'modules' => [
           'cache-purge' => \modules\cachepurge\Module::class,
       ],
       'bootstrap' => ['cache-purge'],
   ];
   ```

4. Add the environment variables (see below) on production only.

Deploy order matters: the autoloader must know about the namespace before
`config/app.php` lists the module in `bootstrap`, or every request fails
with a class-not-found error.

## Configuration

```
CLOUDWAYS_ACCESS_TOKEN=""
CLOUDWAYS_SERVER_ID=""
CLOUDWAYS_APP_ID=""
CLOUDWAYS_API_BASE=""
CLOUDWAYS_PURGE_SERVER_VARNISH=false
```

The app-level purge clears one application; the server-level Varnish purge
clears every app on the server, so it's off by default. Create the access
token with the narrowest scope that covers the purge endpoints.

## Notes on the implementation

- **Queued, not inline.** The API call runs after the save commits, so a
  slow or failing API can't block an editor's save.
- **One job per request.** A save that touches many elements (propagation,
  resaves) queues one purge, not dozens.
- **Drafts and revisions skipped.** Autosave fires constantly; purging on
  every keystroke would be pointless and rate-limited.
- **Fails loudly in the right place.** Failures throw with the API's own
  response text, which surfaces in Utilities > Queue Manager.
- **Inert without config.** No access token means no listeners, so local and
  staging environments do nothing.

## Known gaps

- Future-dated entries go live without a save, so nothing triggers a purge.
  A short TTL or a scheduled job covers that.
- Template and asset deploys need a purge step of their own, since cached
  HTML still references the previous build's asset hashes.
- Failures are silent to editors. If that matters, the job can notify on
  failure.

## License

MIT
