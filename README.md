# Mortez · The Chateau

The band's 3D website: a haunted estate you walk through in the browser. Going live at **chateau.mortezmusic.com**.

## What's where
| Path | What it is |
|---|---|
| `site/index.html` | The whole site: the 3D world plus the classic (flat) view. Almost every change happens here. |
| `site/media/` | The Reaper song for the lyric-video coaster, the share image and the icon |
| `site/contact.php` | Sends the Booking form to the band's inbox (PHP hosting) |
| `site/admin.php` | Saves the Admin switches and lyric timings for everyone (PHP hosting). **Set its password before launch.** |
| `site/music/ videos/ story/ merch/ about/ contact/ tour/` | Small plain pages for search engines |
| `deploy/` | The staging `.htaccess` (keeps a private preview out of search engines) and the old Netlify preview headers |
| `docs/` | The full lyric sheet and the pamphlet |

## Where the content lives in `site/index.html`
Search for these names:
- `SONGBOOK`: the library. There's one bookcase per release, and each has a `theme`, a `date` and `songs` (title, lyrics, `url`). Adding a `url` shows a **Listen** button.
- `ROOMS`: the Chapel Theater (videos) and the Merch shop. Discography, Tour and Booking rooms are switched off with `off: true`.
- `STORY`: the band story stops on the crypt ride.
- `SONG` and `LYRICS`: the Reaper coaster and its lyric timings.

## Deploying (Namecheap Stellar Plus, cPanel)
1. In **Domains**, create the `chateau.mortezmusic.com` subdomain.
2. In **File Manager**, upload the contents of `site/` into the subdomain's folder.
3. In **SSL/TLS Status**, run AutoSSL for the subdomain.
4. Set a real password in `admin.php` (replace `change-me-before-launch`). Don't commit the real password.
5. Create the `noreply@mortezmusic.com` mailbox, then send a test from the Booking button.
6. Before launch, `site/robots.txt` still blocks search engines. Switch it to allow them when the site goes live.

## Status (Nov 2026)
**Done:**
- The grounds, foyer, wings and courtyard.
- The dining hall and the haunted hall.
- The library, with 10 themed bookcases and lyrics for all 12 songs.
- The Chapel Theater and the Merch shop.
- The crypt and the Reaper coaster.
- Hauntings, the relics hunt, Play together and the Booking form.

**Still placeholder:** Theater videos, merch products and checkout, the band bio and story stops, streaming links, and the real booking inbox.


## Site structure (Nov 2026)

- `site/index.html`: the classic home page (mortezmusic.com). The hero is the Chateau gate; every section has a doorway into a room.
- `site/chateau/`: the 3D world. Deep links: `/chateau/?room=library|merch|film|station|foyer`, or the short routes in `site/.htaccess` (`/chateau/store`, `/chateau/library`, `/chateau/theater`, `/chateau/reaper`).
- `site/shop/`: the flat online shop (products arrive from the Printful sync, `merch.php`, still to come).
- `site/data/releases.js`: every release and lyric. The Chateau library and the classic Lyrics section both read it.
- `site/coven.php`: mailing-list sign-ups, saved to `coven-subscribers.csv` one level above the web root.
- Links, videos and room cards on the home page: the `LINKS`, `VIDEOS` and `ROOMS` lists near the top of the script in `site/index.html`.

## The live world (Cloudflare)

- `live/worker.js` is the always-on world: one Durable Object passes positions, emotes, preset phrases and the Chapel's shared screening between everyone in the Chateau. Free text chat is never relayed (it stays in private Play together sessions).
- Deployed as the `mortez-live` Worker: `wss://mortez-live.connor-832.workers.dev/world`. The address is the `LIVE` constant in `site/chateau/index.html` (or `?live=` to test another).
- Redeploy: `CF_TOKEN_FILE=~/.mortez/cloudflare-token CF_ACCOUNT=<account id> python3 scripts/deploy_live.py` (or `npx wrangler deploy` in `live/`). The token is never stored in this repo.
- Allowed sites: mortezmusic.com and its subdomains, localhost and *.netlify.app. Add others with the `EXTRA_ORIGINS` variable.
- Turn it off without a deploy: the "Live world" switch in admin.

## Before launch
- Switch `site/robots.txt` from `Disallow: /` to allow search engines.
