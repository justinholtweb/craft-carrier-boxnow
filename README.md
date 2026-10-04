# Carrier for BOX NOW

BOX NOW parcel lockers for [Carrier](https://justinholt.com/plugins/craft-carrier), on the BOX NOW
Partner API: Greece, Cyprus, Bulgaria, Croatia and Slovenia. Locker delivery, cash on delivery,
labels, cancellation, tracking and the full locker list.

This package translates one API and nothing else. Packing, checkout, the locker picker, label
storage, the claim that stops a label being bought twice and the log are Carrier's.

## Requirements

- Craft CMS 5.3+, Craft Commerce 5, PHP 8.2+
- Carrier 5.0+
- BOX NOW partner credentials (client ID and secret) for your country, and the id of the warehouse
  BOX NOW registered for you. BOX NOW issues these at onboarding.

## Installation

```sh
composer require justinholtweb/craft-carrier-boxnow
php craft plugin/install carrier-boxnow
```

Add a BOX NOW connection under **Carrier → Connections**, choose the country, and press **Test**:
it lists your warehouse ids if the one you entered is wrong.

## What it does

| Feature | Notes |
|---|---|
| Labels | PDF (A6) or ZPL, fetched per parcel; several parcels per order (one compartment each) |
| Cash on delivery | EUR, up to 4,999.99, if your account has the COD permission |
| Cancel | Per parcel, until the parcel is delivered |
| Reprint | One document per order where BOX NOW can |
| Tracking | Polled per parcel |
| Lockers | The whole country's list, synced daily from BOX NOW's public feed |

No live rates (BOX NOW does not quote prices; use a table rate), no collections (couriers collect
from your warehouse on a schedule) and no manifest.

## Things worth knowing

- **One connection, one country.** BOX NOW delivers domestically only and issues credentials per
  country. Slovenia uses the Croatian API host unless BOX NOW gave you another URL.
- **Phones must be international** (`+385 91 …`). National numbers are completed with the
  connection country's code. The customer's name, phone and email are all required, because the
  locker PIN goes out by SMS and email.
- **20 kg per parcel**, and each parcel must fit a compartment: small 8 × 45 × 60 cm, medium 17,
  large 36 cm high. The smallest that fits is chosen from the parcel's dimensions.
- **Order numbers.** BOX NOW never accepts the same order number twice, even after a cancel.
  Carrier sends the order reference plus a short hash of what is being shipped, so a retry of the
  same shipment sends the same number and BOX NOW's rule stops a duplicate delivery. If BOX NOW
  reports the number taken, Carrier checks it: a cancelled predecessor moves on to `-2`; a live
  one is reported as uncertain, because it is almost certainly an earlier attempt whose answer was
  lost.
- **No tracking webhooks.** BOX NOW can push tracking, but documents no way to sign or
  authenticate it, so Carrier polls instead.

## Unverified

Written against the Partner API manual v1.65 and the official HR+SI WordPress plugin without a
live account. These are marked **UNVERIFIED** in `src/carriers/BoxNowCarrier.php`:

- whether `.si` credentials work on the `.hr` host and the other way round;
- that the token endpoint accepts only JSON;
- the delivery request id's field name (`id` or `referenceNumber`; both are read);
- the PDF label's exact paper size, and the ZPL Accept header;
- the Cyprus locker feed's language code, and every stage feed except Croatia's;
- what a locker in state `only-delivery` means;
- the full tracking event vocabulary;
- whether `/parcels` takes more than one parcel id per request;
- public tracking pages outside Croatia.

## Testing

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-carrier/tests/integration/carriers.php boxnow
```

## Licence

The Craft License. See [LICENSE.md](LICENSE.md). Carrier for BOX NOW is free: no editions, no licence key, and no licensing code in the plugin. It needs [Carrier](https://justinholt.com/plugins/craft-carrier), which is commercial.
