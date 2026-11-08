<?php

namespace justinholtweb\carrierboxnow\carriers;

use Craft;
use justinholtweb\carrier\base\ActionResult;
use justinholtweb\carrier\base\AuthInterface;
use justinholtweb\carrier\base\Capabilities;
use justinholtweb\carrier\base\Carrier;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\base\HealthResult;
use justinholtweb\carrier\base\LabelResult;
use justinholtweb\carrier\base\PointPage;
use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\base\ShipmentResult;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\base\Transport;
use justinholtweb\carrier\helpers\Addresses;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\helpers\Units;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\Label;
use justinholtweb\carrier\models\canonical\Parcel;
use justinholtweb\carrier\models\canonical\PickupPoint;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\models\canonical\ShipmentRef;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;
use justinholtweb\carrierboxnow\auth\BoxNowAuth;

/**
 * BOX NOW parcel lockers (Greece, Cyprus, Bulgaria, Croatia, Slovenia), on the Partner API.
 *
 * Every delivery goes to a locker, so there is one service and it is `points: true`. A connection
 * is one country: BOX NOW is domestic-only, its credentials are issued per country, and so is the
 * API host — HR and SI share the `.hr` host (the official HR+SI WordPress plugin offers only
 * that), GR, BG and CY have their own. BOX NOW hands out the API URL with the credentials, so it
 * can be overridden in settings.
 *
 * Lockers come from the public, unauthenticated location feed
 * (`locationapi-*.boxnow.{cc}/v1/apms_{lang}-{CC}.json`), one whole country per request, which is
 * exactly what a `list`-mode sync wants. The authenticated `/api/v1/destinations` search defaults
 * to 50 results, so it is not used for the sync. The feed has no cursor and is returned as one
 * page. Only the connection's own country is synced: a locker in another country is one this
 * connection could never ship to.
 *
 * **Order numbers.** BOX NOW requires `orderNumber` to be unique *forever* — P410 on any reuse,
 * even after a cancel — and the official plugin appends five random digits. Random is the wrong
 * choice here: Carrier may retry a purchase the carrier refused, and if a refusal was in fact a
 * lost success, a random suffix would create a second delivery. Instead the suffix is a short hash
 * of what is being sent (reference, service, locker, parcels, COD), so a retry of the *same*
 * shipment sends the *same* order number and BOX NOW's own uniqueness rule becomes the idempotency
 * key. On P410 this class asks BOX NOW what that order number holds: if every parcel was
 * cancelled (a voided shipment being sent again), it moves to `-2`, `-3`; if anything is live, the
 * result is **uncertain** with the existing ids, because that is almost certainly an earlier
 * attempt whose answer was lost. A merchant who genuinely ships an identical second consignment
 * while the first is live can pass `options['boxnowOrderNumber']`.
 *
 * Vendor traps, from the Partner API manual v1.65 (checked against the manual's text, not only
 * the research summary) and the official HR+SI WordPress plugin:
 * - Money is **strings** (`"49.90"`); `weight` is a number, in kg ("if unknown pass 1").
 * - COD amount must be in (0, 5000) and needs the account's `codPayment` permission (P411). There
 *   is no currency field: it is the partner's currency, EUR in every BOX NOW country (Bulgaria
 *   since 2026-01-01). A non-EUR COD is refused here rather than collected in the wrong money.
 * - Phone numbers must be **full international format** (P405/C404). Recipient name, phone and
 *   email are all required — the locker PIN goes out by SMS and email.
 * - One item is one parcel is one locker compartment: 1 small (8 × 45 × 60 cm), 2 medium (17 cm
 *   high), 3 large (36 cm). 20 kg per parcel. Anything that fits no compartment is refused here.
 * - Errors are real 4xx with `{"code":"P4xx","message":…}`; 503 P600/P610 are the locker bridge
 *   or geocoder being down.
 * - Locker `lat`/`lng` are **strings** in the feed. `addressLine2` is the city. There are no
 *   opening hours (lockers are presumed 24/7; some are inside shops, see `note`).
 * - Labels are raw bytes from `GET /parcels/{id}/label.pdf` (or `.zpl?dpi=200`); the extension and
 *   the Accept header both matter, because the same path can also answer JSON label data.
 * - Cancel is per parcel (`POST /parcels/{id}:cancel`), idempotent, and refused once a parcel is
 *   delivered (P420). A delivery request has no cancel of its own.
 * - Tracking states are spelled both `canceled` and `cancelled`; both are mapped. There is no
 *   out-for-delivery state: a parcel in the locker is `final-destination`, ready for pickup.
 * - No rates (use table rates), no pickups (couriers collect from the registered warehouse on a
 *   schedule), no manifest.
 *
 * Not declared: `WEBHOOKS`. BOX NOW can push CloudEvents-style tracking, but no signature or
 * authentication scheme is documented, and an unauthenticated tracking endpoint would let anyone
 * mark an order delivered. Polling `/parcels` covers it.
 *
 * UNVERIFIED — confirm on the stage host before relying on them:
 * - Whether `.si` credentials work on the `.hr` host and vice versa (SI defaults to `.hr`).
 * - That the token endpoint accepts only JSON (it is sent JSON, as documented).
 * - The delivery request's own id: §4.4 of the manual answers `referenceNumber`, the appendix
 *   and the HR plugin `id`; both are read. (`parcels[].id` is the same everywhere.)
 * - The PDF label's exact paper size (treated as A6) and the ZPL Accept header.
 * - The CY locker feed's language code (`el-CY` here; only HR, SI, GR and BG were probed), and
 *   every stage feed except HR's.
 * - What `state: only-delivery` means for a locker (kept, and recorded in `extra`).
 * - The full webhook/event `type` vocabulary; unknown states map to in transit.
 * - That `GET /parcels` takes one `parcelId` per request (tracking is not batched).
 * - The public tracking page for countries other than Croatia (`t.boxnow.hr` is used for all).
 */
