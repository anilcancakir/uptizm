/**
 * HMAC-SHA256 verification for incoming API requests.
 *
 * Laravel signs `${timestamp}.${body}` with the shared `RELAY_SECRET`.
 * The Worker recomputes the signature with WebCrypto and compares it
 * using constant-time equality to prevent timing side-channels. This
 * module is verify-only: the sync relay never signs a callback, so
 * there is deliberately no `sign()` counterpart here.
 */

const TTL_SECONDS = 300;

export async function verifySignature(
    body: string,
    timestamp: string,
    signatureHex: string,
    secret: string,
): Promise<boolean> {
    // 1. Reject timestamps outside the replay window.
    const parsed = Number.parseInt(timestamp, 10);
    if (!Number.isFinite(parsed)) {
        return false;
    }
    const now = Math.floor(Date.now() / 1000);
    if (Math.abs(now - parsed) > TTL_SECONDS) {
        return false;
    }

    // 2. Import the shared secret as an HMAC key.
    const key = await crypto.subtle.importKey(
        "raw",
        new TextEncoder().encode(secret),
        {
            name: "HMAC",
            hash: "SHA-256",
        },
        false,
        [
            "sign",
        ],
    );

    // 3. Sign `${timestamp}.${body}` and hex-encode.
    const signatureBytes = await crypto.subtle.sign(
        "HMAC",
        key,
        new TextEncoder().encode(`${timestamp}.${body}`),
    );
    const expected = bytesToHex(new Uint8Array(signatureBytes));

    // 4. Constant-time compare.
    return timingSafeEqual(expected, signatureHex);
}

function bytesToHex(bytes: Uint8Array): string {
    return Array.from(bytes)
        .map((b) => b.toString(16).padStart(2, "0"))
        .join("");
}

function timingSafeEqual(a: string, b: string): boolean {
    if (a.length !== b.length) {
        return false;
    }
    let diff = 0;
    for (let i = 0; i < a.length; i++) {
        diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
    }
    return diff === 0;
}
