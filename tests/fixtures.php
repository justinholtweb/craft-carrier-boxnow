<?php
/**
 * Recorded happy-path answers for the Carrier conformance suite.
 *
 * Shapes follow the BOX NOW Partner API manual v1.65, the official HR+SI WordPress plugin and
 * live probes of the public locker feed, as summarised in
 * craft-carrier/docs/research/see-carriers.md §3 — trimmed but structurally faithful.
 */

use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\helpers\Pdf;

return [
    'boxnow' => static function(string $method, string $url, array $options): Response {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $query = $options['query'] ?? [];
        $json = $options['json'] ?? [];

        $pdf = static fn(string $id) => new Response(200, ['Content-Type' => ['application/pdf']], Pdf::text(['BOX NOW', 'Parcel ' . $id, 'Locker 1689'], 297.6, 419.5));
        $zpl = static fn(string $id) => new Response(200, ['Content-Type' => ['application/zpl']], "^XA^FO40,40^A0N,40,40^FDBOX NOW^FS^FO40,120^BCN,120,Y,N,N^FD$id^FS^XZ");

        return match (true) {
            str_ends_with($path, '/api/v1/auth-sessions') => Response::json(200, [
                'access_token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.fixture',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),

            str_ends_with($path, '/api/v1/origins') => Response::json(200, [
                'data' => [[
                    'id' => '2',
                    'type' => 'warehouse',
                    'name' => 'TCK Store warehouse',
                    'title' => 'TCK Store',
                    'addressLine1' => 'Ilica 242',
                    'addressLine2' => 'Zagreb',
                    'postalCode' => '10000',
                    'country' => 'HR',
                    'lat' => '45.81500',
                    'lng' => '15.95000',
                    'note' => '',
                    'region' => 'hr-HR',
                ]],
            ]),

            $method === 'POST' && str_ends_with($path, '/api/v1/delivery-requests') => Response::json(200, [
                'id' => '42224',
                'parcels' => array_map(
                    static fn(int $i) => ['id' => (string)(2270192741 + $i)],
                    array_keys((array)($json['items'] ?? [[]])),
                ),
            ]),

            (bool)preg_match('#/api/v1/(parcels|delivery-requests)/([^/]+)/label\.pdf$#', $path, $m) => $pdf(urldecode($m[2])),
            (bool)preg_match('#/api/v1/(parcels|delivery-requests)/([^/]+)/label\.zpl$#', $path, $m) => $zpl(urldecode($m[2])),

            $method === 'POST' && str_ends_with($path, ':cancel') => new Response(200, [], ''),

            $method === 'GET' && str_ends_with($path, '/api/v1/parcels') => Response::json(200, [
                'data' => [[
                    'id' => (string)($query['parcelId'] ?? '2270192741'),
                    'state' => 'final-destination',
                    'events' => [
                        ['type' => 'new', 'createTime' => '2026-10-03T08:12:44.000Z', 'locationDisplayName' => 'TCK Store warehouse', 'locationId' => '2', 'postalCode' => '10000'],
                        ['type' => 'in-depot', 'createTime' => '2026-10-03T16:40:02.000Z', 'locationDisplayName' => 'Zagreb depot', 'locationId' => 'D1', 'postalCode' => '10000'],
                        ['type' => 'final-destination', 'createTime' => '2026-10-04T09:05:31.000Z', 'locationDisplayName' => 'BOX NOW Vrapče - Kebab Agram', 'locationId' => '1689', 'postalCode' => '10090'],
                    ],
                    'deliveryRequest' => ['orderNumber' => 'TCK-1001_abc123'],
                    'payment' => ['mode' => 'prepaid', 'price' => ['amount' => '2.90', 'currency' => 'EUR'], 'state' => 'pending'],
                    'cancelationRequested' => false,
                    'createTime' => '2026-10-03T08:12:44.000Z',
                    'updateTime' => '2026-10-04T09:05:31.000Z',
                ]],
                'pagination' => ['next' => null, 'size' => 1],
            ]),

            (bool)preg_match('#/v1/apms_[a-z]{2}-[A-Z]{2}\.json$#', $path) => Response::json(200, [
                'data' => [
                    [
                        'id' => '1689',
                        'type' => 'apm',
                        'lat' => '45.817207',
                        'lng' => '15.88953',
                        'addressLine1' => 'Bolnička cesta 66',
                        'addressLine2' => 'Zagreb',
                        'postalCode' => '10090',
                        'title' => 'BOX NOW Vrapče',
                        'name' => 'BOX NOW Vrapče - Kebab Agram',
                        'country' => 'HR',
                        'image' => 'https://storage.googleapis.com/boxnow/apm/1689.jpg',
                        'note' => 'Uz ulaz u restoran',
                        'region' => 'hr-HR',
                        'state' => 'boxnow-ready',
                        'expectedDeliveryTime' => '2026-10-04T20:00:00.000Z',
                    ],
                    [
                        'id' => '1702',
                        'type' => 'apm',
                        'lat' => '45.800912',
                        'lng' => '15.97121',
                        'addressLine1' => 'Savska cesta 41',
                        'addressLine2' => 'Zagreb',
                        'postalCode' => '10000',
                        'title' => 'BOX NOW Savska',
                        'name' => 'BOX NOW Savska - Konzum',
                        'country' => 'HR',
                        'image' => '',
                        'note' => '',
                        'region' => 'hr-HR',
                        'state' => 'only-delivery',
                        'expectedDeliveryTime' => '2026-10-04T20:00:00.000Z',
                    ],
                ],
            ]),

            default => Response::json(404, ['code' => 'P404', 'message' => 'No fixture for ' . $method . ' ' . $path]),
        };
    },
];
