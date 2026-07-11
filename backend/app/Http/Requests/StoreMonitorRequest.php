<?php

namespace App\Http\Requests;

use App\Enums\HttpMethod;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
use App\Models\Monitor;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation rules for POST /monitors.
 *
 * Beyond the standard field validation, this request carries the SSRF
 * guard: the target URL is rejected when its host resolves to a loopback,
 * RFC1918, link-local, IPv6 ULA, or reserved `.internal` address, so a
 * tenant can never point a probe at the platform's own internal network
 * (see {@see self::noInternalHost()}).
 */
class StoreMonitorRequest extends FormRequest
{
    /**
     * IPv4 CIDR ranges a monitor host is never allowed to resolve into.
     *
     * Covers loopback, the three RFC1918 private blocks, and the
     * link-local range that fronts cloud metadata endpoints
     * (169.254.169.254).
     *
     * @var list<string>
     */
    protected const array BLOCKED_IPV4_CIDRS = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->current_team_id !== null;
    }

    /**
     * Normalize the payload before validation.
     *
     * Drops an explicit `expected_status_code: null` so the request never
     * carries it into {@see Monitor::create()}: the column is
     * NOT NULL DEFAULT 200, so persisting a literal null would 500. Removing
     * the key lets the database default apply.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('expected_status_code') === null) {
            $this->getInputSource()->remove('expected_status_code');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:200',
            ],
            'url' => [
                'required',
                'string',
                'url',
                'max:2048',
                $this->noInternalHost(),
            ],
            'type' => [
                'required',
                Rule::enum(MonitorType::class),
            ],
            'method' => [
                'required',
                Rule::enum(HttpMethod::class),
            ],
            'check_interval_sec' => [
                'required',
                'integer',
                'min:30',
                'max:86400',
            ],
            'timeout_sec' => [
                'required',
                'integer',
                'min:1',
                'max:120',
            ],
            'regions' => [
                'required',
                'array',
                'min:1',
            ],
            'regions.*' => [
                Rule::enum(MonitorRegion::class),
            ],
            'expected_status_code' => [
                'nullable',
                'integer',
                'min:100',
                'max:599',
            ],
            'auth_config' => [
                'nullable',
                'array',
            ],
        ];
    }

    /**
     * Build the SSRF guard closure for the `url` field.
     *
     * Extracts the host from the URL, rejects the reserved `localhost` /
     * `*.internal` names outright, then resolves the host to its candidate
     * IPs and fails validation when any of them falls inside a blocked
     * range. Hosts that cannot be resolved are allowed through: an
     * unresolvable host cannot be probed and carries no SSRF risk.
     *
     * @return Closure(string, mixed, Closure): void
     */
    protected function noInternalHost(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $host = parse_url((string) $value, PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                $fail('The :attribute must contain a valid host.');

                return;
            }

            // 1. Normalize: lowercase and strip IPv6 literal brackets.
            $host = strtolower(trim($host, '[]'));

            // 2. Reject reserved names that never resolve to a public host.
            if ($host === 'localhost' || str_ends_with($host, '.internal')) {
                $fail('The :attribute host is not allowed.');

                return;
            }

            // 3. Reject when any resolved address falls in a blocked range.
            foreach ($this->resolveHostIps($host) as $ip) {
                if ($this->isBlockedIp($ip)) {
                    $fail('The :attribute host resolves to a disallowed address.');

                    return;
                }
            }
        };
    }

    /**
     * Resolve a host to its candidate IP addresses.
     *
     * A dotted IPv4 / bracket-stripped IPv6 literal resolves to itself; an
     * integer, hex, or octal IPv4 literal (e.g. `2130706433`, `0x7f000001`)
     * normalizes to dotted-quad first so the range check sees the real
     * address; a hostname resolves via DNS to both its A and AAAA records.
     * A resolution failure yields an empty list (the host is treated as
     * unreachable, not blocked).
     *
     * @return list<string>
     */
    protected function resolveHostIps(string $host): array
    {
        // 1. A canonical dotted IPv4 or IPv6 literal resolves to itself.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // 2. A bare numeric literal can masquerade as an IPv4 address
        //    (`http://2130706433` is 127.0.0.1); normalize before checking.
        $numeric = $this->normalizeNumericIpv4($host);
        if ($numeric !== null) {
            return [$numeric];
        }

        // 3. A hostname resolves via DNS to its A and AAAA records so an
        //    internal-only IPv6 target (::1, ULA) is caught as well as IPv4.
        return [
            ...$this->resolveIpv4($host),
            ...$this->resolveIpv6($host),
        ];
    }

    /**
     * Normalize an integer, hex, or octal IPv4 literal to dotted-quad.
     *
     * Mirrors `inet_aton`'s numeric forms: decimal (`2130706433`), hex
     * (`0x7f000001`), and octal (`017700000001`). Returns null when the host
     * is not a bare numeric literal or falls outside the 32-bit range, so a
     * real hostname flows on to DNS resolution untouched.
     */
    protected function normalizeNumericIpv4(string $host): ?string
    {
        if (preg_match('/^(0x[0-9a-f]+|0[0-7]*|[1-9][0-9]*)$/i', $host) !== 1) {
            return null;
        }

        // Base 0 auto-detects the 0x (hex) and 0 (octal) prefixes.
        $long = intval($host, 0);
        if ($long < 0 || $long > 0xFFFFFFFF) {
            return null;
        }

        return long2ip($long);
    }

    /**
     * Resolve a hostname's IPv4 (A record) addresses.
     *
     * @return list<string>
     */
    protected function resolveIpv4(string $host): array
    {
        $resolved = gethostbynamel($host);

        return $resolved === false ? [] : array_values($resolved);
    }

    /**
     * Resolve a hostname's IPv6 (AAAA record) addresses.
     *
     * @return list<string>
     */
    protected function resolveIpv6(string $host): array
    {
        if (! checkdnsrr($host, 'AAAA')) {
            return [];
        }

        $records = dns_get_record($host, DNS_AAAA);
        if ($records === false) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (array $record): ?string => $record['ipv6'] ?? null,
            $records,
        )));
    }

    /**
     * Determine whether an IP address falls inside a blocked range.
     */
    protected function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::BLOCKED_IPV4_CIDRS as $cidr) {
                if ($this->ipv4InCidr($ip, $cidr)) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $this->ipv6IsBlocked($ip);
        }

        return false;
    }

    /**
     * Test whether an IPv4 address sits inside the given CIDR block.
     */
    protected function ipv4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $prefix = (int) $bits;

        $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        $ipLong = ip2long($ip) & 0xFFFFFFFF;
        $subnetLong = ip2long($subnet) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Test whether an IPv6 address is loopback (`::1`) or ULA (`fc00::/7`).
     */
    protected function ipv6IsBlocked(string $ip): bool
    {
        $packed = inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        // Loopback ::1.
        if ($packed === inet_pton('::1')) {
            return true;
        }

        // Unique local addresses fc00::/7: the top 7 bits are 1111110,
        // so the first byte masked with 0xFE equals 0xFC (covers fc/fd).
        return (ord($packed[0]) & 0xFE) === 0xFC;
    }
}
