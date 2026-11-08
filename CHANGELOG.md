# Release Notes for Carrier for BOX NOW

## 5.1.0 - 2026-11-08

- A points-only connection: the client ID, client secret and origin ID are needed only for labels and tracking, so the locker picker can run without them (requires Carrier 5.1). The public locker feed no longer receives the OAuth token. ([craft-carrier#4](https://github.com/justinholtweb/craft-carrier/issues/4))

## 5.0.0 — 2026-10-03

Initial release.

### Added

- BOX NOW Partner API carrier for Carrier, for Greece, Cyprus, Bulgaria, Croatia and Slovenia,
  with per-country hosts and an API URL override.
- Locker delivery requests with cash on delivery, automatic compartment sizing and international
  phone normalisation.
- Deterministic order numbers, so a retried purchase can never create a second delivery.
- PDF and ZPL labels, per-parcel cancellation, reprints and tracking.
- Full locker list synced from BOX NOW's public location feed.
- Connection test that lists the account's registered warehouses.
