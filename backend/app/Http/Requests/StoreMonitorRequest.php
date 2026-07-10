<?php

namespace App\Http\Requests;

use App\Enums\HttpMethod;
use App\Enums\MonitorRegion;
use App\Enums\MonitorType;
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
     * A literal IP is returned as-is; a hostname is resolved via DNS.
     * A resolution failure yields an empty list (the host is treated as
     * unreachable, not blocked).
     *
     * @return list<string>
     */
    protected function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $resolved = gethostbynamel($host);

        return $resolved === false ? [] : array_values($resolved);
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
