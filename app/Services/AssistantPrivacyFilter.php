<?php

namespace App\Services;

use App\Models\SalesTransaction;
use RuntimeException;
use Throwable;

class AssistantPrivacyFilter
{
    /** @var list<array{customer_name: ?string, customer_contact: ?string, transaction_no: ?string}>|null */
    private ?array $saleHeaders = null;

    private const CREDENTIAL_PATTERN = '/(?:\b(?:password|passcode|secret|api[\s_-]*key|access[\s_-]*token|auth[\s_-]*token|token)\b\s*(?::|=|\bis\b)\s*\S+|\b(?:gh[pousr]_[A-Za-z0-9_]{12,}|AIza[A-Za-z0-9_-]{20,}|sk-[A-Za-z0-9_-]{16,})\b)/iu';

    private const EMAIL_PATTERN = '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[\p{L}]{2,}/u';

    private const PH_MOBILE_PATTERN = '/(?<!\d)(?:\+63[\s-]*9|09)(?:[\s-]*\d){9}(?!\d)/u';

    private const LONG_DIGITS_PATTERN = '/(?<!\d)\d{8,}(?!\d)/u';

    private const SALE_REFERENCE_PATTERN = '/(?:\#[A-Z0-9-]*\d[A-Z0-9-]*\b|\b(?:TXN|INV)[-#:][A-Z0-9-]*\d[A-Z0-9-]*\b|\b(?:sale|transaction|invoice|receipt|order)\s*(?:number|no\.?|\#)\s*[-#:]*\s*[A-Z0-9-]*\d[A-Z0-9-]*\b)/iu';

    private const NAME_CONTEXT = '(?:ask|tell|text|call|message|contact|customer|buyer|client|ni|kay|mr|mrs|ms)';