class BoxNowCarrier extends Carrier
{
    /**
     * The point feed is public, so these credentials are needed for everything except points.
     * A shop can run the picker before its contract is signed (core #4). Listed rather than
     * `Feature::allExcept()` so the add-on still loads on core 5.0.
     */
    private const NOT_POINTS = [
        Feature::RATES, Feature::LABELS, Feature::CANCEL, Feature::REPRINT, Feature::TRACKING,
        Feature::PICKUPS, Feature::CLOSE_DAY, Feature::ADDRESS_VALIDATION, Feature::WEBHOOKS,
    ];

    /** 20 kg, every compartment. */
    private const MAX_WEIGHT_G = 20000;

    /** Compartment => internal height in mm. Every compartment is 450 mm wide and 600 mm deep. */
    private const COMPARTMENTS = [1 => 80, 2 => 170, 3 => 360];

    /**
     * Per country: the API host's TLD, the locker feed's TLD and language, the calling code.
     */
    private const COUNTRIES = [
        'HR' => ['api' => 'hr', 'feed' => 'hr', 'lang' => 'hr-HR', 'calling' => '385'],
        'SI' => ['api' => 'hr', 'feed' => 'si', 'lang' => 'sl-SI', 'calling' => '386'],
        'GR' => ['api' => 'gr', 'feed' => 'gr', 'lang' => 'el-GR', 'calling' => '30'],
        'BG' => ['api' => 'bg', 'feed' => 'bg', 'lang' => 'bg-BG', 'calling' => '359'],
        'CY' => ['api' => 'cy', 'feed' => 'cy', 'lang' => 'el-CY', 'calling' => '357'],
    ];

    private const STATUS_MAP = [
        'new' => TrackingStatus::PRE_TRANSIT,
        'in-depot' => TrackingStatus::IN_TRANSIT,
        'in-transit' => TrackingStatus::IN_TRANSIT,
        'wait-for-load' => TrackingStatus::IN_TRANSIT,
        'final-destination' => TrackingStatus::READY_FOR_PICKUP,
        'delivered' => TrackingStatus::DELIVERED,
        'returned' => TrackingStatus::RETURNED,
        'expired-return' => TrackingStatus::RETURNED,
        'canceled' => TrackingStatus::CANCELLED,
        'cancelled' => TrackingStatus::CANCELLED,
        'cancelled-return' => TrackingStatus::CANCELLED,
        'canceled-return' => TrackingStatus::CANCELLED,
        'lost' => TrackingStatus::EXCEPTION,
        'missing' => TrackingStatus::EXCEPTION,
    ];

    /** States after which a parcel no longer occupies its order number in any useful sense. */
    private const DEAD_STATES = ['canceled', 'cancelled', 'cancelled-return', 'canceled-return'];

    public static function handle(): string
    {
        return 'boxnow';
    }

    public static function displayName(): string
    {
        return 'BOX NOW';
    }

