# WP oEmbed Control

Take control of WordPress embeds. Either cache oEmbed responses so a pasted YouTube/Vimeo link stops hitting the remote provider on every page load, or switch embeds off completely.

## Features

- **Shared embed cache** — fetched embeds are stored once per URL in a transient (default 24h) and reused across every post that embeds the same URL, instead of WordPress's per-post cache
- **Disable mode** — removes discovery links, the `wp-embed` script and the oEmbed REST route, so pasted URLs stay plain links
- **Provider allow-list** — one host per line (`youtube.com`, `vimeo.com`); empty means every provider is allowed. Disallowed providers never embed and are never fetched
- **One-click flush** — clear the whole embed cache from the settings page
- **Configurable TTL** — cache lifetime in seconds, filterable at runtime via the `oembed_ttl` filter

## How it works

- **Cache mode (default):** `pre_oembed_result` serves a cached transient before WordPress fetches anything; `oembed_result` stores fresh responses; `oembed_providers` drops disallowed providers from the list.
- **Disable mode:** discovery links, the embed script and the REST route are removed, and `embed_oembed_discover` returns false.

## Filters

- `oembed_ttl` — WordPress core filter, used here to apply your TTL setting (`$ttl`, `$url`, `$args`)

## Requirements

- WordPress 5.8+
- PHP 7.4+

## License

MIT — see [LICENSE](LICENSE).