    /**
     * This service screens disclosure only; it does not decide a store intent.
     *
     * @return array{status: 'safe'|'redacted'|'blocked', text: ?string, reason: ?string, message: ?string}
     */
    public function screen(string $message): array
    {
        if (trim($message) === '' || mb_strlen($message) > 500) {
            return $this->blocked('invalid_input', 'Please send a shorter message so I can help safely.');
        }

        if (preg_match(self::CREDENTIAL_PATTERN, $message) === 1) {
            return $this->blocked('credential', 'Please leave passwords, keys, and tokens out of our chat. Ask me again without them.');
        }

        try {
            $screened = $message;
            $redacted = false;

            foreach ([
                [self::EMAIL_PATTERN, '[private contact]'],
                [self::PH_MOBILE_PATTERN, '[private contact]'],
                [self::SALE_REFERENCE_PATTERN, '[private reference]'],
            ] as [$pattern, $placeholder]) {
                $screened = $this->redact($screened, $pattern, $placeholder, $redacted);
            }

            $fullName = '\\p{Lu}[\\p{Ll}]{2,}(?:\\s+(?:de|del|dela))?\\s+\\p{Lu}[\\p{Ll}]{2,}';
            $screened = $this->redact($screened, '/\\b(?i:does|did|tell)\\s+\\K'.$fullName.'/u', '[private person]', $redacted);
            $screened = $this->redact($screened, '/\\b(?:ni|kay)\\s+\\K[\\p{L}]{3,}(?:\\s+(?:de|del|dela))?(?:\\s+[\\p{L}]{3,})?/iu', '[private person]', $redacted);
            $screened = $this->redact($screened, '/\\b(?:mr|mrs|ms)\\.\\s*\\K[\\p{L}]{3,}/iu', '[private person]', $redacted);
            $screened = $this->redact($screened, '/\\bask\\s+\\K[\\p{Ll}]{3,}(?=\\s+if\\b)/u', '[private person]', $redacted);

            $names = [];
            $contacts = [];
            $references = [];

            foreach ($this->saleHeaders() as $sale) {
                if (is_string($sale['customer_name']) && trim($sale['customer_name']) !== '') {
                    $names[mb_strtolower(trim($sale['customer_name']))] = trim($sale['customer_name']);
                }

                if (is_string($sale['customer_contact']) && trim($sale['customer_contact']) !== '') {
                    $contacts[$sale['customer_contact']] = $sale['customer_contact'];
                }

                if (is_string($sale['transaction_no']) && trim($sale['transaction_no']) !== '') {
                    $references[$sale['transaction_no']] = $sale['transaction_no'];
                }
            }

            foreach ($contacts as $contact) {
                $digits = preg_replace('/\D/u', '', $contact);

                if (strlen($digits) >= 7) {
                    $pattern = '/(?<!\d)'.implode('[\s-]*', str_split($digits)).'(?!\d)/u';
                    $screened = $this->redact($screened, $pattern, '[private contact]', $redacted);
                }
            }

            foreach ($references as $reference) {
                if (mb_strlen($reference) >= 4) {
                    $screened = $this->redact($screened, $this->wholeToken($reference), '[private reference]', $redacted);
                }
            }

            $screened = $this->redact($screened, self::LONG_DIGITS_PATTERN, '[private number]', $redacted);

            usort($names, fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));
            $nameTokens = [];

            foreach ($names as $name) {
                $parts = preg_split('/\s+/u', $name) ?: [];

                if (count($parts) > 1) {
                    $pattern = '/(?<![\p{L}\p{N}])'.implode('\s+', array_map(fn (string $part): string => preg_quote($part, '/'), $parts)).'(?![\p{L}\p{N}])/iu';
                    $screened = $this->redact($screened, $pattern, '[private person]', $redacted);
                }

                foreach ([$parts[0] ?? '', $parts[count($parts) - 1] ?? ''] as $part) {
                    if (mb_strlen($part) >= 3) {
                        $nameTokens[mb_strtolower($part)] = $part;
                    }
                }
            }

            foreach ($nameTokens as $token) {
                $pattern = '/(?<prefix>\b'.self::NAME_CONTEXT.'\b\.?\s+)(?<name>'.preg_quote($token, '/').')(?![\p{L}\p{N}])/iu';
                $screened = $this->redactNameAfterContext($screened, $pattern, $redacted);
            }

            if (! $redacted) {
                return ['status' => 'safe', 'text' => $message, 'reason' => null, 'message' => null];
            }

            $remaining = preg_replace('/\[private (?:contact|reference|number|person)\]/u', '', $screened);

            if ($remaining === null || preg_match('/[\p{L}]{2,}/u', $remaining) !== 1) {
                return $this->blocked('private_detail', 'Please ask again without personal or sale details.');
            }

            return [
                'status' => 'redacted',
                'text' => $screened,
                'reason' => 'private_detail',
                'message' => 'I removed a private detail before continuing.',
            ];
        } catch (Throwable) {
            return $this->blocked('screen_unavailable', 'I cannot safely check that message right now. Please try again shortly.');
        }
    }

    private function wholeToken(string $value): string
    {
        return '/(?<![\p{L}\p{N}])'.preg_quote($value, '/').'(?![\p{L}\p{N}])/iu';
    }

    /** @return list<array{customer_name: ?string, customer_contact: ?string, transaction_no: ?string}> */
    private function saleHeaders(): array
    {
        if ($this->saleHeaders === null) {
            $this->saleHeaders = [];

            foreach (SalesTransaction::query()->select(['customer_name', 'customer_contact', 'transaction_no'])->cursor() as $sale) {
                $this->saleHeaders[] = [
                    'customer_name' => $sale->customer_name,
                    'customer_contact' => $sale->customer_contact,
                    'transaction_no' => $sale->transaction_no,
                ];
            }
        }

        return $this->saleHeaders;
    }

    private function redact(string $text, string $pattern, string $placeholder, bool &$redacted): string
    {
        $count = 0;
        $result = preg_replace($pattern, $placeholder, $text, -1, $count);

        if ($result === null) {
            throw new RuntimeException('Privacy pattern failed.');
        }

        $redacted = $redacted || $count > 0;

        return $result;
    }

    private function redactNameAfterContext(string $text, string $pattern, bool &$redacted): string
    {
        $count = 0;
        $result = preg_replace_callback(
            $pattern,
            fn (array $matches): string => $matches['prefix'].'[private person]',
            $text,
            -1,
            $count,
        );

        if ($result === null) {
            throw new RuntimeException('Privacy name pattern failed.');
        }

        $redacted = $redacted || $count > 0;

        return $result;
    }

    /** @return array{status: 'blocked', text: null, reason: string, message: string} */
    private function blocked(string $reason, string $message): array
    {
        return ['status' => 'blocked', 'text' => null, 'reason' => $reason, 'message' => $message];
    }
}
