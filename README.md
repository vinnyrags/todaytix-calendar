# todaytix-calendar

A framework-agnostic **TodayTix availability engine** plus a WordPress (Mythus + IX)
**ticket-calendar block**. It renders a month-by-month performance calendar showing
each performance's buyer-facing state — **available / limited / sold out** — off the
public TodayTix white-label feed, with buy links into the show's white-label booking
flow.

Built for [A View from the Bridge](https://viewfromthebridgeplay.com) (ARTHOUSE), but
deliberately show-agnostic and portable to future shows.

## Architecture

The package is split at one seam: a **pure PHP engine** (zero WordPress) and a thin
**WordPress presentation layer**.

```
src/Engine/                 PURE PHP — unit-tested, reusable, no WP
  Availability.php          available | limited | sold_out
  Showtime.php              id, datetime (show tz), availability, seats, price
  PerformanceRef.php        {id, datetime} — the canonical-store atom
  Http/HttpTransport.php    the one seam to the outside world (interface)
  TodayTixClient.php        feed  -> Showtime[]
  SoldOutResolver.php       canonical vs live feed -> sold-out; grows canonical
  CalendarModel.php         run -> CalendarMonth[]/CalendarDay[] grids
  BuyLinkBuilder.php        showtime -> white-label deep link

src/Providers/TodayTixCalendar/    WordPress glue
  TodayTixCalendarProvider.php     block + cron + REST wiring (IX Provider)
  TodayTixCalendarService.php      transient cache, cron, canonical, overrides
  Http/WpHttpTransport.php         wp_remote_get adapter for the engine seam
  Endpoints/AvailabilityEndpoint.php   read-only cached JSON (optional)
  blocks/calendar/                 block.json, render.php, calendar.twig, style.scss, view.js
```

**Key principle:** request-time render never calls TodayTix. WP-Cron (~10 min) writes
the cache; render only reads it. An API outage serves last-known-good — never a blank
or a fatal.

**Sold-out detection:** TodayTix drops a performance from the feed once it can't be
sold, so the feed alone can't report sold-out. The service keeps an ever-growing
*canonical schedule* of every performance seen; any canonical performance now missing
from the feed is reported SOLD OUT.

## Consuming it (WordPress)

1. Require the package (path repo during dev, satis once tagged).
2. Add its `src/Providers` to the theme's `build-providers` `extraProviderDirs`.
3. Register `TodayTixCalendarProvider` in the theme's `$providers`.
4. Supply site config via the `todaytix_calendar/config` filter:
   ```php
   add_filter('todaytix_calendar/config', fn () => [
       'show_id'   => 46495,
       'base_url'  => 'https://tickets.viewfromthebridgeplay.com',
       'timezone'  => 'America/New_York',
       'run_start' => '2026-11-27',
       'run_end'   => '2027-02-27',
   ]);
   ```
5. Insert the **Ticket Calendar** block into page content.

Reskin per show by overriding the `--ttx-*` CSS custom properties on `.ttx-calendar`
in the child theme — markup and engine are untouched.

## Development

```bash
composer install
composer test        # 36 unit tests, pure engine, no WordPress booted
```

## License

Proprietary. © Vincent Ragosta. Retained ownership (licensed to consuming projects,
not transferred).
