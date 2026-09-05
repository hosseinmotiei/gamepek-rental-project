<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pardakht Novin IPG client.
 *
 * Implemented strictly from the official documents provided:
 * - "پیاده سازی درگاه اینترنتی (خرید)" (I.P.IT.012.00) -- NormalSale/Confirm/Reverse
 * - Merchant credentials document
 *
 * Every field name, endpoint, and response shape below is taken verbatim from
 * those documents: requestToken() (NormalSale, Sprint 3 Task 2), confirm()
 * (Confirm, Sprint 3 Task 3), reverse() (Reverse, Sprint 3 Task 4).
 *
 * This class contains ONLY gateway wire-protocol logic. It knows nothing about
 * Order/PaymentTransaction/OrderService -- that orchestration lives in
 * PaymentService, per this task's explicit requirement.
 */
class PardakhtNovinGateway
{
    /** Doc page 4 (I.P.IT.012.00): Address for متد خرید (NormalSale). */
    private const NORMAL_SALE_URL = 'https://pna.shaparak.ir/mhipg/api/Payment/NormalSale';

    /**
     * Doc page 3: "https://pna.shaparak.ir/mhui/home/index/{token}" -- the page
     * the customer's browser is redirected to after a successful NormalSale call.
     */
    private const REDIRECT_URL_TEMPLATE = 'https://pna.shaparak.ir/mhui/home/index/%s';

    /** Doc page 6: Address for تایید خرید (Confirm). */
    private const CONFIRM_URL = 'https://pna.shaparak.ir/mhipg/api/Payment/confirm';

    /** Doc page 6-7: Address for بازگشت خرید (Reverse). */
    private const REVERSE_URL = 'https://pna.shaparak.ir/mhipg/api/Payment/Reverse';

    /** Doc page 4/6: Status "0" means "عملیات موفق" (operation successful). */
    private const STATUS_SUCCESS = '0';

    public function __construct(private ?string $corporationPin = null)
    {
        $this->corporationPin = $corporationPin ?? config('rental.payment.pardakhtnovin.corporation_pin');
    }

    /**
     * NormalSale (متد خرید) -- doc page 4-6.
     *
     * Request fields (exact names/types per the doc's input-parameter table):
     *   CorporationPin  string  required
     *   Amount          long    required
     *   OrderId         long    required, unique, merchant-generated
     *   CallBackUrl     string  required
     *   AdditionalData  string  optional (not sent in Phase 1 -- no value to supply yet)
     *   Originator      string  optional (not sent in Phase 1 -- no value to supply yet)
     *
     * Response fields (exact names per the doc):
     *   Token   string  -- valid 15 minutes per the doc's own note
     *   Message string
     *   Status  short   -- "0" = success
     *
     * @return array{success:bool, token:?string, status:?string, message:?string, raw:array}
     */
    public function requestToken(int $orderId, int $amount, string $callbackUrl): array
    {
        $payload = [
            'CorporationPin' => $this->corporationPin,
            'Amount' => $amount,
            'OrderId' => $orderId,
            'CallBackUrl' => $callbackUrl,
        ];

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->post(self::NORMAL_SALE_URL, $payload);
        } catch (\Throwable $e) {
            Log::error('PardakhtNovinGateway::requestToken transport failure', [
                'order_id' => $orderId,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'token' => null,
                'status' => null,
                'message' => 'خطا در اتصال به درگاه پرداخت.',
                'raw' => ['exception' => $e->getMessage()],
            ];
        }

        $data = $response->json() ?? [];

        Log::info('PardakhtNovinGateway::requestToken response', [
            'order_id' => $orderId,
            'http_status' => $response->status(),
            'response' => $data,
        ]);

        $status = array_find_ci($data, 'Status');
        $status = $status !== null ? (string) $status : null;
        $token = array_find_ci($data, 'Token');

        return [
            'success' => $response->successful() && $status === self::STATUS_SUCCESS && ! empty($token),
            'token' => $token,
            'status' => $status,
            'message' => array_find_ci($data, 'Message'),
            'raw' => $data,
        ];
    }