    public static function region(): string
    {
        return Craft::t('carrier', 'Europe');
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'BOX NOW parcel-locker delivery in Greece, Cyprus, Bulgaria, Croatia and Slovenia, with cash on delivery and the full locker list.');
    }

    /**
     * Credentials and the API URL are issued at partner onboarding; this is the page the manual
     * names as the home of the Partner API documentation.
     */
    public static function setupUrl(): ?string
    {
        return 'https://www.boxnow.bg/en/partner-api';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(
                Feature::LABELS, Feature::CANCEL, Feature::REPRINT, Feature::TRACKING, Feature::COD,
                Feature::MULTI_PIECE,
            )
            ->points(Capabilities::POINTS_LIST, PickupPoint::TYPE_LOCKER)
            ->labelFormats(LabelFormat::PDF_A6, LabelFormat::ZPL)
            ->originCountries('HR', 'SI', 'GR', 'BG', 'CY')
            ->maxParcelWeight(self::MAX_WEIGHT_G)
            ->trackingBatch(1)
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return [
            Field::environment([
                'instructions' => 'Sandbox uses BOX NOW’s stage host. Stage parcels are never collected.',
            ]),
            Field::select('country', 'Country', [
                'HR' => 'Croatia',
                'SI' => 'Slovenia',
                'GR' => 'Greece',
                'BG' => 'Bulgaria',
                'CY' => 'Cyprus',
            ], [
                'instructions' => 'BOX NOW issues credentials per country and delivers only within it. Slovenia uses the Croatian host unless BOX NOW gave you another URL.',
            ]),
            Field::text('clientId', 'Client ID', ['required' => true, 'requiredFor' => self::NOT_POINTS]),
            Field::secret('clientSecret', 'Client secret', ['required' => true, 'requiredFor' => self::NOT_POINTS]),
            Field::text('originLocationId', 'Origin (warehouse) ID', [
                'required' => true,
                'requiredFor' => self::NOT_POINTS,
                'instructions' => 'The id BOX NOW registered for your warehouse — the connection test lists them. Use “any-apm” if you drop parcels into any locker yourself.',
            ]),
            Field::text('partnerId', 'Partner ID', [
                'instructions' => 'Only when one login manages several partners. Sent as X-PartnerID.',
            ]),
            Field::url('apiUrl', 'API URL', [
                'instructions' => 'Leave blank to use the standard host for the country and environment. Fill it in if BOX NOW gave you a different one.',
                'placeholder' => 'https://api-production.boxnow.hr',
            ]),
            Field::select('compartmentSize', 'Locker compartment', [
                'auto' => 'Smallest that fits the parcel’s dimensions',
                '1' => 'Small (8 × 45 × 60 cm)',
                '2' => 'Medium (17 × 45 × 60 cm)',
                '3' => 'Large (36 × 45 × 60 cm)',
            ]),
            Field::boolean('allowReturn', 'Allow the customer to return through BOX NOW', ['default' => true]),
        ];
    }

    public static function services(): array
    {
        return [
            'locker' => ['name' => 'BOX NOW locker', 'international' => false, 'points' => true],
        ];
    }

    public static function trackingUrl(string $trackingNumber): ?string
    {
        return 'https://t.boxnow.hr/?track=' . rawurlencode($trackingNumber);
    }

    // =========================================================================
    // Plumbing
    // =========================================================================

    private function country(): string
    {
        $country = strtoupper((string)$this->setting('country', 'HR'));

        return isset(self::COUNTRIES[$country]) ? $country : 'HR';
    }

    private function baseUrl(): string
    {
        $override = rtrim(trim((string)$this->setting('apiUrl', '')), '/');

        if (str_starts_with($override, 'https://')) {
            return $override;
        }

        return sprintf('https://api-%s.boxnow.%s', $this->isSandbox() ? 'stage' : 'production', self::COUNTRIES[$this->country()]['api']);
    }

    private function feedUrl(string $country): string
    {
        $c = self::COUNTRIES[$country];

        return sprintf('https://locationapi-%s.boxnow.%s/v1/apms_%s.json', $this->isSandbox() ? 'stage' : 'production', $c['feed'], $c['lang']);
    }

    protected function buildTransport(): Transport
    {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        $partner = trim((string)$this->setting('partnerId', ''));

        if ($partner !== '') {
            $headers['X-PartnerID'] = $partner;
        }

        return (new Transport())
            ->setBaseUri($this->baseUrl())
            ->setDefaultHeaders($headers)
            ->setTimeout(30);
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new BoxNowAuth(fn() => $this->baseUrl() . '/api/v1/auth-sessions');
    }

    /**
     * List the merchant's origins. It is the cheapest authenticated call, and it answers the
     * question merchants actually get wrong: which warehouse id to put in the settings.
     */
    protected function probe(): HealthResult
    {
        $response = $this->transport()->get('/api/v1/origins', ['locationType' => 'warehouse']);

        if (!$response->ok()) {
            return HealthResult::fail(
                Craft::t('carrier', 'BOX NOW refused the connection: {message}', ['message' => $this->boxNowError($response)]),
                [Craft::t('carrier', 'Check the client ID and secret, and that the country and environment match the credentials BOX NOW issued — each country has its own host.')],
            );
        }

        $origin = trim((string)$this->setting('originLocationId', ''));
        $origins = array_values(array_filter((array)$response->at('data', []), 'is_array'));
        $ids = array_map(static fn(array $o) => (string)($o['id'] ?? ''), $origins);
        $details = ['Host' => $this->baseUrl(), 'Warehouses' => implode(', ', array_map(
            static fn(array $o) => ($o['id'] ?? '') . ' (' . ($o['name'] ?? $o['title'] ?? '') . ')',
            $origins,
        ))];

        if ($origin === 'any-apm' || in_array($origin, $ids, true)) {
            return HealthResult::pass(Craft::t('carrier', 'Connected to BOX NOW {country}.', ['country' => $this->country()]), array_filter($details));
        }

        return HealthResult::fail(
            Craft::t('carrier', 'Signed in to BOX NOW, but “{origin}” is not one of your origins.', ['origin' => $origin]),
            [$ids !== []
                ? Craft::t('carrier', 'Use one of: {ids}.', ['ids' => implode(', ', $ids)])
                : Craft::t('carrier', 'BOX NOW lists no warehouse for this account yet. Ask BOX NOW to register one, or use “any-apm”.')],
            array_filter($details),
        );
    }

    // =========================================================================
    // Labels
    // =========================================================================

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        $items = [];
        $problem = $this->shipmentProblem($request, $items);

        if ($problem !== null) {
            return ShipmentResult::rejected($problem);
        }

        $override = trim((string)$request->option('boxnowOrderNumber', ''));
        $base = $override !== '' ? $override : $this->orderNumber($request);
        $orderNumber = $base;
        $body = $this->deliveryBody($request, $items, $orderNumber);

        // A P410 sends us to look; a cancelled predecessor lets us move to the next suffix.
        for ($attempt = 1; ; $attempt++) {
            $response = $this->transport()->post('/api/v1/delivery-requests', $body);

            if ($response->ok() || $override !== '' || $attempt >= 4 || !$this->isDuplicate($response)) {
                break;
            }

            $existing = $this->parcelsForOrder($orderNumber);

            if ($existing === null) {
                return ShipmentResult::uncertain(Craft::t('carrier', 'BOX NOW already has an order {order}, and Carrier could not check what it holds. It may be an earlier attempt at this shipment; check the BOX NOW portal before trying again.', ['order' => $orderNumber]));
            }

            $live = array_filter($existing, static fn(array $p) => !in_array(strtolower((string)($p['state'] ?? '')), self::DEAD_STATES, true));

            if ($live !== []) {
                return ShipmentResult::uncertain(Craft::t('carrier', 'BOX NOW already has order {order} with live parcels {parcels} — almost certainly an earlier attempt at this shipment whose answer was lost. Record it as created with the first parcel number; do not buy it again.', [
                    'order' => $orderNumber,
                    'parcels' => implode(', ', array_map(static fn(array $p) => (string)($p['id'] ?? ''), $live)),
                ]));
            }

            $orderNumber = $base . '-' . ($attempt + 1);
            $body = $this->deliveryBody($request, $items, $orderNumber);
        }

        if (!$response->ok()) {
            return ShipmentResult::fromResponse($response, $this->boxNowError($response));
        }

        $id = (string)($response->at('id') ?? $response->at('referenceNumber') ?? '');
        $parcelIds = array_values(array_filter(array_map(
            static fn($p) => is_array($p) ? (string)($p['id'] ?? $p['referenceNumber'] ?? '') : '',
            (array)$response->at('parcels', []),
        )));

        if ($parcelIds === [] || count($parcelIds) !== count($items)) {
            // 200 means BOX NOW accepted it; without ids nobody can tell what exists.
            return ShipmentResult::uncertain(Craft::t('carrier', 'BOX NOW accepted order {order} but returned {got} parcel numbers for {want} parcels. Check the BOX NOW portal before trying again.', [
                'order' => $orderNumber,
                'got' => count($parcelIds),
                'want' => count($items),
            ]));
        }

        $format = $this->labelFormatFor($request);
        $labels = [];

        foreach ($parcelIds as $i => $parcelId) {
            $bytes = $this->fetchLabel('/api/v1/parcels/' . rawurlencode($parcelId), $format);

            if ($bytes === null) {
                // Created and live, but nothing to print. Reprint fetches the label; a rebuy must not happen.
                return ShipmentResult::uncertain(Craft::t('carrier', 'BOX NOW created order {order} (parcels {parcels}) but the label for {parcel} could not be fetched. Record it as created with parcel {first}; Carrier will fetch the labels again. Do not buy it again.', [
                    'order' => $orderNumber,
                    'parcels' => implode(', ', $parcelIds),
                    'parcel' => $parcelId,
                    'first' => $parcelIds[0],
                ]));
            }

            $labels[] = new Label([
                'parcelIndex' => $i,
                'trackingNumber' => $parcelId,
                'format' => $format,
                'data' => $bytes,
            ]);
        }

        return ShipmentResult::ok($id !== '' ? $id : $orderNumber, $parcelIds, $labels, [
            'orderNumber' => $orderNumber,
            'deliveryRequestId' => $id,
            'parcelIds' => $parcelIds,
            'country' => $this->country(),
        ]);
    }

    /**
     * Everything BOX NOW would refuse that can be refused here, with the compartment chosen for
     * each parcel along the way.
     *
     * @param array<int,array{parcel:Parcel,size:int}> $items filled in
     */
    private function shipmentProblem(ShipmentRequest $request, array &$items): ?string
    {
        $country = $this->country();

        if (!$request->shipTo || strtoupper($request->shipTo->countryCode) !== $country) {
            return Craft::t('carrier', 'This BOX NOW connection delivers within {country} only.', ['country' => $country]);
        }

        if ($request->isReturn) {
            return Craft::t('carrier', 'BOX NOW return labels are not supported. Enable “Allow return” so customers can return through the locker.');
        }

        if ($request->pointId === '') {
            return Craft::t('carrier', 'BOX NOW delivers to lockers only, and no locker was chosen for this order.');
        }

        if ($this->phone($request->shipTo->phone) === '') {
            return Craft::t('carrier', 'BOX NOW needs the recipient’s mobile number in international format — the locker PIN is sent by SMS.');
        }

        if (trim($request->shipTo->email) === '' || $request->shipTo->contactName() === '') {
            return Craft::t('carrier', 'BOX NOW needs the recipient’s name and email address.');
        }

        if (trim((string)$this->setting('originLocationId', '')) === '') {
            return Craft::t('carrier', 'Fill in the origin (warehouse) ID on this BOX NOW connection.');
        }

        if ($request->hasCod()) {
            if ($request->codCurrency !== '' && strtoupper($request->codCurrency) !== 'EUR') {
                return Craft::t('carrier', 'BOX NOW collects cash on delivery in EUR only; this order is in {currency}.', ['currency' => $request->codCurrency]);
            }

            if ($request->codAmount >= 5000) {
                return Craft::t('carrier', 'BOX NOW collects at most 4,999.99 EUR on delivery.');
            }
        }

        foreach (array_values($request->parcels) as $i => $parcel) {
            if ($parcel->weightG > self::MAX_WEIGHT_G) {
                return Craft::t('carrier', 'Parcel {n} weighs more than the 20 kg a BOX NOW locker takes.', ['n' => $i + 1]);
            }

            $size = $this->compartment($parcel);

            if ($size === null) {
                return Craft::t('carrier', 'Parcel {n} does not fit a BOX NOW locker compartment (at most 36 × 45 × 60 cm).', ['n' => $i + 1]);
            }

            $items[] = ['parcel' => $parcel, 'size' => $size];
        }

        return $items === [] ? Craft::t('carrier', 'There is nothing to ship.') : null;
    }

    /**
     * The smallest compartment the parcel fits, by its sorted dimensions; medium when the
     * dimensions are unknown, as the official plugin does. A fixed size in settings wins.
     */
    private function compartment(Parcel $parcel): ?int
    {
        $fixed = (string)$this->setting('compartmentSize', 'auto');
        $sides = [$parcel->lengthMm, $parcel->widthMm, $parcel->heightMm];
        rsort($sides);

        if ($parcel->hasDimensions() && ($sides[0] > 600 || $sides[1] > 450 || $sides[2] > self::COMPARTMENTS[3])) {
            return null;
        }

        if (isset(self::COMPARTMENTS[(int)$fixed])) {
            return (int)$fixed;
        }

        if (!$parcel->hasDimensions()) {
            return 2;
        }

        foreach (self::COMPARTMENTS as $size => $height) {
            if ($sides[2] <= $height) {
                return $size;
            }
        }

        return null;
    }

    /**
     * The reference plus a hash of what is being sent. See "Order numbers" in the class docblock
     * for why this is deterministic rather than random.
     */
    private function orderNumber(ShipmentRequest $request): string
    {
        $reference = preg_replace('/[^A-Za-z0-9_-]/', '', $request->reference ?: (string)$request->orderId) ?: 'ORDER';
        $fingerprint = json_encode([
            $request->reference,
            $request->serviceCode,
            $request->pointId,
            round($request->codAmount, 2),
            array_map(static fn(Parcel $p) => [$p->weightG, $p->lengthMm, $p->widthMm, $p->heightMm, round($p->value, 2)], array_values($request->parcels)),
        ]);

        return mb_substr($reference, 0, 40) . '_' . substr(sha1((string)$fingerprint), 0, 6);
    }

    /**
     * @param array<int,array{parcel:Parcel,size:int}> $items
     */
    private function deliveryBody(ShipmentRequest $request, array $items, string $orderNumber): array
    {
        $cod = $request->hasCod();
        $from = $request->shipFrom ?? new Address();
        $reference = $request->reference !== '' ? $request->reference : $orderNumber;
        $lines = [];

        foreach ($items as $i => $item) {
            $parcel = $item['parcel'];
            $lines[] = [
                'id' => mb_substr($reference, 0, 40) . '-' . ($i + 1),
                'name' => mb_substr($parcel->description !== '' ? $parcel->description : Craft::t('carrier', 'Order {reference}', ['reference' => $reference]), 0, 100),
                'value' => self::money($parcel->value),
                // kg, a number; BOX NOW asks for 1 when the weight is unknown.
                'weight' => $parcel->weightG > 0 ? Units::gramsToKg($parcel->weightG, 3) : 1,
                'compartmentSize' => $item['size'],
            ];
        }

        $origin = array_filter([
            'locationId' => trim((string)$this->setting('originLocationId')),
            'contactNumber' => $this->phone($from->phone),
            'contactEmail' => $from->email,
            'contactName' => $from->company !== '' ? $from->company : $from->name,
        ], static fn($v) => $v !== '');

        return [
            'orderNumber' => $orderNumber,
            // The manual's own example sends the goods value on a prepaid order; for COD it is
            // what the customer pays at the locker.
            'invoiceValue' => self::money($cod ? $request->codAmount : $request->declaredValue),
            'paymentMode' => $cod ? 'cod' : 'prepaid',
            'amountToBeCollected' => $cod ? self::money($request->codAmount) : '0.00',
            'allowReturn' => $this->boolSetting('allowReturn', true),
            'origin' => $origin,
            'destination' => [
                'locationId' => $request->pointId,
                'contactNumber' => $this->phone($request->shipTo->phone),
                'contactEmail' => $request->shipTo->email,
                'contactName' => $request->shipTo->contactName(),
            ],
            'items' => $lines,
            'notifyOnAccepted' => '',
            'additionalInformation' => 'Craft Commerce / Carrier',
        ];
    }

    private function isDuplicate(Response $response): bool
    {
        return $response->status === 400 && strtoupper((string)$response->at('code', '')) === 'P410';
    }

    /**
     * The parcels BOX NOW holds under an order number, or null when it could not be asked.
     *
     * @return array<int,array>|null
     */
    private function parcelsForOrder(string $orderNumber): ?array
    {
        $response = $this->transport()->get('/api/v1/parcels', ['orderNumber' => $orderNumber, 'limit' => 100]);

        if (!$response->ok()) {
            return null;
        }

        return array_values(array_filter((array)$response->at('data', []), 'is_array'));
    }

    /**
     * Raw label bytes for a parcel or an order, or null when what came back is not a label.
     */
    private function fetchLabel(string $path, string $format): ?string
    {
        $response = $format === LabelFormat::ZPL
            ? $this->transport()->get($path . '/label.zpl', ['dpi' => 200], ['headers' => ['Accept' => 'application/zpl, text/plain']])
            : $this->transport()->get($path . '/label.pdf', [], ['headers' => ['Accept' => 'application/pdf']]);

        if (!$response->ok() || !LabelFormat::bytesMatch($format, $response->body)) {
            return null;
        }

        return $response->body;
    }

    // =========================================================================
    // Void, reprint
    // =========================================================================

    public function cancelShipment(ShipmentRef $shipment): ActionResult
    {
        $parcelIds = array_values(array_filter(array_map('strval', $shipment->data['parcelIds'] ?? $shipment->trackingNumbers)));

        if ($parcelIds === []) {
            return ActionResult::failed(Craft::t('carrier', 'There is no BOX NOW parcel number to cancel.'));
        }

        $cancelled = [];
        $errors = [];

        // BOX NOW cancels parcels, not delivery requests. Cancelling a cancelled parcel is a no-op.
        foreach ($parcelIds as $parcelId) {
            $response = $this->transport()->post('/api/v1/parcels/' . rawurlencode($parcelId) . ':cancel', new \stdClass());

            if ($response->ok()) {
                $cancelled[] = $parcelId;
            } else {
                $errors[] = $parcelId . ': ' . $this->boxNowError($response);
            }
        }

        if ($errors !== []) {
            return ActionResult::failed(implode('; ', $errors) . ($cancelled !== []
                ? ' ' . Craft::t('carrier', 'Cancelled: {parcels}.', ['parcels' => implode(', ', $cancelled)])
                : ''));
        }

        return ActionResult::ok(implode(', ', $cancelled), Craft::t('carrier', 'Cancelled with BOX NOW.'));
    }

    public function printLabels(array $shipments, string $format): LabelResult
    {
        if (!static::capabilities()->supportsLabelFormat($format)) {
            $format = LabelFormat::PDF_A6;
        }

        $labels = [];

        foreach ($shipments as $shipment) {
            $orderNumber = (string)($shipment->data['orderNumber'] ?? '');
            $parcelIds = array_values(array_filter(array_map('strval', $shipment->data['parcelIds'] ?? $shipment->trackingNumbers)));

            // One order's parcels come back as one document when BOX NOW is asked by order.
            if ($orderNumber !== '' && count($parcelIds) > 1) {
                $bytes = $this->fetchLabel('/api/v1/delivery-requests/' . rawurlencode($orderNumber), $format);

                if ($bytes !== null) {
                    $labels[] = new Label(['format' => $format, 'data' => $bytes]);
                    continue;
                }
            }

            foreach ($parcelIds as $i => $parcelId) {
                $bytes = $this->fetchLabel('/api/v1/parcels/' . rawurlencode($parcelId), $format);

                if ($bytes === null) {
                    return LabelResult::failed(Craft::t('carrier', 'BOX NOW did not return a label for parcel {parcel}.', ['parcel' => $parcelId]));
                }

                $labels[] = new Label(['parcelIndex' => $i, 'trackingNumber' => $parcelId, 'format' => $format, 'data' => $bytes]);
            }
        }

        return $labels === [] ? LabelResult::failed(Craft::t('carrier', 'Nothing to print.')) : LabelResult::ok($labels);
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    public function track(array $trackingNumbers): array
    {
        $out = [];

        foreach ($trackingNumbers as $number) {
            $number = (string)$number;
            $response = $this->transport()->get('/api/v1/parcels', ['parcelId' => $number]);

            if (!$response->ok()) {
                continue;
            }

            $parcel = null;

            foreach ((array)$response->at('data', []) as $row) {
                if (is_array($row) && (string)($row['id'] ?? '') === $number) {
                    $parcel = $row;
                    break;
                }
            }

            $out[] = $parcel === null
                ? new TrackingInfo(['trackingNumber' => $number, 'notFound' => true])
                : $this->trackingInfo($number, $parcel);
        }

        return $out;
    }

    private function trackingInfo(string $number, array $parcel): TrackingInfo
    {
        $events = [];
        $deliveredAt = null;

        foreach ((array)($parcel['events'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $type = strtolower(trim((string)($raw['type'] ?? '')));
            $status = self::mapStatus($type, self::STATUS_MAP);
            $at = self::parseDate((string)($raw['createTime'] ?? ''), 'UTC');

            $events[] = new TrackingEvent([
                'occurredAt' => $at,
                'status' => $status,
                'code' => $type,
                'description' => self::describe($type),
                'location' => trim(implode(', ', array_filter([(string)($raw['locationDisplayName'] ?? ''), (string)($raw['postalCode'] ?? '')]))),
            ]);

            if ($status === TrackingStatus::DELIVERED) {
                $deliveredAt = $at;
            }
        }

        usort($events, static fn(TrackingEvent $a, TrackingEvent $b) => ($b->occurredAt?->getTimestamp() ?? 0) <=> ($a->occurredAt?->getTimestamp() ?? 0));

        $state = strtolower(trim((string)($parcel['state'] ?? '')));
        $status = $state !== '' ? self::mapStatus($state, self::STATUS_MAP) : ($events[0]->status ?? TrackingStatus::PRE_TRANSIT);

        return new TrackingInfo([
            'trackingNumber' => $number,
            'status' => $status,
            'statusText' => $state !== '' ? self::describe($state) : TrackingStatus::label($status),
            'deliveredAt' => $status === TrackingStatus::DELIVERED ? $deliveredAt : null,
            'events' => $events,
        ]);
    }

    /** BOX NOW's kebab-case states, as words. */
    private static function describe(string $state): string
    {
        return match ($state) {
            'final-destination' => Craft::t('carrier', 'In the locker, waiting for the customer'),
            'expired-return' => Craft::t('carrier', 'Not collected in time, returning'),
            'wait-for-load' => Craft::t('carrier', 'Waiting to be loaded'),
            'in-depot' => Craft::t('carrier', 'At the depot'),
            default => ucfirst(str_replace('-', ' ', $state)),
        };
    }

    // =========================================================================
    // Lockers
    // =========================================================================

    /**
     * The whole country's lockers from BOX NOW's public feed, as one page.
     */
    public function pickupPoints(PointQuery $query): PointPage
    {
        $country = strtoupper($query->countryCode !== '' ? $query->countryCode : $this->country());

        // A connection ships within its own country; another country's lockers are unusable here.
        if ($country !== $this->country()) {
            return PointPage::of([]);
        }

        // A public feed on another host: it must never receive the OAuth bearer token.
        $response = $this->transport()->get($this->feedUrl($country), [], ['headers' => ['Accept' => 'application/json'], 'auth' => false]);

        if (!$response->ok()) {
            return PointPage::failed(Craft::t('carrier', 'BOX NOW’s locker list could not be fetched: {message}', ['message' => $this->boxNowError($response)]));
        }

        $points = [];

        foreach ((array)$response->at('data', []) as $row) {
            if (!is_array($row) || (string)($row['id'] ?? '') === '') {
                continue;
            }

            // Latitude and longitude arrive as strings.
            $lat = $row['lat'] ?? null;
            $lng = $row['lng'] ?? null;

            $points[] = new PickupPoint([
                'id' => (string)$row['id'],
                'type' => PickupPoint::TYPE_LOCKER,
                'name' => (string)($row['name'] ?? '') ?: (string)($row['title'] ?? $row['id']),
                'street' => (string)($row['addressLine1'] ?? ''),
                // addressLine2 is the city.
                'city' => (string)($row['addressLine2'] ?? ''),
                'postcode' => (string)($row['postalCode'] ?? ''),
                'countryCode' => strtoupper((string)($row['country'] ?? '')) ?: $country,
                'latitude' => is_numeric($lat) ? (float)$lat : null,
                'longitude' => is_numeric($lng) ? (float)$lng : null,
                'maxWeightG' => self::MAX_WEIGHT_G,
                'extra' => array_filter([
                    'title' => (string)($row['title'] ?? ''),
                    'note' => (string)($row['note'] ?? ''),
                    'image' => (string)($row['image'] ?? ''),
                    'state' => (string)($row['state'] ?? ''),
                ]),
            ]);
        }

        return PointPage::of($points);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Full international format, which BOX NOW insists on (P405). Carrier normalises numbers to
     * `+…` already; a national number is completed with the connection country's calling code.
     */
    private function phone(string $phone): string
    {
        $calling = self::COUNTRIES[$this->country()]['calling'];
        $normalised = Addresses::normalisePhone($phone, $calling);

        if ($normalised !== '' && !str_starts_with($normalised, '+') && str_starts_with($normalised, $calling)) {
            $normalised = '+' . $normalised;
        }

        return str_starts_with($normalised, '+') && strlen($normalised) >= 9 ? $normalised : '';
    }

    private static function money(float $amount): string
    {
        return number_format(max(0.0, $amount), 2, '.', '');
    }

    private function boxNowError(Response $response): string
    {
        $code = (string)$response->at('code', '');
        $message = $response->errorMessage();

        return $this->redactSecrets($code !== '' && !str_contains($message, $code) && preg_match('/^[A-Z]\d{3}$/', $code) ? "$code: $message" : $message);
    }
}