    /**
     * Doc page 3: the token from a successful NormalSale response is used to
     * build the URL the customer's browser is redirected to.
     */
    public function redirectUrl(string $token): string
    {
        return sprintf(self::REDIRECT_URL_TEMPLATE, $token);
    }

    /**
     * Confirm (تایید خرید) -- doc page 6.
     *
     * Request fields (exact names per the doc):
     *   CorporationPin  string  required
     *   Token           string  required
     *
     * Response fields (exact names per the doc -- NOTE: unlike NormalSale/
     * Reverse, Confirm's response has NO "Message" field):
     *   Status              short   -- "0" = success
     *   CardNumberMasked    string
     *   RRN                 long (per the doc's own note: "لازم به ذکر است RRN از نوع Long میباشد")
     *   Token               string
     *
     * @return array{success:bool, status:?string, rrn:?string, card_number_masked:?string, raw:array}
     */
    public function confirm(string $token): array
    {
        $payload = [
            'CorporationPin' => $this->corporationPin,
            'Token' => $token,
        ];

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->post(self::CONFIRM_URL, $payload);
        } catch (\Throwable $e) {
            Log::error('PardakhtNovinGateway::confirm transport failure', [
                'token' => $token,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => null,
                'rrn' => null,
                'card_number_masked' => null,
                'raw' => ['exception' => $e->getMessage()],
            ];
        }

        $data = $response->json() ?? [];

        Log::info('PardakhtNovinGateway::confirm response', [
            'token' => $token,
            'http_status' => $response->status(),
            'response' => $data,
        ]);

        $status = array_find_ci($data, 'Status');
        $status = $status !== null ? (string) $status : null;
        $rrn = array_find_ci($data, 'RRN');

        return [
            'success' => $response->successful() && $status === self::STATUS_SUCCESS,
            'status' => $status,
            'rrn' => $rrn !== null ? (string) $rrn : null,
            'card_number_masked' => array_find_ci($data, 'CardNumberMasked'),
            'raw' => $data,
        ];
    }

    /**
     * Reverse (بازگشت خرید) -- doc page 6-7.
     *
     * Request fields (exact names per the doc -- identical shape to Confirm's
     * request):
     *   CorporationPin  string  required
     *   Token           string  required
     *
     * Response fields (exact names per the doc -- identical shape to
     * NormalSale's response, i.e. Reverse DOES include "Message", unlike
     * Confirm's response which does not):
     *   Token   string
     *   Message string
     *   Status  short  -- "0" = success
     *
     * Per the doc's narrative (page 3-4): Reverse is only meaningful within
     * "کمتر از 15 دقیقه" (less than 15 minutes) after a successful Confirm --
     * this method does not enforce that window itself (the gateway does, via
     * response code -1552 "PaymentRequestIsNotEligibleToReversal"); it is
     * PaymentService's responsibility to decide whether to call this at all.
     *
     * @return array{success:bool, token:?string, status:?string, message:?string, raw:array}
     */
    public function reverse(string $token): array
    {
        $payload = [
            'CorporationPin' => $this->corporationPin,
            'Token' => $token,
        ];

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->post(self::REVERSE_URL, $payload);
        } catch (\Throwable $e) {
            Log::error('PardakhtNovinGateway::reverse transport failure', [
                'token' => $token,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'token' => null,
                'status' => null,
                'message' => 'خطا در اتصال به درگاه پرداخت.',
                'raw' => ['exception' => $e->getMessage()],
            ];
        }

        $data = $response->json() ?? [];

        Log::info('PardakhtNovinGateway::reverse response', [
            'token' => $token,
            'http_status' => $response->status(),
            'response' => $data,
        ]);

        $status = array_find_ci($data, 'Status');
        $status = $status !== null ? (string) $status : null;

        return [
            'success' => $response->successful() && $status === self::STATUS_SUCCESS,
            'token' => array_find_ci($data, 'Token'),
            'status' => $status,
            'message' => array_find_ci($data, 'Message'),
            'raw' => $data,
        ];
    }
}
